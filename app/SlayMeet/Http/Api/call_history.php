<?php
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session_security.php';
require_once __DIR__ . '/../../../includes/chat_presence.php';
require_once __DIR__ . '/../../Domain/slaymeet_calls_schema.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$currentUserId = (int) $_SESSION['user_id'];
$companyId = (int) ($_SESSION['company_id'] ?? 0);
$partnerId = (int) ($_GET['user_id'] ?? 0);
$limit = min(20, max(1, (int) ($_GET['limit'] ?? 8)));
global $pdo;

if ($partnerId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'user_id required']);
    exit;
}

try {
    slayly_ensure_slaymeet_calls_table($pdo);

    $memberChk = $pdo->prepare("
        SELECT id FROM users
        WHERE id = ? AND company_id = ? AND status = 'active' AND role != 'guest'
        LIMIT 1
    ");
    $memberChk->execute([$partnerId, $companyId]);
    if (!$memberChk->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT c.id, c.caller_id, c.receiver_id, c.status, c.started_at, c.ended_at, c.duration_sec, c.created_at,
               caller.name AS caller_name, receiver.name AS receiver_name
        FROM slaymeet_calls c
        INNER JOIN users caller ON caller.id = c.caller_id
        INNER JOIN users receiver ON receiver.id = c.receiver_id
        WHERE c.status IN ('accepted', 'ended', 'rejected')
          AND (
              (c.caller_id = ? AND c.receiver_id = ?)
              OR (c.caller_id = ? AND c.receiver_id = ?)
          )
        ORDER BY COALESCE(c.ended_at, c.started_at, c.created_at) DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$currentUserId, $partnerId, $partnerId, $currentUserId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $calls = [];
    foreach ($rows as $r) {
        $isOutgoing = (int) $r['caller_id'] === $currentUserId;
        $calls[] = [
            'id' => (int) $r['id'],
            'direction' => $isOutgoing ? 'outgoing' : 'incoming',
            'status' => (string) $r['status'],
            'partner_name' => $isOutgoing ? $r['receiver_name'] : $r['caller_name'],
            'started_at' => $r['started_at'],
            'ended_at' => $r['ended_at'],
            'duration_sec' => $r['duration_sec'] !== null ? (int) $r['duration_sec'] : null,
            'created_at' => $r['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'calls' => $calls]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load call history']);
}
