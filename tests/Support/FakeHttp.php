<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Dto\CompanyDto;
use App\HttpClient\CheckoApiClient;

/**
 * Test double для CompanyService (final-класс, PHPUnit createMock() его
 * удвоить не может). Подменяем только HTTP-слой: внутрь реального
 * CheckoApiClient ставим Guzzle MockHandler с фиксированным payload.
 *
 * Так тестируется настоящий код CompanyService::getCompanyInfo() —
 * cache-aside логика без сети и без реальной БД.
 */
final class FakeHttp
{
    /** Фиксирует в клиенте ответ Checko v2 по переданному DTO. */
    public static function clientReturning(CompanyDto $dto): CheckoApiClient
    {
        $payload = [
            'data' => [
                'НаимСокр' => $dto->name,
                'ОГРН'     => $dto->ogrn,
                'ЮрАдрес'  => ['АдресРФ' => $dto->address],
                'Статус'   => ['Наим' => $dto->status],
                'Руковод'  => $dto->director !== null ? [['ФИО' => $dto->director]] : [],
            ],
        ];

        return self::clientWithResponses([
            new \GuzzleHttp\Psr7\Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
            ),
        ]);
    }

    /** Фиксирует в клиенте очередь ответов/исключений Guzzle. */
    public static function clientWithResponses(array $queue): CheckoApiClient
    {
        $mock = new \GuzzleHttp\Handler\MockHandler($queue);

        return new CheckoApiClient(
            'fake-key',
            'https://api.checko.ru/v2/',
            false,
            new \GuzzleHttp\Client([
                'handler'  => \GuzzleHttp\HandlerStack::create($mock),
                'base_uri' => 'https://api.checko.ru/v2/',
            ]),
        );
    }
}
