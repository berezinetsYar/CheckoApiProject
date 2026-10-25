<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use App\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResponseCapture;

/**
 * Фиксированный контроллер-обработчик для проверки маршрутизации.
 * (В тестах не используем реальный CompanyController — он тянет сервисы/БД.)
 */
final class StubController
{
    /** @var array<string, mixed>|null аргументы последнего вызова */
    public static ?array $lastArgs = null;

    public static function reset(): void
    {
        self::$lastArgs = null;
    }

    public function getInfo(string $inn): array
    {
        self::$lastArgs = ['inn' => $inn];

        return ['ok' => true, 'inn' => $inn];
    }

    public function index(): array
    {
        self::$lastArgs = [];

        return ['ok' => true];
    }

    public function create(): array
    {
        self::$lastArgs = [];

        return ['created' => true];
    }
}

final class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StubController::reset();
        // Сбрасываем «суперглобалы» после каждого теста (tearDown ниже).
        unset($GLOBALS['_SERVER']['REQUEST_METHOD'], $GLOBALS['_SERVER']['REQUEST_URI']);
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_SERVER']['REQUEST_METHOD'],
            $GLOBALS['_SERVER']['REQUEST_URI'],
            $GLOBALS['argv']
        );
        parent::tearDown();
    }

    private function setRequest(string $method, string $uri): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
    }

    private function dispatch(Router $router): ResponseCapture
    {
        $capture = new ResponseCapture();
        $capture->run(static fn () => $router->dispatch());

        return $capture;
    }

    #[Test]
    public function registeredRouteIsDispatchedToHandler(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/company/7707083893');
        $capture = $this->dispatch($router);

        $decoded = json_decode($capture->body, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('7707083893', $decoded['inn']);
        $this->assertSame(['inn' => '7707083893'], StubController::$lastArgs);
    }

    #[Test]
    public function unknownRouteReturns404Json(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/unknown/path');
        $capture = $this->dispatch($router);

        $this->assertSame('{"error":"Not Found"}', $capture->body);

        if (ResponseCapture::canPatchHttp()) {
            $this->assertSame(404, $capture->status);
        }
    }

    #[Test]
    public function routeWithoutParamsIsMatched(): void
    {
        $router = new Router();
        $router->add('GET', '/api/ping', [StubController::class, 'index']);

        $this->setRequest('GET', '/api/ping');
        $capture = $this->dispatch($router);

        $this->assertJson($capture->body);
        $this->assertTrue(json_decode($capture->body, true)['ok']);
    }

    #[Test]
    public function routeParametersAreUnpackedAsNamedArguments(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/company/8601023568');
        $this->dispatch($router);

        // Если бы распаковка ...$params не работала, мы получили бы
        // ArgumentCountError/TypeError — тест упал бы до ассертов.
        $this->assertSame(['inn' => '8601023568'], StubController::$lastArgs);
    }

    /**
     * @return array<string, array{string, string}> [method, uri]
     */
    public static function methodMismatchProvider(): array
    {
        return [
            'POST instead of GET'  => ['POST', '/api/company/7707083893'],
            'PUT instead of GET'   => ['PUT', '/api/company/7707083893'],
            'DELETE instead of GET'=> ['DELETE', '/api/company/7707083893'],
        ];
    }

    #[Test]
    #[DataProvider('methodMismatchProvider')]
    public function differentHttpMethodDoesNotMatchRoute(string $method, string $uri): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest($method, $uri);
        $capture = $this->dispatch($router);

        $this->assertSame('{"error":"Not Found"}', $capture->body);
        $this->assertNull(StubController::$lastArgs, 'Обработчик не должен вызываться для другого метода');
    }

    #[Test]
    public function postRouteMatchesOnlyPostRequests(): void
    {
        $router = new Router();
        $router->add('POST', '/api/company', [StubController::class, 'create']);

        $this->setRequest('POST', '/api/company');
        $capture = $this->dispatch($router);
        $this->assertStringContainsString('"created":true', $capture->body);

        StubController::reset();
        $this->setRequest('GET', '/api/company');
        $capture = $this->dispatch($router);
        $this->assertSame('{"error":"Not Found"}', $capture->body);
        $this->assertNull(StubController::$lastArgs);
    }

    #[Test]
    public function methodMatchingIsCaseInsensitive(): void
    {
        $router = new Router();
        $router->add('get', '/api/ping', [StubController::class, 'index']);

        $this->setRequest('GET', '/api/ping');
        $capture = $this->dispatch($router);

        $this->assertStringContainsString('"ok":true', $capture->body);
    }

    /**
     * @return array<string, array{string}> вариант URI с нормализуемыми слешами
     */
    public static function trailingSlashProvider(): array
    {
        return [
            'без слеша'      => ['/api/company/7707083893'],
            'конечный слеш'  => ['/api/company/7707083893/'],
        ];
    }

    #[Test]
    #[DataProvider('trailingSlashProvider')]
    public function trailingSlashIsNormalized(string $uri): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', $uri);
        $capture = $this->dispatch($router);

        $this->assertStringContainsString('"ok":true', $capture->body);
        $this->assertSame(['inn' => '7707083893'], StubController::$lastArgs);
    }

    #[Test]
    public function queryStringIsStrippedBeforeMatching(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/company/7707083893?foo=bar&baz=1');
        $capture = $this->dispatch($router);

        $this->assertStringContainsString('"ok":true', $capture->body);
        $this->assertSame(['inn' => '7707083893'], StubController::$lastArgs);
    }

    #[Test]
    public function urlEncodedParameterIsDecoded(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/company/77070838%393');
        $this->dispatch($router);

        $this->assertSame(['inn' => '7707083893'], StubController::$lastArgs);
    }

    #[Test]
    public function parameterDoesNotCrossPathSegments(): void
    {
        $router = new Router();
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/company/7707083893/extra');
        $capture = $this->dispatch($router);

        $this->assertSame('{"error":"Not Found"}', $capture->body);
    }

    #[Test]
    public function multipleRoutesResolveIndependently(): void
    {
        $router = new Router();
        $router->add('GET', '/api/ping', [StubController::class, 'index']);
        $router->add('GET', '/api/company/{inn}', [StubController::class, 'getInfo']);

        $this->setRequest('GET', '/api/ping');
        $this->dispatch($router);
        $this->assertSame([], StubController::$lastArgs);

        StubController::reset();
        $this->setRequest('GET', '/api/company/1234567890');
        $this->dispatch($router);
        $this->assertSame(['inn' => '1234567890'], StubController::$lastArgs);
    }
}
