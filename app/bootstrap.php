<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');
ini_set('display_errors', '0');

function config(): array
{
    static $settings;
    if ($settings !== null) {
        return $settings;
    }

    $path = __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Store configuration is missing.');
    }

    $settings = require $path;
    return $settings;
}

function db(): PDO
{
    static $connection;
    if ($connection !== null) {
        return $connection;
    }

    $settings = config()['database'];
    $socket = $settings['socket'] ?? '';
    $location = $socket !== ''
        ? 'unix_socket=' . $socket
        : 'host=' . $settings['host'] . ';port=' . $settings['port'];
    $dsn = 'mysql:' . $location . ';dbname=' . $settings['name'] . ';charset=utf8mb4';
    $connection = new PDO($dsn, $settings['user'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET time_zone = '+06:00'");
    return $connection;
}

function e(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function no_cache(): void
{
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
}

function is_https(): bool
{
    return in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function can_use_private_routes(): bool
{
    return is_https() || (PHP_SAPI === 'cli-server'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));
}

function checkout_enabled(PDO $connection): bool
{
    return (config()['checkout_enabled'] ?? false) === true
        && $connection->query('SELECT 1 FROM admins LIMIT 1')->fetchColumn() !== false;
}

function log_app_error(string $area, Throwable $error): void
{
    error_log('Ibadah Jewellers ' . $area . ': ' . $error::class . ' (' . $error->getCode() . ')');
}
