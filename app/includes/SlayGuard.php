<?php

if (!defined('DB_HOST')) {
    require_once dirname(__DIR__) . '/config/config.php';
}

class SlayGuard
{
    public static function gatekeep(array $options = []): array
    {
        $config = array_merge([
            'auth' => true,
            'csrf' => true,
            'rate_limit' => 'global',
            'company' => true,
            'post_only' => false,
        ], $options);

        if ($config['post_only'] && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::fail(405, 'POST method required');
        }

        require_once __DIR__ . '/RateLimiter.php';
        if ($config['rate_limit'] !== false && $config['rate_limit'] !== null) {
            RateLimiter::check((string) $config['rate_limit']);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($config['auth'] && empty($_SESSION['user_id'])) {
            self::fail(401, 'Unauthorized access');
        }

        if ($config['csrf']) {
            require_once __DIR__ . '/csrf.php';
            try {
                verifyCsrfToken();
            } catch (Exception $e) {
                self::fail(403, $e->getMessage());
            }
        }

        $companyId = (int) ($_SESSION['company_id'] ?? 0);
        if ($companyId <= 0 && !empty($_SESSION['user_id'])) {
            $companyId = defined('SLAYMEET_COMPANY_ID') ? (int) SLAYMEET_COMPANY_ID : 1;
            $_SESSION['company_id'] = $companyId;
        }

        if ($config['company'] && $companyId <= 0) {
            self::fail(403, 'Company association required for this action');
        }

        return [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'company_id' => $companyId,
        ];
    }

    private static function fail(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
