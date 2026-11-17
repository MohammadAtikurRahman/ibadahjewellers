CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_limits (
    subject CHAR(64) NOT NULL PRIMARY KEY,
    failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    reset_at DATETIME NOT NULL,
    INDEX (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category ENUM('Earrings', 'Necklaces', 'Rings', 'Bracelets') NOT NULL,
    finish ENUM('gold', 'silver', 'rose') NOT NULL,
    finish_label VARCHAR(100) NOT NULL,
    price INT UNSIGNED NOT NULL,
    compare_price INT UNSIGNED NOT NULL DEFAULT 0,
    image VARCHAR(150) NOT NULL,
    tag VARCHAR(100) NOT NULL,
    note VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    details TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT UNSIGNED NOT NULL DEFAULT 1,
    INDEX (active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS variants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(40) NOT NULL,
    size VARCHAR(24) NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY product_size (product_id, size),
    FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL UNIQUE,
    request_key CHAR(36) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    email VARCHAR(150) NOT NULL DEFAULT '',
    address VARCHAR(500) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    fulfillment ENUM('delivery', 'pickup') NOT NULL,
    delivery_zone VARCHAR(12) NOT NULL DEFAULT '',
    coupon VARCHAR(20) NOT NULL DEFAULT '',
    gift_message VARCHAR(160) NOT NULL DEFAULT '',
    subtotal INT UNSIGNED NOT NULL,
    discount INT UNSIGNED NOT NULL,
    wrapping INT UNSIGNED NOT NULL,
    shipping INT UNSIGNED NOT NULL,
    total INT UNSIGNED NOT NULL,
    status ENUM('pending', 'confirmed', 'shipped', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    variant_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(120) NOT NULL,
    size VARCHAR(24) NOT NULL,
    unit_price INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    UNIQUE KEY order_variant (order_id, variant_id),
    FOREIGN KEY (order_id) REFERENCES orders (id),
    FOREIGN KEY (variant_id) REFERENCES variants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
