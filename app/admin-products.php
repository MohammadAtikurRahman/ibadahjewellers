<?php

declare(strict_types=1);

const ADMIN_PRODUCT_CATEGORIES = ['Earrings', 'Necklaces', 'Rings', 'Bracelets'];
const ADMIN_PRODUCT_FINISHES = ['gold' => 'Gold tone', 'silver' => 'Silver tone', 'rose' => 'Rose-gold tone'];
const ADMIN_PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
const ADMIN_PRODUCT_FIELDS = ['name', 'category', 'finish', 'finish_label', 'price', 'description', 'details', 'active'];

function admin_product_id(string $id): string
{
    if (!preg_match('/\A[a-z0-9][a-z0-9-]{0,39}\z/', $id)) {
        throw new DomainException('Product not found.');
    }
    return $id;
}

function admin_product_text(mixed $input, string $label, int $limit, bool $required = false, bool $multiline = false): string
{
    // Reuse the same Unicode and control-character validation as checkout.
    try {
        return order_text($input, $label, $limit, $required, $multiline);
    } catch (OrderException $error) {
        throw new DomainException($error->getMessage(), 0, $error);
    }
}

function admin_product_fields(array $input): array
{
    $category = $input['category'] ?? null;
    if (!in_array($category, ADMIN_PRODUCT_CATEGORIES, true)) {
        throw new DomainException('Choose a product category.');
    }
    $finish = $input['finish'] ?? null;
    if (!is_string($finish) || !isset(ADMIN_PRODUCT_FINISHES[$finish])) {
        throw new DomainException('Choose a product tone.');
    }
    $finishLabel = admin_product_text($input['finish_label'] ?? '', 'finish description', 100);
    $active = $input['active'] ?? null;
    if (!in_array($active, ['0', '1'], true)) {
        throw new DomainException('Choose a visibility setting.');
    }

    return [
        'name' => admin_product_text($input['name'] ?? null, 'product name', 120, required: true),
        'category' => $category,
        'finish' => $finish,
        'finish_label' => $finishLabel === '' ? ADMIN_PRODUCT_FINISHES[$finish] : $finishLabel,
        'price' => admin_product_integer($input['price'] ?? null, 'price', 1, 10000000),
        'description' => admin_product_text($input['description'] ?? null, 'description', 2000, required: true, multiline: true),
        'details' => admin_product_text($input['details'] ?? '', 'product details', 2000, multiline: true),
        'active' => (int) $active,
    ];
}

function admin_product_integer(mixed $raw, string $label, int $min, int $max): int
{
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($value === false) {
        throw new DomainException('Enter a whole number from ' . $min . ' to ' . $max . ' for ' . $label . '.');
    }
    return $value;
}

function admin_product_variants(mixed $sizes, mixed $stocks): array
{
    if (!is_array($sizes) || !array_is_list($sizes) || !is_array($stocks) || !array_is_list($stocks)
        || count($sizes) !== count($stocks) || count($sizes) > 3) {
        throw new DomainException('Enter each size with a starting stock count.');
    }
    $variants = [];
    foreach ($sizes as $index => $size) {
        $stock = $stocks[$index];
        if ($index > 0 && $size === '' && $stock === '') {
            continue;
        }
        $variants[] = [
            admin_product_text($size, 'size', 24, required: true),
            admin_product_integer($stock, 'stock', 0, 1000000),
        ];
    }
    if (!$variants) {
        throw new DomainException('Enter at least one size and its starting stock.');
    }
    return $variants;
}

function admin_product_photo_name(string $name): bool
{
    return preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $name) === 1;
}

