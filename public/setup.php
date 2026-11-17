<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
no_cache();
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');

if (!can_use_private_routes()) {
    http_response_code(403);
    exit('Open this setup page over HTTPS.');
}

$error = '';
try {
    $setupToken = config()['setup_token'] ?? '';
    $connection = db();
    if (!is_string($setupToken) || strlen($setupToken) < 32
        || $connection->query('SELECT 1 FROM admins LIMIT 1')->fetchColumn() !== false) {
        http_response_code(404);
        exit('Setup is unavailable.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['token'] ?? null;
        $username = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;
        if (!is_string($token) || !hash_equals($setupToken, $token)) {
            http_response_code(403);
            $error = 'The setup token did not match.';
        } elseif (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,50}$/D', $username)) {
            $error = 'Use 3–50 letters, numbers, periods, hyphens or underscores for your username.';
        } elseif (!is_string($password) || !preg_match('/^[ -~]{12,72}$/D', $password)
            || $password !== ($_POST['password_confirmation'] ?? null)) {
            $error = 'Enter the same password twice, using 12–72 printable ASCII characters.';
        } else {
            $statement = $connection->prepare(
                'INSERT INTO admins (id, username, password_hash) VALUES (1, ?, ?)'
            );
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            header('Location: ./mehedi/', true, 303);
            exit;
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof PDOException && ($exception->errorInfo[1] ?? null) === 1062) {
        http_response_code(404);
        exit('Setup is unavailable.');
    }
    log_app_error('setup', $exception);
    http_response_code(503);
    exit('Setup is temporarily unavailable. Check the database configuration and import the schema first.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up Ibadah Jewellers Admin</title>
    <link rel="stylesheet" href="./setup.css">
</head>
<body>
<main>
    <p>Ibadah Jewellers</p>
    <h1>Create your admin account</h1>
    <?php if ($error !== ''): ?><p role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
        <label>Setup token <input name="token" type="password" maxlength="256" autocomplete="off" required></label>
        <label>Username <input name="username" minlength="3" maxlength="50" autocomplete="username" required></label>
        <label>Password <input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
        <label>Confirm password <input name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
        <button type="submit">Create admin</button>
    </form>
</main>
</body>
</html>
