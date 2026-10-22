<?php

declare(strict_types=1);

/**
 * Точка входа приложения (front controller).
 *
 * Для .NET-разработчика: это аналог Program.cs — здесь собираются
 * «сервисы» (наш мини-контейнер), настраивается маршрутизация и
 * запускается обработка запроса. В PHP весь этот код исполняется
 * заново на каждый HTTP-запрос (нет долгоживущего процесса, как Kestrel).
 */

use App\Controllers\CompanyController;
use App\Container;
use App\HttpClient\CheckoApiClient;
use App\Routing\Router;
use App\Services\CompanyService;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. Конфигурация из окружения (docker-compose задаёт эти переменные)
// ---------------------------------------------------------------------------
$apiKey       = getenv('CHECKO_API_KEY') ?: 'demo-key';
$baseUrl      = getenv('CHECKO_BASE_URL') ?: 'https://checko.ru/api/company';
$useMock      = filter_var(getenv('CHECKO_USE_MOCK'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_DEFAULT) ?? true;

$dbHost       = getenv('DB_HOST') ?: 'postgresql';
$dbPort       = getenv('DB_PORT') ?: '5432';
$dbName       = getenv('DB_NAME') ?: 'app';
$dbUser       = getenv('DB_USER') ?: 'app';
$dbPass       = getenv('DB_PASS') ?: 'secret';

// ---------------------------------------------------------------------------
// 2. Мини-DI контейнер (аналог IServiceCollection в упрощённом виде)
// ---------------------------------------------------------------------------
$container = new Container();

// PDO — singleton: одно соединение на время обработки запроса.
$container->bind(PDO::class, function () use ($dbHost, $dbPort, $dbName, $dbUser, $dbPass): PDO {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);

    try {
        return new PDO($dsn, $dbUser, $dbPass, [
            // Все ошибки БД — исключения (в т.ч. PDOException), а не тихие false.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            // Ассоциативные массивы по умолчанию для fetch().
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Настоящие prepared statements на стороне PostgreSQL
            // (без подстановки значений PHP-ом) — защита от SQL-инъекций.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw \App\Exceptions\DatabaseException::connection($e);
    }
});

// Внешний API-клиент Checko.
$container->bind(CheckoApiClient::class, fn (): CheckoApiClient => new CheckoApiClient(
    apiKey:  $apiKey,
    baseUrl: $baseUrl,
    useMock: $useMock,
));

// Бизнес-сервис и контроллер разрешаются автоматически по type-hint'ам
// конструктора (autowiring через рефлексию в App\Container).
$container->bind(CompanyService::class);
$container->bind(CompanyController::class);

// ---------------------------------------------------------------------------
// 3. Маршрутизация
// ---------------------------------------------------------------------------
$router = new Router($container);

$router->add('GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']);

// ---------------------------------------------------------------------------
// 4. Диспетчеризация
// ---------------------------------------------------------------------------
$router->dispatch();