<?php

declare(strict_types=1);

function json_response(array $payload, int $status = 200): never
{
    no_cache();
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        json_response(['message' => 'This method is unavailable.'], 405);
    }
}

function json_request(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        json_response(['message' => 'Send checkout details as JSON.'], 415);
    }

    $limit = 16384;
    $body = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($body === false || strlen($body) > $limit) {
        json_response(['message' => 'Checkout details are too long.'], 413);
    }

    try {
        $payload = json_decode($body, true, 12, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['message' => 'Checkout details could not be read.'], 400);
    }

    if (!is_array($payload) || array_is_list($payload)) {
        json_response(['message' => 'Checkout details are missing.'], 400);
    }

    return $payload;
}
