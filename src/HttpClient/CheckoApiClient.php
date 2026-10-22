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

final class CheckoApiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.checko.ru/v2',
        private readonly bool $useMock = false,
        private ?ClientInterface $httpClient = null,
    ) {
    }

    public function getCompanyByInn(string $inn): array
    {
        return $this->getCompanyDto($inn)->toArray();
    }

    public function getCompanyDto(string $inn): CompanyDto
    {
        // Очистка от скрытых пробелов и переносов строк (частая проблема из .env)
        $cleanInn = trim($inn);
        $cleanApiKey = trim($this->apiKey);

        if (!preg_match('/^\d{10}(\d{2})?$/', $cleanInn)) {
            throw new ExternalApiException(
                'Invalid INN format: expected 10 or 12 digits',
                ExternalApiException::CODE_INVALID_INPUT,
            );
        }

        if ($this->useMock) {
            return CompanyDto::fromArray($this->mockResponse($cleanInn));
        }

        try {
            // Добавлены заголовки, чтобы WAF не блокировал запрос как бота
            $response = $this->client()->request('GET', '/company', [
                'query' => [
                    'key' => $cleanApiKey,
                    'inn' => $cleanInn,
                ],
                'headers' => [
                    'Accept'     => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
                ],
                'timeout' => 10,
            ]);

            $body    = (string) $response->getBody();
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            $code   = match (true) {
                $status === 401 || $status === 403 => ExternalApiException::CODE_UNAUTHORIZED,
                $status === 404                    => ExternalApiException::CODE_NOT_FOUND,
                $status >= 500                     => ExternalApiException::CODE_SERVER_ERROR,
                default                            => ExternalApiException::CODE_BAD_RESPONSE,
            };
            
            $errorMsg = 'Checko API responded with HTTP ' . $status;
            $responseBody = $e->getResponse()->getBody()->getContents();
            $errorJson = json_decode($responseBody, true);
            
            if (isset($errorJson['errors'][0]['text'])) {
                $errorMsg = $errorJson['errors'][0]['text'];
            }

            throw new ExternalApiException($errorMsg, $code, previous: $e);
            
        } catch (TransferException $e) {
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

        $companyData = $decoded['company'] ?? $decoded;

        return CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => $cleanInn,
            'name'     => (string) ($companyData['name'] ?? $companyData['title'] ?? 'Неизвестно'),
            'ogrn'     => $companyData['ogrn'] ?? null,
            'address'  => $companyData['address']['value'] ?? ($companyData['address'] ?? null),
            'status'   => $companyData['status'] ?? null,
            'director' => $companyData['director']['name'] ?? ($companyData['director'] ?? null),
            'raw'      => $decoded,
        ]);
    }

    private function client(): ClientInterface
    {
        return $this->httpClient ??= new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
        ]);
    }

    private function mockResponse(string $inn): array
    {
        $isIndividualEntrepreneur = strlen($inn) === 12;
        return [
            'source'   => 'mock',
            'inn'      => $inn,
            'name'     => $isIndividualEntrepreneur ? 'Иванов Иван Иванович (ИП)' : 'ПАО «Заглушка»',
            'ogrn'     => $isIndividualEntrepreneur ? '3' . str_pad(substr($inn, 2), 14, '0') : '1027700132195',
            'address'  => 'г. Москва, ул. Тестовая, д. 1',
            'status'   => 'ACTIVE',
            'director' => $isIndividualEntrepreneur ? null : 'Сидоров С. С.',
            'raw'      => ['mock' => true, 'generated_for' => $inn],
        ];
    }
}