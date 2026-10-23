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
    public const CODE_UNAUTHORIZED  = 401; // некорректный/отсутствующий API-ключ
    public const CODE_NOT_FOUND     = 404; // компания с таким ИНН не найдена
    public const CODE_SERVER_ERROR  = 500; // 5xx на стороне внешнего сервиса
    public const CODE_BAD_RESPONSE  = 502; // внешний сервис вернул невалидный JSON/HTML
    public const CODE_NETWORK_ERROR = 503; // соединение недоступно/таймаут

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
