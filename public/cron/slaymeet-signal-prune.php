<?php
declare(strict_types=1);

/**
 * Prune old SlayMeet WebRTC signaling rows.
 */
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/SlayMeet/Domain/slaymeet_helpers.php';

$cronKey = getenv('CRON_SECRET') ?: '';
if (PHP_SAPI !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($cronKey === '' || !hash_equals($cronKey, (string) $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    fwrite(STDERR, 'DB connect failed' . PHP_EOL);
    exit(1);
}

$pruned = SlayMeetHelpers::pruneStaleSignals($conn, 6);
$conn->close();

$msg = "SlayMeet signal prune: removed {$pruned} row(s).";
if (PHP_SAPI === 'cli') {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
}
