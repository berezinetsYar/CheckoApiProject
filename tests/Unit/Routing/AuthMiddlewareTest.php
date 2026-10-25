<?php

declare(strict_types=1);

namespace Tests\Unit\Routing;

use App\Routing\AuthMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\ResponseCapture;

final class AuthMiddlewareTest extends TestCase
{
    private const API_KEY = 'super-secret-key-123';

    protected function setUp(): void
    {
        parent::setUp();
        unset(
            $_SERVER['HTTP_X_API_KEY'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_GET['api_key']
        );
    }

    protected function tearDown(): void
    {
        unset(
            $_SERVER['HTTP_X_API_KEY'],
            $_SERVER['HTTP_AUTHORIZATION'],
            $_GET['api_key']
        );
        parent::tearDown();
    }

    private function handle(AuthMiddleware $middleware, string $method = 'GET', string $path = '/api/company/7707083893'): ResponseCapture
    {
        $capture = new ResponseCapture();
        $capture->run(static fn (): bool => $middleware->handle($method, $path));

        return $capture;
    }

    #[Test]
    public function validApiKeyHeaderAllowsRequest(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = self::API_KEY;

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('', $capture->body, 'Успешная авторизация ничего не выводит');
        $this->assertNull($capture->status);
    }

    #[Test]
    public function wrongApiKeyIsRejectedWith401(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = 'wrong-key';

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);

        if (ResponseCapture::canPatchHttp()) {
            $this->assertSame(401, $capture->status);
        }
    }

    #[Test]
    public function missingApiKeyHeaderIsRejected(): void
    {
        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);
    }

    #[Test]
    public function emptyApiKeyHeaderIsRejected(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = '';

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);
    }

    #[Test]
    public function unprotectedPathPassesWithoutKey(): void
    {
        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware, 'GET', '/swagger.html');

        $this->assertSame('', $capture->body);
        $this->assertNull($capture->status);
    }

    #[Test]
    public function optionsPreflightPassesWithoutKey(): void
    {
        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware, 'OPTIONS', '/api/company/7707083893');

        $this->assertSame('', $capture->body);
    }

    #[Test]
    #[DataProvider('emptyKeyProvider')]
    public function authorizationDisabledWhenConfiguredKeyIsEmpty(?string $configuredKey): void
    {
        // Даже без заголовка запрос проходит — dev-режим.
        $middleware = new AuthMiddleware($configuredKey, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('', $capture->body);
        $this->assertNull($capture->status);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function emptyKeyProvider(): array
    {
        return [
            'null (не настроен)' => [null],
            'пустая строка'      => [''],
        ];
    }

    #[Test]
    public function emptyPrefixesProtectsAllPaths(): void
    {
        $middleware = new AuthMiddleware(self::API_KEY, []);
        $capture = $this->handle($middleware, 'GET', '/any/path');

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);
    }

    #[Test]
    public function bearerAuthorizationHeaderIsSupported(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::API_KEY;

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('', $capture->body);
    }

    #[Test]
    public function queryParameterApiKeyIsSupported(): void
    {
        $_GET['api_key'] = self::API_KEY;

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('', $capture->body);
    }

    #[Test]
    public function headerTakesPrecedenceOverQueryAndIsTrimmed(): void
    {
        $_SERVER['HTTP_X_API_KEY'] = '  ' . self::API_KEY . '  ';
        $_GET['api_key'] = 'garbage';

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('', $capture->body, 'Заголовок с пробелами по краям должен проходить после trim()');
    }

    #[Test]
    public function keyOfSameLengthButDifferentContentIsRejected(): void
    {
        // hash_equals сравнивает посимвольно за константное время:
        // даже минимальное отличие при совпадающей длине -> отказ.
        $_SERVER['HTTP_X_API_KEY'] = substr(str_pad(self::API_KEY, strlen(self::API_KEY), 'x'), 0, -1) . '!';
        $this->assertSame(strlen(self::API_KEY), strlen((string) $_SERVER['HTTP_X_API_KEY']));

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);
    }

    #[Test]
    public function shorterKeyWithMatchingPrefixIsRejected(): void
    {
        // Классический случай для timing-атаки: префикс совпадает, длина — нет.
        $_SERVER['HTTP_X_API_KEY'] = substr(self::API_KEY, 0, 5);

        $middleware = new AuthMiddleware(self::API_KEY, ['/api/']);
        $capture = $this->handle($middleware);

        $this->assertSame('{"error":"Unauthorized"}', $capture->body);
    }
}
