<?php
declare(strict_types=1);

// Включаем отображение ошибок для отладки
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use App\Controllers\CompanyController;
use App\Container;
use App\HttpClient\CheckoApiClient;
use App\Routing\AuthMiddleware;
use App\Routing\Router;
use App\Services\CompanyService;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. ОБСЛУЖИВАНИЕ СТАТИЧЕСКИХ ФАЙЛОВ (для Swagger и фронтенда)
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli-server') {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    
    // Если запрашивается реальный файл (swagger.html, openapi.json, client.html), 
    // возвращаем false, чтобы встроенный сервер PHP отдал его нативно.
    if (is_file($file)) {
        return false; 
    }
}

// ---------------------------------------------------------------------------
// 2. Конфигурация из окружения
// ---------------------------------------------------------------------------
$apiKey  = getenv('CHECKO_API_KEY') ?: 'demo-key';
$baseUrl = getenv('CHECKO_BASE_URL') ?: 'https://checko.ru/api/company';
$useMock = filter_var(getenv('CHECKO_USE_MOCK'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

$dbHost = getenv('DB_HOST') ?: 'postgresql';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'app';
$dbUser = getenv('DB_USER') ?: 'app';
$dbPass = getenv('DB_PASS') ?: 'secret';

$appApiKey = getenv('APP_API_KEY') ?: '';

// ---------------------------------------------------------------------------
// 3. Мини-DI контейнер
// ---------------------------------------------------------------------------
$container = new Container();

$pdoFactory = static function () use ($dbHost, $dbPort, $dbName, $dbUser, $dbPass): PDO {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $dbHost, $dbPort, $dbName);
    return new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
};

$container->bind(PDO::class, $pdoFactory);
$container->bind(CheckoApiClient::class, static fn (): CheckoApiClient => new CheckoApiClient(
    apiKey:  $apiKey,
    baseUrl: $baseUrl,
    useMock: $useMock,
));
$container->bind(CompanyService::class);
$container->bind(CompanyController::class);

// ---------------------------------------------------------------------------
// 4. Маршрутизация + middleware
// ---------------------------------------------------------------------------
$router = new Router($container);
$router->add('GET', '/api/company/{inn}', [CompanyController::class, 'getInfo']);

$auth = new AuthMiddleware($appApiKey !== '' ? $appApiKey : null, ['/api/']);

$origDispatch = static function () use ($router, $auth): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (PHP_SAPI === 'cli' && isset($GLOBALS['argv'][1], $GLOBALS['argv'][2])) {
        $method = strtoupper($GLOBALS['argv'][1]);
        $path   = $GLOBALS['argv'][2];
    }

    if (!$auth->handle($method, $path)) {
        return;
    }

    $router->dispatch();
};

$origDispatch();