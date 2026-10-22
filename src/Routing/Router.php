<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Простой маршрутизатор (аналог минимального MVC Routing в ASP.NET Core).
 *
 * Маршрут: ['GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']]
 * Плейсхолдеры {name} компилируются в именованные группы регулярки.
 */
final class Router
{
    /**
     * @var list<array{method: string, regex: string, handler: array{0: class-string, 1: string}}>
     */
    private array $routes = [];

    public function __construct(
        private ?\App\Container $container = null,
    ) {
    }

    /**
     * Регистрирует маршрут.
     *
     * @param string                $method  GET|POST|PUT|DELETE...
     * @param string                $path    Шаблон пути, например /api/company/{inn}
     * @param array{0: class-string, 1: string} $handler [Контроллер::class, 'метод']
     */
    public function add(string $method, string $path, array $handler): void
    {
        // {inn} -> (?P<inn>[^/]+)
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . rtrim($regex, '/') . '/?$#',
            'handler' => $handler,
        ];
    }

    /**
     * Разбирает $_SERVER и вызывает контроллер; отдаёт JSON.
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        // Отрезаем query string: /api/company/123?x=1 -> /api/company/123
        $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = '/' . trim($path, '/');

        header('Content-Type: application/json; charset=UTF-8');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                $params = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $params[$key] = $value;
                    }
                }

                [$class, $action] = $route['handler'];

                // Контроллер создаём сами: либо через контейнер, либо new $class().
                // В ASP.NET Core это делает встроенный ControllerActivator.
                $controller = $this->container !== null
                    ? $this->container->get($class)
                    : new $class();

                echo json_encode($controller->$action($params), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
