<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

// Пути для сканирования OA-атрибутов
$paths = [
    __DIR__ . '/../src/Controllers',
];

// Генерация спецификации
$generator = new Generator();
$openapi = $generator->generate($paths);

// Запись в public/openapi.json
$outputPath = __DIR__ . '/../public/openapi.json';
file_put_contents($outputPath, $openapi->toJson());

echo "✅ OpenAPI spec written to {$outputPath}\n";
echo "📏 Size: " . filesize($outputPath) . " bytes\n";