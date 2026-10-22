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
use App\Routing\Router;

// Простой DI-контейнер (опционально; без него Router делает new $class()).
// Аналог builder.Services.AddSingleton/AddTransient + BuildServiceProvider().
$container = new Container();

// Инициализация роутера и регистрация маршрутов.
// Аналог app.MapControllers() + атрибутов [Route(...)] в ASP.NET Core,
// только маршруты описаны декларативным массивом.
$router = new Router($container);

$router->add('GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']);

// Диспетчеризация: разбор $_SERVER['REQUEST_URI'] / REQUEST_METHOD,
// поиск маршрута, вызов контроллера, JSON-ответ (или 404 {"error":"Not Found"}).
$router->dispatch();