function admin_save_product_photo(mixed $file, bool $required): ?string
{
    if ($file === null || (is_array($file) && ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE)) {
        if ($required) {
            throw new DomainException('Upload a product photo.');
        }
        return null;
    }

    if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
        || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('The photo could not be uploaded. Choose a JPG, PNG or WebP file up to 5 MB.');
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size === 0 || $size > 5 * 1024 * 1024) {
        throw new DomainException('Upload a photo up to 5 MB.');
    }

    // Infer the output format from the image bytes, never the original filename or client MIME header.
    $image = @getimagesize($file['tmp_name']);
    if ($image === false || !isset(ADMIN_PHOTO_TYPES[$image[2]]) || $image[0] < 1 || $image[1] < 1
        || $image[0] > 8192 || $image[1] > 8192 || $image[0] * $image[1] > 32_000_000) {
        throw new DomainException('Upload a JPG, PNG or WebP photo up to 8,192 pixels per side and 32 megapixels.');
    }

    $directory = store_media_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the product photo directory.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . ADMIN_PHOTO_TYPES[$image[2]];
    $path = $directory . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        throw new RuntimeException('Could not store the product photo.');
    }
    if (!chmod($path, 0600)) {
        @unlink($path);
        throw new RuntimeException('Could not protect the product photo.');
    }
    return $name;
}

function admin_remove_product_photo(?string $name): void
{
    if ($name !== null && admin_product_photo_name($name)) {
        @unlink(store_media_directory() . '/' . $name);
    }
}

function admin_read_product(PDO $db, string $id, bool $lock = false): array|false
{
    $query = $db->prepare('SELECT id, name, category, finish, finish_label, price, image, description, details, active
        FROM products WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    return $query->fetch();
}

function admin_product_version(array $product): string
{
    $fields = array_map(static fn(string $key): string => (string) $product[$key], [...ADMIN_PRODUCT_FIELDS, 'image']);
    return hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
}

function admin_insert_product_variant(PDOStatement $insert, string $product, string $size, int $stock): void
{
    try {
        $insert->execute([$product, $size, $stock]);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
            throw new DomainException('Each size must have a different name.', 0, $error);
        }
        throw $error;
    }
}

function admin_create_product(PDO $db, array $input, mixed $file): string
{
    $fields = admin_product_fields($input);
    $variants = admin_product_variants($input['starting_size'] ?? null, $input['starting_stock'] ?? null);
    $id = 'p-' . bin2hex(random_bytes(10));
    $image = admin_save_product_photo($file, required: true);

    try {
        $db->beginTransaction();
        $insert = $db->prepare('INSERT INTO products
            (id, name, category, finish, finish_label, price, description, details, active, image, tag, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'\', \'\')');
        $insert->execute([$id, ...array_values($fields), $image]);
        $insertVariant = $db->prepare('INSERT INTO variants (product_id, size, stock) VALUES (?, ?, ?)');
        foreach ($variants as [$size, $stock]) {
            admin_insert_product_variant($insertVariant, $id, $size, $stock);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        admin_remove_product_photo($image);
        throw $error;
    }
    return $id;
}

function admin_update_product_details(PDO $db, string $id, array $input, mixed $file): void
{
    $fields = admin_product_fields($input);
    $version = $input['version'] ?? '';
    if (!is_string($version)) {
        throw new DomainException('Open the product again and try saving.');
    }

    $newImage = admin_save_product_photo($file, required: false);
    try {
        $db->beginTransaction();
        $current = admin_read_product($db, $id, lock: true);
        if (!$current || !hash_equals(admin_product_version($current), $version)) {
            throw new DomainException('This product changed since you opened it. Review the current details and try again.');
        }
        $image = $newImage ?? $current['image'];
        if ($fields['active'] === 1 && (!admin_product_photo_name($image)
            || !is_file(store_media_directory() . '/' . $image))) {
            throw new DomainException('Upload a product photo before making this product visible.');
        }

        $update = $db->prepare('UPDATE products
            SET name = ?, category = ?, finish = ?, finish_label = ?, price = ?, description = ?, details = ?, active = ?, image = ?
            WHERE id = ?');
        $update->execute([...array_values($fields), $image, $id]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        admin_remove_product_photo($newImage);
        throw $error;
    }
    if ($newImage !== null) {
        admin_remove_product_photo($current['image']);
    }
}

function admin_add_product_variant(PDO $db, string $id, array $input): void
{
    $size = admin_product_text($input['size'] ?? null, 'size', 24, required: true);
    $stock = admin_product_integer($input['stock'] ?? null, 'stock', 0, 1000000);
    $insert = $db->prepare('INSERT INTO variants (product_id, size, stock) VALUES (?, ?, ?)');
    try {
        admin_insert_product_variant($insert, $id, $size, $stock);
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) === 1452) {
            throw new DomainException('Product not found.', 0, $error);
        }
        throw $error;
    }
}
