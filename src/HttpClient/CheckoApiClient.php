<?php

declare(strict_types=1);

namespace App\HttpClient;

use App\Dto\CompanyDto;
use App\Exceptions\ExternalApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\ClientInterface;
use JsonException;

/**
 * Клиент Checko API.
 *
 * Зависимости — через constructor property promotion (PHP 8):
 * параметры с private readonly одновременно создают свойства и присваиваются.
 * 4-й параметр $httpClient позволяет подставить мок в тестах
 * (Guzzle MockHandler) без изменения кода класса.
 */
final class CheckoApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://checko.ru/api/company',
        private readonly bool $useMock = false,
        private ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Данные компании по ИНН.
     *
     * @return array<string, mixed> нормализованный payload (CompanyDto::toArray())
     *
     * @throws ExternalApiException при ошибках аутентификации/поиска/сети/API
     */
    public function getCompanyByInn(string $inn): array
    {
        return $this->getCompanyDto($inn)->toArray();
    }

    public function getCompanyDto(string $inn): CompanyDto
    {
        if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            throw new ExternalApiException(
                'Invalid INN format: expected 10 or 12 digits',
                ExternalApiException::CODE_INVALID_INPUT,
            );
        }

        if ($this->useMock) {
            return CompanyDto::fromArray($this->mockResponse($inn));
        }

        try {
            $response = $this->client()->request('GET', '/' . $inn, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept'        => 'application/json',
                ],
                'query'   => ['api_key' => $this->apiKey],
                'timeout' => 10,
            ]);

            $body    = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (BadResponseException $e) {
            // HTTP-ошибка от Checko: транслируем статус в доменное исключение.
            $status = $e->getResponse()->getStatusCode();
            $code   = match (true) {
                $status === 401              => ExternalApiException::CODE_UNAUTHORIZED,
                $status === 404              => ExternalApiException::CODE_NOT_FOUND,
                $status >= 500               => ExternalApiException::CODE_SERVER_ERROR,
                default                      => ExternalApiException::CODE_BAD_RESPONSE,
            };

            throw new ExternalApiException(
                sprintf('Checko API responded with HTTP %d', $status),
                $code,
                previous: $e,
            );
        } catch (TransferException $e) {
            // DNS/timeout/connection refused — до ответа дело не дошло.
            throw new ExternalApiException(
                'Checko API is unreachable: ' . $e->getMessage(),
                ExternalApiException::CODE_NETWORK_ERROR,
                previous: $e,
            );
        } catch (JsonException $e) {
            throw new ExternalApiException(
                'Checko API returned invalid JSON',
                ExternalApiException::CODE_BAD_RESPONSE,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ExternalApiException(
                'Unexpected payload shape from Checko API',
                ExternalApiException::CODE_BAD_RESPONSE,
            );
        }

        return CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => $inn,
            'name'     => (string) ($decoded['name'] ?? $decoded['title'] ?? ''),
            'ogrn'     => $decoded['ogrn'] ?? null,
            'address'  => $decoded['address']['value'] ?? ($decoded['address'] ?? null),
            'status'   => $decoded['status'] ?? null,
            'director' => $decoded['director']['name'] ?? ($decoded['director'] ?? null),
            'raw'      => $decoded,
        ]);
    }

    /** Ленивая инициализация: в mock-режиме Guzzle не создаётся вовсе. */
    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
        ]);
    }

    /**
     * Заглушка для разработки/тестов (CHECKO_USE_MOCK=true).
     *
     * @return array<string, mixed>
     */
    private function mockResponse(string $inn): array
    {
        $isIndividualEntrepreneur = strlen($inn) === 12;

        return [
            'source'   => 'mock',
            'inn'      => $inn,
            'name'     => $isIndividualEntrepreneur
                ? 'Иванов Иван Иванович (ИП)'
                : 'ПАО «Заглушка»',
            'ogrn'     => $isIndividualEntrepreneur ? '3' . str_pad(substr($inn, 2), 14, '0') : '1027700132195',
            'address'  => 'г. Москва, ул. Тестовая, д. 1',
            'status'   => 'ACTIVE',
            'director' => $isIndividualEntrepreneur ? null : 'Сидоров С. С.',
            'raw'      => ['mock' => true, 'generated_for' => $inn],
        ];
    }
}
