-- Схема кэша компаний (PostgreSQL 16+).
-- Применяется автоматически при первом старте контейнера postgresql
-- (монтируется в /docker-entrypoint-initdb.d) либо вручную:
--   psql -U app -d app -f database/schema.sql

CREATE TABLE IF NOT EXISTS company_cache (
    inn        VARCHAR(12)   PRIMARY KEY,
    data       JSONB         NOT NULL,
    cached_at  TIMESTAMP     NOT NULL DEFAULT now()
);

-- Индекс для задач «почистить протухший кэш» (TTL-ревью по времени).
CREATE INDEX IF NOT EXISTS idx_company_cache_cached_at
    ON company_cache (cached_at);

COMMENT ON TABLE  company_cache           IS 'Кэш ответов внешнего API по ИНН';
COMMENT ON COLUMN company_cache.data      IS 'Нормализованный payload компании (CompanyDto::toArray()) в JSONB';
COMMENT ON COLUMN company_cache.cached_at IS 'Момент последнего обновления записи (TTL проверяется на уровне приложения)';
