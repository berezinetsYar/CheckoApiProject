<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Сервис работы с компаниями (заглушка).
 * Позже инкапсулирует HTTP-вызовы к внешнему API (через App\HttpClient\ApiClient).
 */
final class CompanyService
{
    /**
     * @return array{inn: string, name: string}
     */
    public function getByInn(string $inn): array
    {
        return [
            'inn'  => $inn,
            'name' => 'ПАО «Заглушка»',
        ];
    }
}
