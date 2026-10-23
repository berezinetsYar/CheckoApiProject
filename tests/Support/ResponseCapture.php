<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Хелпер для юнит-тестов, вызывающих код, который использует «глобальные»
 * HTTP-функции PHP: header(), http_response_code(), echo.
 *
 * В CLI-режиме PHPUnit эти функции — no-op (http_response_code() возвращает
 * переданный код, но не хранит его), поэтому мы временно подменяем их через
 * расширение uopz либо runkit7, если оно установлено. Если подмена недоступна,
 * перехватывается только вывод (echo/print) — статус в этом случае просто
 * не проверяется (тесты помечают такие проверки как skip).
 */
final class ResponseCapture
{
    /** @var int|null перехваченный http_response_code() */
    public ?int $status = null;

    /** @var string[] перехваченные заголовки */
    public array $headers = [];

    /** @var string перехваченное тело ответа (echo/print) */
    public string $body = '';

    /** Доступна ли полная подмена HTTP-функций (uopz/runkit7). */
    public static function canPatchHttp(): bool
    {
        return function_exists('uopz_set_return') || function_exists('runkit7_function_override');
    }

    public function run(callable $callback): void
    {
        ob_start();

        try {
            $patched = $this->patchHttpFunctions();

            $callback();

            if ($patched) {
                $this->restoreHttpFunctions();
            }
        } finally {
            $this->body .= (string) ob_get_clean();
        }
    }

    private function patchHttpFunctions(): bool
    {
        if (function_exists('uopz_set_return')) {
            uopz_set_return('http_response_code', function (?int $code = null): int|false {
                if ($code !== null) {
                    $this->status = $code;

                    return $code;
                }

                return $this->status ?? 200;
            }, true);
            uopz_set_return('header', function (string $header): void {
                $this->headers[] = $header;
            }, true);

            return true;
        }

        if (function_exists('runkit7_function_override')) {
            $capture = $this;
            runkit7_function_override('http_response_code', function (?int $code = null) use ($capture): int|false {
                if ($code !== null) {
                    $capture->status = $code;

                    return $code;
                }

                return $capture->status ?? 200;
            });
            runkit7_function_override('header', function (string $header) use ($capture): void {
                $capture->headers[] = $header;
            });

            return true;
        }

        return false;
    }

    private function restoreHttpFunctions(): void
    {
        if (function_exists('uopz_unset_return')) {
            uopz_unset_return('http_response_code');
            uopz_unset_return('header');
        } elseif (function_exists('runkit7_function_restore')) {
            runkit7_function_restore('http_response_code');
            runkit7_function_restore('header');
        }
    }
}
