<?php

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/http.php';
require __DIR__ . '/../../app/orders.php';

require_method('POST');
if (!can_use_private_routes()) {
    json_response(['message' => 'Open the secure HTTPS shop to place an order.'], 403);
}

$payload = json_request();
$requestKey = $payload['requestKey'] ?? null;
if (!is_string($requestKey)) {
    json_response(['message' => 'Checkout session is missing. Please reopen checkout.'], 400);
}

try {
    $connection = db();
    if (!checkout_enabled($connection)) {
        json_response(['message' => 'Online orders are currently paused.'], 503);
    }
    json_response(create_order($connection, $payload, $requestKey));
} catch (OrderException $error) {
    json_response(['message' => $error->getMessage()], $error->getCode());
} catch (Throwable $error) {
    log_app_error('checkout', $error);
    json_response(['message' => 'We could not complete your request. Please retry with the same checkout details.'], 503);
}
