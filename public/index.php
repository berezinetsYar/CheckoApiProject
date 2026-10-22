<?php

declare(strict_types=1);

/**
 * Точка входа приложения (front controller). Аналог Program.cs.
 */

use App\Controllers\CompanyController;
use App\Container;
use App\HttpClient\CheckoApiClient;
use App\Routing\AuthMiddleware;
use App\Routing\Router;
use App\Services\CompanyService;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. Конфигурация из окружения (docker-compose задаёт эти переменные)
// ---------------------------------------------------------------------------
$apiKey  = getenv('CHECKO_API_KEY') ?: 'demo-key';
$baseUrl = getenv('CHECKO_BASE_URL') ?: 'https://checko.ru/api/company';
$useMock = filter_var(getenv('CHECKO_USE_MOCK'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

$dbHost = getenv('DB_HOST') ?: 'postgresql';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'app';
$dbUser = getenv('DB_USER') ?: 'app';
$dbPass = getenv('DB_PASS') ?: 'secret';

// Ключ для защиты НАШЕГО API (заголовок X-API-KEY). Пустой = auth выключен.
$appApiKey = getenv('APP_API_KEY') ?: '';

// ---------------------------------------------------------------------------
// 2. Мини-DI контейнер (аналог IServiceCollection в упрощённом виде)
// ---------------------------------------------------------------------------
$container = new Container();

$pdoFactory = static function () use ($dbHost, $dbPort, $dbName, $dbUser, $dbPass): PDO {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);

    try {
        return new PDO($dsn, $dbUser, $dbPass, [
            // Все ошибки БД — исключения (в т.ч. PDOException), а не тихие false.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Настоящие prepared statements на стороне PostgreSQL
            // (без подстановки значений PHP-ом) — защита от SQL-инъекций.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw \App\Exceptions\DatabaseException::connection($e);
    }
};

if (PHP_SAPI === 'cli' && !str_starts_with($argv[0] ?? '', 'php-cli')) {
    // В CLI без БД (тест роутинга) используем SQLite in-memory с той же схемой.
    $pdoFactory = static function (): PDO {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE company_cache (inn TEXT PRIMARY KEY, data TEXT NOT NULL, cached_at TEXT NOT NULL)');

        return $pdo;
    };
}

$container->bind(PDO::class, $pdoFactory);

$container->bind(CheckoApiClient::class, static fn (): CheckoApiClient => new CheckoApiClient(
    apiKey:  $apiKey,
    baseUrl: $baseUrl,
    useMock: $useMock,
));

// Бизнес-сервис и контроллер разрешаются автоматически по type-hint'ам
// конструктора (autowiring через рефлексию в App\Container).
$container->bind(CompanyService::class);
$container->bind(CompanyController::class);

// ---------------------------------------------------------------------------
// 3. Маршрутизация + middleware
// ---------------------------------------------------------------------------
$router = new Router($container);
$auth   = new AuthMiddleware($appApiKey !== '' ? $appApiKey : null, ['/api/']);

// Оборачиваем dispatch: сначала auth, затем маршруты.
$origDispatch = static function () use ($router, $auth): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (PHP_SAPI === 'cli' && isset($GLOBALS['argv'][1], $GLOBALS['argv'][2])) {
        $method = strtoupper($GLOBALS['argv'][1]);
        $path   = $GLOBALS['argv'][2];
    }

    if (!$auth->handle($method, $path)) {
        return; // 401 уже отправлен
    }

    $router->dispatch();
};

$origDispatch();
