<?php

declare(strict_types=1);

final class OrderException extends RuntimeException
{
}

/** @return array{reference: string, total: int} */
function create_order(PDO $db, array $input, string $requestKey): array
{
    $requestKey = strtolower($requestKey);
    if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $requestKey)) {
        throw new OrderException('A checkout request ID is required.', 422);
    }

    $order = normalize_order_input($input);
    $requestHash = hash('sha256', json_encode($order, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $reference = 'IBJ-' . strtoupper(bin2hex(random_bytes(8)));

    $db->beginTransaction();
    try {
        // Claim the request first. A simultaneous retry waits on this unique key
        // and can return the original order after it commits.
        $insertOrder = $db->prepare(
            'INSERT INTO orders
                (reference, request_key, request_hash, customer_name, phone, email, address, city,
                 fulfillment, delivery_zone, coupon, gift_message, subtotal, discount, wrapping,
                 shipping, total, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, \'pending\')'
        );
        try {
            $insertOrder->execute([
                $reference, $requestKey, $requestHash, $order['name'], $order['phone'],
                $order['email'], $order['address'], $order['city'], $order['fulfillment'],
                $order['zone'], $order['coupon'], $order['giftMessage'],
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $db->rollBack();
            $priorOrder = $db->prepare('SELECT reference, request_hash, total FROM orders WHERE request_key = ?');
            $priorOrder->execute([$requestKey]);
            $previous = $priorOrder->fetch(PDO::FETCH_ASSOC);
            if (!$previous) {
                throw $exception;
            }
            if (!hash_equals($previous['request_hash'], $requestHash)) {
                throw new OrderException('This checkout ID was already used. Please start a new checkout.', 409);
            }
            return ['reference' => $previous['reference'], 'total' => (int) $previous['total']];
        }

        $orderId = $db->lastInsertId();
        $lines = lock_order_variants($db, $order['items']);
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line['price'] * $line['quantity'];
        }
        $discount = 0;
        $wrapping = $order['giftWrap'] ? 100 : 0;
        $shipping = 0;
        if ($subtotal - $discount < 2500) {
            $shipping = $order['zone'] === 'dhaka' ? 80 : 120;
        }
        $total = $subtotal - $discount + $wrapping + $shipping;
        if ($subtotal > 4294967295 || $total > 4294967295) {
            throw new OrderException('This order total is too high. Please reduce the quantity.', 422);
        }
        if ($order['expectedTotal'] !== null && $total !== $order['expectedTotal']) {
            throw new OrderException('Your order total has changed. Refresh the shop and review your bag before trying again.', 409);
        }

        $insertItem = $db->prepare(
            'INSERT INTO order_items (order_id, variant_id, product_name, size, unit_price, quantity)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $reserveStock = $db->prepare('UPDATE variants SET stock = stock - ? WHERE id = ?');
        foreach ($lines as $line) {
            $insertItem->execute([
                $orderId, $line['variantId'], $line['name'], $line['size'], $line['price'], $line['quantity'],
            ]);
            $reserveStock->execute([$line['quantity'], $line['variantId']]);
        }
        $setTotals = $db->prepare('UPDATE orders SET subtotal = ?, discount = ?, wrapping = ?, shipping = ?, total = ? WHERE id = ?');
        $setTotals->execute([$subtotal, $discount, $wrapping, $shipping, $total, $orderId]);
        $db->commit();
        return ['reference' => $reference, 'total' => $total];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function normalize_order_input(array $input): array
{
    $name = order_text($input['name'] ?? null, 'full name', 100, required: true);
    $phone = order_text($input['phone'] ?? null, 'phone number', 25, required: true);
    $phone = strtr($phone, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
        '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    $phone = str_replace([' ', '-', '(', ')'], '', $phone);
    if (!preg_match('/\A\+?[0-9]{7,15}\z/', $phone)) {
        throw new OrderException('Enter a valid phone number.', 422);
    }

    $email = order_text($input['email'] ?? null, 'email address', 150);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new OrderException('Enter a valid email address.', 422);
    }

    if (array_key_exists('fulfillment', $input) && $input['fulfillment'] !== 'delivery') {
        throw new OrderException('Only cash on delivery is available.', 422);
    }
    $address = order_text($input['address'] ?? null, 'delivery address', 500, required: true, multiline: true);
    $city = order_text($input['city'] ?? null, 'city or area', 100, required: true);
    $zone = $input['zone'] ?? null;
    if (!in_array($zone, ['dhaka', 'outside'], true)) {
        throw new OrderException('Choose your delivery area.', 422);
    }

    $coupon = strtoupper(order_text($input['coupon'] ?? null, 'promo code', 16));
    if ($coupon !== '') {
        throw new OrderException('That promo code is unavailable.', 422);
    }

    $giftWrap = $input['giftWrap'] ?? false;
    if (!is_bool($giftWrap)) {
        throw new OrderException('Choose a valid gift wrap option.', 422);
    }
    $giftMessage = $giftWrap ? order_text($input['giftMessage'] ?? null, 'gift message', 160, multiline: true) : '';

    $expectedTotal = $input['expectedTotal'] ?? null;
    if ($expectedTotal !== null && (!is_int($expectedTotal) || $expectedTotal < 0 || $expectedTotal > 4294967295)) {
        throw new OrderException('Your order total is invalid. Refresh your bag and try again.', 422);
    }

    $rawItems = $input['items'] ?? null;
    if (!is_array($rawItems) || !array_is_list($rawItems) || !$rawItems || count($rawItems) > 100) {
        throw new OrderException('Add items to your bag before checkout. An order can contain up to 100 sizes.', 422);
    }

    $items = [];
    foreach ($rawItems as $item) {
        if (!is_array($item)) {
            throw new OrderException('An item in your bag is invalid.', 422);
        }
        $productId = $item['productId'] ?? null;
        $quantity = $item['quantity'] ?? null;
        if (!is_string($productId) || !preg_match('/\A[a-z0-9][a-z0-9-]{0,39}\z/', $productId)
            || !is_int($quantity) || $quantity < 1 || $quantity > 99) {
            throw new OrderException('An item in your bag is invalid.', 422);
        }

        $size = order_text($item['size'] ?? null, 'size', 24, required: true);
        $key = $productId . "\0" . $size;
        $quantity += $items[$key]['quantity'] ?? 0;
        if ($quantity > 99) {
            throw new OrderException('An order can contain at most 99 pieces of each size.', 422);
        }
        $items[$key] = ['productId' => $productId, 'size' => $size, 'quantity' => $quantity];
    }
    ksort($items, SORT_STRING);

    return [
        'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'city' => $city,
        'fulfillment' => 'delivery', 'zone' => $zone, 'coupon' => $coupon, 'giftWrap' => $giftWrap,
        'giftMessage' => $giftMessage, 'expectedTotal' => $expectedTotal, 'items' => array_values($items),
    ];
}

function order_text(mixed $raw, string $label, int $limit, bool $required = false, bool $multiline = false): string
{
    if ($raw === null && !$required) {
        return '';
    }
    if (!is_string($raw) || strlen($raw) > $limit * 4 + 128) {
        throw new OrderException("Enter a valid {$label} (up to {$limit} characters).", 422);
    }
    $value = $multiline ? str_replace(["\r\n", "\r"], "\n", $raw) : $raw;
    $value = trim($value, " \t\r\n");
    $length = preg_match_all('/./us', $value);
    $controls = $multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    if ($length === false || $length > $limit || preg_match($controls, $value)
        || ($required && preg_match('/\A\s*\z/u', $value))) {
        throw new OrderException("Enter a valid {$label} (up to {$limit} characters).", 422);
    }
    return $value;
}

function lock_order_variants(PDO $db, array $items): array
{
    $getProduct = $db->prepare('SELECT name, price, active, image FROM products WHERE id = ? FOR UPDATE');
    $getVariant = $db->prepare('SELECT id, size, stock FROM variants WHERE product_id = ? AND size = ? FOR UPDATE');
    $lines = [];
    $productId = null;
    $product = null;
    // Checkout and cancellation lock variant rows in product and size order.
    foreach ($items as $item) {
        if ($item['productId'] !== $productId) {
            $productId = $item['productId'];
            $getProduct->execute([$productId]);
            $product = $getProduct->fetch(PDO::FETCH_ASSOC);
            if (!$product || (int) $product['active'] !== 1 || !store_photo_exists($product['image'])) {
                throw new OrderException('A piece in your bag is no longer available.', 409);
            }
        }

        $getVariant->execute([$productId, $item['size']]);
        $variant = $getVariant->fetch(PDO::FETCH_ASSOC);
        if (!$variant || $variant['size'] !== $item['size']) {
            throw new OrderException('A size in your bag is no longer available.', 409);
        }
        if ((int) $variant['stock'] < $item['quantity']) {
            throw new OrderException("Only {$variant['stock']} left for {$product['name']} ({$variant['size']}). Update your bag and try again.", 409);
        }

        $lines[] = [
            'variantId' => $variant['id'], 'name' => $product['name'], 'size' => $variant['size'],
            'price' => (int) $product['price'], 'quantity' => $item['quantity'],
        ];
    }
    return $lines;
}

function order_status_options(string $status, string $fulfillment): array
{
    return match ($status) {
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => [$fulfillment === 'pickup' ? 'completed' : 'shipped', 'cancelled'],
        'shipped' => ['completed'],
        default => [],
    };
}

function update_order_status(PDO $db, int $orderId, string $newStatus, ?string $expectedStatus = null): void
{
    $db->beginTransaction();
    try {
        $findOrder = $db->prepare('SELECT status, fulfillment FROM orders WHERE id = ? FOR UPDATE');
        $findOrder->execute([$orderId]);
        $order = $findOrder->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new OrderException('Order not found.', 404);
        }
        $currentStatus = $order['status'];
        if ($currentStatus === $newStatus) {
            $db->commit();
            return;
        }
        if ($expectedStatus !== null && $currentStatus !== $expectedStatus) {
            throw new OrderException('This order status has changed. Reload the order and try again.', 409);
        }
        if (!in_array($newStatus, order_status_options($currentStatus, $order['fulfillment']), true)) {
            throw new OrderException('That change is unavailable for the current order status.', 409);
        }
        if ($newStatus === 'cancelled') {
            $findItems = $db->prepare(
                'SELECT i.variant_id, i.quantity FROM order_items i JOIN variants v ON v.id = i.variant_id
                 WHERE i.order_id = ? ORDER BY BINARY v.product_id, BINARY v.size'
            );
            $findItems->execute([$orderId]);
            $items = $findItems->fetchAll(PDO::FETCH_ASSOC);
            $returnStock = $db->prepare('UPDATE variants SET stock = stock + ? WHERE id = ?');
            foreach ($items as $item) {
                $returnStock->execute([$item['quantity'], $item['variant_id']]);
            }
        }
        $setStatus = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $setStatus->execute([$newStatus, $orderId]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}
