<?php

declare(strict_types=1);

/**
 * Точка входа приложения (front controller).
 *
 * Аналог Program.cs в ASP.NET Core: здесь настраивается "конвейер" —
 * контейнер зависимостей, роутер и регистрация маршрутов.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Controllers\CompanyController;
use App\Container;
use App\HttpClient\CheckoApiClient;
use App\Routing\Router;
use App\Services\CompanyService;

// Простой DI-контейнер (аналог builder.Services.AddSingleton + BuildServiceProvider()).
$container = new Container();

// Конфигурация из переменных окружения (аналог IConfiguration / appsettings.json).
$env = static fn (string $key, string $default = ''): string => (string) (getenv($key) ?: $default);

// Регистрация зависимостей: CheckoApiClient <- CompanyService <- CompanyController.
// Ключ API и режим mock берутся из окружения (см. docker-compose.yml / .env).
$container->bind(CheckoApiClient::class, static fn (): CheckoApiClient => new CheckoApiClient(
    apiKey:  $env('CHECKO_API_KEY', 'demo-key'),
    baseUrl: $env('CHECKO_BASE_URL', 'https://checko.ru/api/company'),
    useMock: filter_var($env('CHECKO_USE_MOCK', 'true'), FILTER_VALIDATE_BOOL),
));

$container->bind(CompanyService::class, static fn (Container $c): CompanyService =>
    new CompanyService($c->get(CheckoApiClient::class)));

// Композиция корневых зависимостей выполняется явно здесь, а не внутри контроллера.
$router = new Router($container);

$router->add('GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']);

// Диспетчеризация: разбор $_SERVER['REQUEST_URI'] / REQUEST_METHOD,
// поиск маршрута, вызов контроллера, JSON-ответ (или 404 {"error":"Not Found"}).
$router->dispatch();
