<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\DatabaseException;
use App\Exceptions\ExternalApiException;
use App\Services\CompanyService;
use OpenApi\Attributes as OA;

/**
 * Контроллер компаний.
 *
 * Методы принимают параметры маршрута как именованные аргументы
 * (роутер использует оператор распаковки ...$params).
 * Ошибки внешнего API ловятся здесь и превращаются в корректные HTTP-статусы.
 */
#[OA\Info(
    title: 'Checko PHP API',
    version: '1.0.0',
    description: 'Данные компаний по ИНН (внешний API Checko + PostgreSQL-кэш)',
)]
#[OA\Server(url: '/', description: 'Текущий сервер')]
#[OA\SecurityScheme(
    securityScheme: 'ApiKeyAuth',
    type: 'apiKey',
    in: 'header',
    name: 'X-API-KEY',
)]
final class CompanyController
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {
    }

    #[OA\Get(
        path: '/api/company/{inn}',
        summary: 'Получить данные компании по ИНН',
        operationId: 'getCompanyByInn',
        tags: ['Companies'],
        parameters: [
            new OA\Parameter(
                name: 'inn',
                in: 'path',
                required: true,
                description: 'ИНН юридического лица (10 цифр) или ИП (12 цифр)',
                schema: new OA\Schema(type: 'string', pattern: '^\d{10}(\d{2})?$', example: '7707083893'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Успешный ответ (данные из кэша БД или свежего запроса к Checko)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'cached', type: 'boolean', description: 'true — если данные взяты из кэша PostgreSQL'),
                        new OA\Property(property: 'company', type: 'object', description: 'Данные компании', additionalProperties: true),
                    ]
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Некорректный запрос (например, неверный формат ИНН)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string')]),
            ),
            new OA\Response(
                response: 401,
                description: 'Ошибка аутентификации (неверный X-API-KEY или ключ Checko отклонён)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string')]),
            ),
            new OA\Response(
                response: 404,
                description: 'Компания не найдена или маршрут не существует',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string')]),
            ),
            new OA\Response(
                response: 500,
                description: 'Внутренняя ошибка сервера (сбой БД или недоступность внешнего API)',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'error', type: 'string')]),
            ),
        ],
    )]
    /**
     * GET /api/company/{inn}
     *
     * параметр $inn передаётся как именованный аргумент благодаря ...$params в роутере.
     *
     * @param string $inn ИНН компании (10 или 12 цифр)
     * @return array<string, mixed>
     */
    public function getInfo(string $inn): array
    {
        $inn = trim($inn);
        
        if ($inn === '') {
            http_response_code(400);
            return ['error' => 'INN is required'];
        }
        
        if (!preg_match('/^\d{10}(\d{2})?$/', $inn)) {
            http_response_code(400);
            return ['error' => 'Invalid INN format', 'inn' => $inn];
        }

        try {
            return $this->companyService->getCompanyInfo($inn);
        } catch (ExternalApiException $e) {
            http_response_code($e->getClientStatus());
            return ['error' => $e->getMessage()];
        } catch (DatabaseException $e) {
            http_response_code(500);
            error_log('DB error: ' . $e->getMessage() . ' | ' . (string) $e->getPrevious()?->getMessage());
            return ['error' => 'Internal server error'];
        }
    }
}