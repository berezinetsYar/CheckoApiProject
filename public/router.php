<?php
// Front-controller для php -S: статика отдаётся напрямую, остальное — в index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // встроенный сервер отдаст файл сам
}

require __DIR__ . '/index.php';
