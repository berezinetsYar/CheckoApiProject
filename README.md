# 🎯 Checko PHP API — данные компаний по ИНН

**REST API на Vanilla PHP 8.2** (без фреймворков, по духу — аналог ASP.NET Core Minimal API) для получения реквизитов российских компаний по ИНН через внешний сервис **Checko API v2** с кэшированием ответов в **PostgreSQL**.

> 🧑‍💻 Проект написан как тестовое задание и ориентирован на .NET-разработчика: в коде и документации везде проводятся аналогии с ASP.NET Core (`Program.cs`, `IServiceProvider`, middleware pipeline, DTO-records, Swashbuckle).

### ✨ Ключевые особенности

- ⚡ **Front Controller + мини-DI контейнер** с autowiring — без Laravel/Symfony, только чистый PHP 8.2
- 🔌 **Интеграция с Checko API v2** через Guzzle с нормализацией ответа (русские ключи JSON → типизированный `CompanyDto`)
- 🗄 **Cache-aside** в PostgreSQL 16 (таблица `company_cache`, JSONB, TTL 1 час, UPSERT)
- 🔐 **AuthMiddleware** — защита по заголовку `X-API-KEY` с `hash_equals()` (защита от timing-атак)
- 📖 **Swagger/OpenAPI** из атрибутов `#[OA\...]` (zircote/swagger-php) — аналог Swashbuckle
- 🧪 **Mock-режим** (`CHECKO_USE_MOCK=true`) — разработка без реального API-ключа Checko
- 🐳 **Docker Compose**: один `up` поднимает PHP-сервер и PostgreSQL со схемой БД

---

## 🏗 Архитектура

### Схема обработки запроса

```
Клиент ──HTTP──▶ public/index.php (front controller / «Program.cs»)
                      │
                      ├─▶ AuthMiddleware («AuthorizationMiddleware»)
                      │       проверка X-API-KEY для путей /api/*
                      │
                      ├─▶ Router («WebApplication.MapGet»)
                      │       GET /api/company/{inn}
                      │
                      └─▶ CompanyController («[ApiController]»)
                              │
                              ▼
                        CompanyService (Service layer, паттерн Cache-Aside)
                              │
                    ┌─────────┴──────────┐
                    ▼                    ▼
            PostgreSQL cache     CheckoApiClient (Guzzle)
             (hit → cached:true)      │ (miss → cached:false)
                                      ▼
                              Checko API v2 (api.checko.ru)
                                      │
                                      ▼
                              CompanyDto → UPSERT в кэш → JSON-ответ
```

### Таблица соответствия компонентов .NET ↔ PHP

| Компонент PHP | Аналог в ASP.NET Core |
|---------------|----------------------|
| `public/index.php` (front controller) | `Program.cs` |
| `App\Container` (мини-DI) | `IServiceCollection` + `IServiceProvider` |
| `App\Routing\Router` | `WebApplication.MapGet()` |
| `App\Routing\AuthMiddleware` | `AuthorizationMiddleware` |
| `App\Controllers\CompanyController` | `[ApiController]` |
| `App\Services\CompanyService` | Service layer |
| `App\HttpClient\CheckoApiClient` (Guzzle) | `HttpClient` + `IHttpClientFactory` |
| `App\Dto\CompanyDto` | DTO / Record |
| `App\Exceptions\ExternalApiException` | Custom Exception + ExceptionFilter |
| `zircote/swagger-php` (атрибуты `#[OA\...]`) | `Swashbuckle` / `NSwag` |
| `public/swagger.html` + `openapi.json` | `app.UseSwaggerUI()` |
| `public/client.html` | Простой HTML+JS клиент |
| PostgreSQL 16 + таблица `company_cache` | SQL Server + кэш-таблица |
| `docker-compose.yml` (php + postgresql) | `docker-compose.yml` для .NET + SQL |

### Использованные паттерны

