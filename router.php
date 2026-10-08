<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}
$relative = $path === '/' ? '/index.html' : $path;
$file = realpath(__DIR__ . $relative);
if ($file === false || !str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR) || str_contains($file, DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR) || basename($file)[0] === '.') {
    http_response_code(404);
    echo 'No encontrado.';
    return true;
}
if (is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['html', 'css', 'js', 'map'], true)) {
    return false;
}
http_response_code(404);
echo 'No encontrado.';

