<?php

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/http.php';

require_method('GET');

try {
    $connection = db();
    $rows = $connection->query(
        'SELECT p.id, p.name, p.category, p.finish, p.finish_label, p.price, p.compare_price,
                p.image, p.tag, p.note, p.description, p.details, v.size, v.stock
         FROM products p
         JOIN variants v ON v.product_id = p.id
         WHERE p.active = 1
         ORDER BY p.sort_order, p.id, v.id'
    );
    $products = [];
    foreach ($rows as $row) {
        // Only show products with photos uploaded through the current catalogue.
        if (!store_photo_exists($row['image'])) {
            continue;
        }
        $id = $row['id'];
        $products[$id] ??= [
            'id' => $id,
            'name' => $row['name'],
            'category' => $row['category'],
            'finish' => $row['finish'],
            'finishLabel' => $row['finish_label'],
            'price' => (int) $row['price'],
            'comparePrice' => (int) $row['compare_price'],
            'image' => $row['image'],
            'tag' => $row['tag'],
            'note' => $row['note'],
            'description' => $row['description'],
            'details' => $row['details'],
            'sizes' => [],
            'stock' => [],
        ];
        $products[$id]['sizes'][] = $row['size'];
        $products[$id]['stock'][$row['size']] = (int) $row['stock'];
    }
    foreach ($products as &$product) {
        $product['stock'] = (object) $product['stock'];
    }
    unset($product);

    json_response([
        'products' => array_values($products),
        'checkoutEnabled' => can_use_private_routes() && checkout_enabled($connection),
    ]);
} catch (Throwable $error) {
    log_app_error('catalogue', $error);
    json_response(['message' => 'The catalogue is temporarily unavailable. Please try again shortly.'], 503);
}
