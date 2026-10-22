<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\ExternalApiException;
use App\Services\CompanyService;

/**
 * Контроллер компаний.
 *
 * Методы принимают ассоциативный массив параметров маршрута
 * и возвращают массив — роутер сериализует его в JSON.
 * Ошибки внешнего API ловятся здесь и превращаются в корректные HTTP-статусы
 * (аналог ExceptionFilterMiddleware / ProblemDetails в ASP.NET Core).
 */
final class CompanyController
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {
    }

    /**
     * GET /api/company/{inn}
     *
     * @param array<string, string> $params Например: ['inn' => '7707083893']
     * @return array<string, mixed>
     */
    public function getInfo(array $params): array
    {
        $inn = $params['inn'] ?? '';

        if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            http_response_code(422);

            return ['error' => 'Invalid INN format', 'inn' => $inn];
        }

        try {
            return $this->companyService->getByInn($inn)->toArray();
        } catch (ExternalApiException $e) {
            http_response_code($e->getClientStatus());

            return ['error' => $e->getMessage()];
        }
    }
}
