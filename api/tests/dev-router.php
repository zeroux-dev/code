<?php
// Local dev: php -S localhost:8100 api/tests/dev-router.php  (from the project root)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) { require __DIR__ . '/../index.php'; return true; }
$file = realpath(__DIR__ . '/../..' . $path);
if ($path !== '/' && $file && is_file($file) && str_starts_with($file, realpath(__DIR__ . '/../..'))) return false;
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/../../index.html');
