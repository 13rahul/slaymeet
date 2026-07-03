<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/Core/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $pdo = \Slayly\Core\Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id, name, password, company_id, role FROM users WHERE email = ? AND status = ? LIMIT 1');
        $stmt->execute([$email, 'active']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, (string) $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = (string) $user['name'];
            $_SESSION['company_id'] = (int) ($user['company_id'] ?: SLAYMEET_COMPANY_ID);
            $_SESSION['role'] = (string) $user['role'];
            $redirect = $_GET['redirect'] ?? '/meet';
            header('Location: ' . $redirect);
            exit;
        }
        $error = 'Invalid email or password.';
    } catch (Throwable $e) {
        $error = 'Login failed. Check database connection.';
    }
}

$token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — SlayMeet</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0a0a0a; color: #fff; font-family: Inter, system-ui, sans-serif; }
        .card { background: #111; padding: 32px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); width: min(400px, 92vw); }
        input { width: 100%; padding: 12px; margin: 8px 0 16px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.15); background: #1a1a1a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 12px; border: none; border-radius: 10px; background: #ccff00; color: #000; font-weight: 700; cursor: pointer; }
        .err { color: #f87171; margin-bottom: 12px; font-size: 14px; }
    </style>
</head>
<body>
<div class="card">
    <h1 style="margin-top:0;">SlayMeet</h1>
    <p style="color:#aaa;">Sign in to start a meeting.</p>
    <?php if ($error !== ''): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <label>Email</label>
        <input type="email" name="email" value="admin@localhost" required>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
