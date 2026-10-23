<?php

declare(strict_types=1);

namespace App\Exceptions;

use PDOException;

/**
 * Исключение уровня БД. Оборачивает сырой PDOException, чтобы наружу
 * (в HTTP-ответ) не утекли SQL-детали; оригинал доступен через getPrevious().
 */
final class DatabaseException extends \RuntimeException
{
    public const CODE_CONNECTION = 5001;
    public const CODE_QUERY      = 5002;
    public const CODE_JSON       = 5003;

    private int $dbCode = 0;

    public static function connection(PDOException $previous): self
    {
        $e              = new self('Database connection failed', self::CODE_CONNECTION, $previous);
        $e->dbCode      = (int) ($previous->errorInfo[1] ?? 0);

        return $e;
    }

    public static function query(PDOException $previous): self
    {
        $e              = new self('Database query failed', self::CODE_QUERY, $previous);
        $e->dbCode      = (int) ($previous->errorInfo[1] ?? 0);

        return $e;
    }

    public static function json(\JsonException $previous): self
    {
        return new self('Cached payload is not valid JSON', self::CODE_JSON, $previous);
    }

    /** Нативный код ошибки драйвера (pgsql), если доступен. */
    public function getDbCode(): int
    {
        return $this->dbCode;
    }
}
