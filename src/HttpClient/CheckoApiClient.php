<?php

declare(strict_types=1);

namespace App\HttpClient;

use App\Dto\CompanyDto;
use App\Exceptions\ExternalApiException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;

/**
 * Клиент Checko API (https://checko.ru) — поиск компании по ИНН.
 *
 * Зависимости внедряются через конструктор (constructor property promotion):
 *   $client = new CheckoApiClient($apiKey, $baseUrl, useMock: true);
 *
 * В продакшене экземпляры создаёт DI-контейнер (см. public/index.php),
 * чтобы контроллеры/сервисы не знали, откуда берутся ключи.
 */
final class CheckoApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://checko.ru/api/company',
        private readonly bool $useMock = false,
        /** Инъектируется только в тестах (аналог подделки HttpMessageHandler в .NET). */
        private ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * Возвращает данные компании по ИНН.
     *
     * @param string $inn 10 (юрлицо) или 12 (ИП) цифр
     * @return array<string, mixed> Нормализованный массив данных компании
     *
     * @throws ExternalApiException при 401 / 404 / 5xx / сетевых ошибках
     */
    public function getCompanyByInn(string $inn): array
    {
        return $this->getCompanyDto($inn)->toArray();
    }

    /**
     * То же, что getCompanyByInn(), но типизированно: DTO вместо «сырого» массива.
     *
     * @throws ExternalApiException
     */
    public function getCompanyDto(string $inn): CompanyDto
    {
        if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            throw new ExternalApiException(
                "Invalid INN format: \"{$inn}\" (expected 10 or 12 digits)",
                ExternalApiException::CODE_NOT_FOUND,
            );
        }

        if ($this->useMock) {
            return CompanyDto::fromArray($this->mockResponse($inn));
        }

        try {
            $response = $this->client()->request('GET', ltrim($inn, '/'), [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'User-Agent'    => 'vanilla-php-app/1.0',
                ],
                'query'   => ['api_key' => $this->apiKey],
                'timeout' => 10.0,
            ]);

            $body    = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (BadResponseException $e) {
            // Сервер ответил HTTP-кодом >= 400.
            $status = $e->getResponse()->getStatusCode();

            throw new ExternalApiException(
                match ($status) {
                    401      => 'Checko API: unauthorized (invalid or missing API key)',
                    404      => "Checko API: company with INN {$inn} not found",
                    default  => "Checko API: server error (HTTP {$status})",
                },
                match (true) {
                    $status === 401 => ExternalApiException::CODE_UNAUTHORIZED,
                    $status === 404 => ExternalApiException::CODE_NOT_FOUND,
                    $status >= 500  => ExternalApiException::CODE_SERVER_ERROR,
                    default         => ExternalApiException::CODE_BAD_RESPONSE,
                },
                previous: $e,
            );
        } catch (TransferException $e) {
            // Соединение не установлено / DNS / таймаут (без HTTP-ответа).
            throw new ExternalApiException(
                'Checko API is unavailable: ' . $e->getMessage(),
                ExternalApiException::CODE_NETWORK_ERROR,
                previous: $e,
            );
        } catch (\JsonException $e) {
            throw new ExternalApiException(
                'Checko API returned malformed JSON response',
                ExternalApiException::CODE_BAD_RESPONSE,
                previous: $e,
            );
        }

        if (!is_array($decoded)) {
            throw new ExternalApiException(
                'Checko API returned unexpected payload structure',
                ExternalApiException::CODE_BAD_RESPONSE,
            );
        }

        return CompanyDto::fromArray($this->normalize($decoded, $inn));
    }

    /** Ленивая инициализация Guzzle (чтобы mock-режим не требовал curl). */
    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
        ]);
    }

    /**
     * Приводит сырой ответ Checko к стабильной внутренней форме.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw, string $inn): array
    {
        $address = $raw['address'] ?? '';
        if (is_array($address)) {
            $address = (string) ($address['unrestricted_value'] ?? '');
        }

        return [
            'source'   => 'checko',
            'inn'      => (string) ($raw['inn'] ?? $inn),
            'name'     => (string) ($raw['name'] ?? $raw['title'] ?? ''),
            'ogrn'     => isset($raw['ogrn']) ? (string) $raw['ogrn'] : null,
            'address'  => (string) $address,
            'status'   => (string) ($raw['status'] ?? 'unknown'),
            'director' => isset($raw['manager_name']) ? (string) $raw['manager_name'] : null,
            'raw'      => $raw,
        ];
    }

    /**
     * Заготовленные тестовые данные (используются когда $useMock === true).
     *
     * @return array<string, mixed>
     */
    private function mockResponse(string $inn): array
    {
        $isIp = strlen($inn) === 12;

        return [
            'source'   => 'mock',
            'inn'      => $inn,
            'name'     => $isIp
                ? sprintf('ИП Моков И.И. (%s)', $inn)
                : sprintf('ООО «Чекко-Моки» (%s)', $inn),
            'ogrn'     => $isIp ? null : '10277' . substr(str_pad($inn, 10, '0'), -7),
            'address'  => 'г. Москва, ул. Тестовая, д. 1',
            'status'   => 'active',
            'director' => $isIp ? null : 'Иванов Иван Иванович',
            'raw'      => ['mock' => true, 'inn' => $inn],
        ];
    }
}
