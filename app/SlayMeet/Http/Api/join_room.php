<?php
require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../includes/SlayGuard.php';
require_once __DIR__ . '/../../Domain/slaymeet_helpers.php';

$guard = SlayGuard::gatekeep([
    'rate_limit' => 'slaymeet_write',
    'csrf' => true,
    'post_only' => true,
    'company' => false
]);

header('Content-Type: application/json');

$jsonFlags = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}

$userId = (int) $guard['user_id'];
$companyId = (int) $guard['company_id'];
$roomToken = trim((string) ($_POST['room'] ?? ''));
if ($roomToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room token required'], $jsonFlags);
    exit;
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed'], $jsonFlags);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, company_id, room_name, status, starts_at, ended_at, created_by
    FROM slay_meet_rooms
    WHERE public_token = ?
    LIMIT 1
");
$stmt->bind_param('s', $roomToken);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Room not found'], $jsonFlags);
    exit;
}

// Relaxed company check: Public tokens are the primary authorization for joining the waiting room.
// We only enforce company_id for internal members to ensure they use their correct workplace context.
if ($companyId > 0 && (int)($room['company_id'] ?? 0) !== $companyId) {
    // If user belongs to a different company, they join as an external participant (guest-like).
    // No error thrown here; admission logic below will handle 'pending' status.
}

$roomId = (int) $room['id'];
$isHost = ((int) $room['created_by'] === $userId);
$role = $isHost ? 'host' : 'participant';

// Backward-compatible fallback for environments where waiting-room migration is not applied yet.
$hasAdmission = false;
$hasRequested = false;
$hasDecidedAt = false;
$hasDecidedBy = false;
$check = $conn->query("SHOW COLUMNS FROM slay_meet_participants");
if ($check) {
    while ($c = $check->fetch_assoc()) {
        $f = (string) ($c['Field'] ?? '');
        if ($f === 'admission_status') $hasAdmission = true;
        if ($f === 'requested_at') $hasRequested = true;
        if ($f === 'decided_at') $hasDecidedAt = true;
        if ($f === 'decided_by') $hasDecidedBy = true;
    }
}
$waitingRoomReady = $hasAdmission && $hasRequested && $hasDecidedAt && $hasDecidedBy;

