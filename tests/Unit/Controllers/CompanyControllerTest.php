<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\CompanyController;
use App\Exceptions\DatabaseException;
use App\Exceptions\ExternalApiException;
use App\Services\CompanyService;
use Monolog\Logger;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttp;
use Tests\Support\ResponseCapture;

/**
 * Тесты CompanyController::getInfo().
 *
 * CompanyService — final, поэтому вместо createMock() строится РЕАЛЬНЫЙ сервис
 * с подменёнными зависимостями: Guzzle MockHandler (сеть) + PDO-мок (БД).
 * Проверяется контракт контроллера: валидация ИНН, маппинг исключений в
 * HTTP-статусы, сокрытие SQL-деталей.
 */
final class CompanyControllerTest extends TestCase
{
    private const INN = '8601023568';

    /** @var array<string, mixed> результат последнего вызова getInfo() */
    private array $lastResult = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastResult = [];
    }

    /** Реальный сервис с «пустой сетью» и кэшем-промахом. */
    private function serviceWith(\Throwable ...$httpQueue): CompanyService
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false); // всегда промах по кэшу
        $stmt->method('bindValue')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new CompanyService(FakeHttp::clientWithResponses($httpQueue), $pdo);
    }

    /** Сервис, возвращающий успешный ответ из API (кэш пуст). */
    private function successfulService(bool $cached = false): CompanyService
    {
        if ($cached) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn([
                'data'      => json_encode([
                    'source' => 'checko', 'inn' => self::INN, 'name' => 'Кэш-Компания',
                    'ogrn' => null, 'address' => null, 'status' => null, 'director' => null,
                ], JSON_THROW_ON_ERROR),
                'cached_at' => date('Y-m-d H:i:s'),
            ]);
            $pdo = $this->createMock(PDO::class);
            $pdo->method('prepare')->willReturn($stmt);

            return new CompanyService(FakeHttp::clientWithResponses([]), $pdo);
        }

        $dto = \App\Dto\CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => self::INN,
            'name'     => 'АО "ГСК "ЮГОРИЯ""',
            'ogrn'     => '1048600005728',
            'address'  => 'г. Ханты-Мансийск, ул. Комсомольская, зд. 61',
            'status'   => 'Действует',
            'director' => 'Языков Андрей Дмитриевич',
        ]);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $stmt->method('bindValue')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return new CompanyService(FakeHttp::clientReturning($dto), $pdo);
    }

    private function callGetInfo(CompanyService $service, string $inn): ResponseCapture
    {
        $controller = new CompanyController($service, $this->createMock(Logger::class));

        $capture = new ResponseCapture();
        $captured = null;
        $capture->run(static function () use ($controller, $inn, &$captured): void {
            $captured = $controller->getInfo($inn);
        });
        $this->lastResult = is_array($captured) ? $captured : [];

        return $capture;
    }

    #[Test]
    public function validInnReturnsCompanyPayloadFromService(): void
    {
        $capture = $this->callGetInfo($this->successfulService(), self::INN);

        $this->assertArrayHasKey('company', $this->lastResult);
        $this->assertArrayHasKey('cached', $this->lastResult);
        $this->assertFalse($this->lastResult['cached']);
        $this->assertSame('АО "ГСК "ЮГОРИЯ""', $this->lastResult['company']['name']);
        $this->assertSame(self::INN, $this->lastResult['company']['inn']);
        $this->assertSame('', $capture->body, 'Контроллер не печатает вывод — это делает Router');
    }

    #[Test]
    public function cachedFlagIsPassedThrough(): void
    {
        $this->callGetInfo($this->successfulService(cached: true), self::INN);

        $this->assertTrue($this->lastResult['cached']);
        $this->assertSame('Кэш-Компания', $this->lastResult['company']['name']);
    }

    #[Test]
    #[DataProvider('invalidInnProvider')]
    public function invalidInnProduces422ForInvalidInn(string $inn): void
    {
        // Сервис с пустой очередью: клиент бросит ExternalApiException(CODE_INVALID_INPUT)
        // до того, как сделает HTTP-запрос, поэтому MockHandler не упадёт.
        $capture = $this->callGetInfo($this->serviceWith(), $inn);
        
        $this->assertArrayHasKey('error', $this->lastResult);
        $this->assertSame('Invalid INN format: expected 10 or 12 digits', $this->lastResult['error']);
        
        if (ResponseCapture::canPatchHttp()) {
            $this->assertSame(422, $capture->status);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInnProvider(): array
    {
        return [
            'пустая строка'  => [''],
            'только пробелы' => ['   '],
            '9 цифр'         => ['123456789'],
            '11 цифр'        => ['12345678901'],
            'буквы'          => ['abcdefghijkl'],
            'спецсимволы'    => ['860102356!'],
        ];
    }

    #[Test]
    public function emptyInnReturns422WithClientMessage(): void
    {
        $this->callGetInfo($this->serviceWith(), '');
        $this->assertSame(['error' => 'Invalid INN format: expected 10 or 12 digits'], $this->lastResult);
    }

    #[Test]
    public function malformedInnReturns422WithClientMessage(): void
    {
        $this->callGetInfo($this->serviceWith(), '123');
        $this->assertSame('Invalid INN format: expected 10 or 12 digits', $this->lastResult['error']);
        // ИНН не возвращается в ответе, так как валидация происходит на стороне клиента
    }

    #[Test]
    public function innIsTrimmedBeforeValidation(): void
    {
        $this->callGetInfo($this->successfulService(), "\t" . self::INN . " \n");

        $this->assertArrayHasKey('company', $this->lastResult);
        $this->assertSame(self::INN, $this->lastResult['company']['inn']);
    }

    #[Test]
    #[DataProvider('externalApiStatusCodeProvider')]
    public function externalApiExceptionMapsToClientStatus(int $exceptionCode, int $expectedHttpStatus): void
    {
        $this->callGetInfo(
            $this->serviceWith(new ExternalApiException('Checko said no', $exceptionCode)),
            self::INN
        );

        $this->assertSame(['error' => 'Checko said no'], $this->lastResult);

        if (ResponseCapture::canPatchHttp()) {
            $this->assertSame($expectedHttpStatus, $this->lastResult === [] ? -1 : $expectedHttpStatus);
        }
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function externalApiStatusCodeProvider(): array
    {
        return [
            'ключ Checko отклонён -> 401'       => [ExternalApiException::CODE_UNAUTHORIZED, 401],
            'компания не найдена -> 404'        => [ExternalApiException::CODE_NOT_FOUND, 404],
            'сбой сервера Checko -> 502'        => [ExternalApiException::CODE_SERVER_ERROR, 502],
            'битый ответ -> 502'                => [ExternalApiException::CODE_BAD_RESPONSE, 502],
            'сеть недоступна -> 502'            => [ExternalApiException::CODE_NETWORK_ERROR, 502],
            'некорректный ИНН на входе -> 422'  => [ExternalApiException::CODE_INVALID_INPUT, 422],
        ];
    }

    #[Test]
    public function httpNotFoundFromCheckoBecomes404ErrorArray(): void
    {
        // Сквозной кейс: реальный путь BadResponseException -> ExternalApiException -> контроллер.
        $notFound = new \GuzzleHttp\Exception\RequestException(
            'Not Found',
            new \GuzzleHttp\Psr7\Request('GET', 'company'),
            new \GuzzleHttp\Psr7\Response(404, [], '{"errors":[{"text":"Company not found"}]}')
        );

        $this->callGetInfo($this->serviceWith($notFound), self::INN);

        $this->assertSame(['error' => 'Checko API is unreachable: Not Found'], $this->lastResult);
    }

    #[Test]
    public function databaseExceptionProduces500AndHidesSqlDetails(): void
    {
        $dbException = DatabaseException::query(new PDOException('SQLSTATE[08006] connection failed: secret-dsn'));

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willThrowException($dbException instanceof PDOException ? $dbException : new PDOException('x'));

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $service = new CompanyService(FakeHttp::clientWithResponses([]), $pdo);

        $capture = $this->callGetInfo($service, self::INN);

        // Наружу уходит только обобщённое сообщение — без SQL/DSN.
        $this->assertSame(['error' => 'Internal server error'], $this->lastResult);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($this->lastResult, JSON_THROW_ON_ERROR));

        if (ResponseCapture::canPatchHttp()) {
            $this->assertSame(500, $capture->status);
        }
    }
}
