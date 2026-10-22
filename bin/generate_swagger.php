#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Генерация OpenAPI-спецификации из аннотаций/атрибутов zircote/swagger-php.
 *
 * Сканирует src/ и сохраняет результат в public/openapi.json.
 * Запуск:  php bin/generate_swagger.php   (или composer swagger)
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

$srcDir    = dirname(__DIR__) . '/src';
$outFile   = dirname(__DIR__) . '/public/openapi.json';

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Source directory not found: {$srcDir}\n");
    exit(1);
}

try {
    $openapi = Generator::scan([$srcDir]);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Swagger generation failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$json = $openapi->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (file_put_contents($outFile, $json . PHP_EOL) === false) {
    fwrite(STDERR, "Failed to write {$outFile}\n");
    exit(1);
}

printf("OpenAPI spec written to %s (%d bytes)\n", $outFile, strlen($json));
