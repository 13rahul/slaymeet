<?php
declare(strict_types=1);

/**
 * SlayMeet application bootstrap.
 */
if (defined('SLAYMEET_BOOTSTRAPPED')) {
    return;
}
define('SLAYMEET_BOOTSTRAPPED', true);

$appRoot = dirname(__DIR__);
if (!defined('SLAYMEET_ROOT')) {
    define('SLAYMEET_ROOT', $appRoot);
}

$configPath = $appRoot . '/app/config/config.php';
if (!is_file($configPath)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Configuration missing']);
    }
    throw new RuntimeException('Configuration missing: app/config/config.php');
}

require_once $configPath;