- **Front Controller** — все HTTP-запросы проходят через `public/index.php`: там конфигурация из окружения, построение DI-контейнера, подключение middleware и роутера (как весь bootstrap в `Program.cs`).
- **Dependency Injection** — `App\Container` регистрирует фабрики (`PDO`, `CheckoApiClient`) и разрешает зависимости конструкторов рефлексией (аналог `builder.Services.AddSingleton<T>()` + `GetRequiredService<T>()`).
- **Middleware** — `AuthMiddleware::handle()` вызывается перед диспетчеризацией роута; вернул `false` — обработка запроса остановлена (аналог `app.Use(...)` c `return` без `next(context)`).
- **Cache-Aside** — `CompanyService` сначала читает кэш, при промахе обращается к внешнему API и пишет результат в БД (UPSERT). Ошибка записи в кэш не роняет ответ — данные уже получены из API.
- **DTO на границе системы** — «бесформенный» JSON Checko парсится один раз в `CompanyDto` (readonly-класс, аналог C# `record`); дальше по коду ходит только типизированные данные.

---

## 📂 Структура проекта

```
/workspace/
├── public/
│   ├── index.php              # Точка входа (front controller): конфиг, DI, роутинг, статика
│   ├── swagger.html           # Swagger UI (CDN, загружает openapi.json)
│   ├── client.html            # Простой HTML-клиент для ручных проверок
│   ├── router.php             # Роутер для встроенного PHP-сервера (отдача статики)
│   └── openapi.json           # Сгенерированная Swagger-спецификация (в .gitignore)
├── src/
│   ├── Container.php          # Мини-DI контейнер с autowiring
│   ├── Controllers/
│   │   └── CompanyController.php  # GET /api/company/{inn} + OA-атрибуты
│   ├── Services/
│   │   └── CompanyService.php     # Логика: кэш (TTL 1ч) → API → UPSERT в кэш
│   ├── HttpClient/
│   │   └── CheckoApiClient.php    # Guzzle-клиент Checko API v2 + mock-режим
│   ├── Dto/
│   │   └── CompanyDto.php         # DTO компании (readonly, аналог record)
│   ├── Routing/
│   │   ├── Router.php             # Роутер с PCRE и параметрами вида {param}
│   │   └── AuthMiddleware.php     # Проверка X-API-KEY (hash_equals)
│   └── Exceptions/
│       ├── ExternalApiException.php   # Ошибки Checko → HTTP 401/404/502
│       └── DatabaseException.php      # Ошибки PDO → HTTP 500
├── database/
│   └── schema.sql             # CREATE TABLE company_cache (монтируется в initdb)
├── bin/
│   ├── generate_swagger.php   # Скрипт генерации public/openapi.json из атрибутов
│   └── init.sh                # composer install + dump-autoload --optimize
├── docker-compose.yml         # Сервисы: php (checko_php) + postgresql (checko_postgres)
├── Dockerfile                 # PHP 8.2 CLI Alpine + pdo_pgsql, curl, opcache
├── composer.json              # Зависимости: guzzlehttp/guzzle, zircote/swagger-php
└── README.md                  # ← этот файл
```

---

## 🚀 Быстрый старт

### Требования

- 🐳 Docker Engine ≥ 24 и Docker Compose v2 (`docker compose`, а не `docker-compose`)
- Свободный порт **8080** на хосте

### Пошаговая инструкция

```bash
# 1. Клонировать проект
git clone <URL_репозитория> checko-php-api
cd checko-php-api

# 2. Создать .env (пример — скопировать и заполнить)
cat > .env <<'EOF'
CHECKO_API_KEY=ваш_ключ_checko
APP_API_KEY=мой_секретный_ключ
CHECKO_USE_MOCK=true
EOF

# 3. Поднять контейнеры (php + postgresql), схема БД применится автоматически
docker compose up -d --build

# 4. Установить PHP-зависимости внутри контейнера
docker exec -it checko_php composer install

# 5. Сгенерировать OpenAPI-спецификацию (public/openapi.json для Swagger UI)
docker exec -it checko_php php bin/generate_swagger.php

# 6. Проверить API (в mock-режиме ключ Checko не нужен)
curl -H "X-API-KEY: мой_секретный_ключ" http://localhost:8080/api/company/7707083893
```

### 🔗 URL-ы после запуска

| Что | Адрес |
|-----|-------|
| API | `http://localhost:8080/api/company/{inn}` |
| Swagger UI | `http://localhost:8080/swagger.html` |
| HTML-клиент | `http://localhost:8080/client.html` |

---

## 🔧 Конфигурация

Все настройки передаются через переменные окружения (в `docker-compose.yml` они читаются из `.env` на хосте).

| Переменная | По умолчанию | Описание |
|------------|--------------|----------|
| `CHECKO_API_KEY` | `demo-key` | API-ключ внешнего сервиса Checko (параметр `key`) |
| `CHECKO_BASE_URL` | `https://api.checko.ru/v2` | Базовый URL Checko API v2 |
| `CHECKO_USE_MOCK` | `true` | `true` — заглушка вместо реальных запросов к Checko (dev/тесты) |
| `APP_API_KEY` | *(пусто)* | Ключ защиты **нашего** API (заголовок `X-API-KEY`). Пусто — авторизация отключена |
| `DB_HOST` | `postgresql` | Хост БД. ⚠️ Именно имя сервиса `postgresql`, **не `localhost`** — контейнеры общаются через Docker-сеть |
| `DB_PORT` | `5432` | Порт PostgreSQL |
| `DB_NAME` | `app` | Имя базы данных |
| `DB_USER` | `app` | Пользователь БД |
| `DB_PASS` | `secret` | Пароль БД |

### Пример `.env`

```bash
# Реальный режим работы с Checko
CHECKO_API_KEY=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
CHECKO_BASE_URL=https://api.checko.ru/v2
CHECKO_USE_MOCK=false

# Наш секретный ключ для заголовка X-API-KEY
APP_API_KEY=my-super-secret-key-123
```

---

## 📡 API Reference

### `GET /api/company/{inn}`

Возвращает данные компании по ИНН: сначала проверяется кэш PostgreSQL, при промахе — запрос к Checko API v2 с последующим сохранением в кэш.

**Параметры:**

| Параметр | Где | Обязательный | Описание |
|----------|-----|--------------|----------|
| `inn` | path | ✅ | ИНН: **10 цифр** (юрлицо) или **12 цифр** (ИП). Regex: `^\d{10}(\d{2})?$` |
| `X-API-KEY` | header | если задан `APP_API_KEY` | Секретный ключ доступа к API |

**Примеры запросов:**

```bash
# Юридическое лицо (10 цифр)
curl -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/8601023568

# ИП (12 цифр)
curl -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/770118255589

# Через Swagger UI можно выполнять прямо из браузера:
# http://localhost:8080/swagger.html
```

**Успешный ответ — `200 OK`** (свежие данные из Checko):

```json
{
  "company": {
    "source": "checko",
    "inn": "8601023568",
    "name": "АО \"ГСК \"ЮГОРИЯ\"",
    "ogrn": "1048600005728",
    "address": "628011, Ханты-Мансийский автономный округ - Югра, г. Ханты-Мансийск, ул. Комсомольская, зд. 61",
    "status": "Действует",
    "director": "Языков Андрей Дмитриевич"
  },
  "cached": false
}
```

**Поле `cached`:**

| Значение | Смысл |
|----------|-------|
| `false` | Данные только что получены от Checko API и сохранены в PostgreSQL |
| `true` | Данные взяты из кэша PostgreSQL (повторный запрос в пределах TTL = 1 часа) |

**Ответы с ошибками:**

| Код | Когда | Пример тела |
|-----|-------|-------------|
| `400` | Некорректный формат ИНН | `{"error": "Invalid INN format", "inn": "123"}` |
| `401` | Неверный/отсутствующий `X-API-KEY` (или отклонён ключ Checko) | `{"error": "Unauthorized"}` |
| `404` | Компания не найдена в Checko | `{"error": "..."}` |
| `500` | Внутренняя ошибка (сбой БД) | `{"error": "Internal server error"}` |
| `502` | Checko API недоступен (таймаут, 5xx, битый JSON) | `{"error": "Checko API is unreachable: ..."}` |

### 🌐 Особенности интеграции с Checko API v2

Официальная документация: `https://api.checko.ru/v2/company?key=API_KEY&inn=COMPANY_INN`

1. **Guzzle `base_uri`**: `https://api.checko.ru/v2/` + относительный путь `'company'` — **без ведущего `/`**, иначе Guzzle отбросит префикс `/v2`.
2. **Query-параметры**: `key` (API-ключ Checko) и `inn` — именно так, как в документации.
3. **Русские ключи в ответе**: Checko v2 возвращает JSON с русскими именами полей, завёрнутыми в ключ `data` (не `company`):

   | Поле Checko | Поле DTO |
   |-------------|----------|
   | `НаимСокр` | `name` |
   | `ОГРН` | `ogrn` |
   | `ЮрАдрес.АдресРФ` | `address` |
   | `Статус.Наим` | `status` |
   | `Руковод[0].ФИО` (массив — берём первого) | `director` |

4. **Mock-режим**: `CHECKO_USE_MOCK=true` включает заглушку без реальных сетевых запросов (для разработки и тестов).
5. **Заголовки**: отправляется браузерный `User-Agent` для обхода WAF на стороне Checko.

### 🗄 Кэширование в PostgreSQL

Таблица `company_cache` (создаётся автоматически при первом старте контейнера `postgresql` из `database/schema.sql`):

```sql
CREATE TABLE IF NOT EXISTS company_cache (
    inn        VARCHAR(12) PRIMARY KEY,
    data       JSONB       NOT NULL,
    cached_at  TIMESTAMP   NOT NULL DEFAULT now()
);
```

Логика `CompanyService` (Cache-Aside, TTL = 1 час):

1. `SELECT data, cached_at FROM company_cache WHERE inn = :inn` — проверка свежего кэша;
2. запись есть и моложе TTL → вернуть с `cached: true`;
3. промах → запрос к Checko API через `CheckoApiClient`;
4. `INSERT ... ON CONFLICT (inn) DO UPDATE` (UPSERT) — атомарное сохранение;
5. вернуть с `cached: false`.

---

## 🧪 Тестирование

### Mock-режим (без реального ключа Checko)

По умолчанию `CHECKO_USE_MOCK=true` в `docker-compose.yml` — запросы к Checko не уходят, возвращается заглушка:

```bash
curl -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/7707083893
# => {"company":{"source":"mock","inn":"7707083893","name":"ПАО «Заглушка»",...},"cached":false}
```

### Проверка кэширования (два запроса → `cached: true`)

```bash
# Первый запрос — из API (или mock), кладём в кэш
curl -s -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/7707083893 | grep -o '"cached":[a-z]*'
# => "cached":false

# Второй запрос (в пределах TTL 1 часа) — берём из PostgreSQL
curl -s -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/7707083893 | grep -o '"cached":[a-z]*'
# => "cached":true
```

### Проверка содержимого кэша и очистка

```bash
# Посмотреть записи кэша
docker exec -it checko_postgres psql -U app -d app \
  -c "SELECT inn, cached_at FROM company_cache;"

# Очистить кэш (следующий запрос снова вернёт cached: false)
docker exec -it checko_postgres psql -U app -d app -c "DELETE FROM company_cache;"
```

### Проверка авторизации

```bash
# Без ключа => 401 Unauthorized
curl -i http://localhost:8080/api/company/7707083893

# Если APP_API_KEY не задан (пусто) — авторизация отключена (dev-режим), пустой запрос пройдёт
```

### Регенерация Swagger-спецификации

После изменения OA-атрибутов в контроллере:

```bash
docker exec -it checko_php php bin/generate_swagger.php
# => OpenAPI spec written to /app/public/openapi.json
```

---

## 🐛 Troubleshooting

| Симптом | Причина и решение |
|---------|-------------------|
| `Bind for 0.0.0.0:8080 failed: port is already allocated` | Порт 8080 занят другим процессом → измените маппинг в `docker-compose.yml` (секция `ports: - "8081:8080"` сервиса `php`) |
| `PDOException: could not connect to server` | `DB_HOST` должен быть равен имени сервиса **`postgresql`**, а не `localhost` (контейнеры общаются через Docker-сеть по именам сервисов). Также дождитесь готовности БД: `docker logs checko_postgres` |
| `Class "App\..." not found` | Не установлены зависимости / нет автозагрузчика → выполните `docker exec -it checko_php composer install`, затем `docker exec -it checko_php composer dump-autoload` |
| `Checko API responded with HTTP 404` | Проверьте, что путь в Guzzle **относительный** — `'company'`, а не `'/company'` (ведущий слэш заставляет Guzzle отбросить `/v2` из `base_uri`) |
| Swagger UI пустой / не открывается | Убедитесь, что сгенерирован `public/openapi.json` (`php bin/generate_swagger.php`) и что front controller `public/index.php` содержит блок обслуживания статики (возврат `false` для реальных файлов в режиме `cli-server`) |
| Ответ всегда `cached: false` | Сработал TTL (1 час) или запись не сохраняется — проверьте `docker logs checko_php` на ошибки UPSERT и содержимое таблицы `company_cache` |
| Ошибки DNS при обращении к api.checko.ru | В `docker-compose.yml` сервиса `php` уже заданы `dns: [8.8.8.8, 1.1.1.1]`; проверьте сетевой доступ хоста |

---

## 🛠 Технологии

| Технология | Версия | Назначение |
|------------|--------|-----------|
| PHP | 8.2 (`php:8.2-cli-alpine`) | Язык; strict types, readonly-классы, enum-подобные константы, атрибуты |
| Composer | 2.x | Зависимости и PSR-4 автозагрузка (`App\` → `src/`) |
| PostgreSQL | 16 (`postgres:16-alpine`) | Хранилище кэша (JSONB, UPSERT `ON CONFLICT`) |
| Guzzle | `^7.8` | HTTP-клиент для Checko API (аналог `HttpClient`/`IHttpClientFactory`) |
| zircote/swagger-php | `^6.18` | Генерация OpenAPI 3 из атрибутов `#[OA\...]` (аналог Swashbuckle) |
| Docker / Docker Compose | v2 | Окружение: `checko_php` + `checko_postgres` |
| PDO (`pdo_pgsql`) | встроенное | Доступ к PostgreSQL с prepared statements |
| Swagger UI | CDN | Интерактивная документация (`public/swagger.html`) |

---

## 📄 Лицензия

MIT — код опубликован в рамках тестового задания. Ключи API Checko являются конфиденциальными и в репозиторий не коммитятся (`.env` исключён из Git).
