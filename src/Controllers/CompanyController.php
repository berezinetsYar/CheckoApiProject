<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\DatabaseException;
use App\Exceptions\ExternalApiException;
use App\Services\CompanyService;

/**
 * Контроллер компаний.
 *
 * Для .NET-разработчика: это «обычный» класс, который создаёт Router
 * (аналог контроллера без базового ControllerBase и без встроенного DI —
 * зависимости приходят через конструктор из нашего мини-контейнера).
 */
final class CompanyController
{
    public function __construct(
        private readonly CompanyService $companyService,
    ) {
    }

    /**
     * GET /api/company/{inn}
     *
     * @param array<string, string> $params параметры маршрута, напр. ['inn' => '7707083893']
     * @return array<string, mixed> массив сериализуется роутером в JSON
     */
    public function getInfo(array $params): array
    {
        $inn = trim($params['inn'] ?? '');

        if ($inn === '') {
            http_response_code(422);
            return ['error' => 'INN is required'];
        }

        try {
            $result = $this->companyService->getCompanyInfo($inn);
        } catch (ExternalApiException $e) {
            // 401/404 от внешнего API транслируем клиенту как есть,
            // сетевые/5xx ошибки — как 502 Bad Gateway.
            http_response_code($e->getClientStatus());
            return ['error' => $e->getMessage()];
        } catch (DatabaseException $e) {
            // Не раскрываем наружу SQL-детали (сообщения PDOException),
            // пишем их только в лог сервера.
            error_log('[CompanyController] DB error: ' . $e->getMessage());
            http_response_code(500);
            return ['error' => 'Internal server error'];
        }

        return [
            'cached' => $result['cached'],
            'company' => $result['company'],
        ];
    }
}