# 🎯 Checko PHP API — данные компаний по ИНН

**REST API на Vanilla PHP 8.2** (без фреймворков, по духу — аналог ASP.NET Core Minimal API) для получения реквизитов российских компаний по ИНН через внешний сервис **Checko API v2** с кэшированием ответов в **PostgreSQL**.

> 🧑‍💻 Проект написан как тестовое задание и ориентирован на .NET-разработчика: в коде и документации везде проводятся аналогии с ASP.NET Core (`Program.cs`, `IServiceProvider`, middleware pipeline, DTO-records, Swashbuckle, xUnit).

### ✨ Ключевые особенности

- ⚡ **Front Controller + мини-DI контейнер** с autowiring — без Laravel/Symfony, только чистый PHP 8.2
- 🔌 **Интеграция с Checko API v2** через Guzzle с нормализацией ответа (русские ключи JSON → типизированный `CompanyDto`)
- 🗄 **Cache-aside** в PostgreSQL 16 (таблица `company_cache`, JSONB, UPSERT)
- 🔐 **AuthMiddleware** — защита по заголовку `X-API-KEY` с `hash_equals()` (защита от timing-attacks)
- 📖 **Swagger/OpenAPI** из атрибутов `#[OA\...]` (zircote/swagger-php) — аналог Swashbuckle
- 🧪 **94 юнит-теста** (PHPUnit 10) с полной изоляцией зависимостей (Guzzle `MockHandler`, PDO mocks)
- 📝 **Профессиональное логирование** через Monolog с автоматической ротацией файлов (`RotatingFileHandler`, аналог Serilog/NLog)
- 🐳 **Docker Compose**: один `up` поднимает PHP-сервер и PostgreSQL со схемой БД

---

## 🏗 Архитектура

### Схема обработки запроса

```text
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
                              
                        [Параллельно: Monolog записывает INFO/WARNING/ERROR в /var/log]
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
| `App\Dto\CompanyDto` | DTO / `record` |
| `App\Exceptions\ExternalApiException` | Custom Exception + ExceptionFilter |
| `zircote/swagger-php` (атрибуты `#[OA\...]`) | `Swashbuckle` / `NSwag` |
| `Monolog\Logger` | `ILogger<T>` / Serilog |
| `PHPUnit` | xUnit / NUnit |
| `public/swagger.html` + `openapi.json` | `app.UseSwaggerUI()` |
| PostgreSQL 16 + таблица `company_cache` | SQL Server + кэш-таблица |
| `docker-compose.yml` (php + postgresql) | `docker-compose.yml` для .NET + SQL |

### Использованные паттерны

