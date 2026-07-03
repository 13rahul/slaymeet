<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';

header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], $jsonFlags);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$authMode = 'none';
$companyId = 0;
$userId = 0;

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$expectedToken = getenv('DAEMON_TOKEN') ?: '';
if ($expectedToken !== '' && str_starts_with($authHeader, 'Bearer ')) {
    $token = substr($authHeader, 7);
    if (hash_equals($expectedToken, $token)) {
        $authMode = 'daemon';
    }
}

if ($authMode === 'none') {
    try {
        $guard = SlayGuard::gatekeep([
            'rate_limit' => 'slaymeet_write',
            'csrf' => true,
            'post_only' => false,
            'company' => true,
        ]);
        $authMode = 'session';
        $companyId = (int) ($guard['company_id'] ?? 0);
        $userId = (int) ($guard['user_id'] ?? 0);
    } catch (Throwable $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized'], $jsonFlags);
        exit;
    }
}

$roomName = trim((string) ($input['room_name'] ?? ''));
$transcript = trim((string) ($input['transcript'] ?? ''));
$summary = trim((string) ($input['summary'] ?? ''));

if ($roomName === '' || ($transcript === '' && $summary === '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room_name and transcript or summary are required.'], $jsonFlags);
    exit;
}

$dir = dirname(__DIR__, 4) . '/storage/transcripts';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$safeRoom = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $roomName) ?: 'room';
$filename = $safeRoom . '_' . date('Y-m-d_His') . '.md';
$path = $dir . '/' . $filename;

$content = "# Meeting: {$roomName}\n\n";
$content .= "Saved: " . date('c') . "\n\n";
if ($summary !== '') {
    $content .= "## Summary\n\n{$summary}\n\n";
}
if ($transcript !== '') {
    $content .= "## Transcript\n\n{$transcript}\n";
}

file_put_contents($path, $content);

echo json_encode([
    'success' => true,
    'message' => 'Transcript saved to storage/transcripts',
    'path' => 'storage/transcripts/' . $filename,
    'auth' => $authMode,
], $jsonFlags);
