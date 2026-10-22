<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\CompanyDto;
use App\Exceptions\ExternalApiException;
use App\HttpClient\CheckoApiClient;

/**
 * Сервис работы с компаниями.
 *
 * Инкапсулирует вызовы внешнего API (Checko) за спиной контроллера —
 * аналог слоя ICompanyService в ASP.NET Core, который получает
 * HttpClient через Typed Client / IHttpClientFactory.
 */
final class CompanyService
{
    public function __construct(
        private readonly CheckoApiClient $checko,
    ) {
    }

    /**
     * @throws ExternalApiException если внешний API недоступен/вернул ошибку
     */
    public function getByInn(string $inn): CompanyDto
    {
        return $this->checko->getCompanyDto($inn);
    }
}
