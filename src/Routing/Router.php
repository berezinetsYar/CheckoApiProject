<?php

declare(strict_types=1);

namespace App\Routing;

use App\Container;

/**
 * Простой роутер без внешних зависимостей.
 *
 * Маршрут: ['GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']]
 * Шаблон {name} компилируется в именованную группу PCRE (?P<name>[^/]+).
 *
 * Для .NET-разработчика: аналог минимальной маршрутизации MapGet из
 * WebApplication, но разбор $_SERVER и создание контроллера — наша работа.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}, raw: string}> */
    private array $routes = [];

    public function __construct(
        private readonly ?Container $container = null,
    ) {
    }

    /**
     * @param class-string           $controller
     * @param string                 $path     шаблон пути, напр. /api/company/{inn}
     * @param array{0: class-string, 1: string} $handler [класс, метод]
     */
    public function add(string $method, string $path, array $handler): void
    {
        [$controller, $action] = $handler;

        // Экранируем литералы, затем заменяем {param} на именованную группу.
        $pattern = preg_quote($path, '#');
        $pattern = str_replace(['\{', '\}'], ['{', '}'], $pattern);
        $pattern = preg_replace_callback(
            '#\{(\w+)\}#',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $pattern
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $pattern . '/?$#',
            'handler' => [$controller, $action],
            'raw'     => $path,
        ];
    }

    /**
     * Разбирает текущий HTTP-запрос и вызывает обработчик.
     * Пишет JSON-ответ напрямую (Content-Type + http_response_code).
     */
    public function dispatch(): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // REQUEST_URI — «сырой» URI из start-line запроса, включая query string.
        // Отсекаем query через parse_url (аналог HttpContext.Request.Path).
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        // CLI-фолбэк для тестов: php public/index.php GET /api/company/123
        if (PHP_SAPI === 'cli' && isset($argv[1], $argv[2])) {
            $method = strtoupper($argv[1]);
            $path   = $argv[2];
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = array_filter(
                    $matches,
                    static fn (string|int $k): bool => is_string($k),
                    ARRAY_FILTER_USE_KEY
                );

                $result = $this->callHandler($route['handler'], $params);
                echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not Found'], JSON_THROW_ON_ERROR);
    }

    /**
     * Создаёт контроллер (через контейнер, если задан) и вызывает метод,
     * передавая параметры маршрута.
     *
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string>             $params
     */
    private function callHandler(array $handler, array $params): mixed
    {
        [$class, $action] = $handler;
        $controller = $this->container !== null
            ? $this->container->get($class)
            : new $class();

        if (!method_exists($controller, $action)) {
            throw new \RuntimeException(sprintf('Method %s::%s() does not exist', $class, $action));
        }

        // используем оператор распаковки ...
        return $controller->{$action}(...$params);
    }
}
