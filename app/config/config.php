<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env_loader.php';

$root = dirname(__DIR__, 2);
slaymeet_load_env($root . '/.env');

if (session_status() === PHP_SESSION_NONE && !(defined('SLAYMEET_SKIP_SESSION') && SLAYMEET_SKIP_SESSION)) {
    if (!empty($_GET['bot_token'])) {
        session_name('SLAYMEET_BOT');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

define('DB_HOST', slaymeet_env('DB_HOST', 'db'));
define('DB_NAME', slaymeet_env('DB_NAME', 'slaymeet'));
define('DB_USER', slaymeet_env('DB_USER', 'slaymeet'));
define('DB_PASS', slaymeet_env('DB_PASS', 'slaymeet'));
define('SITE_URL', rtrim(slaymeet_env('SITE_URL', 'http://localhost:8080'), '/'));
define('ASSET_VERSION', slaymeet_env('ASSET_VERSION', '1'));
define('SLAYMEET_COMPANY_ID', (int) slaymeet_env('SLAYMEET_COMPANY_ID', '1'));

if (!defined('GEMINI_API_KEY') && slaymeet_env('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', slaymeet_env('GEMINI_API_KEY'));
}

define('SITE_NAME', slaymeet_env('SITE_NAME', 'SlayMeet'));
