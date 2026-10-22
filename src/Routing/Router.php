<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Простой роутер (по аналогии с endpoint routing в ASP.NET Core, но вручную).
 *
 * Маршрут описывается массивом:
 *   ['GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']]
 *
 * Параметры из URL ({inn}) извлекаются из $_SERVER['REQUEST_URI'] и передаются
 * в метод контроллера ассоциативным массивом: ['inn' => '7707083893'].
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}}> */
    private array $routes = [];

    public function __construct(
        private readonly ?\App\Container $container = null,
    ) {
    }

    /**
     * Регистрирует маршрут.
     *
     * @param string                          $httpMethod GET|POST|PUT|DELETE|...
     * @param string                          $path       Путь с плейсхолдерами вида {name}
     * @param array{0: class-string, 1: string} $handler  [Класс контроллера, имя метода]
     */
    public function add(string $httpMethod, string $path, array $handler): void
    {
        // Шаблон -> регулярка: /api/company/{inn} -> #^/api/company/(?P<inn>[^/]+)$#
        // Именованные группы позволяют по ключу достать значение параметра.
        $regex = preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            '(?P<$1>[^/]+)',
            $path
        );

        $this->routes[] = [
            'method'  => strtoupper($httpMethod),
            'pattern' => $path,
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    /**
     * Диспетчеризация текущего HTTP-запроса.
     * Берёт METHOD и PATH из суперглобального $_SERVER, находит первый
     * подходящий маршрут и вызывает обработчик. Если совпадений нет — 404 JSON.
     */
    public function dispatch(): void
    {
        // В CLI-режиме ($_SERVER['REQUEST_METHOD'] не задан) берём аргументы:
        //   php public/index.php GET /api/company/123   — удобно для тестов без веб-сервера
        if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
            global $argv;
            $_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
            $_SERVER['REQUEST_URI']    = $argv[2] ?? '/';
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // REQUEST_URI содержит путь + query string ("/api/company/123?x=1"),
        // поэтому берём только path через parse_url.
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);

        // Нормализация: убираем хвостовой слэш (кроме корня), как в большинстве фреймворков
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $uri, $matches)) {
                // Оставляем только именованные группы: ['inn' => '7707083893']
                $params = array_filter(
                    $matches,
                    static fn (string|int $key): bool => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                [$class, $action] = $route['handler'];

                // В Vanilla PHP нет встроенного DI (как в ASP.NET Core).
                // По умолчанию — простой "new $class()" (аналог new без контейнера);
                // если передан микросервисный контейнер — разрешаем зависимости
                // по type-hint'ам конструктора (аналог GetRequiredService<T>).
                $controller = $this->container !== null
                    ? $this->container->get($class)
                    : new $class();

                $result = $controller->$action($params);

                $this->respond($result);
                return;
            }
        }

        // Маршрут не найден — 404 в JSON
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Not Found'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Приведение результата обработчика к HTTP-ответу.
     */
    private function respond(mixed $result): void
    {
        if (is_array($result)) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return;
        }

        if (is_string($result)) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $result;
            return;
        }

        // null / scalar без обработки — пустой успешный ответ
        http_response_code(204);
    }
}
