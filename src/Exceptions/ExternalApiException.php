<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Ошибки внешнего API (Checko) и связанные с ним сетевые проблемы.
 *
 * Коды соответствуют HTTP-статусам источника; getClientStatus() решает,
 * какой статус показать нашему клиенту:
 *  - 401/404 транслируем как есть (это осмысленные ответы для потребителя);
 *  - всё остальное (5xx, таймауты, битый JSON) -> 502 Bad Gateway.
 */
final class ExternalApiException extends \RuntimeException
{
    public const CODE_UNAUTHORIZED   = 401;
    public const CODE_NOT_FOUND      = 404;
    public const CODE_SERVER_ERROR   = 500;
    public const CODE_BAD_RESPONSE   = 501;
    public const CODE_NETWORK_ERROR  = 599;
    public const CODE_INVALID_INPUT  = 422;

    public function __construct(
        string $message,
        int $code = self::CODE_BAD_RESPONSE,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** HTTP-статус источника ошибки (401/404/500...). */
    public function getStatusCode(): int
    {
        return $this->code;
    }

    /** Статус, который отдаём нашему HTTP-клиенту. */
    public function getClientStatus(): int
    {
        return match (true) {
            $this->code === self::CODE_UNAUTHORIZED  => 401,
            $this->code === self::CODE_NOT_FOUND     => 404,
            $this->code === self::CODE_INVALID_INPUT => 422,
            default                                  => 502,
        };
    }
}
