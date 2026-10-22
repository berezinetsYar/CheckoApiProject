<?php

declare(strict_types=1);

namespace App\Routing;

/**
 * Простой API-key middleware для защиты маршрутов.
 *
 * Для .NET-разработчика: концептуально это аналог AuthorizationMiddleware /
 * политики [Authorize] из ASP.NET Core, но «вручную»: в Vanilla PHP нет
 * встроенного pipeline'а, поэтому middleware — это обычный класс с методом
 * handle(), который Router вызывает перед диспетчеризацией контроллера.
 *
 * Использование:
 *   $router->add('GET', '/api/company/{inn}', [CompanyController::class, 'getInfo'], ['auth' => true]);
 *   либо глобально: новый AuthMiddleware($apiKey, protectedPrefixes: ['/api/'])
 */
final class AuthMiddleware
{
    /**
     * @param string|null $apiKey          ожидаемый ключ (env CHECKO_API_KEY и т.п.); null = защита выключена
     * @param string[]    $protectedPrefixes префиксы путей, требующие аутентификацию (пустой массив = все)
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly array $protectedPrefixes = [],
    ) {
    }

    /**
     * Проверяет запрос. Если маршрут защищён и ключ не совпадает —
     * отправляет 401 JSON и возвращает false (Router должен остановить диспетчеризацию).
     */
    public function handle(string $method, string $path): bool
    {
        // Ключ не настроен — считаем, что auth отключён (dev-режим).
        if ($this->apiKey === null || $this->apiKey === '') {
            return true;
        }

        // OPTIONS (CORS preflight) не требует ключа.
        if ($method === 'OPTIONS') {
            return true;
        }

        if (!$this->isProtected($path)) {
            return true;
        }

        $provided = $this->extractKey();

        // hash_equals — защита от timing-атак (сравнение за константное время),
        // аналог CryptographicOperations.FixedTimeEquals в .NET.
        if ($provided !== null && hash_equals($this->apiKey, $provided)) {
            return true;
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR);

        return false;
    }

    /**
     * Извлекает ключ из заголовка Authorization: Bearer <key>
     * или из query-параметра ?api_key=<key>.
     */
    private function extractKey(): ?string
    {
        // $_SERVER['HTTP_AUTHORIZATION'] заполняется веб-сервером,
        // если клиент прислал заголовок Authorization.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($header) && $header !== '') {
            if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
                return $m[1];
            }
            // Допускаем и «голый» ключ без схемы Bearer.
            return trim($header);
        }

        $query = $_GET['api_key'] ?? null;

        return (is_string($query) && $query !== '') ? $query : null;
    }

    private function isProtected(string $path): bool
    {
        if ($this->protectedPrefixes === []) {
            return true; // по умолчанию защищаем всё
        }

        foreach ($this->protectedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}