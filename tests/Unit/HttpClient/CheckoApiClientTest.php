<?php

declare(strict_types=1);

namespace Tests\Unit\HttpClient;

use App\Exceptions\ExternalApiException;
use App\HttpClient\CheckoApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckoApiClientTest extends TestCase
{
    private const INN_10 = '8601023568';
    private const INN_12 = '770708389312';

    /**
     * Создаёт клиент с Guzzle MockHandler вместо реальной сети.
     * base_uri оканчивается на '/' — как в CheckoApiClient::client():
     * только тогда Guzzle корректно склеивает '/v2/' + 'company'.
     *
     * @param Response|\Throwable ...$queue очередь ответов/исключений
     */

    private function clientWithQueue(Response|\Throwable ...$queue): array
    {
        $mock = new MockHandler($queue);
        
        $httpClient = new Client([
            'handler'  => HandlerStack::create($mock),
            'base_uri' => 'https://api.checko.ru/v2/',
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            ],
        ]);

        return [
            new CheckoApiClient('test-checko-key', 'https://api.checko.ru/v2', false, $httpClient),
            $mock,
        ];
    }
    /** Реалистичный payload Checko v2: данные в ключе `data`, русские поля. */
    private static function checkoV2Payload(): string
    {
        return json_encode([
            'meta' => ['count' => 1],
            'data' => [
                'НаимСокр' => 'АО "ГСК "ЮГОРИЯ""',
                'НаимПолн' => 'Акционерное общество "Городская строительная компания "ЮГОРИЯ""',
                'ОГРН'     => '1048600005728',
                'ЮрАдрес'  => ['АдресРФ' => '628011, Ханты-Мансийский автономный округ - Югра, г. Ханты-Мансийск, ул. Комсомольская, зд. 61'],
                'Статус'   => ['Наим' => 'Действует'],
                'Руковод'  => [
                    ['ФИО' => 'Языков Андрей Дмитриевич', 'Должность' => 'Генеральный директор'],
                    ['ФИО' => 'Второй Руководитель', 'Должность' => 'Заместитель'],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    #[Test]
    public function mockModeReturnsStubWithoutNetwork(): void
    {
        // 4-й аргумент (httpClient) не передан — при mock-режиме он и не создаётся.
        $client = new CheckoApiClient('any-key', 'https://api.checko.ru/v2', true);

        $dto = $client->getCompanyDto(self::INN_10);

        $this->assertSame('mock', $dto->source);
        $this->assertSame(self::INN_10, $dto->inn);
        $this->assertSame('ПАО «Заглушка»', $dto->name);
        $this->assertSame('ACTIVE', $dto->status);
        $this->assertSame('Сидоров С. С.', $dto->director);
    }

    #[Test]
    public function mockModeForIndividualEntrepreneur(): void
    {
        $client = new CheckoApiClient('any-key', 'https://api.checko.ru/v2', true);

        $dto = $client->getCompanyDto(self::INN_12);

        $this->assertStringContainsString('(ИП)', $dto->name);
        $this->assertStringStartsWith('3', (string) $dto->ogrn);
        $this->assertNull($dto->director);
    }

    #[Test]
    public function successfulRequestMapsRussianKeysToDto(): void
    {
        [$client] = $this->clientWithQueue(new Response(200, [], self::checkoV2Payload()));

        $dto = $client->getCompanyDto(self::INN_10);

        $this->assertSame('checko', $dto->source);
        $this->assertSame(self::INN_10, $dto->inn);
        $this->assertSame('АО "ГСК "ЮГОРИЯ""', $dto->name);          // НаимСокр
        $this->assertSame('1048600005728', $dto->ogrn);              // ОГРН
        $this->assertSame(                                                                 
            '628011, Ханты-Мансийский автономный округ - Югра, г. Ханты-Мансийск, ул. Комсомольская, зд. 61',
            $dto->address                                            // ЮрАдрес.АдресРФ
        );
        $this->assertSame('Действует', $dto->status);                 // Статус.Наим
        $this->assertSame('Языков Андрей Дмитриевич', $dto->director); // Руковод[0].ФИО
    }

    #[Test]
    public function requestUsesRelativePathKeyAndInnQuery(): void
    {
        [$client, $mock] = $this->clientWithQueue(new Response(200, [], self::checkoV2Payload()));

        $client->getCompanyDto(self::INN_10);

        $request = $mock->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('GET', $request->getMethod());

        $uri = $request->getUri();
        // Относительный путь 'company' + base_uri c '/v2/' -> полный URL содержит /v2/company
        $this->assertStringContainsString('/v2/company', (string) $uri);
        $this->assertSame('company', $uri->getPath() === '' ? '' : basename($uri->getPath()));

        parse_str($uri->getQuery(), $query);
        $this->assertSame('test-checko-key', $query['key']); // query-параметр key
        $this->assertSame(self::INN_10, $query['inn']);      // query-параметр inn

        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('Mozilla/5.0', $request->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function getCompanyByInnReturnsArrayShape(): void
    {
        [$client] = $this->clientWithQueue(new Response(200, [], self::checkoV2Payload()));

        $result = $client->getCompanyByInn(self::INN_10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('source', $result);
        $this->assertArrayHasKey('inn', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayNotHasKey('raw', $result, 'toArray() не отдаёт сырой payload наружу');
    }

    #[Test]
    public function missingNameFallsBackToFullNameThenUnknown(): void
    {
        $payloadNoShort = json_encode(['data' => ['НаимПолн' => 'Полное наименование ООО']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        [$client] = $this->clientWithQueue(new Response(200, [], $payloadNoShort));
        $this->assertSame('Полное наименование ООО', $client->getCompanyDto(self::INN_10)->name);

        [$client2] = $this->clientWithQueue(new Response(200, [], '{"data":{}}'));
        $this->assertSame('Неизвестно', $client2->getCompanyDto(self::INN_10)->name);
    }

    #[Test]
    #[DataProvider('errorStatusProvider')]
    public function httpErrorStatusesMapToExpectedExceptionCodes(int $status, int $expectedCode, string $expectedMessageFragment): void
    {
        [$client] = $this->clientWithQueue(new Response($status, ['Content-Type' => 'application/json'], '{"errors":[{"text":"Access denied by WAF"}]}'));

        try {
            $client->getCompanyDto(self::INN_10);
            $this->fail('Ожидалось ExternalApiException для HTTP ' . $status);
        } catch (ExternalApiException $e) {
            $this->assertSame($expectedCode, $e->getStatusCode());
            $this->assertStringContainsString($expectedMessageFragment, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function errorStatusProvider(): array
    {
        return [
            'HTTP 401' => [401, ExternalApiException::CODE_UNAUTHORIZED, 'Access denied'],
            'HTTP 403' => [403, ExternalApiException::CODE_UNAUTHORIZED, 'Access denied'],
            'HTTP 404' => [404, ExternalApiException::CODE_NOT_FOUND, 'Access denied'],
            'HTTP 500' => [500, ExternalApiException::CODE_SERVER_ERROR, 'Access denied'],
            'HTTP 503' => [503, ExternalApiException::CODE_SERVER_ERROR, 'Access denied'],
        ];
    }

    #[Test]
    public function errorMessageFallsBackToHttpStatusWhenBodyHasNoErrorsText(): void
    {
        [$client] = $this->clientWithQueue(new Response(404, [], '{}'));

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('Checko API responded with HTTP 404');

        $client->getCompanyDto(self::INN_10);
    }

    #[Test]
    public function networkFailureThrowsNetworkErrorCode(): void
    {
        $exception = new ConnectException('Connection refused', new Request('GET', 'company'));
        
        // передаём исключение 3 раза (1 основной запрос + 2 retry)
        [$client] = $this->clientWithQueue($exception, $exception, $exception);

        try {
            $client->getCompanyDto(self::INN_10);
            $this->fail('Ожидалось ExternalApiException');
        } catch (ExternalApiException $e) {
            $this->assertSame(ExternalApiException::CODE_NETWORK_ERROR, $e->getStatusCode());
            $this->assertStringContainsString('unreachable', $e->getMessage());
            $this->assertSame(502, $e->getClientStatus(), 'Сетевой сбой Checko -> 502 для нашего клиента');
        }
    }

    #[Test]
    public function invalidJsonBodyThrowsBadResponse(): void
    {
        [$client] = $this->clientWithQueue(new Response(200, [], '{not a valid json!!!'));

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('Checko API returned invalid JSON');
        $this->expectExceptionCode(ExternalApiException::CODE_BAD_RESPONSE);

        $client->getCompanyDto(self::INN_10);
    }

    #[Test]
    public function scalarJsonPayloadIsRejectedAsUnexpectedShape(): void
    {
        [$client] = $this->clientWithQueue(new Response(200, [], '"just-a-string"'));

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('Unexpected payload shape');

        $client->getCompanyDto(self::INN_10);
    }

    #[Test]
    #[DataProvider('invalidInnProvider')]
    public function invalidInnFormatThrowsBeforeAnyRequest(string $inn): void
    {
        // Очередь пуста: если запрос всё же уйдёт в сеть — MockHandler выбросит
        //OutOfBoundsException, и тест упадёт. Значит, валидация работает до HTTP.
        [$client] = $this->clientWithQueue();

        $this->expectException(ExternalApiException::class);
        $this->expectExceptionCode(ExternalApiException::CODE_INVALID_INPUT);
        $this->expectExceptionMessage('Invalid INN format');

        $client->getCompanyDto($inn);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInnProvider(): array
    {
        return [
            'пустая строка'      => [''],
            '9 цифр'             => ['123456789'],
            '11 цифр'            => ['12345678901'],
            '13 цифр'            => ['1234567890123'],
            'буквы'              => ['860102356a'],
            'пробел внутри'      => ['8601 23568'],
            'только символы'     => ['abcdefghij'],
        ];
    }

    #[Test]
    #[DataProvider('validInnProvider')]
    public function validInnFormatsPassValidation(string $inn): void
    {
        [$client] = $this->clientWithQueue(new Response(200, [], '{"data":{"НаимСокр":"Тест"}}'));

        $dto = $client->getCompanyDto($inn);

        $this->assertSame($inn, $dto->inn);
        $this->assertSame('Тест', $dto->name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validInnProvider(): array
    {
        return [
            'юрлицо 10 цифр' => ['7707083893'],
            'ИП 12 цифр'     => ['770708389312'],
        ];
    }

    #[Test]
    public function innWithSurroundingWhitespaceIsValidatedAfterTrim(): void
    {
        $_SERVER['argv'] ??= [];
        [$client, $mock] = $this->clientWithQueue(new Response(200, [], self::checkoV2Payload()));

        $dto = $client->getCompanyDto(" \t" . self::INN_10 . "\n");

        $this->assertSame(self::INN_10, trim($dto->inn));
        parse_str($mock->getLastRequest()->getUri()->getQuery(), $query);
        $this->assertSame(self::INN_10, $query['inn']);
    }
}