- **Front Controller** — все HTTP-запросы проходят через `public/index.php`: там конфигурация из окружения, построение DI-контейнера, подключение middleware и роутера.
- **Dependency Injection** — `App\Container` регистрирует фабрики (`PDO`, `CheckoApiClient`, `Logger`) и разрешает зависимости конструкторов рефлексией (аналог `builder.Services.AddSingleton<T>()` + `GetRequiredService<T>()`).
- **Middleware** — `AuthMiddleware::handle()` вызывается перед диспетчеризацией роута; вернул `false` — обработка запроса остановлена (аналог `app.Use(...)` c `return` без `next(context)`).
- **Cache-Aside** — `CompanyService` сначала читает кэш, при промахе обращается к внешнему API и пишет результат в БД (UPSERT).
- **DTO на границе системы** — «бесформенный» JSON Checko парсится один раз в `CompanyDto` (readonly-класс, аналог C# `record`); дальше по коду ходят только типизированные данные.

---

## 📂 Структура проекта

```text
/workspace/
├── public/
│   ├── index.php              # Точка входа: конфиг, DI, роутинг, статика
│   ├── swagger.html           # Swagger UI (CDN, загружает openapi.json)
│   ├── client.html            # Простой HTML-клиент для ручных проверок
│   ├── router.php             # Роутер для встроенного PHP-сервера (отдача статики)
│   └── openapi.json           # Сгенерированная Swagger-спецификация
├── src/
│   ├── Container.php          # Мини-DI контейнер с autowiring
│   ├── Controllers/
│   │   └── CompanyController.php  # GET /api/company/{inn} + OA-атрибуты + логирование
│   ├── Services/
│   │   └── CompanyService.php     # Логика: кэш → API → UPSERT в кэш
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
├── tests/                     # 🧪 Юнит-тесты (PHPUnit 10)
│   ├── Unit/                  # Модульные тесты (Controllers, Services, HttpClient, Dto)
│   └── Support/               # Тестовые хелперы (FakeHttp, ResponseCapture)
├── var/
│   └── log/                   # 📝 Логи приложения (app-YYYY-MM-DD.log, ротация 7 дней)
├── database/
│   └── schema.sql             # CREATE TABLE company_cache (монтируется в initdb)
├── bin/
│   ├── generate_swagger.php   # Скрипт генерации public/openapi.json из атрибутов
│   └── init.sh                # composer install + dump-autoload --optimize
├── docker-compose.yml         # Сервисы: php (checko_php) + postgresql (checko_postgres)
├── Dockerfile                 # PHP 8.2 CLI Alpine + pdo_pgsql, curl, opcache
├── composer.json              # Зависимости: guzzle, swagger-php, monolog, phpunit
├── phpunit.xml.dist           # Конфигурация тестового фреймворка
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

# 4. Установить PHP-зависимости внутри контейнера (включая dev-зависимости для тестов)
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

---

## 🧪 Тестирование

Проект покрыт **94 юнит-тестами (212 ассертов)**, написанными на PHPUnit 10 с использованием современных атрибутов (`#[Test]`, `#[DataProvider]`).

### Автоматизированное тестирование (Unit Tests)

Тесты полностью изолированы: внешние HTTP-запросы мокаются через Guzzle `MockHandler`, а работа с БД — через моки `PDO`/`PDOStatement`. Реальные сетевые вызовы или обращения к БД не выполняются.

```bash
# Запуск всех тестов
docker exec -it checko_php vendor/bin/phpunit

# Запуск с красивым, читаемым выводом (рекомендуется)
docker exec -it checko_php vendor/bin/phpunit --testdox

# Запуск конкретного тест-класса
docker exec -it checko_php vendor/bin/phpunit tests/Unit/HttpClient/CheckoApiClientTest.php
```

### Ручное тестирование API

**1. Mock-режим (без реального ключа Checko):**
По умолчанию `CHECKO_USE_MOCK=true` — запросы к Checko не уходят, возвращается заглушка:
```bash
curl -H "X-API-KEY: my-super-secret-key-123" http://localhost:8080/api/company/7707083893
# => {"company":{"source":"mock","inn":"7707083893","name":"ООО «Заглушка»",...},"cached":false}
```

**2. Проверка кэширования (два запроса → `cached: true`):**
```bash
# Первый запрос — из API (или mock), кладём в кэш
curl -s -H "X-API-KEY: my-super-secret-key-123" http://localhost:8080/api/company/7707083893 | grep -o '"cached":[a-z]*'
# => "cached":false

# Второй запрос (в пределах TTL) — берём из PostgreSQL
curl -s -H "X-API-KEY: my-super-secret-key-123" http://localhost:8080/api/company/7707083893 | grep -o '"cached":[a-z]*'
# => "cached":true
```

**3. Проверка содержимого кэша и очистка:**
```bash
# Посмотреть записи кэша
docker exec -it checko_postgres psql -U app -d app -c "SELECT inn, cached_at FROM company_cache;"

# Очистить кэш (следующий запрос снова вернёт cached: false)
docker exec -it checko_postgres psql -U app -d app -c "DELETE FROM company_cache;"
```

---

## 📝 Логирование (Observability)

Проект использует **Monolog** (стандарт де-факто в PHP, аналог `Serilog`/`NLog` в .NET) для записи событий приложения.

- **Уровни логирования:** `INFO` (успешные запросы), `WARNING` (ошибки Checko API: 401, 404, 5xx), `ERROR` (сбои БД).
- **Ротация:** Используется `RotatingFileHandler`. Логи автоматически сохраняются в файлы с датой (например, `app-2026-10-24.log`) и хранятся за последние **7 дней**, предотвращая переполнение диска.
- **Расположение:** `/app/var/log/` внутри контейнера.

**Как посмотреть логи:**
```bash
# Просмотр всех файлов логов (с учетом ротации по датам)
docker exec -it checko_php sh -c "cat /app/var/log/app*.log"

# Отслеживание логов в реальном времени (tail)
docker exec -it checko_php tail -f /app/var/log/app*.log
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
     http://localhost:8080/api/company/7707083893

# ИП (12 цифр)
curl -H "X-API-KEY: my-super-secret-key-123" \
     http://localhost:8080/api/company/770000000000
```

**Успешный ответ — `200 OK`** (свежие данные из Checko):

```json
{
  "company": {
    "source": "checko",
    "inn": "7707083893",
    "name": "ООО \"Тестовая Компания\"",
    "ogrn": "1027700132195",
    "address": "123456, г. Москва, ул. Примерная, д. 1, офис 10",
    "status": "Действует",
    "director": "Иванов Иван Иванович"
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

*(Подробности маппинга русских ключей Checko и логики UPSERT см. в исходном коде `CheckoApiClient.php` и `CompanyService.php`)*

---

## 🐛 Troubleshooting

| Симптом | Причина и решение |
|---------|-------------------|
| `Bind for 0.0.0.0:8080 failed: port is already allocated` | Порт 8080 занят → измените маппинг в `docker-compose.yml` (секция `ports: - "8081:8080"` сервиса `php`) |
| `PDOException: could not connect to server` | `DB_HOST` должен быть равен имени сервиса **`postgresql`**, а не `localhost`. Также дождитесь готовности БД: `docker logs checko_postgres` |
| `Class "App\..." not found` | Не установлены зависимости → выполните `docker exec -it checko_php composer install`, затем `composer dump-autoload` |
| `Checko API responded with HTTP 404` | Проверьте, что путь в Guzzle **относительный** — `'company'`, а не `'/company'` (ведущий слэш заставляет Guzzle отбросить `/v2` из `base_uri`) |
| **Странные ответы или ошибки 500** | **Проверьте логи приложения:** `docker exec -it checko_php sh -c "cat /app/var/log/app*.log"` |
| Ошибки DNS при обращении к api.checko.ru | В `docker-compose.yml` сервиса `php` уже заданы `dns: [8.8.8.8, 1.1.1.1]`; проверьте сетевой доступ хоста |

---

## 🛠 Технологии

| Технология | Версия | Назначение |
|------------|--------|-----------|
| PHP | 8.2 (`php:8.2-cli-alpine`) | Язык; strict types, readonly-классы, атрибуты |
| Composer | 2.x | Зависимости и PSR-4 автозагрузка (`App\` → `src/`) |
| PostgreSQL | 16 (`postgres:16-alpine`) | Хранилище кэша (JSONB, UPSERT `ON CONFLICT`) |
| Guzzle | `^7.8` | HTTP-клиент для Checko API (аналог `HttpClient`/`IHttpClientFactory`) |
| zircote/swagger-php | `^4.x` | Генерация OpenAPI 3 из атрибутов `#[OA\...]` |
| **Monolog** | `^3.7` | **Библиотека для логирования с ротацией (аналог Serilog/NLog)** |
| **PHPUnit** | `^10.5` | **Фреймворк для юнит-тестирования (аналог xUnit/NUnit)** |
| Docker / Docker Compose | v2 | Окружение: `checko_php` + `checko_postgres` |

---

## 📄 Лицензия

MIT — код опубликован в рамках тестового задания. Ключи API Checko являются конфиденциальными и в репозиторий не коммитятся (`.env` и `var/log/` исключены из Git через `.gitignore`).
