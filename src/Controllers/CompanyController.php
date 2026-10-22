<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CompanyService;

/**
 * Контроллер компаний (пока заглушка).
 *
 * Методы принимают ассоциативный массив параметров маршрута
 * и возвращают массив — роутер сериализует его в JSON.
 */
final class CompanyController
{
    public function __construct(
        private readonly CompanyService $companyService = new CompanyService(),
    ) {
    }

    /**
     * GET /api/company/{inn}
     *
     * @param array<string, string> $params Например: ['inn' => '7707083893']
     */
    public function getInfo(array $params): array
    {
        $inn = $params['inn'] ?? '';

        if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            http_response_code(422);

            return ['error' => 'Invalid INN format', 'inn' => $inn];
        }

        // Заглушка: позже здесь будет реальный вызов внешнего API
        // через $this->companyService->getByInn($inn)
        return [
            'inn'    => $inn,
            'name'   => 'ПАО «Заглушка»',
            'source' => 'stub',
        ];
    }
}
