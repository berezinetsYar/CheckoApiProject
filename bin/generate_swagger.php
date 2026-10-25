<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

// Пути для сканирования OA-атрибутов
$paths = [
    __DIR__ . '/../src/Controllers',
];

// Основной точкой входа теперь является нестатический метод generate().
$openapi = (new Generator())->generate($paths);

// Базовая проверка результата: пустой объект или отсутствие путей — ошибка.
// Используем empty() вместо count(), так как $openapi->paths может быть объектом или массивом.
if ($openapi === null || empty($openapi->paths)) {
    fwrite(STDERR, "❌ OpenAPI spec is empty or contains no paths.\n");
    fwrite(STDERR, "💡 Проверьте, что в src/Controllers есть атрибуты #[OA\\Get], #[OA\\Info] и т.д.\n");
    exit(1);
}

// Запись в public/openapi.json
$outputPath = __DIR__ . '/../public/openapi.json';
file_put_contents($outputPath, $openapi->toJson());

echo "✅ OpenAPI spec written to {$outputPath}\n";
echo "📏 Size: " . filesize($outputPath) . " bytes\n";
