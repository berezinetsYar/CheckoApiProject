<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Dto\CompanyDto;
use App\Exceptions\DatabaseException;
use App\Exceptions\ExternalApiException;
use App\Services\CompanyService;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHttp;

/**
 * Тесты cache-aside логики CompanyService.
 *
 * CheckoApiClient — final, поэтому вместо createMock() используется реальный
 * клиент с Guzzle MockHandler (см. FakeHttp): сеть не задействуется, но
 * проверяется настоящий путь «кэш → API → UPSERT». PDO и PDOStatement —
 * обычные PHPUnit-модели: тесты не зависят от реальной БД.
 */
final class CompanyServiceTest extends TestCase
{
    private const INN = '8601023568';

    /** @var list<string> SQL, подготовленные через pdo->prepare() */
    private array $preparedSql = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparedSql = [];
    }

    private function freshDto(): CompanyDto
    {
        return CompanyDto::fromArray([
            'source'   => 'checko',
            'inn'      => self::INN,
            'name'     => 'АО "ГСК "ЮГОРИЯ""',
            'ogrn'     => '1048600005728',
            'address'  => 'г. Ханты-Мансийск, ул. Комсомольская, зд. 61',
            'status'   => 'Действует',
            'director' => 'Языков Андрей Дмитриевич',
        ]);
    }

    /**
     * PDO-мок: prepare() возвращает переданные statement'ы по порядку,
     * записывая SQL в $this->preparedSql.
     */
    private function createPdo(PDOStatement ...$statements): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $test = $this;

        $pdo->method('prepare')
            ->willReturnCallback(
                static function (string $sql) use ($statements, $test): PDOStatement {
                    static $index = 0;
                    $test->recordPreparedSql($sql);
                    $stmt = $statements[$index] ?? end($statements);
                    $index++;

                    return $stmt;
                }
            );

        return $pdo;
    }

    /** Пубlico для колбэка внутри createPdo(). */
    public function recordPreparedSql(string $sql): void
    {
        $this->preparedSql[] = $sql;
    }

    private function selectStmt(bool $found, ?array $row = null): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($found ? $row : false);

        return $stmt;
    }

    private function okUpsertStmt(): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('bindValue')->willReturn(true);

        return $stmt;
    }

    #[Test]
    public function returnsCachedEntryWithCachedTrue(): void
    {
        $select = $this->selectStmt(true, [
            'data'      => json_encode($this->freshDto()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'cached_at' => date('Y-m-d H:i:s', time() - 60), // свежая запись (TTL = 1 час)
        ]);

        $pdo = $this->createPdo($select);

        // Ни одного ответа в очереди клиента нет: если бы запрос к API состоялся,
        // Guzzle MockHandler бросил быOutOfBoundsException — тест упал бы.
        $apiClient = FakeHttp::clientWithResponses([]);

        $service = new CompanyService($apiClient, $pdo);
        $result = $service->getCompanyInfo(self::INN);

        $this->assertTrue($result['cached']);
        $this->assertSame('АО "ГСК "ЮГОРИЯ""', $result['company']['name']);
        $this->assertSame(self::INN, $result['company']['inn']);
        $this->assertCount(1, $this->preparedSql, 'Только SELECT: без API и без записи');
    }

    #[Test]
    public function cacheMissCallsApiAndReturnsCachedFalse(): void
    {
        $select = $this->selectStmt(false);
        $upsert = $this->okUpsertStmt();
        $pdo = $this->createPdo($select, $upsert);

        $apiClient = FakeHttp::clientReturning($this->freshDto());

        $service = new CompanyService($apiClient, $pdo);
        $result = $service->getCompanyInfo(self::INN);

        $this->assertFalse($result['cached']);
        $this->assertSame('Действует', $result['company']['status']);
        $this->assertCount(2, $this->preparedSql, 'SELECT кэша + INSERT..ON CONFLICT');
    }

    #[Test]
    public function successfulApiCallIsSavedToCacheViaUpsert(): void
    {
        $bound = [];

        $upsert = $this->createMock(PDOStatement::class);
        $upsert->method('execute')->willReturn(true);
        $upsert->method('bindValue')->willReturnCallback(
            static function (string $param, $value, int $type = PDO::PARAM_STR) use (&$bound): bool {
                $bound[$param] = $value;

                return true;
            }
        );

        $pdo = $this->createPdo($this->selectStmt(false), $upsert);
        $apiClient = FakeHttp::clientReturning($this->freshDto());

        $service = new CompanyService($apiClient, $pdo);
        $result = $service->getCompanyInfo(self::INN);

        $this->assertStringContainsString('SELECT data, cached_at FROM company_cache', $this->preparedSql[0]);
        $this->assertStringContainsString('INSERT INTO company_cache', $this->preparedSql[1]);
        $this->assertStringContainsString('ON CONFLICT (inn) DO UPDATE', $this->preparedSql[1]);

        $this->assertFalse($result['cached']);
        $this->assertSame(self::INN, $bound[':inn']);
        $decodedData = json_decode((string) $bound[':data'], true);
        $this->assertSame('АО "ГСК "ЮГОРИЯ""', $decodedData['name']);
    }

    #[Test]
    public function staleCacheEntryIsRefreshedFromApi(): void
    {
        $select = $this->selectStmt(true, [
            'data'      => json_encode($this->freshDto()->toArray(), JSON_THROW_ON_ERROR),
            'cached_at' => date('Y-m-d H:i:s', time() - CompanyService::CACHE_TTL_SECONDS - 10),
        ]);
        $pdo = $this->createPdo($select, $this->okUpsertStmt());

        $apiClient = FakeHttp::clientReturning($this->freshDto());

        $service = new CompanyService($apiClient, $pdo);
        $result = $service->getCompanyInfo(self::INN);

        $this->assertFalse($result['cached'], 'Запись старше TTL должна обновляться через API');
    }

    #[Test]
    public function corruptedTimestampIsTreatedAsCacheMiss(): void
    {
        $select = $this->selectStmt(true, [
            'data'      => json_encode($this->freshDto()->toArray(), JSON_THROW_ON_ERROR),
            'cached_at' => 'не-дата-вообще',
        ]);
        $pdo = $this->createPdo($select, $this->okUpsertStmt());

        $apiClient = FakeHttp::clientReturning($this->freshDto());

        $service = new CompanyService($apiClient, $pdo);
        $result = $service->getCompanyInfo(self::INN);

        $this->assertFalse($result['cached']);
    }

    #[Test]
    public function externalApiExceptionPropagatesFromClient(): void
    {
        // Клиент: HTTP 404 от Checko -> ExternalApiException(CODE_NOT_FOUND).
        // Это гарантирует, что в CheckoApiClient сработает блок catch (BadResponseException),
        // который корректно маппит HTTP 404 в CODE_NOT_FOUND, а не в CODE_NETWORK_ERROR (599).
        $apiClient = FakeHttp::clientWithResponses([
            new \GuzzleHttp\Exception\BadResponseException(
                'Not found',
                new \GuzzleHttp\Psr7\Request('GET', 'company'),
                new \GuzzleHttp\Psr7\Response(404, [], '{}')
            ),
        ]);

        $pdo = $this->createPdo($this->selectStmt(false));

        $service = new CompanyService($apiClient, $pdo);

        try {
            $service->getCompanyInfo(self::INN);
            $this->fail('Ожидался ExternalApiException');
        } catch (ExternalApiException $e) {
            $this->assertSame(ExternalApiException::CODE_NOT_FOUND, $e->getStatusCode());
            $this->assertSame(404, $e->getClientStatus());
        }

        $this->assertCount(1, $this->preparedSql, 'При ошибке API ничего не записывается в кэш');
    }

    #[Test]
    public function databaseFailureOnCacheReadThrowsDatabaseException(): void
    {
        $select = $this->createMock(PDOStatement::class);
        $select->method('execute')->willThrowException(new PDOException('connection lost'));

        $pdo = $this->createPdo($select);

        $apiClient = FakeHttp::clientWithResponses([]); // вызов запрещён — очередь пуста

        $service = new CompanyService($apiClient, $pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionCode(DatabaseException::CODE_QUERY);

        $service->getCompanyInfo(self::INN);
    }

    #[Test]
    public function cacheWriteFailureDoesNotBreakTheResponse(): void
    {
        $upsert = $this->createMock(PDOStatement::class);
        $upsert->method('execute')->willThrowException(new PDOException('disk full'));
        $upsert->method('bindValue')->willReturn(true);

        $pdo = $this->createPdo($this->selectStmt(false), $upsert);
        $apiClient = FakeHttp::clientReturning($this->freshDto());

        $service = new CompanyService($apiClient, $pdo);

        ob_start(); // error_log() сервиса пишет в вывод CLI — глушим
        try {
            $result = $service->getCompanyInfo(self::INN);
        } finally {
            ob_end_clean();
        }

        $this->assertFalse($result['cached'], 'Данные из API отдаются, даже если кэш не записался');
        $this->assertSame(self::INN, $result['company']['inn']);
    }

    #[Test]
    public function invalidCachedJsonThrowsDatabaseException(): void
    {
        $select = $this->selectStmt(true, [
            'data'      => '{битый json',
            'cached_at' => date('Y-m-d H:i:s'),
        ]);
        $pdo = $this->createPdo($select);

        $apiClient = FakeHttp::clientWithResponses([]);

        $service = new CompanyService($apiClient, $pdo);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionCode(DatabaseException::CODE_JSON);

        $service->getCompanyInfo(self::INN);
    }
}
