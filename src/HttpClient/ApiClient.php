<?php

declare(strict_types=1);

namespace App\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Базовая обёртка над Guzzle для произвольных HTTP-запросов.
 * Специализированные клиенты (например, CheckoApiClient) могут использовать её
 * или собственный Guzzle Client напрямую.
 */
final class ApiClient
{
    private ClientInterface $client;

    public function __construct(string $baseUrl = '', array $defaultHeaders = [])
    {
        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 10.0,
            'headers'  => array_merge([
                'Accept'     => 'application/json',
                'User-Agent' => 'vanilla-php-app/1.0',
            ], $defaultHeaders),
        ]);
    }

    /**
     * @return string Тело ответа
     */
    public function get(string $uri, array $options = []): string
    {
        return (string) $this->client->request('GET', $uri, $options)->getBody();
    }

    /**
     * @return string Тело ответа
     */
    public function post(string $uri, array $json = []): string
    {
        return (string) $this->client
            ->request('POST', $uri, ['json' => $json])
            ->getBody();
    }
}
