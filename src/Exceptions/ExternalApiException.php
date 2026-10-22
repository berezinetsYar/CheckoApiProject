<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Исключение для ошибок внешних HTTP API (Checko и др.).
 *
 * Аналог собственного исключения уровня приложения в .NET
 * (например, ExternalApiException : Exception), которое выбрасывается
 * вместо "сырых" HttpClientException/HttpRequestException.
 */
final class ExternalApiException extends \RuntimeException
{
    /** Коды ошибок для маппинга в HTTP-ответ контроллера. */
    public const CODE_UNAUTHORIZED  = 401; // некорректный/отсутствующий API-ключ
    public const CODE_NOT_FOUND     = 404; // компания с таким ИНН не найдена
    public const CODE_SERVER_ERROR  = 500; // 5xx на стороне внешнего сервиса
    public const CODE_BAD_RESPONSE  = 502; // внешний сервис вернул невалидный JSON/HTML
    public const CODE_NETWORK_ERROR = 503; // соединение недоступно/таймаут

    private int $statusCode;

    public function __construct(
        string $message,
        int $statusCode = self::CODE_SERVER_ERROR,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    /** HTTP-статус внешнего API (или псевдо-статус сетевой ошибки). */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Статус для локального HTTP-ответа:
     * 401/404 транслируем как есть, всё остальное — 502 Bad Gateway.
     */
    public function getClientStatus(): int
    {
        return match ($this->statusCode) {
            self::CODE_UNAUTHORIZED, self::CODE_NOT_FOUND => $this->statusCode,
            default => self::CODE_BAD_RESPONSE,
        };
    }
}
