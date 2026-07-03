<?php

class RateLimiter
{
    private static array $policies = [
        'global' => ['limit' => 120, 'window' => 60],
        'slaymeet_write' => ['limit' => 5000, 'window' => 60],
        'slaymeet_read' => ['limit' => 8000, 'window' => 60],
        'slaymeet_upload' => ['limit' => 40, 'window' => 3600],
    ];

    public static function check(string $endpoint = 'global'): bool
    {
        $policy = self::$policies[$endpoint] ?? self::$policies['global'];
        $dir = dirname(__DIR__, 2) . '/storage/rate_limits';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $file = $dir . '/' . hash('sha256', $ip . '|' . $endpoint) . '.json';
        $now = time();
        $data = ['count' => 0, 'start' => $now];

        if (is_file($file)) {
            $raw = file_get_contents($file);
            $decoded = $raw ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (($now - (int) ($data['start'] ?? 0)) > (int) $policy['window']) {
            $data = ['count' => 0, 'start' => $now];
        }

        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        file_put_contents($file, json_encode($data));

        if ($data['count'] > (int) $policy['limit']) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Too many requests']);
            exit;
        }

        return true;
    }
}
