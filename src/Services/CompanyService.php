<?php

declare(strict_types=1);

namespace App\Services;

use App\Dto\CompanyDto;
use App\Exceptions\DatabaseException;
use App\HttpClient\CheckoApiClient;
use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Бизнес-слой: ИНН -> данные компании.
 *
 * Логика getCompanyInfo():
 *  1) читаем кэш из PostgreSQL (prepared statement);
 *  2) если запись свежая (cached_at < TTL) — возвращаем её;
 *  3) иначе запрашиваем Checko через CheckoApiClient и делаем UPSERT в кэш.
 */
final class CompanyService
{
    /** TTL кэша, секунд (1 час). */
    public const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly CheckoApiClient $checkoApiClient,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param string $inn ИНН (10 или 12 цифр)
     *
     * @return array{company: array<string, mixed>, cached: bool}
     *
     * @throws DatabaseException      если БД недоступна / запрос упал
     * @throws \App\Exceptions\ExternalApiException если внешний API вернул ошибку
     */
    public function getCompanyInfo(string $inn): array
    {
        // --- Шаг 1. Проверка кэша -----------------------------------------
        $cached = $this->findFreshCacheEntry($inn);
        if ($cached !== null) {
            return ['company' => $cached, 'cached' => true];
        }

        // --- Шаг 2. Промах по кэшу: запрос к внешнему API -------------------
        $dto = $this->checkoApiClient->getCompanyByInn($inn);

        // --- Шаг 3. Сохранение в кэш (UPSERT) --------------------------------
        // Ошибка записи не должна ронять ответ: данные уже получены из API.
        try {
            $this->upsertCache($inn, $dto);
        } catch (DatabaseException $e) {
            error_log(sprintf(
                '[CompanyService] failed to cache company %s: %s',
                $inn,
                $e->getMessage()
            ));
        }

        return ['company' => $dto, 'cached' => false];
    }

    /**
     * Читает запись кэша по ИНН; возвращает декодированный payload,
     * только если она моложе CACHE_TTL_SECONDS, иначе null.
     *
     * @return array<string, mixed>|null
     */
    private function findFreshCacheEntry(string $inn): ?array
    {
        // Только плейсхолдеры: пользовательский ввод никогда не попадает в SQL.
        $stmt = $this->pdo->prepare(
            'SELECT data, cached_at FROM company_cache WHERE inn = :inn LIMIT 1'
        );
        $stmt->bindValue(':inn', $inn, PDO::PARAM_STR);

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            throw DatabaseException::query($e);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null; // нет записи -> промах по кэшу
        }

        // cached_at хранится как TIMESTAMP без таймзоны; Postgres пишет now()
        // в серверном времени (в контейнере — UTC), PHP-приложение тоже работает
        // в UTC (см. docker-compose: TZ=UTC) — сравнение корректно.
        try {
            $cachedAt = new DateTimeImmutable((string) $row['cached_at']);
        } catch (\Throwable) {
            // повреждённый timestamp считаем «протухшим» — обновим с API
            return null;
        }

        // Важно: сравниваем Unix-time (time()), а не timeDiff(), который
        // учитывает летнее время и может давать +/-1 час на краях перехода.
        $ageSeconds = time() - $cachedAt->getTimestamp();

        if ($ageSeconds >= self::CACHE_TTL_SECONDS || $ageSeconds < 0) {
            return null; // старше часа (или часы «из будущего») — устарело
        }

        try {
            $data = json_decode((string) $row['data'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw DatabaseException::json($e);
        }

        return is_array($data) ? $data : null;
    }

    /**
     * INSERT ... ON CONFLICT (UPSERT): одна операция вместо SELECT+INSERT/UPDATE,
     * атомарна и не боится гонки параллельных запросов одного ИНН.
     *
     * @param array<string, mixed> $data
     */
    private function upsertCache(string $inn, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO company_cache (inn, data, cached_at)
             VALUES (:inn, :data::jsonb, now())
             ON CONFLICT (inn) DO UPDATE
                 SET data = EXCLUDED.data,
                     cached_at = now()'
        );
        $stmt->bindValue(':inn', $inn, PDO::PARAM_STR);
        $stmt->bindValue(
            ':data',
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            PDO::PARAM_STR
        );

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            throw DatabaseException::query($e);
        }
    }
}