if (!$waitingRoomReady) {
    $stmt = $conn->prepare("
        INSERT INTO slay_meet_participants (room_id, user_id, role, joined_at, is_active)
        VALUES (?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE left_at = NULL, is_active = 1, joined_at = NOW()
    ");
    $stmt->bind_param('iis', $roomId, $userId, $role);
    $stmt->execute();
    $stmt->close();

    if ($room['status'] === 'scheduled') {
        $nextStatus = 'live';
        $stmt = $conn->prepare("UPDATE slay_meet_rooms SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $nextStatus, $roomId);
        $stmt->execute();
        $stmt->close();
        $room['status'] = 'live';
    }

    $participants = [];
    $stmt = $conn->prepare("
        SELECT p.user_id, p.role, p.joined_at, u.name, u.profile_pic
        FROM slay_meet_participants p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.room_id = ? AND p.is_active = 1
        ORDER BY p.joined_at ASC
    ");
    $stmt->bind_param('i', $roomId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if ((int)$row['user_id'] === -1) {
            $row['name'] = 'Ultra Looper AI Assistant';
            $row['profile_pic'] = '';
        }
        $participants[] = $row;
    }
    $stmt->close();
    $conn->close();

    $out = json_encode([
        'success' => true,
        'waiting' => false,
        'admission_status' => 'admitted',
        'room' => $room,
        'participants' => $participants
    ], $jsonFlags);
    echo $out !== false ? $out : json_encode(['success' => false, 'message' => 'Encoding error'], $jsonFlags);
    exit;
}

// Guests require host approval before becoming active participants.
$isGuestSession = !empty($_SESSION['slaymeet_guest_session']);
$isGuestRole = false;
$userRow = null;
$trCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'team_role'");
$hasTeamRole = $trCheck && $trCheck->num_rows > 0;
$userSql = $hasTeamRole
    ? 'SELECT role, team_role FROM users WHERE id = ? LIMIT 1'
    : 'SELECT role FROM users WHERE id = ? LIMIT 1';
$stmt = $conn->prepare($userSql);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if ($userRow) {
    $isGuestRole = (strtolower((string) ($userRow['role'] ?? '')) === 'guest')
        || ($hasTeamRole && strtolower((string) ($userRow['team_role'] ?? '')) === 'guest');
}
$isBotUser = !empty($_SESSION['slaymeet_bot_runner'])
    || (!empty($_SESSION['slaymeet_guest_name']) && in_array($_SESSION['slaymeet_guest_name'], ['Teena', 'Slayly AI', 'Slayly AI Assistant', 'Ultra Looper AI Assistant'], true));
// Slayly AI uses the same waiting-room flow as human guests (host must Admit).
$needsApproval = !$isHost && ($isGuestSession || $isGuestRole || $isBotUser || ($companyId > 0 && (int)($room['company_id'] ?? 0) !== $companyId));
$targetAdmission = $isHost ? 'admitted' : ($needsApproval ? 'pending' : 'admitted');

// Upsert participant with admission workflow.
$stmt = $conn->prepare("
    SELECT id, admission_status
    FROM slay_meet_participants
    WHERE room_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $roomId, $userId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    $existingStatus = (string) ($existing['admission_status'] ?? 'admitted');
    if ($isHost) {
        $stmt = $conn->prepare("
            UPDATE slay_meet_participants
            SET role = 'host',
                admission_status = 'admitted',
                requested_at = NOW(),
                decided_at = NOW(),
                decided_by = ?,
                left_at = NULL,
                is_active = 1,
                joined_at = NOW()
            WHERE room_id = ? AND user_id = ?
        ");
        $stmt->bind_param('iii', $userId, $roomId, $userId);
        $stmt->execute();
        $stmt->close();
    } else {
        $finalStatus = $targetAdmission;
        if ($existingStatus === 'admitted') {
            $finalStatus = 'admitted';
        }
        $deciderUserId = (int) ($room['created_by'] ?? 0);
        // Split admitted vs pending updates — avoids fragile CASE + bind_param ordering across MySQL/MariaDB.
        if ($finalStatus === 'admitted') {
            $stmt = $conn->prepare("
                UPDATE slay_meet_participants
                SET role = ?,
                    admission_status = 'admitted',
                    requested_at = NOW(),
                    decided_at = NOW(),
                    decided_by = ?,
                    left_at = NULL,
                    is_active = 1,
                    joined_at = NOW()
                WHERE room_id = ? AND user_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('siii', $role, $deciderUserId, $roomId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("
                UPDATE slay_meet_participants
                SET role = ?,
                    admission_status = 'pending',
                    requested_at = NOW(),
                    decided_at = NULL,
                    decided_by = NULL,
                    left_at = NULL,
                    is_active = 0,
                    joined_at = joined_at
                WHERE room_id = ? AND user_id = ?
            ");
            if ($stmt) {
                $stmt->bind_param('sii', $role, $roomId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
        $targetAdmission = $finalStatus;
    }
} else {
    $isActive = ($targetAdmission === 'admitted') ? 1 : 0;
    $requestedAt = date('Y-m-d H:i:s');
    // `joined_at` is NOT NULL in schema; keep it populated even for pending guests.
    $joinedAt = $requestedAt;
    if ($targetAdmission === 'admitted') {
        $decidedBy = (int) $room['created_by'];
        $stmt = $conn->prepare("
            INSERT INTO slay_meet_participants
                (room_id, user_id, role, joined_at, requested_at, admission_status, decided_at, decided_by, is_active)
            VALUES (?, ?, ?, ?, ?, 'admitted', ?, ?, 1)
        ");
        $stmt->bind_param('iissssi', $roomId, $userId, $role, $joinedAt, $requestedAt, $requestedAt, $decidedBy);
        $stmt->execute();
        $stmt->close();
    } else {
        // Keep pending inserts strict-mode safe: explicit NULL for decision columns.
        $stmt = $conn->prepare("
            INSERT INTO slay_meet_participants
                (room_id, user_id, role, joined_at, requested_at, admission_status, decided_at, decided_by, is_active)
            VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, 0)
        ");
        $stmt->bind_param('iisss', $roomId, $userId, $role, $joinedAt, $requestedAt);
        $stmt->execute();
        $stmt->close();
    }
}

if ($room['status'] === 'scheduled' && $targetAdmission === 'admitted') {
    $nextStatus = 'live';
    $stmt = $conn->prepare("UPDATE slay_meet_rooms SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $nextStatus, $roomId);
    $stmt->execute();
    $stmt->close();
    $room['status'] = 'live';
}

$participants = [];
$stmt = $conn->prepare("
    SELECT p.user_id, p.role, p.joined_at, u.name, u.profile_pic
    FROM slay_meet_participants p
    LEFT JOIN users u ON u.id = p.user_id
    WHERE p.room_id = ? AND p.is_active = 1 AND p.admission_status = 'admitted'
    ORDER BY p.joined_at ASC
");
$stmt->bind_param('i', $roomId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    if ((int)$row['user_id'] === -1) {
        $row['name'] = 'Ultra Looper AI Assistant';
        $row['profile_pic'] = '';
    }
    $participants[] = $row;
}
$stmt->close();

$userName = 'Guest';
if (!empty($_SESSION['slaymeet_guest_name'])) {
    $userName = $_SESSION['slaymeet_guest_name'];
} else {
    $uStmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    if ($uStmt) {
        $uStmt->bind_param('i', $userId);
        $uStmt->execute();
        $uRow = $uStmt->get_result()->fetch_assoc();
        if ($uRow) {
            $userName = trim($uRow['name']);
        }
        $uStmt->close();
    }
}
$conn->close();

$waiting = ($targetAdmission !== 'admitted');
$livekitToken = null;
$livekitUrl = null;

if (!$waiting) {
    // Secret comes from app/includes/.secrets/.env (LIVEKIT_API_SECRET). No secret is hardcoded here.
    $lkApiKey = getenv('LIVEKIT_API_KEY') ?: 'slaymeet_api_key';
    $lkApiSecret = trim((string) (getenv('LIVEKIT_API_SECRET') ?: ''));
    $isLocalDev = SlayMeetHelpers::isLocalDevRequest();
    if ($isLocalDev) {
        $livekitUrl = getenv('LIVEKIT_URL') ?: 'ws://127.0.0.1:7880';
    } else {
        $livekitUrl = getenv('LIVEKIT_URL') ?: 'wss://ultralooper.com/livekit/';
        // Production must use secure WebSocket; upgrade accidental ws:// in .env.
        if (preg_match('#^ws://#i', $livekitUrl)) {
            $livekitUrl = 'wss://' . substr($livekitUrl, 5);
        }
    }

    if ($lkApiSecret !== '') {
        $lkRoomName = (string) ($room['public_token'] ?? '');
        if ($lkRoomName === '') {
            $lkRoomName = (string) ($room['id'] ?? '');
        }

        $lkHeader = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $lkPayload = json_encode([
            'exp' => time() + 21600,
            'iss' => $lkApiKey,
            'sub' => (string) $userId,
            'name' => $userName,
            'video' => [
                'room' => $lkRoomName,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true
            ]
        ]);

        $base64UrlEncode = function($data) {
            return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
        };

        $lkBase64Header = $base64UrlEncode($lkHeader);
        $lkBase64Payload = $base64UrlEncode($lkPayload);
        $lkSignature = hash_hmac('sha256', $lkBase64Header . "." . $lkBase64Payload, $lkApiSecret, true);
        $lkBase64Signature = $base64UrlEncode($lkSignature);

        $livekitToken = $lkBase64Header . "." . $lkBase64Payload . "." . $lkBase64Signature;
    }
}

$out = json_encode([
    'success' => true,
    'waiting' => $waiting,
    'admission_status' => $targetAdmission,
    'room' => $room,
    'participants' => $participants,
    'livekit_token' => $livekitToken,
    'livekit_url' => $livekitUrl
], $jsonFlags);
echo $out !== false ? $out : json_encode(['success' => false, 'message' => 'Encoding error'], $jsonFlags);
exit;
