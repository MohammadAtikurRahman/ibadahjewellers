<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/orders.php';
require_once __DIR__ . '/../../app/admin-products.php';

no_cache();
header('X-LiteSpeed-Cache-Control: no-cache');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if (!can_use_private_routes()) {
    http_response_code(403);
    exit('Use HTTPS to open the admin panel.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ibadah_admin');
session_cache_limiter('');
session_set_cookie_params([
    'path' => '/mehedi/',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (!session_start()) {
    http_response_code(503);
    exit('The admin panel is temporarily unavailable.');
}

const ADMIN_STATUSES = [
    'pending' => 'Pending',
    'confirmed' => 'Confirmed',
    'shipped' => 'Shipped',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];
const ADMIN_PAGE_SIZE = 25;

function admin_input(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

function admin_number(string $value, int $min = 1, int $max = PHP_INT_MAX): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($parsed === false) {
        throw new DomainException('Enter a valid number and try again.');
    }
    return $parsed;
}

function admin_token_field(): string
{
    return '<input type="hidden" name="token" value="' . e($_SESSION['csrf']) . '">';
}

function admin_redirect(string $target = './', string $message = '', bool $error = false): never
{
    if ($message !== '') {
        $_SESSION['notice'] = [$message, $error];
    }
    header('Location: ' . $target, true, 303);
    exit;
}

function admin_reset_session(): void
{
    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Could not regenerate the admin session.');
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function admin_user(PDO $db): ?array
{
    if (!isset($_SESSION['admin_id'], $_SESSION['admin_version'], $_SESSION['admin_seen'])) {
        return null;
    }
    if (time() - $_SESSION['admin_seen'] > 1800) {
        admin_reset_session();
        return null;
    }

    $query = $db->prepare('SELECT id, username, password_hash, session_version FROM admins WHERE id = ?');
    $query->execute([$_SESSION['admin_id']]);
    $account = $query->fetch();
    if (!$account || (int) $account['session_version'] !== $_SESSION['admin_version']) {
        admin_reset_session();
        return null;
    }

    $_SESSION['admin_seen'] = time();
    return $account;
}

function admin_reserve_attempt(PDO $db, string $subject): bool
{
    if (random_int(1, 50) === 1) {
        $db->exec('DELETE FROM admin_login_limits WHERE reset_at <= CURRENT_TIMESTAMP');
    }

    $db->beginTransaction();
    try {
        $create = $db->prepare('INSERT INTO admin_login_limits (subject, failures, reset_at)
            VALUES (?, 0, CURRENT_TIMESTAMP + INTERVAL 15 MINUTE)
            ON DUPLICATE KEY UPDATE subject = VALUES(subject)');
        $create->execute([$subject]);
        $check = $db->prepare('SELECT failures, reset_at > CURRENT_TIMESTAMP AS current_window
            FROM admin_login_limits WHERE subject = ? FOR UPDATE');
        $check->execute([$subject]);
        $limit = $check->fetch();

        $allowed = !(int) $limit['current_window'] || (int) $limit['failures'] < 6;
        if ($allowed) {
            $sql = (int) $limit['current_window']
                ? 'UPDATE admin_login_limits SET failures = failures + 1 WHERE subject = ?'
                : 'UPDATE admin_login_limits SET failures = 1, reset_at = CURRENT_TIMESTAMP + INTERVAL 15 MINUTE WHERE subject = ?';
            $db->prepare($sql)->execute([$subject]);
        }
        $db->commit();
        return $allowed;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function admin_sign_in(PDO $db): void
{
    $username = trim(admin_input('username'));
    $password = admin_input('password');
    if (strlen($username) > 50 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new DomainException('Invalid username or password.');
    }

    $subject = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!admin_reserve_attempt($db, $subject)) {
        throw new DomainException('Too many sign-in attempts. Try again in 15 minutes.');
    }

    $find = $db->prepare('SELECT id, password_hash, session_version FROM admins WHERE username = ?');
    $find->execute([$username]);
    $account = $find->fetch();
    $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $verified = password_verify($password, $account['password_hash'] ?? $dummy);
    if (!$account || !$verified) {
        throw new DomainException('Invalid username or password.');
    }

    $db->prepare('DELETE FROM admin_login_limits WHERE subject = ?')->execute([$subject]);
    admin_reset_session();
    $_SESSION['admin_id'] = (int) $account['id'];
    $_SESSION['admin_version'] = (int) $account['session_version'];
    $_SESSION['admin_seen'] = time();
}

function admin_change_password(PDO $db, array $account): void
{
    $current = admin_input('current_password');
    $password = admin_input('new_password');
    if (strlen($current) > 72 || str_contains($current, "\0") || !password_verify($current, $account['password_hash'])) {
        throw new DomainException('The current password is incorrect.');
    }
    if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new DomainException('Use a password with 12 to 72 bytes.');
    }
    if ($password !== admin_input('confirm_password')) {
        throw new DomainException('The new passwords do not match.');
    }

    $update = $db->prepare('UPDATE admins SET password_hash = ?, session_version = session_version + 1
        WHERE id = ? AND session_version = ?');
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $account['id'], $account['session_version']]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('Your account changed. Sign in again before updating your password.');
    }

    admin_reset_session();
    $_SESSION['admin_id'] = (int) $account['id'];
    $_SESSION['admin_version'] = (int) $account['session_version'] + 1;
    $_SESSION['admin_seen'] = time();
}

function admin_update_stock(PDO $db): void
{
    $variant = admin_number(admin_input('variant'));
    $oldStock = admin_number(admin_input('previous_stock'), 0, 4294967295);
    $newStock = admin_number(admin_input('stock'), 0, 1000000);
    if ($oldStock === $newStock) {
        return;
    }
    $query = $db->prepare('UPDATE variants SET stock = ? WHERE id = ? AND stock = ?');
    $query->execute([$newStock, $variant, $oldStock]);
    if ($query->rowCount() !== 1) {
        throw new DomainException('Stock changed since you opened this page. Check the current quantity and try again.');
    }
}

function admin_money(int|string $amount): string
{
    return '৳' . number_format((int) $amount);
}

function admin_datetime(string $date): string
{
    return date('j M Y, g:i a', strtotime($date));
}

function admin_badge(string $status): string
{
    return '<span class="badge" data-status="' . e($status) . '">' . e(ADMIN_STATUSES[$status]) . '</span>';
}

try {
    $db = db();
    $account = admin_user($db);
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $productFormError = '';

    $action = admin_input('action');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $target = './';
        try {
            if (!hash_equals($_SESSION['csrf'], admin_input('token'))) {
                throw new DomainException('This form expired. Try again.');
            }
            if ($action === 'sign_in' && !$account) {
                admin_sign_in($db);
                admin_redirect();
            }
            if (!$account) {
                throw new DomainException('Sign in to continue.');
            }
            switch ($action) {
                case 'sign_out':
                    admin_reset_session();
                    admin_redirect();
                case 'order_status':
                    $orderId = admin_number(admin_input('order'));
                    $target = '?view=order&id=' . $orderId;
                    update_order_status($db, $orderId, admin_input('status'), admin_input('previous_status'));
                    admin_redirect($target, 'Order status saved.');
                case 'stock':
                    $target = '?view=stock';
                    admin_update_stock($db);
                    admin_redirect($target, 'Stock saved.');
                case 'product_create':
                    $target = '?view=product-new';
                    $productId = admin_create_product($db, $_POST, $_FILES['photo'] ?? null);
                    admin_redirect('?view=product&id=' . rawurlencode($productId), 'Product added.');
                case 'product':
                    $productId = admin_product_id(admin_input('product'));
                    $target = '?view=product&id=' . rawurlencode($productId);
                    admin_update_product_details($db, $productId, $_POST, $_FILES['photo'] ?? null);
                    admin_redirect($target, 'Product saved.');
                case 'variant_add':
                    $productId = admin_product_id(admin_input('product'));
                    $target = '?view=product&id=' . rawurlencode($productId);
                    admin_add_product_variant($db, $productId, $_POST);
                    admin_redirect('?view=stock#product-' . rawurlencode($productId), 'Size added.');
                case 'password':
                    $target = '?view=account';
                    admin_change_password($db, $account);
                    admin_redirect($target, 'Password changed. Other sessions have been signed out.');
                default:
                    throw new DomainException('This action is unavailable.');
            }
        } catch (DomainException | OrderException $error) {
            if ($target === '?view=product-new' || ($action === 'product' && isset($productId))) {
                $productFormError = $error->getMessage();
            } else {
                admin_redirect($target, $error->getMessage(), true);
            }
        }
    }

    $view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'orders';
    $notice = $productFormError === '' ? ($_SESSION['notice'] ?? null) : [$productFormError, true];
    if ($productFormError !== '') {
        $view = $action === 'product_create' ? 'product-new' : 'product';
    }
    unset($_SESSION['notice']);

    if ($account && $view === 'order') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $query = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $query->execute([$id ?: 0]);
        $order = $query->fetch();
        if ($order) {
            $query = $db->prepare('SELECT product_name, size, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id');
            $query->execute([$order['id']]);
            $items = $query->fetchAll();
            $nextStatuses = order_status_options($order['status'], $order['fulfillment']);
        } else {
            http_response_code(404);
        }
    } elseif ($account && $view === 'stock') {
        $inventory = $db->query('SELECT p.id, p.name, p.category, p.price, p.active, p.image, v.id AS variant_id, v.size, v.stock
            FROM products p LEFT JOIN variants v ON v.product_id = p.id
            ORDER BY p.sort_order, p.id, v.id')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);
    } elseif ($account && in_array($view, ['product-new', 'product'], true)) {
        $editing = $view === 'product';
        $id = $productId ?? (is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
        $product = $editing ? admin_read_product($db, $id) : null;
        if ($editing && !$product) {
            http_response_code(404);
        }
        $version = $product ? admin_product_version($product) : '';
        $submittedVersion = admin_input('version');
        $submittedFields = $productFormError !== '' && (!$editing || hash_equals($version, $submittedVersion));
        $form = $product ?: array_fill_keys(ADMIN_PRODUCT_FIELDS, '');
        if (!$editing) {
            $form['active'] = '0';
        }
        if ($submittedFields) {
            foreach (ADMIN_PRODUCT_FIELDS as $field) {
                $form[$field] = admin_input($field);
            }
        }
        $startingSizes = !$editing && $submittedFields && is_array($_POST['starting_size'] ?? null) ? $_POST['starting_size'] : ['One size'];
        $startingStocks = !$editing && $submittedFields && is_array($_POST['starting_stock'] ?? null) ? $_POST['starting_stock'] : [];
    } elseif ($account && $view !== 'account') {
        $view = 'orders';
        $filterStatus = is_string($_GET['status'] ?? null) && isset(ADMIN_STATUSES[$_GET['status']])
            ? $_GET['status'] : '';
        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $statusCounts = array_fill_keys(array_keys(ADMIN_STATUSES), 0);
        foreach ($db->query('SELECT status, COUNT(*) AS count FROM orders GROUP BY status') as $row) {
            $statusCounts[$row['status']] = (int) $row['count'];
        }
        $count = $filterStatus === '' ? array_sum($statusCounts) : $statusCounts[$filterStatus];
        $pageCount = max(1, (int) ceil($count / ADMIN_PAGE_SIZE));
        $page = min($page, $pageCount);
        $where = $filterStatus === '' ? '' : ' WHERE status = :status';
        $query = $db->prepare("SELECT id, reference, customer_name, phone, total, status, created_at
            FROM orders$where ORDER BY id DESC LIMIT :limit OFFSET :offset");
        if ($filterStatus !== '') {
            $query->bindValue(':status', $filterStatus);
        }
        $query->bindValue(':limit', ADMIN_PAGE_SIZE, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * ADMIN_PAGE_SIZE, PDO::PARAM_INT);
        $query->execute();
        $orders = $query->fetchAll();
    }
} catch (Throwable $error) {
    log_app_error('admin', $error);
    http_response_code(503);
    exit('The admin panel is temporarily unavailable.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ibadah Jewellers | Admin</title>
    <link rel="stylesheet" href="admin.css">
</head>
<body>
<header>
    <div class="header-inner">
        <a class="brand" href="./">Ibadah Jewellers Admin</a>
        <nav aria-label="Admin navigation">
            <?php if ($account): ?>
                <a href="?view=orders"<?= in_array($view, ['orders', 'order'], true) ? ' aria-current="page"' : '' ?>>Orders</a>
                <a href="?view=stock"<?= in_array($view, ['stock', 'product-new', 'product'], true) ? ' aria-current="page"' : '' ?>>Inventory</a>
                <a href="?view=account"<?= $view === 'account' ? ' aria-current="page"' : '' ?>>Account</a>
            <?php endif; ?>
            <a href="../">View shop</a>
            <?php if ($account): ?>
                <form method="post" action="./">
                    <?= admin_token_field() ?>
                    <button name="action" value="sign_out">Sign out</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
    <?php if ($notice): ?>
        <p class="notice<?= $notice[1] ? ' error' : '' ?>" role="<?= $notice[1] ? 'alert' : 'status' ?>"><?= e($notice[0]) ?></p>
    <?php endif; ?>

    <?php if (!$account): ?>
        <section class="panel narrow">
            <h1>Sign in</h1>
            <form method="post" action="./">
                <?= admin_token_field() ?>
                <label>Username <input name="username" required maxlength="50" autocomplete="username"></label>
                <label>Password <input type="password" name="password" required maxlength="72" autocomplete="current-password"></label>
                <button class="button" name="action" value="sign_in">Sign in</button>
            </form>
        </section>

    <?php elseif ($view === 'orders'): ?>
        <div class="intro"><h1>Orders</h1></div>
        <div class="stats" aria-label="Orders by status">
            <?php foreach (ADMIN_STATUSES as $key => $label): ?>
                <div class="panel"><span class="muted"><?= e($label) ?></span><strong><?= $statusCounts[$key] ?></strong></div>
            <?php endforeach; ?>
        </div>
        <section class="panel">
            <form class="action-row" method="get" action="./">
                <input type="hidden" name="view" value="orders">
                <label>Status
                    <select name="status">
                        <option value="">All orders</option>
                        <?php foreach (ADMIN_STATUSES as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $filterStatus === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="button secondary">Filter</button>
            </form>
            <?php if (!$orders): ?>
                <p class="muted">No orders to show.</p>
            <?php else: ?>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Orders table">
                    <table class="order-table">
                        <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Total</th><th scope="col">Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($orders as $row): ?>
                            <tr>
                                <td><a class="strong" href="?view=order&amp;id=<?= (int) $row['id'] ?>"><?= e($row['reference']) ?></a><br><small class="muted"><?= e(admin_datetime($row['created_at'])) ?></small></td>
                                <td><?= e($row['customer_name']) ?><br><small class="muted"><?= e($row['phone']) ?></small></td>
                                <td class="amount"><?= admin_money($row['total']) ?></td>
                                <td><?= admin_badge($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($pageCount > 1): ?>
                    <div class="pager action-row">
                        <?php if ($page > 1): ?>
                            <a class="button secondary" href="?view=orders&amp;status=<?= e($filterStatus) ?>&amp;page=<?= $page - 1 ?>">Previous</a>
                        <?php endif; ?>
                        <span>Page <?= $page ?> of <?= $pageCount ?></span>
                        <?php if ($page < $pageCount): ?>
                            <a class="button secondary" href="?view=orders&amp;status=<?= e($filterStatus) ?>&amp;page=<?= $page + 1 ?>">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php elseif ($view === 'order'): ?>
        <div class="intro">
            <p><a href="?view=orders">← Back to orders</a></p>
            <h1><?= $order ? e($order['reference']) : 'Order not found' ?></h1>
            <?php if ($order): ?>
                <p><?= admin_badge($order['status']) ?> <span class="muted"><?= e(admin_datetime($order['created_at'])) ?></span></p>
            <?php endif; ?>
        </div>
        <?php if ($order): ?>
            <div class="order-grid">
                <section class="panel">
                    <h2>Customer</h2>
                    <dl>
                        <div><dt>Name</dt><dd><?= e($order['customer_name']) ?></dd></div>
                        <div><dt>Phone</dt><dd><a href="tel:<?= e($order['phone']) ?>"><?= e($order['phone']) ?></a></dd></div>
                        <?php if ($order['email']): ?><div><dt>Email</dt><dd><?= e($order['email']) ?></dd></div><?php endif; ?>
                        <?php if ($order['fulfillment'] === 'pickup'): ?>
                            <div><dt>Fulfillment</dt><dd>Pickup at Mirpur 1 (previous option)</dd></div>
                            <div><dt>Payment</dt><dd>Pay at pickup</dd></div>
                        <?php else: ?>
                            <div><dt>Payment</dt><dd>Cash on delivery</dd></div>
                        <?php endif; ?>
                        <?php if ($order['address']): ?><div><dt>Address</dt><dd><?= nl2br(e($order['address'])) ?></dd></div><?php endif; ?>
                        <?php if ($order['city']): ?><div><dt>City</dt><dd><?= e($order['city']) ?></dd></div><?php endif; ?>
                        <?php if ($order['delivery_zone']): ?><div><dt>Delivery area</dt><dd><?= $order['delivery_zone'] === 'dhaka' ? 'Within Dhaka' : 'Outside Dhaka' ?></dd></div><?php endif; ?>
                        <?php if ($order['gift_message']): ?><div><dt>Gift message</dt><dd><?= nl2br(e($order['gift_message'])) ?></dd></div><?php endif; ?>
                    </dl>
                </section>
                <section class="panel">
                    <h2>Order status</h2>
                    <?php if ($nextStatuses): ?>
                        <form class="action-row" method="post" action="./">
                            <?= admin_token_field() ?>
                            <input type="hidden" name="order" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="previous_status" value="<?= e($order['status']) ?>">
                            <label>New status
                                <select name="status" required>
                                    <option value="" selected disabled>Select status</option>
                                    <?php foreach ($nextStatuses as $status): ?>
                                        <option value="<?= e($status) ?>"><?= e(ADMIN_STATUSES[$status]) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="button" name="action" value="order_status">Update status</button>
                        </form>
                        <?php if (in_array('cancelled', $nextStatuses, true)): ?><p class="form-help">Cancelling an order puts its items back in stock.</p><?php endif; ?>
                    <?php else: ?>
                        <p class="muted">This order is <?= e(strtolower(ADMIN_STATUSES[$order['status']])) ?>.</p>
                    <?php endif; ?>
                </section>
            </div>
            <section class="panel">
                <h2>Order items</h2>
                <div class="table-wrap" tabindex="0" role="region" aria-label="Order items table">
                    <table class="items-table">
                        <thead><tr><th scope="col">Product</th><th scope="col">Size</th><th scope="col">Unit price</th><th scope="col">Qty</th><th scope="col">Line total</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= e($item['product_name']) ?></td><td><?= e($item['size']) ?></td>
                                <td class="amount"><?= admin_money($item['unit_price']) ?></td><td><?= (int) $item['quantity'] ?></td>
                                <td class="amount"><?= admin_money((int) $item['unit_price'] * (int) $item['quantity']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <dl class="order-totals">
                    <div><dt>Subtotal</dt><dd><?= admin_money($order['subtotal']) ?></dd></div>
                    <?php if ((int) $order['discount']): ?><div><dt>Discount<?= $order['coupon'] ? ' (' . e($order['coupon']) . ')' : '' ?></dt><dd>−<?= admin_money($order['discount']) ?></dd></div><?php endif; ?>
                    <?php if ((int) $order['wrapping']): ?><div><dt>Gift wrapping</dt><dd><?= admin_money($order['wrapping']) ?></dd></div><?php endif; ?>
                    <?php if ($order['fulfillment'] !== 'pickup'): ?><div><dt>Delivery</dt><dd><?= admin_money($order['shipping']) ?></dd></div><?php endif; ?>
                    <div><dt>Total</dt><dd><strong><?= admin_money($order['total']) ?></strong></dd></div>
                </dl>
            </section>
        <?php endif; ?>

    <?php elseif ($view === 'stock'): ?>
        <div class="intro product-heading">
            <div>
                <h1>Inventory</h1>
                <p>Manage products and stock for each size.</p>
            </div>
            <a class="button" href="?view=product-new">Add product</a>
        </div>
        <?php if (!$inventory): ?><p class="panel muted">No products yet.</p><?php endif; ?>
        <?php foreach ($inventory as $productId => $variants): $product = $variants[0]; $hasPhoto = store_photo_exists($product['image']); ?>
            <section class="panel" aria-labelledby="product-<?= e($productId) ?>">
                <div class="product-heading">
                    <div class="product-summary">
                        <?php if ($hasPhoto): ?>
                            <img src="../media.php?f=<?= e($product['image']) ?>" alt="" width="64" height="64" loading="lazy">
                        <?php endif; ?>
                        <div>
                            <h2 id="product-<?= e($productId) ?>"><?= e($product['name']) ?></h2>
                            <span class="muted"><?= e($product['category']) ?> · <?= admin_money($product['price']) ?></span>
                            <span class="badge"><?= (int) $product['active'] === 1 && $hasPhoto ? 'Visible' : 'Hidden' ?></span>
                        </div>
                    </div>
                    <a class="button secondary" href="?view=product&amp;id=<?= rawurlencode($productId) ?>">Edit product</a>
                </div>
                <?php if (!$product['variant_id']): ?>
                    <p class="muted">No sizes configured.</p>
                <?php else: ?>
                    <div class="table-wrap" tabindex="0" role="region" aria-label="<?= e($product['name']) ?> stock">
                        <table class="stock-table">
                            <thead><tr><th scope="col">Size</th><th scope="col">Availability</th><th scope="col">Stock</th></tr></thead>
                            <tbody>
                            <?php foreach ($variants as $variant): $stock = (int) $variant['stock']; ?>
                                <tr>
                                    <td><?= e($variant['size']) ?></td>
                                    <td>
                                        <?php if ($stock === 0): ?>
                                            <span class="badge" data-stock="out">Out of stock</span>
                                        <?php elseif ($stock <= 3): ?>
                                            <span class="badge" data-stock="low">Low stock</span>
                                        <?php else: ?>
                                            <span class="badge">In stock</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form class="stock-form" method="post" action="./">
                                            <?= admin_token_field() ?>
                                            <input type="hidden" name="variant" value="<?= (int) $variant['variant_id'] ?>">
                                            <input type="hidden" name="previous_stock" value="<?= $stock ?>">
                                            <label class="visually-hidden" for="stock-<?= (int) $variant['variant_id'] ?>"><?= e($product['name'] . ' ' . $variant['size']) ?> stock</label>
                                            <input id="stock-<?= (int) $variant['variant_id'] ?>" type="number" name="stock" min="0" max="1000000" value="<?= $stock ?>" required>
                                            <button class="button" name="action" value="stock">Save stock</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php elseif ($view === 'product-new' || $view === 'product'): ?>
        <div class="intro">
            <p><a href="?view=stock">← Back to inventory</a></p>
            <h1><?= $editing ? ($product ? 'Edit product' : 'Product not found') : 'Add product' ?></h1>
        </div>
        <?php if (!$editing || $product): ?>
            <section class="panel">
                <form class="product-form" method="post" enctype="multipart/form-data" action="./">
                    <?= admin_token_field() ?>
                    <?php if ($editing): ?>
                        <input type="hidden" name="product" value="<?= e($product['id']) ?>">
                        <input type="hidden" name="version" value="<?= e($version) ?>">
                    <?php endif; ?>
                    <label class="field-wide">Product name
                        <input name="name" required maxlength="120" value="<?= e($form['name']) ?>">
                    </label>
                    <label>Category
                        <select name="category" required>
                            <option value="" disabled<?= $form['category'] === '' ? ' selected' : '' ?>>Choose a category</option>
                            <?php foreach (ADMIN_PRODUCT_CATEGORIES as $category): ?>
                                <option value="<?= e($category) ?>"<?= $form['category'] === $category ? ' selected' : '' ?>><?= e($category) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Tone
                        <select name="finish" required>
                            <option value="" disabled<?= $form['finish'] === '' ? ' selected' : '' ?>>Choose a tone</option>
                            <?php foreach (ADMIN_PRODUCT_FINISHES as $finish => $label): ?>
                                <option value="<?= e($finish) ?>"<?= $form['finish'] === $finish ? ' selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field-wide">Finish description (optional)
                        <input name="finish_label" maxlength="100" value="<?= e($form['finish_label']) ?>">
                    </label>
                    <label>Price (৳)
                        <input type="number" name="price" min="1" max="10000000" required value="<?= e($form['price']) ?>">
                    </label>
                    <label>Visibility
                        <select name="active">
                            <option value="0"<?= (string) $form['active'] === '0' ? ' selected' : '' ?>>Hidden</option>
                            <option value="1"<?= (string) $form['active'] === '1' ? ' selected' : '' ?>>Visible in shop</option>
                        </select>
                    </label>
                    <label class="field-wide">Description
                        <textarea name="description" rows="3" maxlength="2000" required><?= e($form['description']) ?></textarea>
                    </label>
                    <label class="field-wide">Materials, dimensions or care (optional)
                        <textarea name="details" rows="3" maxlength="2000"><?= e($form['details']) ?></textarea>
                    </label>
                    <label class="field-wide">Product photo
                        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"<?= $editing ? '' : ' required' ?> aria-describedby="photo-help">
                        <small class="form-help" id="photo-help">JPG, PNG or WebP, up to 5 MB.<?= $editing ? ' Choose a file to replace the current photo.' : '' ?></small>
                    </label>
                    <?php if ($editing && store_photo_exists($product['image'])): ?>
                        <img class="product-photo-preview" src="../media.php?f=<?= e($product['image']) ?>" alt="Current product photo" width="96" height="96">
                    <?php endif; ?>
                    <?php if (!$editing): ?>
                        <fieldset class="field-wide starting-sizes">
                            <legend>Sizes and starting stock</legend>
                            <p class="form-help">Set the first size and quantity. You can add more sizes after saving.</p>
                            <?php for ($i = 0; $i < 3; $i++): ?>
                                <div class="starting-size-row">
                                    <label><?= $i === 0 ? 'Size' : 'Additional size' ?>
                                        <input name="starting_size[]" maxlength="24"<?= $i === 0 ? ' required' : '' ?> value="<?= e(is_string($startingSizes[$i] ?? null) ? $startingSizes[$i] : '') ?>">
                                    </label>
                                    <label>Starting stock
                                        <input type="number" name="starting_stock[]" min="0" max="1000000"<?= $i === 0 ? ' required' : '' ?> value="<?= e(is_string($startingStocks[$i] ?? null) ? $startingStocks[$i] : '') ?>">
                                    </label>
                                </div>
                            <?php endfor; ?>
                        </fieldset>
                    <?php endif; ?>
                    <div class="field-wide"><button class="button" name="action" value="<?= $editing ? 'product' : 'product_create' ?>"><?= $editing ? 'Save product' : 'Add product' ?></button></div>
                </form>
            </section>
            <?php if ($editing): ?>
                <section class="panel">
                    <h2>Add a size</h2>
                    <form class="action-row variant-form" method="post" action="./">
                        <?= admin_token_field() ?>
                        <input type="hidden" name="product" value="<?= e($product['id']) ?>">
                        <label>Size <input name="size" maxlength="24" required></label>
                        <label>Starting stock <input type="number" name="stock" min="0" max="1000000" required></label>
                        <button class="button secondary" name="action" value="variant_add">Add size</button>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($view === 'account'): ?>
        <section class="panel narrow">
            <h1>Change password</h1>
            <p class="muted">Signed in as <?= e($account['username']) ?>.</p>
            <form method="post" action="./">
                <?= admin_token_field() ?>
                <label>Current password <input type="password" name="current_password" maxlength="72" required autocomplete="current-password"></label>
                <label>New password <input type="password" name="new_password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
                <label>Confirm new password <input type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
                <button class="button" name="action" value="password">Change password</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
