<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$name = $_GET['f'] ?? '';
if (!is_string($name) || !preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp)\z/', $name, $match)) {
    http_response_code(404);
    exit;
}

try {
    $path = store_media_directory() . '/' . $name;
    $image = @fopen($path, 'rb');
} catch (Throwable $error) {
    log_app_error('media', $error);
    $image = false;
}
if ($image === false) {
    http_response_code(404);
    exit;
}

$mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $mimeTypes[$match[1]]);
header('Content-Length: ' . fstat($image)['size']);
header('Cache-Control: public, max-age=86400, immutable');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");

if ($method === 'GET') {
    fpassthru($image);
}
fclose($image);
