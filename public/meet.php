<?php
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/includes/csrf.php';
require_once __DIR__ . '/../app/includes/session_security.php';
$meetBrand = ['full' => 'SlayMeet', 'short' => 'SlayMeet', 'prefix' => '', 'accent' => 'SlayMeet'];

$room = isset($_GET['room']) ? trim((string) $_GET['room']) : '';

// Bootstrap headless bot session if bot_token is provided
if (!isset($_SESSION['user_id']) && !empty($_GET['bot_token'])) {
    require_once __DIR__ . '/../app/SlayMeet/Domain/slaymeet_helpers.php';
    $boot = SlayMeetHelpers::bootstrapBotGuestSession($room, (string) $_GET['bot_token']);
    if (!$boot['ok']) {
        http_response_code(403);
        echo 'Bot authentication failed: ' . ($boot['message'] ?? 'invalid token');
        exit;
    }
}

// Guest Flow
if (!isset($_SESSION['user_id'])) {
    if ($room !== '') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_name'])) {
            require_once __DIR__ . '/../app/Core/Database.php';
            try {
                // Security: Verify CSRF token
                verifyCsrfToken();

                $db = \Slayly\Core\Database::getInstance()->getConnection();
                $gName = trim((string) $_POST['guest_name']);
                if ($gName === '') {
                    $gName = 'Guest';
                }
                $gName = mb_substr($gName, 0, 120);

                // Align guest tenant/company with the invited room to pass SlayGuard checks.
                $stmtRoom = $db->prepare('SELECT company_id FROM slay_meet_rooms WHERE public_token = :token LIMIT 1');
                $stmtRoom->execute([':token' => $room]);
                $roomRow = $stmtRoom->fetch(\PDO::FETCH_ASSOC);
                if (!$roomRow || (int) ($roomRow['company_id'] ?? 0) <= 0) {
                    http_response_code(404);
                    echo 'Meeting room not found or expired.';
                    exit;
                }
                $roomCompanyId = (int) $roomRow['company_id'];

                $gEmail = 'guest_' . bin2hex(random_bytes(8)) . '@slaymeet.local';
                $guestPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

                $stmt = $db->prepare("
                    INSERT INTO users 
                    (name, email, password, company_id, team_role, role, status, is_verified, onboarding_completed, force_password_reset, created_at) 
                    VALUES 
                    (:name, :email, :password, :company_id, 'teammate', 'guest', 'active', 1, 1, 0, NOW())
                ");
                $stmt->execute([
                    ':name' => $gName,
                    ':email' => $gEmail,
                    ':password' => $guestPass,
                    ':company_id' => $roomCompanyId
                ]);
                $gId = (int) $db->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $gId;
                $_SESSION['user_name'] = $gName . ' (Guest)';
                $_SESSION['company_id'] = $roomCompanyId;
                $_SESSION['role'] = 'guest';
                // Scoped guest: session_security strips this identity outside SlayMeet routes.
                $_SESSION['slaymeet_guest_session'] = true;

                header("Location: " . SITE_URL . "/meet?room=" . urlencode($room));
                exit;
            } catch (\Throwable $guestErr) {
                error_log('[SlayMeet Guest Join] ' . $guestErr->getMessage());
                // On live site, a 500 error here usually means the 'guest' role is missing from the ENUM.
                $errorMsg = $guestErr->getMessage();
                if (stripos($errorMsg, 'Data truncated') !== false || stripos($errorMsg, 'incorrect value') !== false) {
                    $errorMsg = "Database restriction: The 'guest' role is not allowed on this server. Please run the provided SQL update.";
                }
                http_response_code(500);
                echo '<!DOCTYPE html><html><head><title>Join ' . htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8') . ' Error</title><style>body{background:#0a0a0a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;} .card{background:#111;padding:40px;border-radius:20px;border:1px solid rgba(255,255,255,0.1);text-align:center;max-width:500px;}</style></head><body><div class="card"><h2>Join Failed</h2><p style="color:#ff4444;">' . htmlspecialchars($errorMsg) . '</p><p style="font-size:14px;color:#aaa;line-height:1.6;">If you are the admin, please ensure the <code>users</code> table allows the <code>guest</code> role in its ENUM column.</p><p><a href="" style="color:#ccff00;">Try Again</a></p></div></body></html>';
                exit;
            }
        } else {
            // Render Guest Join UI
            echo '<!DOCTYPE html><html><head><title>Join ' . htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8') . '</title><meta name="viewport" content="width=device-width, initial-scale=1.0">';
            echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">';
            echo '<style>body{background:#0a0a0a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;font-family:Inter,sans-serif;} .card{background:#111;padding:40px;border-radius:20px;border:1px solid rgba(255,255,255,0.1);text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.5);} input{width:100%;padding:15px;background:#1a1a1a;border:1px solid rgba(255,255,255,0.1);color:#fff;border-radius:10px;margin-bottom:20px;box-sizing:border-box;} button{background:#ccff00;color:#000;border:none;padding:15px;width:100%;border-radius:10px;font-weight:700;cursor:pointer;}</style></head>';
            echo '<body><div class="card"><h2 style="margin-top:0;">Join Meeting</h2><p style="color:#aaa;margin-bottom:30px;">Enter your name to join as a guest.</p>';
            echo '<form method="POST">';
            echo '<input type="hidden" name="csrf_token" value="' . generateCsrfToken() . '">';
            echo '<input type="text" name="guest_name" placeholder="Your Full Name" required>';
            echo '<button type="submit">Join ' . htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8') . '</button>';
            echo '</form>';
            echo '</div></body></html>';
            exit;
        }
    } else {
        header('Location: ' . SITE_URL . '/login.php');
        exit;
    }
}

$userId = (int) $_SESSION['user_id'];
$userName = (string) ($_SESSION['user_name'] ?? 'You');
$companyId = (int) ($_SESSION['company_id'] ?? 0);
$isDmCall = isset($_GET['dm']) && (string) $_GET['dm'] === '1';
// Outgoing DM call: caller arrives here directly (same window) and we show a
// "Calling…" overlay on top while connecting in the background.
$outgoingCalling = isset($_GET['calling']) && (string) $_GET['calling'] === '1';
$outgoingCallId = isset($_GET['call_id']) ? preg_replace('/[^0-9]/', '', (string) $_GET['call_id']) : '';
$outgoingPeerName = trim((string) ($_GET['peer'] ?? ''));
$outgoingPeerAvatar = trim((string) ($_GET['peer_avatar'] ?? ''));

/**
 * Sidebar opens /slaymeet with no ?room= token — provision an instant room so WebRTC join() has SlayMeetConfig.room.
 */
if ($room === '' && $userId > 0) {
    if ($companyId <= 0) {
        try {
            require_once __DIR__ . '/../app/Core/Database.php';
            $pdo = \Slayly\Core\Database::getInstance()->getConnection();
            $st = $pdo->prepare('SELECT company_id FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $rowUser = $st->fetch(\PDO::FETCH_ASSOC);
            if ($rowUser && (int) ($rowUser['company_id'] ?? 0) > 0) {
                $companyId = (int) $rowUser['company_id'];
                $_SESSION['company_id'] = $companyId;
            }
        } catch (\Throwable $e) {
            error_log('[SlayMeet instant room] company lookup: ' . $e->getMessage());
        }
    }

    if ($companyId > 0) {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn && !$conn->connect_error) {
            $conn->set_charset('utf8mb4');
            $publicToken = bin2hex(random_bytes(16));
            $hostToken = bin2hex(random_bytes(24));
            $roomName = 'Instant Meeting';
            $status = 'live';
            $startsAt = date('Y-m-d H:i:s');
            $stmt = $conn->prepare('
                INSERT INTO slay_meet_rooms
                (company_id, channel_id, created_by, room_name, public_token, host_token, status, starts_at)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?)
            ');
            if ($stmt) {
                $stmt->bind_param('iisssss', $companyId, $userId, $roomName, $publicToken, $hostToken, $status, $startsAt);
                if ($stmt->execute()) {
                    $roomId = (int) $stmt->insert_id;
                    $stmt->close();
                    $role = 'host';
                    $p = $conn->prepare('INSERT INTO slay_meet_participants (room_id, user_id, role, joined_at, is_active) VALUES (?, ?, ?, NOW(), 1)');
                    if ($p) {
                        $p->bind_param('iis', $roomId, $userId, $role);
                        $p->execute();
                        $p->close();
                    }
                    $conn->close();
                    header('Location: ' . SITE_URL . '/meet?room=' . rawurlencode($publicToken));
                    exit;
                }
                $stmt->close();
            }
            $conn->close();
        }
        error_log('[SlayMeet] Failed to provision instant room for user ' . $userId);
    }
}

if ($room === '' && $userId > 0) {
    $dash = SITE_URL . '/meet';
    $retry = SITE_URL . '/meet';
    $why = ($companyId <= 0)
        ? 'Your account needs an active workspace (company) before starting a meeting.'
        : 'We couldn’t create a meeting room right now. Please try again.';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{margin:0;background:#08080c;color:#f8fafc;font-family:system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;text-align:center;}';
    echo 'a{color:#ccff00;font-weight:600;}p{max-width:420px;line-height:1.5;color:rgba(248,250,252,0.85);}</style></head><body>';
    echo '<div><h1 style="margin:0 0 16px;font-size:1.25rem;">' . htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($why) . '</p>';
    if ($companyId <= 0) {
        echo '<p><a href="' . htmlspecialchars($dash) . '">Open workplace / team setup</a></p>';
    } else {
        echo '<p><a href="' . htmlspecialchars($retry) . '">Try again</a></p>';
    }
    echo '</div></body></html>';
    exit;
}

$ulAssetV = defined('ASSET_VERSION') ? ASSET_VERSION : time();
$ulSite = rtrim((string) SITE_URL, '/');
$csrfToken = generateCsrfToken();
$isMeetGuestShell = true;

$slaymeetIce = [
    ['urls' => 'stun:stun.l.google.com:19302'],
    ['urls' => 'stun:stun1.l.google.com:19302'],
    ['urls' => 'stun:stun2.l.google.com:19302'],
    ['urls' => 'stun:stun3.l.google.com:19302'],
    ['urls' => 'stun:stun4.l.google.com:19302'],
];
if (getenv('SLAYMEET_STUN_URL')) {
    $slaymeetIce[] = ['urls' => getenv('SLAYMEET_STUN_URL')];
}
$turnUser = function_exists('slayly_env_clean') ? (slayly_env_clean('SLAYMEET_TURN_USER') ?? '') : trim((string) getenv('SLAYMEET_TURN_USER'));
$turnPass = function_exists('slayly_env_clean') ? (slayly_env_clean('SLAYMEET_TURN_PASS') ?? '') : trim((string) getenv('SLAYMEET_TURN_PASS'));
$turnHost = function_exists('slayly_env_clean') ? (slayly_env_clean('SLAYMEET_TURN_HOST') ?? '') : trim((string) getenv('SLAYMEET_TURN_HOST'));
if ($turnHost !== '') {
    $turnUrls = [
        'turn:' . $turnHost . ':3478?transport=udp',
        'turn:' . $turnHost . ':3478?transport=tcp',
    ];
    $turnsHost = getenv('SLAYMEET_TURNS_HOST') ?: '';
    if ($turnsHost !== '') {
        $turnUrls[] = 'turns:' . $turnsHost . ':5349?transport=tcp';
    }
    $slaymeetIce[] = ['urls' => $turnUrls, 'username' => $turnUser, 'credential' => $turnPass];
} elseif (getenv('SLAYMEET_TURN_URL')) {
    $slaymeetIce[] = [
        'urls' => getenv('SLAYMEET_TURN_URL'),
        'username' => $turnUser,
        'credential' => $turnPass,
    ];
}

$slaymeetInlineStyles = <<<'CSS'
        .prejoin-btn-secondary { background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 10px; cursor: pointer; width: 100%; margin-bottom: 10px; font-weight: 600; }
        .prejoin-video-off::after { content: "Camera is off"; position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.72); color: #e2e8f0; font-size: 12px; font-weight: 700; pointer-events: none; }
        .prejoin-waiting-note { margin-top: 10px; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(99,102,241,0.3); background: rgba(99,102,241,0.12); color: #c7d2fe; font-size: 12px; display: none; }
        .prejoin-waiting-note.show { display: block; }
        .audio-unlock-hint { position: fixed; left: 50%; bottom: calc(112px + env(safe-area-inset-bottom, 0px)); transform: translateX(-50%); z-index: 150; display: none; align-items: center; gap: 10px; background: var(--sm-surface, #121214); border: 1px solid var(--sm-border); border-radius: 12px; padding: 10px 14px; font-size: 12px; }
        .audio-unlock-hint.show { display: inline-flex; }
        .audio-unlock-btn { border: 1px solid rgba(99,102,241,0.45); background: rgba(99,102,241,0.22); color: #eef2ff; border-radius: 8px; padding: 6px 10px; cursor: pointer; font-size: 12px; font-weight: 700; }
        .bg-selection-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: 10px; margin-bottom: 24px; }
        .bg-option { aspect-ratio: 16/9; background: #1e293b; border-radius: 8px; border: 2px solid transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; overflow: hidden; background-size: cover; background-position: center; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .bg-option:hover { transform: scale(1.05); }
        .bg-option.active { border-color: #6366f1; box-shadow: 0 0 10px rgba(99, 102, 241, 0.4); }
        .bg-option[data-bg="none"] { font-weight: 800; opacity: 0.6; }
        .bg-option[data-bg="blur"] { backdrop-filter: blur(4px); font-size: 18px; }
        .whiteboard-overlay { position: fixed; inset: 0; z-index: 850; display: none; flex-direction: row; align-items: stretch; }
        .whiteboard-backdrop { flex: 1; min-width: 72px; border: none; margin: 0; padding: 0; cursor: pointer; background: rgba(2, 6, 23, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
        .whiteboard-panel { width: min(960px, 100%); flex-shrink: 0; display: flex; flex-direction: column; min-width: 0; background: #0f172a; border-left: 1px solid rgba(255,255,255,0.1); box-shadow: -16px 0 40px rgba(0,0,0,0.4); }
        .whiteboard-header { flex-shrink: 0; padding: 12px 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; background: #1e293b; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .wb-heading { flex: 1 1 180px; min-width: 0; }
        .wb-heading h2 { margin: 0; font-size: 17px; font-weight: 700; color: #f8fafc; }
        .wb-heading p { margin: 4px 0 0; font-size: 12px; color: #94a3b8; line-height: 1.4; }
        .whiteboard-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; flex: 2 1 240px; }
        .tool-btn { width: 34px; height: 34px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.12); background: rgba(255,255,255,0.06); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s, box-shadow 0.15s; }
        .tool-btn:hover { background: rgba(255,255,255,0.12); }
        .tool-btn.active { background: #6366f1; border-color: transparent; }
        .tool-btn.wb-color-active { box-shadow: 0 0 0 2px #f8fafc; }
        .tool-sep { width: 1px; height: 22px; background: rgba(255,255,255,0.12); margin: 0 4px; }
        .wb-brush-row { display: flex; align-items: center; gap: 4px; }
        .wb-brush-label { font-size: 11px; color: #94a3b8; margin-right: 2px; }
        .whiteboard-canvas-wrap { flex: 1; min-height: 200px; position: relative; background: #0f172a; }
        #whiteboard-canvas { position: absolute; inset: 0; width: 100%; height: 100%; cursor: crosshair; touch-action: none; display: block; }
        .wb-close-btn { padding: 10px 16px; border-radius: 12px; border: none; font-weight: 700; font-size: 13px; cursor: pointer; background: #ccff00; color: #0f172a; white-space: nowrap; }
        .wb-close-btn:hover { filter: brightness(1.06); }
        @media (max-width: 700px) {
            .whiteboard-overlay { flex-direction: column; }
            .whiteboard-backdrop { flex: 0 0 72px; min-height: 72px; }
            .whiteboard-panel { width: 100%; flex: 1; min-height: 0; border-left: none; border-top: 1px solid rgba(255,255,255,0.1); }
        }
        .chat-file-bubble { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 12px; border-radius: 12px; margin-top: 5px; display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; transition: all 0.2s; }
        .chat-file-bubble:hover { background: rgba(255,255,255,0.1); border-color: #6366f1; }
        .file-icon { width: 32px; height: 32px; background: #6366f1; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
        .file-info { flex: 1; min-width: 0; }
        .file-name { font-weight: 700; font-size: 13px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .file-meta { font-size: 11px; opacity: 0.6; }
        .connection-pill { position: absolute; left: 10px; top: 10px; z-index: 4; font-size: 10px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 4px 8px; border-radius: 999px; background: rgba(15, 23, 42, 0.72); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.35); pointer-events: none; }
        .tile[data-connection-state="connected"] .connection-pill { display: none; }
        .tile[data-connection-state="failed"] .connection-pill { color: #f87171; border-color: rgba(248, 113, 113, 0.45); }
        .meet-live-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #22c55e; margin-right: 6px; box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.5); animation: meetLivePulse 1.8s infinite; }
        @keyframes meetLivePulse { 0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.45); } 70% { box-shadow: 0 0 0 8px rgba(34, 197, 94, 0); } 100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }
        .hidden-canvas { display: none; }
CSS;

$slaymeetConfigScript = '<script>const SlayMeetConfig = ' . json_encode([
    'siteUrl' => SITE_URL,
    'csrfToken' => $csrfToken,
    'room' => $room,
    'userId' => $userId,
    'companyId' => $companyId,
    'userName' => $userName,
    'userProfilePic' => (string) ($_SESSION['profile_pic'] ?? ''),
    'isDmCall' => $isDmCall,
    'outgoingCall' => [
        'active' => ($outgoingCalling && $outgoingCallId !== ''),
        'callId' => $outgoingCallId,
        'peerName' => $outgoingPeerName !== '' ? $outgoingPeerName : 'Team member',
        'peerAvatar' => $outgoingPeerAvatar,
    ],
    'iceServers' => $slaymeetIce,
    'productName' => $meetBrand['full'],
], JSON_UNESCAPED_SLASHES) . ';</script>';

if ($isMeetGuestShell) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo SITE_NAME; ?></title>
    <script>
    (function() {
        const theme = localStorage.getItem('slayly-zen-theme');
        if (theme === 'light') {
            document.documentElement.classList.add('zen-light');
            if (document.body) document.body.classList.add('zen-light');
        }
    })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $ulSite; ?>/assets/css/pages/slaymeet-room.css?v=<?php echo $ulAssetV; ?>">
    <link rel="stylesheet" href="<?php echo $ulSite; ?>/assets/css/pages/slaymeet-call-ui.css?v=<?php echo $ulAssetV; ?>">
    <style>
<?php echo $slaymeetInlineStyles; ?>
    </style>
    <script src="<?php echo $ulSite; ?>/assets/js/slaymeet-agent.js?v=<?php echo $ulAssetV; ?>"></script>
    <?php echo $slaymeetConfigScript; ?>
</head>
<body<?php echo $isDmCall ? ' class="meet-dm-call"' : ''; ?>>
<?php
// Standalone meet shell (no dashboard chrome)
?>
<div class="meet-shell" id="meet-shell">
    <header class="sm-topbar">
        <div class="sm-topbar__brand">
            <span class="slayly-dashboard-nav-slay"><?php echo htmlspecialchars($meetBrand['prefix'], ENT_QUOTES, 'UTF-8'); ?></span><span class="slayly-dashboard-nav-accent"><?php echo htmlspecialchars($meetBrand['accent'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="sm-topbar__center">
            <h1 id="sm-meeting-title">Connecting…</h1>
            <span id="sm-participant-count" class="meta-sub">Preparing media…</span>
            <span id="meet-peer-name" style="display:none;"></span>
            <span id="meet-call-timer" style="display:none;">00:00</span>
            <span id="meetingStatusText" class="meta-sub" style="display:none;"></span>
        </div>
        <div class="sm-topbar__right">
            <div class="sm-topbar__rail-btns">
                <button type="button" class="sm-rail-tab" id="sm-rail-tab-people" data-rail="people">People</button>
                <button type="button" class="sm-rail-tab" id="sm-rail-tab-chat" data-rail="chat">Chat<span class="sm-rail-tab__badge" id="sm-chat-badge"></span></button>
            </div>
            <button class="btn-top has-badge" id="admit-guests-btn" style="display:none;" title="Admit guests">
                <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
                <span class="btn-label">Admit</span>
                <span class="btn-top-badge" id="admit-guests-count">0</span>
            </button>
            <button class="btn-top" id="copy-link" title="Copy invite">
                <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></span>
                <span class="btn-label">Copy</span>
            </button>
            <button class="btn-top" id="add-people" title="Invite">
                <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg></span>
                <span class="btn-label">Invite</span>
            </button>
        </div>
    </header>
    <div class="room-chip" id="roomChip" style="display:none;" aria-hidden="true"></div>
    <div id="room-meta" style="display:none;"></div>

    <div class="admit-queue-panel" id="admit-queue-panel">
        <div class="admit-queue-title">Waiting Room</div>
        <div id="admit-queue-list" class="admit-empty">No pending guests.</div>
    </div>

    <div class="meet-body" id="meet-body">
        <div class="meet-stage-wrap" id="meet-stage-wrap">
            <div id="captions-container" class="captions-overlay"></div>
            <div class="video-stage" id="video-stage"></div>
        </div>

        <aside class="sm-rail" id="sm-rail" aria-label="Meeting panel">
            <div class="sm-rail__header">
                <span class="sm-rail__title" id="sm-rail-title">People</span>
                <button type="button" class="sm-rail-close" id="close-drawer" aria-label="Close panel">&times;</button>
            </div>
            <div class="sm-rail__tabs">
                <button type="button" class="sm-rail-tab is-active" data-rail-panel="people">People</button>
                <button type="button" class="sm-rail-tab" data-rail-panel="chat">Chat</button>
            </div>
            <div class="sm-rail__panel is-active" id="sm-rail-people" data-panel="people">
                <div class="sm-rail__content">
                    <div class="participants-list" id="participants"></div>
                </div>
            </div>
            <div class="sm-rail__panel" id="sm-rail-chat" data-panel="chat">
                <div class="chat-container">
                    <div id="meet-chat-log" class="chat-log"></div>
                    <div class="chat-input-wrap">
                        <button id="meet-file-btn" type="button" style="background:transparent;border:none;color:inherit;cursor:pointer;padding:0 5px;" title="Share File">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                        </button>
                        <input id="meet-file-input" type="file" style="display:none;">
                        <input id="meet-chat-input" type="text" placeholder="Type a message…">
                        <button id="meet-chat-send" type="button">Send</button>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <div class="bottom-dock sm-dock">
        <button class="btn-round" id="toggle-audio" aria-pressed="true" title="Microphone">
            <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1v14"></path><rect x="9" y="1" width="6" height="14" rx="3"></rect><path d="M5 10a7 7 0 0 0 14 0"></path><path d="M12 21v2"></path><path d="M8 23h8"></path></svg></span>
            <span class="btn-label">Mic</span>
        </button>
        <button class="btn-round" id="toggle-video" aria-pressed="true" title="Camera">
            <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 7 6-4v18l-6-4"></path><rect x="2" y="5" width="14" height="14" rx="2"></rect></svg></span>
            <span class="btn-label">Cam</span>
        </button>
        <button class="btn-round" id="share-screen" title="Share screen">
            <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"></rect><path d="M8 20h8"></path><path d="M12 16v4"></path><path d="m10 10 2-2 2 2"></path><path d="M12 8v6"></path></svg></span>
            <span class="btn-label">Share</span>
        </button>
        <div class="dock-more-wrap">
            <button type="button" class="btn-round" id="dock-more-btn" aria-expanded="false" aria-haspopup="true" aria-controls="dock-more-menu" title="More">
                <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><circle cx="6" cy="12" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="18" cy="12" r="2"></circle></svg></span>
                <span class="btn-label">More</span>
            </button>
            <div class="dock-more-menu" id="dock-more-menu" role="menu" aria-hidden="true">
                <button type="button" class="dock-more-menu-item" id="raise-hand" role="menuitem">
                    <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 11V5a2 2 0 0 0-4 0v6"></path><path d="M14 10V4a2 2 0 0 0-4 0v7"></path><path d="M10 11V5a2 2 0 0 0-4 0v7"></path><path d="M6 13V7a2 2 0 0 0-4 0v7a9 9 0 0 0 9 9h1a9 9 0 0 0 9-9v-3a2 2 0 0 0-4 0v2"></path></svg></span>
                    <span class="btn-label">Raise hand</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="toggle-recording" style="display:none;" role="menuitem">
                    <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><circle id="rec-dot" cx="12" cy="12" r="3" fill="currentColor"></circle></svg></span>
                    <span class="btn-label" id="rec-label">Record</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="send-reaction" role="menuitem">
                    <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M8 15s1.5 2 4 2 4-2 4-2"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path></svg></span>
                    <span class="btn-label">React</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="refresh" role="menuitem">
                    <span class="btn-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M3 12a9 9 0 0 1 15.3-6.36L21 8"></path><path d="M21 3v5h-5"></path><path d="M21 12a9 9 0 0 1-15.3 6.36L3 16"></path><path d="M3 21v-5h5"></path></svg></span>
                    <span class="btn-label">Sync</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="toggle-whiteboard" role="menuitem">
                    <span class="btn-label">Whiteboard</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="toggle-captions" role="menuitem">
                    <span class="btn-label">Captions</span>
                </button>
                <button type="button" class="dock-more-menu-item" id="invite-ai" role="menuitem">
                    <span class="btn-label">Ask AI</span>
                </button>
                <div class="section-title" style="margin:8px 12px 4px;">Devices</div>
                <div class="device-selects" style="padding:0 12px 8px;">
                    <select id="mic-device"><option value="">Mic: Default</option></select>
                    <select id="cam-device"><option value="">Cam: Default</option></select>
                </div>
            </div>
        </div>
        <button class="btn-round btn-leave" id="leave" title="Leave meeting">
            <span class="btn-icon sm-dock-leave-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="2"><path d="M10 17l5-5-5-5"></path><path d="M15 12H3"></path></svg></span>
            <span class="btn-label">Leave</span>
        </button>
    </div>
</div>

<div class="sm-rail-backdrop" id="sm-rail-backdrop" aria-hidden="true"></div>
<div class="sm-rail-sheet" id="sm-rail-sheet" aria-hidden="true">
    <div class="sm-rail__header">
        <span class="sm-rail__title" id="sm-rail-sheet-title">People</span>
        <button type="button" class="sm-rail-close" id="sm-rail-sheet-close" aria-label="Close">&times;</button>
    </div>
    <div class="sm-rail__tabs">
        <button type="button" class="sm-rail-tab is-active" data-rail-sheet-panel="people">People</button>
        <button type="button" class="sm-rail-tab" data-rail-sheet-panel="chat">Chat</button>
    </div>
    <div class="sm-rail-sheet-body" id="sm-rail-sheet-body"></div>
</div>

<!-- Legacy id for scripts that referenced toggle-drawer -->
<button type="button" id="toggle-drawer" style="display:none;" aria-hidden="true"></button>
<div class="side-drawer" id="side-drawer" style="display:none;" aria-hidden="true"></div>

<div class="whiteboard-overlay" id="whiteboard-overlay" style="display:none;" aria-hidden="true">
    <button type="button" class="whiteboard-backdrop" id="wb-backdrop" aria-label="Close whiteboard and return to meeting"></button>
    <div class="whiteboard-panel" role="dialog" aria-modal="true" aria-labelledby="wb-title">
        <div class="whiteboard-header">
            <div class="wb-heading">
                <h2 id="wb-title">Whiteboard</h2>
                <p>Everyone in this room sees your strokes in real time. Open the board on your side to draw together.</p>
            </div>
            <div class="whiteboard-tools">
                <button type="button" class="tool-btn active" data-tool="pen" title="Pen">✏️</button>
                <button type="button" class="tool-btn" data-tool="eraser" title="Eraser">🧹</button>
                <span class="tool-sep" aria-hidden="true"></span>
                <span class="wb-brush-label">Size</span>
                <span class="wb-brush-row">
                    <button type="button" class="tool-btn wb-brush" data-brush="3" title="Thin">S</button>
                    <button type="button" class="tool-btn wb-brush active" data-brush="6" title="Medium">M</button>
                    <button type="button" class="tool-btn wb-brush" data-brush="14" title="Thick">L</button>
                </span>
                <span class="tool-sep" aria-hidden="true"></span>
                <button type="button" class="tool-btn wb-color-active" data-color="#ffffff" title="White" aria-label="White" style="background:#ffffff;width:28px;height:28px;border:1px solid rgba(0,0,0,0.25);"></button>
                <button type="button" class="tool-btn" data-color="#94a3b8" title="Gray" aria-label="Gray" style="background:#94a3b8;width:28px;height:28px;"></button>
                <button type="button" class="tool-btn" data-color="#ef4444" title="Red" aria-label="Red" style="background:#ef4444;width:28px;height:28px;"></button>
                <button type="button" class="tool-btn" data-color="#22c55e" title="Green" aria-label="Green" style="background:#22c55e;width:28px;height:28px;"></button>
                <button type="button" class="tool-btn" data-color="#eab308" title="Yellow" aria-label="Yellow" style="background:#eab308;width:28px;height:28px;"></button>
                <button type="button" class="tool-btn" data-color="#6366f1" title="Purple" aria-label="Purple" style="background:#6366f1;width:28px;height:28px;"></button>
                <button type="button" class="tool-btn" data-color="#0f172a" title="Black" aria-label="Black" style="background:#0f172a;width:28px;height:28px;border:1px solid rgba(255,255,255,0.2);"></button>
                <span class="tool-sep" aria-hidden="true"></span>
                <button type="button" class="tool-btn" id="wb-clear" title="Clear for everyone" style="width:auto;min-width:72px;padding:0 10px;font-size:12px;">Clear all</button>
            </div>
            <button type="button" class="wb-close-btn" id="wb-close">Back to meeting</button>
        </div>
        <div class="whiteboard-canvas-wrap">
            <canvas id="whiteboard-canvas" width="800" height="600"></canvas>
        </div>
    </div>
</div>

<div class="prejoin" id="prejoin-modal">
    <div class="prejoin-card">
        <div class="prejoin-header" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;">
            <div style="font-weight:700;">Ready to join <?php echo htmlspecialchars($meetBrand['full'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="opacity:.75;font-size:12px;">Permissions required: camera + microphone</div>
        </div>
        <div class="prejoin-grid">
            <div>
                <div class="prejoin-video">
                    <video id="prejoin-preview" autoplay playsinline muted></video>
                </div>
                <div class="prejoin-help" id="prejoin-help-text">Allow camera and microphone when browser asks. You can still join with mic/cam off later.</div>
            </div>
            <div>

                <div style="font-size:12px;opacity:.8;margin-bottom:4px;">Microphone</div>
                <select id="prejoin-mic" style="width:100%;margin-bottom:8px;background:#111;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px;">
                    <option value="">Default microphone</option>
                </select>
                <div style="font-size:12px;opacity:.8;margin-bottom:4px;">Camera</div>
                <select id="prejoin-cam" style="width:100%;margin-bottom:10px;background:#111;color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px;">
                    <option value="">Default camera</option>
                </select>
                <button class="prejoin-btn-secondary" id="prejoin-toggle-video" type="button">Camera On</button>
                <button class="prejoin-btn-secondary" id="prejoin-refresh">Retry Permissions</button>
                <button class="prejoin-join" id="prejoin-join" style="width:100%;">Join Now</button>
                <div class="prejoin-waiting-note" id="prejoin-waiting-note">Waiting for host approval. You will enter automatically once admitted.</div>
            </div>
        </div>
    </div>
</div>
<div id="audio-unlock-hint" class="audio-unlock-hint" role="status" aria-live="polite">
    <span>Tap to enable meeting audio</span>
    <button type="button" id="audio-unlock-btn" class="audio-unlock-btn">Enable Audio</button>
</div>
<script src="<?php echo $ulSite; ?>/assets/js/slaymeet-gallery.js?v=<?php echo $ulAssetV; ?>"></script>
<script src="<?php echo $ulSite; ?>/assets/vendor/livekit-client.umd.min.js?v=<?php echo $ulAssetV; ?>"></script>
<script src="<?php echo $ulSite; ?>/assets/js/slaymeet-livekit.js?v=<?php echo $ulAssetV; ?>"></script>
<script>
const bgCanvas = document.createElement('canvas');
const bgCtx = bgCanvas.getContext('2d');
const tmpVideo = document.createElement('video');
tmpVideo.setAttribute('autoplay', 'true');
tmpVideo.setAttribute('playsinline', 'true');
tmpVideo.setAttribute('muted', 'true');

// Offscreen buffer for mask processing (fixes inversion & improves perf)
const offCanvas = document.createElement('canvas');
const offCtx = offCanvas.getContext('2d');

let imageSegmenter = null;
let currentBgType = 'none'; // Forced rollback
let bgImageCache = null;
let bgFrameRequest = null;
let rawCameraTrack = null;
let processedStream = null;

// Motion smoothing & Perf buffers
const maskCanvas = document.createElement('canvas');
const maskCtx = maskCanvas.getContext('2d');
let lastMaskData = null; // Stored as Float32Array for temporal blending

// Initialize Grid UI to match saved preference
window.addEventListener('DOMContentLoaded', () => {
    const gridItems = document.querySelectorAll('.bg-option');
    gridItems.forEach(el => {
        el.classList.remove('active');
        if (el.getAttribute('data-bg') === currentBgType) el.classList.add('active');
    });
});

async function loadInitialBg() {
    if (currentBgType.startsWith('bg-')) {
        const ext = currentBgType === 'bg-designer-room' ? '.webp' : '.png';
        const img = new Image();
        img.src = SlayMeetConfig.siteUrl + '/assets/img/' + currentBgType + ext;
        img.crossOrigin = 'Anonymous';
        await new Promise(r => img.onload = r);
        bgImageCache = img;
    }
}

async function initMediaPipe() {
    if (imageSegmenter) return;
    await loadInitialBg();
    
    // Dynamic import to avoid "mediapipe is not defined" (ESM only library)
    const vision = await import('https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.2');
    
    const filesetResolver = await vision.FilesetResolver.forVisionTasks(
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.2/wasm"
    );
    imageSegmenter = await vision.ImageSegmenter.createFromOptions(filesetResolver, {
        baseOptions: {
            modelAssetPath: "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite",
            delegate: "GPU"
        },
        runningMode: "VIDEO",
        outputCategoryMask: false,
        outputConfidenceMasks: true
    });
}

function processSegmentation(results) {
    if (!results || !results.confidenceMasks) return;
    
    const maskObj = results.confidenceMasks[0];
    const mask = maskObj.getAsFloat32Array();
    const width = maskObj.width;
    const height = maskObj.height;

    // 1. TEMPORAL SMOOTHING (Anti-Jitter)
    if (!lastMaskData || lastMaskData.length !== mask.length) {
        lastMaskData = new Float32Array(mask);
    } else {
        for (let i = 0; i < mask.length; i++) {
            // Weighted average: 70% current, 30% last frame
            lastMaskData[i] = (mask[i] * 0.7) + (lastMaskData[i] * 0.3);
        }
    }

    // 2. LOW-RES MASK PROCESSING (Efficient)
    if (maskCanvas.width !== width || maskCanvas.height !== height) {
        maskCanvas.width = width;
        maskCanvas.height = height;
    }

    maskCtx.clearRect(0, 0, width, height);
    const mData = maskCtx.createImageData(width, height);
    for (let i = 0; i < lastMaskData.length; i++) {
        // Boosted Alpha Curve for sharpness
        let alpha = Math.min(255, lastMaskData[i] * 400); 
        mData.data[i * 4 + 3] = alpha;
    }
    maskCtx.putImageData(mData, 0, 0);

    // 3. HD SUBJECT EXTRACTION (GPU Accelerated & Sharp)
    if (offCanvas.width !== bgCanvas.width || offCanvas.height !== bgCanvas.height) {
        offCanvas.width = bgCanvas.width;
        offCanvas.height = bgCanvas.height;
    }

    offCtx.save();
    offCtx.clearRect(0, 0, offCanvas.width, offCanvas.height);
    
    // Draw blurred stencil from low-res mask (upscaled with filter)
    offCtx.filter = 'blur(5px)'; 
    offCtx.drawImage(maskCanvas, 0, 0, offCanvas.width, offCanvas.height);
    
    // Switch to source-in to keep only the intersection
    offCtx.globalCompositeOperation = 'source-in';
    
    // IMPORTANT: Turn off blur before drawing the actual person
    offCtx.filter = 'none'; 
    offCtx.drawImage(tmpVideo, 0, 0, offCanvas.width, offCanvas.height);
    offCtx.restore();

    // 3. Draw to MAIN canvas (Layered)
    bgCtx.save();
    bgCtx.clearRect(0, 0, bgCanvas.width, bgCanvas.height);
    
    // Bottom Layer: Virtual Background
    if (currentBgType === 'blur') {
        bgCtx.filter = 'blur(15px) brightness(0.85)';
        bgCtx.drawImage(tmpVideo, 0, 0, bgCanvas.width, bgCanvas.height);
        bgCtx.filter = 'none';
    } else if (currentBgType.startsWith('#')) {
        bgCtx.fillStyle = currentBgType;
        bgCtx.fillRect(0, 0, bgCanvas.width, bgCanvas.height);
    } else if (bgImageCache) {
        let hRatio = bgCanvas.width / bgImageCache.width;
        let vRatio = bgCanvas.height / bgImageCache.height;
        let ratio = Math.max(hRatio, vRatio);
        let cx = (bgCanvas.width - bgImageCache.width * ratio) / 2;
        let cy = (bgCanvas.height - bgImageCache.height * ratio) / 2;
        
        // Slightly dim the background to make the sharp subject "POP"
        bgCtx.filter = 'brightness(0.85)';
        bgCtx.drawImage(bgImageCache, 0, 0, bgImageCache.width, bgImageCache.height, cx, cy, bgImageCache.width * ratio, bgImageCache.height * ratio);
        bgCtx.filter = 'none';
    } else {
        bgCtx.fillStyle = '#0a0a0c';
        bgCtx.fillRect(0, 0, bgCanvas.width, bgCanvas.height);
    }
    
    // Top Layer: Extracted Human
    bgCtx.drawImage(offCanvas, 0, 0, bgCanvas.width, bgCanvas.height);
    
    bgCtx.restore();
}

async function startBgProcessing() {
    if (!rawCameraTrack) return;
    if (bgFrameRequest) cancelAnimationFrame(bgFrameRequest);
    
    tmpVideo.srcObject = new MediaStream([rawCameraTrack]);
    
    // Wait for HD metadata to ensure exact 1:1 pixel matching
    await new Promise(r => {
        tmpVideo.onloadedmetadata = () => {
            bgCanvas.width = tmpVideo.videoWidth || 640;
            bgCanvas.height = tmpVideo.videoHeight || 480;
            r();
        };
        // Fallback if already loaded
        if (tmpVideo.readyState >= 2) r();
    });

    await tmpVideo.play();
    
    await initMediaPipe();

    function renderLoop() {
        if (!rawCameraTrack || tmpVideo.paused || tmpVideo.ended) return;
        
        if (currentBgType === 'none') {
            bgCtx.drawImage(tmpVideo, 0, 0, bgCanvas.width, bgCanvas.height);
        } else if (imageSegmenter) {
            const startTimeMs = performance.now();
            imageSegmenter.segmentForVideo(tmpVideo, startTimeMs, processSegmentation);
        }
        
        bgFrameRequest = requestAnimationFrame(renderLoop);
    }
    
    renderLoop();
    
    if (!processedStream) {
        processedStream = bgCanvas.captureStream(30);
    }
}

// Background Selection Listener
document.addEventListener('click', async (e) => {
    const opt = e.target.closest('.bg-option');
    if (!opt) return;
    
    const type = opt.getAttribute('data-bg');
    currentBgType = type;
    localStorage.setItem('slaymeet_bg_type', type);
    
    // Sync UI: highlight correct option in ALL grids
    document.querySelectorAll('.bg-option').forEach(el => {
        el.classList.toggle('active', el.getAttribute('data-bg') === type);
    });
    
    if (type.startsWith('bg-')) {
        const ext = type === 'bg-designer-room' ? '.webp' : '.png';
        const img = new Image();
        img.src = SlayMeetConfig.siteUrl + '/assets/img/' + type + ext;
        img.crossOrigin = 'Anonymous';
        await new Promise(r => img.onload = r);
        bgImageCache = img;
    }

    if (rawCameraTrack) {
        if (currentBgType !== 'none') {
            await startBgProcessing();
            const newTrack = processedStream.getVideoTracks()[0];
            if (joined) {
                await replaceTrack('video', newTrack);
            } else {
                const preview = document.getElementById('prejoin-preview');
                if (preview) preview.srcObject = new MediaStream([newTrack]);
            }
        } else {
            const track = rawCameraTrack.clone();
            if (joined) {
                await replaceTrack('video', track);
            } else {
                const preview = document.getElementById('prejoin-preview');
                if (preview) preview.srcObject = new MediaStream([track]);
            }
        }
    }
});

function slayMeetToast(msg, kind) {
    try {
        let host = document.getElementById('slaymeet-toast-host');
        if (!host) {
            host = document.createElement('div');
            host.id = 'slaymeet-toast-host';
            host.style.cssText = 'position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:2147483647;display:flex;flex-direction:column;gap:8px;pointer-events:none;';
            document.body.appendChild(host);
        }
        const el = document.createElement('div');
        el.textContent = String(msg == null ? '' : msg);
        el.style.cssText = 'pointer-events:auto;max-width:min(92vw,440px);padding:12px 16px;border-radius:12px;font:600 13px/1.4 system-ui,-apple-system,sans-serif;color:#fff;box-shadow:0 8px 28px rgba(0,0,0,.35);background:' + (kind === 'error' ? '#dc2626' : '#4f46e5') + ';opacity:0;transform:translateY(-8px);transition:opacity .2s ease,transform .2s ease;';
        host.appendChild(el);
        requestAnimationFrame(() => { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(-8px)';
            setTimeout(() => { try { el.remove(); } catch (_) {} }, 260);
        }, kind === 'error' ? 6000 : 3500);
    } catch (_) {
        if (kind === 'error') { try { alert(msg); } catch (_) {} }
    }
}
function notifyErr(msg) { if (window.slayNotify) slayNotify.error(msg); else slayMeetToast(msg, 'error'); }
function notifyOk(msg) { if (window.slayNotify) slayNotify.success(msg); else slayMeetToast(msg, 'ok'); }
const peerConnections = new Map();
const remoteStreams = new Map();
const peerMeta = new Map();
const queuedIce = new Map();
const analyzers = new Map();
const participantMeta = new Map();
let localStream = null;
let lastSignalId = 0;
let signalTimer = null;
let signalPollDelayMs = 900;
let signalPollTimer = null;
let signalKickTimer = null;
let realtimeLoopsStarted = false;
let roomSignalingActive = false;
let signalEventSource = null;
let sseConnected = false;
let signalPollFallbackOnly = false;
const iceOutbox = new Map();
const iceOutboxTimers = new Map();
let stateTimer = null;
let sessionPingTimer = null;
let joined = false;
let sfuMode = false;
let meetCallTimerStartedAt = 0;
let meetCallTimerInterval = null;
let isPolling = false;
let handRaised = false;
let speakerTimer = null;
/** Speaking border for *remote* tiles uses room-wide signals (same analyser rarely works on received Opus). */
let peerSpeakingBroadcastActive = false;
let speakingBroadcastHangTimer = null;
const SPEAKING_DETECT_LEVEL = 16;
const SPEAKING_BROADCAST_HANG_MS = 480;
let isScreenSharing = false;
var activeScreenShareUserId = null;
let cameraTrackRef = null;
let prejoinStream = null;
// Internal 1:1 (DM) calls start with camera OFF like Microsoft Teams; instant
// meets keep camera on. Do not persist accidental prior OFF state across sessions.
let prejoinCameraEnabled = !SlayMeetConfig.isDmCall;
let isHostUser = false;
let waitingAdmissionPoll = null;
let pendingRequests = [];
let admissionJoinInProgress = false;
let currentRoomName = (SlayMeetConfig.productName || 'UltraMeet') + ' Meeting';
let audioUnlockNeeded = false;

function setAudioUnlockNeeded(on) {
    audioUnlockNeeded = !!on;
    const hint = document.getElementById('audio-unlock-hint');
    if (!hint) return;
    hint.classList.toggle('show', audioUnlockNeeded);
}

/** Call synchronously on Join click — browsers allow remote audio only after user gesture. */
function unlockMeetingAudioOnGesture() {
    setAudioUnlockNeeded(false);
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (Ctx) {
        if (!window.__slayMeetAudioCtx) window.__slayMeetAudioCtx = new Ctx();
        if (window.__slayMeetAudioCtx.state === 'suspended') {
            window.__slayMeetAudioCtx.resume().catch(() => {});
        }
    }
    nudgeRemoteAudioPlayback();
}

/** Lower user id initiates WebRTC offers — avoids offer glare / extra round trips. */
function shouldInitiateOffer(peerUserId) {
    return SlayMeetConfig.userId < peerUserId;
}

function peersNeedFastPoll() {
    if (!peerConnections.size) return roomSignalingActive;
    for (const pc of peerConnections.values()) {
        const st = pc.iceConnectionState;
        if (st === 'new' || st === 'checking' || st === 'disconnected') return true;
        if (st !== 'connected' && st !== 'completed') return true;
    }
    return false;
}

function updatePrejoinCameraUi() {
    const btn = document.getElementById('prejoin-toggle-video');
    const wrap = document.querySelector('.prejoin-video');
    if (btn) {
        btn.textContent = prejoinCameraEnabled ? 'Camera On' : 'Camera Off';
        btn.classList.toggle('prejoin-video-toggle-off', !prejoinCameraEnabled);
    }
    if (wrap) {
        wrap.classList.toggle('prejoin-video-off', !prejoinCameraEnabled);
    }
}

async function api(path, opts = {}) {
    try {
        // Enforce credentials and headers for live site session stability
        const fetchOpts = {
            ...opts,
            credentials: 'include',
            headers: {
                ...(opts.headers || {}),
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        const res = await fetch(SlayMeetConfig.siteUrl + path, fetchOpts);
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error(`[SlayMeet API Error] Non-JSON response from ${path}:`, text.substring(0, 100));
            throw new Error('Invalid server response (Non-JSON)');
        }
        if (!res.ok || !data.success) throw new Error(data.message || data.error || 'Request failed');
        return data;
    } catch (err) {
        console.warn(`[SlayMeet API] Fetch failed for ${path}:`, err.message);
        throw err;
    }
}
function formatMeetCallElapsed(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m < 10 ? '0' : ''}${m}:${r < 10 ? '0' : ''}${r}`;
}

function startMeetCallTimer() {
    if (!SlayMeetConfig.isDmCall) return;
    if (meetCallTimerInterval) return;
    meetCallTimerStartedAt = Date.now();
    const timerEl = document.getElementById('meet-call-timer');
    const nameEl = document.getElementById('meet-peer-name');
    if (timerEl) timerEl.style.display = '';
    if (nameEl) nameEl.style.display = '';
    const tick = () => {
        if (timerEl && meetCallTimerStartedAt) {
            timerEl.textContent = formatMeetCallElapsed(Date.now() - meetCallTimerStartedAt);
        }
    };
    tick();
    meetCallTimerInterval = setInterval(tick, 1000);
}

function updateDmCallPeerHeader(participants) {
    if (!SlayMeetConfig.isDmCall) return;
    const nameEl = document.getElementById('meet-peer-name');
    if (!nameEl) return;
    const remote = (participants || []).find((p) => parseInt(p.user_id, 10) !== parseInt(SlayMeetConfig.userId, 10));
    nameEl.textContent = remote && remote.name ? remote.name : 'Connecting…';
    nameEl.style.display = '';
    const timerEl = document.getElementById('meet-call-timer');
    if (timerEl) timerEl.style.display = '';
}

function renderState(data) {
    const room = data.room || {};
    const ps = data.participants || [];
    pendingRequests = Array.isArray(data.pending_requests) ? data.pending_requests : [];
    isHostUser = parseInt(room.created_by || 0, 10) === parseInt(SlayMeetConfig.userId, 10);
    currentRoomName = room.room_name || 'Meeting';
    ps.forEach(upsertParticipantMeta);
    document.getElementById('room-meta').innerHTML = `
        <div><strong>${room.room_name || 'Room'}</strong></div>
        <div class="meta-sub">Status: ${room.status || 'unknown'} • Starts: ${room.starts_at || '-'}</div>
    `;
    document.getElementById('roomChip').textContent = `Room: ${room.room_name || 'Unknown'}`;
    const titleEl = document.getElementById('sm-meeting-title');
    if (titleEl) titleEl.textContent = room.room_name || 'Meeting';
    const countEl = document.getElementById('sm-participant-count');
    const countLabel = `${ps.length} participant${ps.length === 1 ? '' : 's'}`;
    if (countEl) countEl.textContent = countLabel;
    const statusEl = document.getElementById('meetingStatusText');
    if (statusEl) statusEl.textContent = countLabel;
    updateDmCallPeerHeader(ps);
    if (SlayMeetConfig.isDmCall && joined) {
        startMeetCallTimer();
    }
    document.getElementById('participants').innerHTML = ps.length
        ? ps.map(p => `<div class="p-row">${p.name} <span style="opacity:.6;">(${p.role})</span></div>`).join('')
        : '<div style="opacity:.65;">No active participants</div>';
    renderAdmissionQueue();
    syncParticipantTiles();

    // Show Record in More menu for host
    const recBtn = document.getElementById('toggle-recording');
    if (recBtn) {
        recBtn.style.display = isHostUser ? 'flex' : 'none';
    }
}

function renderAdmissionQueue() {
    const btn = document.getElementById('admit-guests-btn');
    const count = document.getElementById('admit-guests-count');
    const panel = document.getElementById('admit-queue-panel');
    const list = document.getElementById('admit-queue-list');
    if (!btn || !count || !panel || !list) return;

    if (!isHostUser) {
        btn.style.display = 'none';
        panel.classList.remove('open');
        return;
    }

    // Add Mute All button to participants list for host
    const pList = document.getElementById('participants');
    if (pList && !pList.querySelector('.mute-all-btn')) {
        const muteAll = document.createElement('button');
        muteAll.className = 'btn-top mute-all-btn';
        muteAll.style.width = '100%';
        muteAll.style.marginBottom = '15px';
        muteAll.innerHTML = '🔇 Mute All Participants';
        muteAll.onclick = async () => {
            if (confirm('Mute everyone?')) {
                sendSignal('system', { type: 'mute_all' });
                notifyOk('Mute All signal sent');
            }
        };
        pList.prepend(muteAll);
    }

    const c = pendingRequests.length;
    btn.style.display = 'inline-flex';
    count.textContent = String(c);
    count.style.display = c > 0 ? 'inline-block' : 'none';

    if (c === 0) {
        list.className = 'admit-empty';
        list.textContent = 'No pending guests.';
        return;
    }

    list.className = '';
    list.innerHTML = pendingRequests.map((p) => `
        <div class="admit-request-item">
            <div>
                <div class="admit-request-name">${escHtml(p.name || `Guest ${p.user_id}`)}</div>
                <div class="admit-request-meta">Waiting to join</div>
            </div>
            <div class="admit-actions">
                <button class="admit-btn accept" data-action="admit" data-user-id="${parseInt(p.user_id, 10)}">Admit</button>
                <button class="admit-btn deny" data-action="deny" data-user-id="${parseInt(p.user_id, 10)}">Deny</button>
            </div>
        </div>
    `).join('');
}

async function hostUpdateAdmission(participantUserId, action) {
    const fd = new FormData();
    fd.append('room', SlayMeetConfig.room);
    fd.append('participant_user_id', String(participantUserId));
    fd.append('action', action);
    await api('/api/slaymeet/update_admission.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
        body: fd
    });
}

function getInitials(name) {
    if (!name) return 'S';
    // Remove (Guest) marker for clean initials
    const cleanName = name.replace(/\(Guest\)/i, '').trim();
    const parts = cleanName.split(' ').filter(p => p.length > 0);
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return parts[0] ? parts[0][0].toUpperCase() : 'S';
}

function resolveProfilePicUrl(rawPath) {
    const raw = String(rawPath || '').trim();
    if (!raw) return '';
    if (/^https?:\/\//i.test(raw)) return raw;
    const normalized = raw.replace(/^\/+/, '');
    if (normalized.startsWith('uploads/')) return `${SlayMeetConfig.siteUrl}/${normalized}`;
    return `${SlayMeetConfig.siteUrl}/uploads/${normalized}`;
}

function upsertParticipantMeta(p) {
    const uid = parseInt(p.user_id, 10);
    if (!uid) return;
    participantMeta.set(uid, {
        name: String(p.name || `User ${uid}`),
        profilePic: resolveProfilePicUrl(p.profile_pic || '')
    });
}

function syncParticipantTiles() {
    participantMeta.forEach((meta, uid) => {
        const tile = document.querySelector(`.tile[data-user-id="${uid}"]`);
        if (!tile) return;
        const nameEl = tile.querySelector('.name');
        if (nameEl) nameEl.textContent = meta.name;
        const avatarCircle = tile.querySelector('.avatar-circle');
        if (!avatarCircle) return;
        if (meta.profilePic) {
            avatarCircle.innerHTML = `<img class="avatar-photo" src="${escHtml(meta.profilePic)}" alt="${escHtml(meta.name)}">`;
        } else {
            avatarCircle.innerHTML = `<div class="avatar-initials">${escHtml(getInitials(meta.name))}</div>`;
        }
    });
}

function tileHtml(id, name, muted, profilePic = '') {
    const safeName = String(name || `User ${id}`);
    const picUrl = resolveProfilePicUrl(profilePic);
    const avatarMarkup = picUrl
        ? `<img class="avatar-photo" src="${escHtml(picUrl)}" alt="${escHtml(safeName)}">`
        : `<div class="avatar-initials">${escHtml(getInitials(safeName))}</div>`;
    const micIcon = `
        <div class="mic-indicator" title="Microphone Status">
            <svg viewBox="0 0 24 24">
                <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/>
                <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/>
                <path class="strike" d="M4.41 2.86L2.86 4.41l16.73 16.73 1.55-1.55L4.41 2.86z" />
            </svg>
        </div>
    `;
    /* Remote: video shows picture (muted); audio plays via <audio> — fixes Chrome/Edge “video OK, no sound”. */
    const remoteAudioSink =
        !muted ?
            '<audio class="tile-remote-audio" autoplay playsinline aria-hidden="true" style="position:absolute;width:0;height:0;opacity:0;pointer-events:none;z-index:-1"></audio>'
        : '';
    const connectPill = muted ? '' : '<div class="connection-pill" aria-live="polite">Connecting…</div>';
    return `
        <div class="tile" data-user-id="${id}" data-connection-state="${muted ? 'local' : 'connecting'}">
            <div class="avatar-circle">
                ${avatarMarkup}
            </div>
            <video muted autoplay playsinline></video>
            ${remoteAudioSink}
            ${connectPill}
            ${micIcon}
            <div class="name">${escHtml(safeName)}</div>
        </div>
    `;
}

function updatePeerConnectionState(userId, state) {
    const uid = parseInt(userId, 10);
    if (!uid || uid === SlayMeetConfig.userId) return;
    const tile = document.querySelector(`.tile[data-user-id="${uid}"]`);
    if (!tile) return;
    tile.dataset.connectionState = state;
    const pill = tile.querySelector('.connection-pill');
    if (!pill) return;
    if (state === 'connecting') {
        pill.textContent = 'Connecting…';
        pill.style.display = '';
    } else if (state === 'connected') {
        pill.style.display = 'none';
    } else if (state === 'failed') {
        pill.textContent = 'Reconnecting…';
        pill.style.display = '';
    }
    refreshMeetStatusBar();
}

function refreshMeetStatusBar() {
    const el = document.getElementById('meetingStatusText');
    if (!el || !joined) return;
    if (SlayMeetConfig.isDmCall) return;
    if (sfuMode) {
        el.innerHTML = '<span class="meet-live-dot" aria-hidden="true"></span>Live • SFU';
        return;
    }
    let connecting = 0;
    let failed = 0;
    let live = 0;
    peerConnections.forEach((pc, uid) => {
        if (parseInt(uid, 10) === SlayMeetConfig.userId) return;
        const ice = pc.iceConnectionState;
        if (ice === 'connected' || ice === 'completed') live++;
        else if (ice === 'failed') failed++;
        else connecting++;
    });
    if (connecting > 0 && live === 0) {
        el.textContent = 'Connecting audio…';
    } else if (failed > 0) {
        el.textContent = 'Reconnecting…';
    } else if (live > 0) {
        el.innerHTML = '<span class="meet-live-dot" aria-hidden="true"></span>Live • Secured';
    }
}

function updateTileMediaState(userId, sub, enabled) {
    const tile = document.querySelector(`.tile[data-user-id="${userId}"]`);
    if (!tile) return;
    
    if (sub === 'video') {
        tile.classList.toggle('video-off', !enabled);
        if (typeof refreshGridLayout === 'function') refreshGridLayout();
    } else if (sub === 'mic') {
        const mic = tile.querySelector('.mic-indicator');
        if (mic) mic.classList.toggle('muted', !enabled);
        const audioOut = tile.querySelector('.tile-remote-audio');
        if (audioOut) audioOut.muted = !enabled;
    }
}
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
}
function addChatLine(name, text, isSelf = false) {
    const host = document.getElementById('meet-chat-log');
    if (!host) return;
    const line = document.createElement('div');
    line.className = 'chat-item';
    const who = isSelf ? 'You' : (name || 'User');
    line.innerHTML = `<b>${escHtml(who)}:</b> ${escHtml(text)}`;
    host.appendChild(line);
    host.scrollTop = host.scrollHeight;
}

function ensureLocalTile() {
    const stage = document.getElementById('video-stage');
    if (!stage.querySelector(`[data-user-id="${SlayMeetConfig.userId}"]`)) {
        const meta = participantMeta.get(parseInt(SlayMeetConfig.userId, 10));
        const localName = meta && meta.name ? `${meta.name} (You)` : `${SlayMeetConfig.userName} (You)`;
        const localPic = meta && meta.profilePic ? meta.profilePic : resolveProfilePicUrl(SlayMeetConfig.userProfilePic || '');
        stage.insertAdjacentHTML('afterbegin', tileHtml(SlayMeetConfig.userId, localName, true, localPic));
        refreshGridLayout();
    }
}

function ensureRemoteTile(userId, name, profilePic = '') {
    const stage = document.getElementById('video-stage');
    const meta = participantMeta.get(parseInt(userId, 10));
    const resolvedName = meta && meta.name ? meta.name : (name || `User ${userId}`);
    const resolvedPic = meta && meta.profilePic ? meta.profilePic : resolveProfilePicUrl(profilePic);
    const existing = stage.querySelector(`[data-user-id="${userId}"]`);
    if (!existing) {
        stage.insertAdjacentHTML('beforeend', tileHtml(userId, resolvedName, false, resolvedPic));
        refreshGridLayout();
        return;
    }
    const nameEl = existing.querySelector('.name');
    if (nameEl) nameEl.textContent = resolvedName;
    const avatarCircle = existing.querySelector('.avatar-circle');
    if (avatarCircle && resolvedPic) {
        avatarCircle.innerHTML = `<img class="avatar-photo" src="${escHtml(resolvedPic)}" alt="${escHtml(resolvedName)}">`;
    }
}

function removeRemoteTile(userId) {
    const node = document.querySelector(`#video-stage [data-user-id="${userId}"]`);
    if (node && parseInt(userId, 10) !== SlayMeetConfig.userId) node.remove();
    if (parseInt(activeScreenShareUserId, 10) === parseInt(userId, 10)) {
        activeScreenShareUserId = null;
    }
    refreshGridLayout();
}

/* Gallery layout: public/assets/js/slaymeet-gallery.js */

function setTileBadge(userId, text, ttlMs = 3500) {
    const tile = document.querySelector(`#video-stage [data-user-id="${userId}"]`);
    if (!tile) return;
    let badge = tile.querySelector('.badge');
    if (!badge) {
        badge = document.createElement('div');
        badge.className = 'badge';
        tile.appendChild(badge);
    }
    badge.textContent = text;
    if (ttlMs > 0) {
        setTimeout(() => {
            if (badge && badge.parentNode) badge.remove();
        }, ttlMs);
    }
}

function setSpeaking(userId, speaking) {
    const tile = document.querySelector(`#video-stage [data-user-id="${userId}"]`);
    if (!tile) return;
    tile.classList.toggle('speaking', !!speaking);
    if (typeof refreshGridLayout === 'function') refreshGridLayout();
}

function startAudioAnalyzer(userId, stream) {
    if (!stream) return;
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const ctx = new AudioCtx();
    if (ctx.state === 'suspended') {
        ctx.resume().catch(() => {});
    }
    const src = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 256;
    src.connect(analyser);
    const data = new Uint8Array(analyser.frequencyBinCount);
    analyzers.set(userId, { ctx, src, analyser, data });
}

function stopAudioAnalyzer(userId) {
    const a = analyzers.get(userId);
    if (!a) return;
    try { a.src.disconnect(); } catch (_) {}
    try { a.analyser.disconnect(); } catch (_) {}
    try { a.ctx.close(); } catch (_) {}
    analyzers.delete(userId);
}

function resetSpeakingBroadcastState() {
    if (speakingBroadcastHangTimer) {
        clearTimeout(speakingBroadcastHangTimer);
        speakingBroadcastHangTimer = null;
    }
    peerSpeakingBroadcastActive = false;
}

function forceSpeakingBroadcastOff() {
    if (speakingBroadcastHangTimer) {
        clearTimeout(speakingBroadcastHangTimer);
        speakingBroadcastHangTimer = null;
    }
    if (!peerSpeakingBroadcastActive) return;
    peerSpeakingBroadcastActive = false;
    sendSignal('system', { type: 'speaking', active: false }).catch(() => {});
}

function sendSpeakingBroadcast(active) {
    sendSignal('system', { type: 'speaking', active: !!active }).catch(() => {});
}

function queueSpeakingBroadcast(shouldShow) {
    if (!joined) return;

    if (shouldShow) {
        if (speakingBroadcastHangTimer) {
            clearTimeout(speakingBroadcastHangTimer);
            speakingBroadcastHangTimer = null;
        }
        if (!peerSpeakingBroadcastActive) {
            peerSpeakingBroadcastActive = true;
            sendSpeakingBroadcast(true);
        }
        return;
    }

    if (!peerSpeakingBroadcastActive) return;

    if (!speakingBroadcastHangTimer) {
        speakingBroadcastHangTimer = setTimeout(() => {
            speakingBroadcastHangTimer = null;
            peerSpeakingBroadcastActive = false;
            sendSpeakingBroadcast(false);
        }, SPEAKING_BROADCAST_HANG_MS);
    }
}

function monitorActiveSpeakers() {
    const myId = parseInt(String(SlayMeetConfig.userId), 10);
    for (const [uid, a] of analyzers.entries()) {
        a.analyser.getByteFrequencyData(a.data);
        let sum = 0;
        for (let i = 0; i < a.data.length; i++) sum += a.data[i];
        const avg = sum / a.data.length;
        const uidInt = parseInt(String(uid), 10);
        if (uidInt === myId) {
            const micOn = !!(localStream && localStream.getAudioTracks().some((t) => t.enabled));
            const localSpeaking = micOn && avg > SPEAKING_DETECT_LEVEL;
            setSpeaking(uidInt, localSpeaking);
            queueSpeakingBroadcast(localSpeaking);
        }
        /* Remote “speaking” rings: driven only by payload.type === speaking (see handleSignal). */
    }
}

/**
 * MeetingRecorder: Captures the meeting stage and uploads to Workplace Drive.
 * Designed for SlayMeet Mesh architecture.
 */
class MeetingRecorder {
    constructor() {
        this.mediaRecorder = null;
        this.chunks = [];
        this.recordingId = Math.random().toString(36).substring(2, 11);
        this.startTime = null;
        this.isRecording = false;
        this.stream = null;
        this.audioNode = null;
        this.audioCtx = null;
        this.recordCanvas = null;
        this.recordCtx = null;
        this.recordRaf = null;
    }

    /** `#video-stage` is a div — only canvas/video support captureStream. Composite tiles into an offscreen canvas. */
    compositeStageFrame() {
        const stage = document.getElementById('video-stage');
        const ctx = this.recordCtx;
        const canvas = this.recordCanvas;
        if (!stage || !ctx || !canvas) return;
        const cw = canvas.width;
        const ch = canvas.height;
        const sr = stage.getBoundingClientRect();
        if (sr.width < 2 || sr.height < 2) return;
        const scaleX = cw / sr.width;
        const scaleY = ch / sr.height;
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, cw, ch);
        stage.querySelectorAll('.tile').forEach((tile) => {
            const video = tile.querySelector('video');
            if (!video || !video.videoWidth) return;
            const tr = video.getBoundingClientRect();
            const x = (tr.left - sr.left) * scaleX;
            const y = (tr.top - sr.top) * scaleY;
            const w = tr.width * scaleX;
            const h = tr.height * scaleY;
            try {
                ctx.drawImage(video, x, y, w, h);
            } catch (_) { /* drawImage security / decode */ }
        });
    }

    canvasCaptureStream(canvas, fps) {
        if (canvas.captureStream) return canvas.captureStream(fps);
        if (canvas.mozCaptureStream) return canvas.mozCaptureStream(fps);
        return null;
    }

    pickMediaRecorderOptions() {
        const candidates = [
            { mimeType: 'video/webm;codecs=vp9,opus', videoBitsPerSecond: 2500000 },
            { mimeType: 'video/webm;codecs=vp8,opus', videoBitsPerSecond: 2500000 },
            { mimeType: 'video/webm;codecs=vp8', videoBitsPerSecond: 2500000 },
            {}
        ];
        for (let i = 0; i < candidates.length; i++) {
            const o = candidates[i];
            if (!o.mimeType || (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(o.mimeType))) {
                return o;
            }
        }
        return {};
    }

    async start() {
        if (this.isRecording) return;
        
        try {
            const stage = document.getElementById('video-stage');
            if (!stage) throw new Error('Stage not found');

            this.recordCanvas = document.createElement('canvas');
            this.recordCanvas.width = 1280;
            this.recordCanvas.height = 720;
            this.recordCtx = this.recordCanvas.getContext('2d', { alpha: false });
            if (!this.recordCtx) throw new Error('Could not create recording canvas');

            const canvasStream = this.canvasCaptureStream(this.recordCanvas, 30);
            const vt = canvasStream && canvasStream.getVideoTracks ? canvasStream.getVideoTracks()[0] : null;
            if (!canvasStream || !vt) {
                throw new Error('This browser does not support canvas.captureStream(). Try Chrome, Edge, or Firefox.');
            }

            const loop = () => {
                if (!this.isRecording) return;
                this.compositeStageFrame();
                this.recordRaf = requestAnimationFrame(loop);
            };

            // 2. Mix Audio Sources
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            let audioTrack = null;
            if (AudioCtx) {
                this.audioCtx = new AudioCtx();
                if (this.audioCtx.state === 'suspended') {
                    await this.audioCtx.resume().catch(() => {});
                }
                const destination = this.audioCtx.createMediaStreamDestination();

                if (localStream && localStream.getAudioTracks().length > 0) {
                    const source = this.audioCtx.createMediaStreamSource(new MediaStream([localStream.getAudioTracks()[0]]));
                    source.connect(destination);
                }

                remoteStreams.forEach((stream) => {
                    if (stream.getAudioTracks().length > 0) {
                        const source = this.audioCtx.createMediaStreamSource(new MediaStream([stream.getAudioTracks()[0]]));
                        source.connect(destination);
                    }
                });

                const at = destination.stream.getAudioTracks()[0];
                audioTrack = at || null;
            }

            const tracks = audioTrack ? [vt, audioTrack] : [vt];
            this.stream = new MediaStream(tracks);

            const options = this.pickMediaRecorderOptions();
            try {
                this.mediaRecorder = new MediaRecorder(this.stream, options);
            } catch (e1) {
                this.mediaRecorder = new MediaRecorder(this.stream);
            }

            this.mediaRecorder.ondataavailable = (e) => {
                if (e.data.size > 0) {
                    this.chunks.push(e.data);
                }
            };

            this.mediaRecorder.onstop = async () => {
                notifyOk('Processing recording...');
                await this.save();
            };

            this.isRecording = true;
            this.startTime = Date.now();
            this.recordRaf = requestAnimationFrame(loop);

            // Start recording in 5 second segments to handle data available
            this.mediaRecorder.start(5000);

            this.updateUI(true);
            sendSignal('system', { type: 'recording_state', active: true });
            
        } catch (err) {
            console.error('Recording Start Error:', err);
            this.isRecording = false;
            if (this.recordRaf != null) {
                cancelAnimationFrame(this.recordRaf);
                this.recordRaf = null;
            }
            if (this.audioCtx) {
                try { this.audioCtx.close(); } catch (_) {}
                this.audioCtx = null;
            }
            this.recordCanvas = null;
            this.recordCtx = null;
            notifyErr('Failed to start recording: ' + err.message);
        }
    }

    stop() {
        if (!this.isRecording) return;
        this.isRecording = false;
        if (this.recordRaf != null) {
            cancelAnimationFrame(this.recordRaf);
            this.recordRaf = null;
        }
        this.recordCanvas = null;
        this.recordCtx = null;

        try {
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                this.mediaRecorder.stop();
            }
        } catch (_) {}

        this.updateUI(false);
        sendSignal('system', { type: 'recording_state', active: false });

        if (this.audioCtx) {
            try { this.audioCtx.close(); } catch (_) {}
            this.audioCtx = null;
        }
    }

    updateUI(active) {
        const btn = document.getElementById('toggle-recording');
        const dot = document.getElementById('rec-dot');
        const label = document.getElementById('rec-label');
        if (btn) btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (dot) dot.setAttribute('fill', active ? '#ef4444' : 'currentColor');
        if (label) label.textContent = active ? 'Stop' : 'Record';
        
        const status = document.getElementById('meetingStatusText');
        if (active) {
            status.innerHTML = '<span style="color:#ef4444;font-weight:800;">● REC</span> • Live';
        } else {
            status.textContent = 'Meeting Live • Secured';
        }
    }

    async save() {
        const blob = new Blob(this.chunks, { type: 'video/webm' });
        const fd = new FormData();
        fd.append('video_blob', blob);
        fd.append('room_name', currentRoomName);
        fd.append('recording_id', this.recordingId);
        fd.append('room', SlayMeetConfig.room);

        try {
            const data = await api('/api/slaymeet/upload_recording.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
                body: fd
            });
            if (data.success) {
                const extra = data.share_note ? ` ${data.share_note}` : '';
                notifyOk((data.message || 'Recording saved to Workplace Drive.') + extra);
                this.chunks = [];
            }
        } catch (err) {
            notifyErr('Could not upload to Workplace Drive (Meet Recordings): ' + err.message);
            if (typeof window.confirm === 'function' &&
                confirm('Download a backup copy of this recording to your computer? (Fix upload errors so the file goes to Workplace Drive instead.)')) {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `meeting_${this.recordingId}.webm`;
                a.click();
                setTimeout(() => URL.revokeObjectURL(url), 60000);
            }
        }
    }
}

const recorder = new MeetingRecorder();

/**
 * SlayBoard: room-wide strokes via sendSignal('system', …) with no to_user_id (broadcast in DB).
 */
class SlayBoard {
    constructor() {
        this.overlay = document.getElementById('whiteboard-overlay');
        this.canvas = document.getElementById('whiteboard-canvas');
        if (!this.canvas) {
            this.ctx = null;
            console.warn('[SlayBoard] #whiteboard-canvas not found; whiteboard disabled.');
            return;
        }
        this.ctx = this.canvas.getContext('2d');
        this.isDrawing = false;
        this.currentTool = 'pen';
        this.currentColor = '#ffffff';
        this.brushSize = 6;
        this.lastX = 0;
        this.lastY = 0;
        
        this.init();
    }

    localStrokeWidth() {
        if (this.currentTool === 'eraser') {
            return Math.max(this.brushSize * 5, 26);
        }
        return this.brushSize;
    }

    isOpen() {
        return this.overlay && this.overlay.style.display === 'flex';
    }

    close() {
        if (!this.overlay) return;
        this.overlay.style.display = 'none';
        this.overlay.setAttribute('aria-hidden', 'true');
    }

    init() {
        if (!this.canvas || !this.ctx) return;
        window.addEventListener('resize', () => {
            if (this.isOpen()) this.resize();
        });

        this.canvas.addEventListener('mousedown', (e) => this.startDrawing(e));
        this.canvas.addEventListener('mousemove', (e) => this.draw(e));
        this.canvas.addEventListener('mouseup', () => this.stopDrawing());
        this.canvas.addEventListener('mouseout', () => this.stopDrawing());

        this.canvas.addEventListener('touchstart', (e) => {
            if (!e.touches || !e.touches[0]) return;
            this.startDrawing(e.touches[0]);
        }, { passive: true });
        this.canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            if (e.touches && e.touches[0]) this.draw(e.touches[0]);
        }, { passive: false });
        this.canvas.addEventListener('touchend', () => this.stopDrawing());
        this.canvas.addEventListener('touchcancel', () => this.stopDrawing());

        const root = this.overlay || document;
        root.querySelectorAll('.tool-btn[data-tool]').forEach((btn) => {
            btn.addEventListener('click', () => {
                root.querySelectorAll('.tool-btn[data-tool]').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentTool = btn.dataset.tool || 'pen';
            });
        });

        root.querySelectorAll('.tool-btn[data-brush]').forEach((btn) => {
            btn.addEventListener('click', () => {
                root.querySelectorAll('.tool-btn[data-brush]').forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                const w = parseInt(btn.dataset.brush, 10);
                if (w > 0) this.brushSize = w;
            });
        });

        root.querySelectorAll('.tool-btn[data-color]').forEach((btn) => {
            btn.addEventListener('click', () => {
                root.querySelectorAll('.tool-btn[data-color]').forEach((b) => b.classList.remove('wb-color-active'));
                btn.classList.add('wb-color-active');
                this.currentColor = btn.dataset.color || '#ffffff';
            });
        });

        const wbClear = document.getElementById('wb-clear');
        if (wbClear) {
            wbClear.addEventListener('click', () => {
                if (confirm('Clear the board for everyone in this room?')) {
                    this.clear();
                    sendSignal('system', { type: 'wb_clear' });
                }
            });
        }

        const onClose = () => this.close();
        const wbClose = document.getElementById('wb-close');
        if (wbClose) wbClose.addEventListener('click', onClose);
        const wbBackdrop = document.getElementById('wb-backdrop');
        if (wbBackdrop) wbBackdrop.addEventListener('click', onClose);

        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if (this.isOpen()) this.close();
        });
    }

    resize() {
        if (!this.canvas || !this.ctx) return;
        const wrap = document.querySelector('.whiteboard-canvas-wrap');
        const w = wrap ? wrap.clientWidth : window.innerWidth;
        const h = wrap ? wrap.clientHeight : Math.max(240, window.innerHeight - 120);
        this.canvas.width = Math.max(320, Math.floor(w));
        this.canvas.height = Math.max(240, Math.floor(h));
        this.redraw();
    }

    startDrawing(e) {
        if (!this.canvas || !this.ctx) return;
        if (typeof e.clientX !== 'number' || typeof e.clientY !== 'number') return;
        this.isDrawing = true;
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;
        [this.lastX, this.lastY] = [(e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY];
    }

    draw(e, remote = false, data = null) {
        if (!this.canvas || !this.ctx) return;
        if (!this.isDrawing && !remote) return;

        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;

        let x;
        let y;
        let lx;
        let ly;
        let color;
        let lw;

        if (remote && data) {
            const cw = this.canvas.width;
            const ch = this.canvas.height;
            if (typeof data.nx === 'number' && typeof data.ny === 'number') {
                x = data.nx * cw;
                y = data.ny * ch;
                lx = (typeof data.nlx === 'number' ? data.nlx : data.nx) * cw;
                ly = (typeof data.nly === 'number' ? data.nly : data.ny) * ch;
            } else {
                x = data.x;
                y = data.y;
                lx = data.lx;
                ly = data.ly;
            }
            color = data.color || '#ffffff';
            const minSide = Math.min(this.canvas.width, this.canvas.height);
            if (typeof data.nlw === 'number' && minSide > 0) {
                lw = Math.max(1, Math.min(220, data.nlw * minSide));
            } else {
                lw = typeof data.lineWidth === 'number' ? data.lineWidth : 3;
            }
        } else {
            if (!e || typeof e.clientX !== 'number') return;
            x = (e.clientX - rect.left) * scaleX;
            y = (e.clientY - rect.top) * scaleY;
            lx = this.lastX;
            ly = this.lastY;
            color = this.currentTool === 'eraser' ? '#0f172a' : this.currentColor;
            lw = this.localStrokeWidth();
        }

        this.ctx.beginPath();
        this.ctx.moveTo(lx, ly);
        this.ctx.lineTo(x, y);
        this.ctx.strokeStyle = color;
        this.ctx.lineWidth = lw;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.stroke();

        if (!remote) {
            const cw = this.canvas.width;
            const ch = this.canvas.height;
            if (cw < 1 || ch < 1) return;
            const base = Math.min(cw, ch);
            this.throttleSignal({
                nx: x / cw,
                ny: y / ch,
                nlx: lx / cw,
                nly: ly / ch,
                nlw: lw / base,
                color,
                lineWidth: lw,
                tool: this.currentTool
            });
            [this.lastX, this.lastY] = [x, y];
        }
    }

    throttleSignal(point) {
        if (!this.signalBuffer) this.signalBuffer = [];
        this.signalBuffer.push(point);

        if (!this.signalTimer) {
            this.signalTimer = setTimeout(() => {
                sendSignal('system', { type: 'wb_draw', points: this.signalBuffer });
                this.signalBuffer = [];
                this.signalTimer = null;
            }, 100);
        }
    }

    stopDrawing() {
        this.isDrawing = false;
    }

    clear() {
        if (!this.canvas || !this.ctx) return;
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }

    redraw() {
        /* Resize clears bitmap; full history would require server-side or stroke log — not implemented. */
    }
}

const slayBoard = new SlayBoard();

/**
 * Smart File Sharing Logic
 */
async function uploadSharedFile(file) {
    if (!file) return;
    notifyOk(`Uploading ${file.name}...`);
    
    const fd = new FormData();
    fd.append('shared_file', file);
    fd.append('room', SlayMeetConfig.room);

    try {
        const data = await api('/api/slaymeet/upload_file.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
            body: fd
        });

        if (data.success) {
            notifyOk('File shared with team!');
            sendSignal('system', { 
                type: 'file_shared', 
                file: { 
                    name: data.name, 
                    url: data.url, 
                    id: data.item_id,
                    mime: data.mime
                }
            });
            // Locally add to chat
            renderFileShared(SlayMeetConfig.userName, data, true);
        }
    } catch (err) {
        notifyErr('File upload failed: ' + err.message);
    }
}

function renderFileShared(from, file, isMe) {
    const log = document.getElementById('meet-chat-log');
    if (!log) return;
    
    const item = document.createElement('div');
    item.className = 'chat-item';
    item.innerHTML = `
        <b>${from}</b> shared a file:
        <a href="${SlayMeetConfig.siteUrl}/${file.url}" target="_blank" class="chat-file-bubble">
            <div class="file-icon">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            </div>
            <div class="file-info">
                <span class="file-name">${file.name}</span>
                <span class="file-meta">Stored in Workplace Drive</span>
            </div>
        </a>
    `;
    log.appendChild(item);
    log.scrollTop = log.scrollHeight;
}

async function bindLocalVideo() {
    ensureLocalTile();
    const tile = document.querySelector(`#video-stage [data-user-id="${SlayMeetConfig.userId}"] video`);
    if (tile && localStream) {
        tile.srcObject = localStream;
        tile.muted = true;
        tile.play().catch(() => {});
        // set initial state
        const micOn = localStream.getAudioTracks().some(t => t.enabled);
        const camOn = localStream.getVideoTracks().some(t => t.enabled);
        updateTileMediaState(SlayMeetConfig.userId, 'mic', micOn);
        updateTileMediaState(SlayMeetConfig.userId, 'video', camOn);
    }
    startAudioAnalyzer(SlayMeetConfig.userId, localStream);
}

/** Browsers may block remote <audio> until after user gesture; retry after tracks attach. */
function nudgeRemoteAudioPlayback() {
    const nodes = Array.from(document.querySelectorAll('#video-stage .tile-remote-audio'));
    if (!nodes.length) {
        setAudioUnlockNeeded(false);
        return;
    }
    let ok = 0;
    let failed = 0;
    nodes.forEach((a) => {
        a.play()
            .then(() => { ok++; if (ok > 0) setAudioUnlockNeeded(false); })
            .catch(() => { failed++; if (ok === 0 && failed > 0) setAudioUnlockNeeded(true); });
    });
}

function bindRemoteVideo(userId, stream, name) {
    ensureRemoteTile(userId, name);
    const tileRoot = document.querySelector(`#video-stage .tile[data-user-id="${userId}"]`);
    const tile = tileRoot ? tileRoot.querySelector('video') : null;
    if (tile) {
        /**
         * Never attach the same audio MediaStreamTrack to both <video> and <audio>.
         * Chrome/Edge/Safari often play no remote audio in that case; Google Meet splits sinks.
         */
        const vTracks = stream.getVideoTracks();
        const aTracks = stream.getAudioTracks();
        try {
            tile.srcObject = vTracks.length ? new MediaStream(vTracks) : null;
        } catch (_) {
            tile.srcObject = null;
        }
        tile.muted = true;
        tile.volume = 0;
        let audioEl = tileRoot.querySelector('.tile-remote-audio');
        if (!audioEl) {
            audioEl = document.createElement('audio');
            audioEl.className = 'tile-remote-audio';
            audioEl.autoplay = true;
            audioEl.setAttribute('playsinline', '');
            audioEl.setAttribute('aria-hidden', 'true');
            audioEl.style.cssText = 'position:absolute;width:0;height:0;opacity:0;pointer-events:none;z-index:-1';
            tileRoot.appendChild(audioEl);
        }
        try {
            const nextIds = aTracks.map((t) => t.id).join(',');
            const curIds = audioEl.srcObject
                ? audioEl.srcObject.getAudioTracks().map((t) => t.id).join(',')
                : '';
            if (nextIds !== curIds) {
                audioEl.srcObject = aTracks.length ? new MediaStream(aTracks) : null;
            }
        } catch (_) {
            audioEl.srcObject = null;
        }
        audioEl.muted = false;
        audioEl.volume = 1;
        stream.getAudioTracks().forEach((t) => {
            try {
                t.enabled = true;
            } catch (_) {}
            t.addEventListener(
                'unmute',
                () => {
                    audioEl.play().catch(() => { setAudioUnlockNeeded(true); });
                },
                { once: true }
            );
        });
        audioEl.play().catch(() => { setAudioUnlockNeeded(true); });
        tile.play().catch(() => {});
        nudgeRemoteAudioPlayback();
        // Default remotes to "ON" until a state signal arrives (or we'll catch it in the sync-request reply)
        updateTileMediaState(userId, 'mic', true);
        updateTileMediaState(userId, 'video', true);
    }
    if (!sfuMode) startAudioAnalyzer(userId, stream);
}

function configureLiveKitHooks() {
    if (!window.SlayMeetLiveKit) return;
    SlayMeetLiveKit.configure({
        bindRemote: bindRemoteVideo,
        removeRemote: removeRemoteTile,
        setSpeaking,
        updateConnectionState: updatePeerConnectionState,
        onScreenShare: (userId, active) => {
            activeScreenShareUserId = active
                ? userId
                : (parseInt(activeScreenShareUserId, 10) === userId ? null : activeScreenShareUserId);
            refreshGridLayout();
        },
        onLocalScreenShareEnd: () => {
            isScreenSharing = false;
            activeScreenShareUserId = null;
            const btn = document.getElementById('share-screen');
            const label = btn ? btn.querySelector('.btn-label') : null;
            if (label) label.textContent = 'Share';
            if (btn) btn.setAttribute('aria-pressed', 'false');
            refreshGridLayout();
            sendSignal('system', { type: 'screenshare', active: false });
        }
    });
}

async function connectMeshPeers(participants) {
    await Promise.all((participants || []).map(async (p) => {
        const pid = parseInt(p.user_id, 10);
        if (!pid || pid === SlayMeetConfig.userId) return;
        if (shouldInitiateOffer(pid)) {
            await createOfferFor(pid);
        }
        await sendSignal('system', { type: 'sync-request' }, pid);
    }));
}

async function tryConnectLiveKit(data) {
    if (!data.livekit_token || !data.livekit_url || !window.LivekitClient || !window.SlayMeetLiveKit) {
        return false;
    }
    configureLiveKitHooks();
    try {
        await SlayMeetLiveKit.connect(data.livekit_url, data.livekit_token);
        await SlayMeetLiveKit.publishLocalStream(localStream);
        sfuMode = true;
        refreshMeetStatusBar();
        return true;
    } catch (err) {
        console.warn('[SlayMeet] LiveKit SFU connect failed, falling back to mesh:', err);
        notifyErr('SFU unavailable — using direct connection');
        try { await SlayMeetLiveKit.disconnect(); } catch (_) {}
        sfuMode = false;
        return false;
    }
}

async function setupLocalMedia() {
    const savedMic = localStorage.getItem('slaymeet_mic_device') || '';
    const savedCam = localStorage.getItem('slaymeet_cam_device') || '';
    const audioBase = {
        echoCancellation: true,
        noiseSuppression: true,
        autoGainControl: true
    };
    const audioOpts = savedMic ? Object.assign({ deviceId: { exact: savedMic } }, audioBase) : audioBase;
    const videoOpts = savedCam ? { deviceId: { exact: savedCam } } : true;

    localStream = new MediaStream();

    const addTracks = (stream) => {
        if (!stream) return;
        stream.getTracks().forEach((t) => localStream.addTrack(t));
    };

    // Try requesting both first
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: audioOpts, video: videoOpts });
        addTracks(stream);
        // Stale saved mic ID can yield video-only success on some browsers — grab default mic.
        if (localStream.getAudioTracks().length === 0) {
            try {
                const aDefault = await navigator.mediaDevices.getUserMedia({ audio: audioBase });
                addTracks(aDefault);
            } catch (aOnlyErr) {
                console.warn('Audio add-on after combined success failed', aOnlyErr);
            }
        }
    } catch (e) {
        console.warn("Could not get both media, trying separately...", e);
        // Fallback: try audio only
        try {
            const aStream = await navigator.mediaDevices.getUserMedia({ audio: audioOpts });
            addTracks(aStream);
        } catch (ae) { console.warn("Audio fallback failed", ae); }
        // Fallback: try video only
        try {
            const vStream = await navigator.mediaDevices.getUserMedia({ video: videoOpts });
            addTracks(vStream);
        } catch (ve) { console.warn("Video fallback failed", ve); }

        // Device IDs can go stale across browser/hardware changes; retry with default devices.
        if (localStream.getVideoTracks().length === 0) {
            try {
                const vDefault = await navigator.mediaDevices.getUserMedia({ video: true });
                addTracks(vDefault);
            } catch (vDefaultErr) { console.warn("Video default fallback failed", vDefaultErr); }
        }
        if (localStream.getAudioTracks().length === 0) {
            try {
                const aDefault = await navigator.mediaDevices.getUserMedia({ audio: audioBase });
                addTracks(aDefault);
            } catch (aDefaultErr) { console.warn("Audio default fallback failed", aDefaultErr); }
        }
    }

    if (localStream.getTracks().length === 0) {
        throw new Error('No working camera or microphone found.');
    }

    cameraTrackRef = localStream.getVideoTracks()[0] || null;
    if (cameraTrackRef) {
        cameraTrackRef.enabled = prejoinCameraEnabled;
        rawCameraTrack = cameraTrackRef.clone();
        if (currentBgType !== 'none') {
            await startBgProcessing();
            localStream.removeTrack(cameraTrackRef);
            localStream.addTrack(processedStream.getVideoTracks()[0]);
        }
    }
    bindLocalVideo();
    
    // Initialize STT
    if (typeof initLocalSpeechRecognition === 'function') {
        initLocalSpeechRecognition();
        const micOn = localStream.getAudioTracks().some(t => t.enabled);
        updateSpeechRecognitionState(micOn);
    }
}
async function populateDevices() {
    const micSelect = document.getElementById('mic-device');
    const camSelect = document.getElementById('cam-device');
    const preMic = document.getElementById('prejoin-mic');
    const preCam = document.getElementById('prejoin-cam');
    if (!micSelect || !camSelect || !navigator.mediaDevices?.enumerateDevices) return;
    const devices = await navigator.mediaDevices.enumerateDevices();
    const mics = devices.filter(d => d.kind === 'audioinput');
    const cams = devices.filter(d => d.kind === 'videoinput');
    const micOptions = '<option value="">Mic: Default</option>' + mics.map(d => `<option value="${escHtml(d.deviceId)}">${escHtml(d.label || 'Microphone')}</option>`).join('');
    const camOptions = '<option value="">Cam: Default</option>' + cams.map(d => `<option value="${escHtml(d.deviceId)}">${escHtml(d.label || 'Camera')}</option>`).join('');
    micSelect.innerHTML = micOptions;
    camSelect.innerHTML = camOptions;
    if (preMic) preMic.innerHTML = '<option value="">Default microphone</option>' + mics.map(d => `<option value="${escHtml(d.deviceId)}">${escHtml(d.label || 'Microphone')}</option>`).join('');
    if (preCam) preCam.innerHTML = '<option value="">Default camera</option>' + cams.map(d => `<option value="${escHtml(d.deviceId)}">${escHtml(d.label || 'Camera')}</option>`).join('');
    const savedMic = localStorage.getItem('slaymeet_mic_device') || '';
    const savedCam = localStorage.getItem('slaymeet_cam_device') || '';
    micSelect.value = savedMic;
    camSelect.value = savedCam;
    if (preMic) preMic.value = savedMic;
    if (preCam) preCam.value = savedCam;
}
function findRtpSender(pc, kind) {
    if (!pc) return null;
    const active = pc.getSenders().find((s) => s.track && s.track.kind === kind);
    if (active) return active;
    return pc.getSenders().find((s) => !s.track && kind === 'video') || null;
}

function buildRemoteStreamFromPeer(userId) {
    const pc = peerConnections.get(userId);
    if (!pc) return null;
    const tracks = pc.getReceivers()
        .map((r) => r.track)
        .filter((t) => t && t.readyState !== 'ended');
    if (!tracks.length) return null;

    const stream = new MediaStream();
    tracks.forEach((track) => {
        stream.getTracks()
            .filter((t) => t.kind === track.kind)
            .forEach((t) => stream.removeTrack(t));
        stream.addTrack(track);
    });
    remoteStreams.set(userId, stream);
    return stream;
}

function rebindRemoteMediaFromPeer(userId) {
    const stream = buildRemoteStreamFromPeer(userId);
    if (!stream) return;
    const meta = participantMeta.get(userId);
    bindRemoteVideo(userId, stream, meta ? meta.name : undefined);
}

function upsertRemoteTrack(userId, track) {
    if (!track) return;
    let stream = remoteStreams.get(userId);
    if (!stream) {
        stream = new MediaStream();
        remoteStreams.set(userId, stream);
    }
    stream.getTracks()
        .filter((t) => t.kind === track.kind && t.id !== track.id)
        .forEach((t) => {
            try { stream.removeTrack(t); } catch (_) {}
        });
    if (!stream.getTracks().some((t) => t.id === track.id)) {
        stream.addTrack(track);
    }
    const refresh = () => rebindRemoteMediaFromPeer(userId);
    track.addEventListener('ended', refresh);
    track.addEventListener('mute', () => setTimeout(refresh, 40));
    track.addEventListener('unmute', () => setTimeout(refresh, 40));
    bindRemoteVideo(userId, stream);
}

async function replaceTrack(kind, newTrack) {
    if (!localStream || !newTrack) return;
    const old = localStream.getTracks().find(t => t.kind === kind);
    if (old) {
        localStream.removeTrack(old);
        old.stop();
    }
    localStream.addTrack(newTrack);
    /**
     * If this peer connection was created when we had no audio (or no video), there is no RTP sender
     * for that kind — replaceTrack never runs and remotes never receive that media.
     * Add the track and run a fresh offer so SDP includes the new m-line / sender.
     */
    for (const [peerUserId, pc] of peerConnections.entries()) {
        const sender = findRtpSender(pc, kind);
        try {
            if (sender) {
                await sender.replaceTrack(newTrack);
            } else {
                pc.addTrack(newTrack, localStream);
                console.warn(`[SlayMeet] First attach ${kind} to peer ${peerUserId} — renegotiating`);
                await createOfferFor(peerUserId);
            }
        } catch (e) {
            console.warn('[SlayMeet] Failed to update', kind, 'for peer', peerUserId, e);
            try {
                pc.addTrack(newTrack, localStream);
                await createOfferFor(peerUserId);
            } catch (e2) {
                console.warn('[SlayMeet] Renegotiation fallback failed for', peerUserId, e2);
            }
        }
    }
    if (kind === 'video' && isScreenSharing) {
        for (const peerUserId of peerConnections.keys()) {
            sendSignal('system', { type: 'screenshare', active: true }, peerUserId).catch(() => {});
        }
    }
    if (kind === 'video' && !isScreenSharing) {
        if (typeof cameraTrackRef !== 'undefined' && cameraTrackRef && cameraTrackRef !== newTrack) {
            cameraTrackRef.stop();
        }
        cameraTrackRef = newTrack;
        if (typeof rawCameraTrack !== 'undefined' && rawCameraTrack) {
            rawCameraTrack.stop();
        }
        rawCameraTrack = newTrack.clone(); // Keep original for ML
        
        if (currentBgType !== 'none') {
            await startBgProcessing();
            newTrack = processedStream.getVideoTracks()[0];
        }
    }
    bindLocalVideo();
}

function getPeer(userId) {
    if (peerConnections.has(userId)) return peerConnections.get(userId);
    const pc = new RTCPeerConnection({
        iceServers: Array.isArray(SlayMeetConfig.iceServers) ? SlayMeetConfig.iceServers : [],
        iceCandidatePoolSize: 10,
        bundlePolicy: 'max-bundle',
        rtcpMuxPolicy: 'require',
    });

    // ── Peer Heartbeat Monitoring ──
    const monitor = {
        retryCount: 0,
        timer: setTimeout(() => checkPeerStability(userId), 15000)
    };

    peerMeta.set(userId, {
        makingOffer: false,
        ignoreOffer: false,
        isSettingRemoteAnswerPending: false,
        polite: SlayMeetConfig.userId > userId,
        monitor: monitor
    });

    localStream.getTracks().forEach(t => pc.addTrack(t, localStream));

    pc.onicecandidate = (ev) => {
        if (ev.candidate) {
            queueIceCandidate(userId, ev.candidate);
            return;
        }
        flushIceOutbox(userId, true).catch(() => {});
    };
    pc.ontrack = (ev) => {
        if (ev.track) {
            upsertRemoteTrack(userId, ev.track);
            console.log(`[SlayMeet] ${ev.track.kind} track from ${userId} (readyState=${ev.track.readyState}).`);
            if (ev.track.kind === 'audio') {
                setTimeout(nudgeRemoteAudioPlayback, 0);
            }
        }
    };
    pc.oniceconnectionstatechange = () => {
        console.log(`[SlayMeet] ICE State for ${userId}: ${pc.iceConnectionState}`);
        if (pc.iceConnectionState === 'connected' || pc.iceConnectionState === 'completed') {
            if (peerMeta.has(userId)) clearTimeout(peerMeta.get(userId).monitor.timer);
            updatePeerConnectionState(userId, 'connected');
            rebindRemoteMediaFromPeer(userId);
            nudgeRemoteAudioPlayback();
        } else if (pc.iceConnectionState === 'failed') {
            updatePeerConnectionState(userId, 'failed');
            document.getElementById('meetingStatusText').textContent = 'Connection Error';
            handlePeerFailure(userId);
        }
    };
    pc.onconnectionstatechange = () => {
        const st = pc.connectionState;
        if (st === 'failed' || st === 'closed') {
            removePeer(userId);
        }
    };
    peerConnections.set(userId, pc);
    updatePeerConnectionState(userId, 'connecting');
    return pc;
}

/**
 * Handle Peer Connectivity Failures
 */
async function checkPeerStability(userId) {
    const pc = peerConnections.get(userId);
    if (!pc) return;
    
    if (pc.iceConnectionState !== 'connected' && pc.iceConnectionState !== 'completed') {
        const meta = peerMeta.get(userId);
        if (meta && meta.monitor.retryCount < 2) {
            meta.monitor.retryCount++;
            console.warn(`[SlayMeet] Peer ${userId} stalled. State: ${pc.iceConnectionState}. Retrying...`);
            await sendSignal('system', { type: 'sync-request' }, userId);
            meta.monitor.timer = setTimeout(() => checkPeerStability(userId), 15000);
        }
    }
}

async function handlePeerFailure(userId) {
    console.error(`[SlayMeet] Peer Connection for ${userId} failed. Re-initiating...`);
    removePeer(userId);
    // The next poll will re-discover the user and trigger a new offer
}

function removePeer(userId) {
    if (iceOutboxTimers.has(userId)) {
        clearTimeout(iceOutboxTimers.get(userId));
        iceOutboxTimers.delete(userId);
    }
    flushIceOutbox(userId, true).catch(() => {});
    iceOutbox.delete(userId);
    const pc = peerConnections.get(userId);
    if (pc) {
        try { pc.close(); } catch (_) {}
        peerConnections.delete(userId);
    }
    remoteStreams.delete(userId);
    stopAudioAnalyzer(userId);
    peerMeta.delete(userId);
    queuedIce.delete(userId);
    removeRemoteTile(userId);
}

async function sendSignal(type, payload, toUserId) {
    const fd = new FormData();
    fd.append('room', SlayMeetConfig.room);
    fd.append('signal_type', type);
    fd.append('payload', JSON.stringify(payload));
    if (toUserId) fd.append('to_user_id', String(toUserId));
    const result = await api('/api/slaymeet/signal_send.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
        body: fd
    });
    if (type === 'offer' || type === 'answer' || type === 'ice') {
        kickSignalPoll();
    }
    return result;
}

function kickSignalPoll() {
    if (!roomSignalingActive) return;
    if (signalKickTimer) return;
    signalKickTimer = setTimeout(async () => {
        signalKickTimer = null;
        signalPollDelayMs = 180;
        try {
            await pollSignals();
        } catch (_) {
            isPolling = false;
        }
        if (!sseConnected) scheduleSignalPoll();
    }, 40);
}

function queueIceCandidate(userId, candidate) {
    if (!iceOutbox.has(userId)) iceOutbox.set(userId, []);
    iceOutbox.get(userId).push(candidate);
    if (iceOutboxTimers.has(userId)) return;
    iceOutboxTimers.set(userId, setTimeout(() => {
        iceOutboxTimers.delete(userId);
        flushIceOutbox(userId, false).catch(() => {});
    }, 32));
}

async function flushIceOutbox(userId, forceFinal) {
    if (iceOutboxTimers.has(userId)) {
        clearTimeout(iceOutboxTimers.get(userId));
        iceOutboxTimers.delete(userId);
    }
    const batch = iceOutbox.get(userId) || [];
    if (!batch.length && !forceFinal) return;
    iceOutbox.set(userId, []);
    if (!batch.length) return;
    await sendSignal('ice', { candidates: batch }, userId);
}

async function ingestSignalBatch(sigs) {
    if (!Array.isArray(sigs) || !sigs.length) return 0;
    for (const s of sigs) {
        await handleSignal(s);
    }
    const tail = sigs[sigs.length - 1];
    const rid = parseInt(tail && tail.id, 10);
    if (rid > lastSignalId) lastSignalId = rid;
    return sigs.length;
}

function startSignalStream() {
    if (!window.EventSource || !SlayMeetConfig.room || !roomSignalingActive) return;
    stopSignalStream();
    const url = SlayMeetConfig.siteUrl
        + '/api/slaymeet/signal_stream.php?room='
        + encodeURIComponent(SlayMeetConfig.room)
        + '&since_id='
        + encodeURIComponent(lastSignalId);
    signalEventSource = new EventSource(url, { withCredentials: true });
    signalEventSource.addEventListener('ready', () => {
        sseConnected = true;
        signalPollFallbackOnly = true;
        signalPollDelayMs = 12000;
    });
    signalEventSource.addEventListener('reconnect', (ev) => {
        try {
            const data = JSON.parse(ev.data);
            if (data.last_id) lastSignalId = Math.max(lastSignalId, parseInt(data.last_id, 10));
        } catch (_) {}
        stopSignalStream();
        setTimeout(() => startSignalStream(), 100);
    });
    signalEventSource.onmessage = async (ev) => {
        try {
            const batch = JSON.parse(ev.data);
            if (batch && batch.signals && batch.signals.length) {
                await ingestSignalBatch(batch.signals);
                if (batch.last_id) lastSignalId = Math.max(lastSignalId, parseInt(batch.last_id, 10));
                nudgeRemoteAudioPlayback();
            }
        } catch (_) {}
    };
    signalEventSource.onerror = () => {
        sseConnected = false;
        signalPollFallbackOnly = false;
        signalPollDelayMs = 220;
        if (roomSignalingActive && !signalPollTimer) scheduleSignalPoll();
    };
}

function stopSignalStream() {
    if (signalEventSource) {
        signalEventSource.close();
        signalEventSource = null;
    }
    sseConnected = false;
    signalPollFallbackOnly = false;
}

async function createOfferFor(userId) {
    const pc = getPeer(userId);
    const meta = peerMeta.get(userId);
    if (!pc || !meta) return;
    if (pc.signalingState !== 'stable') return;
    try {
        meta.makingOffer = true;
        const offer = await pc.createOffer({
            offerToReceiveAudio: true,
            offerToReceiveVideo: true,
            iceRestart: false,
        });
        if (pc.signalingState !== 'stable') return;
        await pc.setLocalDescription(offer);
        await sendSignal('offer', { sdp: offer.sdp, type: offer.type }, userId);
    } finally {
        meta.makingOffer = false;
    }
}

async function flushQueuedIce(userId, pc) {
    const queue = queuedIce.get(userId) || [];
    if (!queue.length) return;
    queuedIce.set(userId, []);
    for (const c of queue) {
        try { await pc.addIceCandidate(new RTCIceCandidate(c)); } catch (_) {}
    }
}

async function handleSignal(s) {
    const from = parseInt(s.from_user_id, 10);
    if (!from || from === SlayMeetConfig.userId) return;
    const payload = s.payload || {};
    if (s.signal_type === 'system') {
        if (payload.type === 'reaction' && payload.emoji) {
            setTileBadge(from, payload.emoji, 2800);
        } else if (payload.type === 'mediastate') {
            updateTileMediaState(from, payload.sub, payload.enabled);
        } else if (payload.type === 'speaking' && typeof payload.active === 'boolean') {
            setSpeaking(from, payload.active);
        } else if (payload.type === 'sync-request' || (payload.type === 'hand' && typeof payload.raised === 'boolean')) {
            if (payload.type === 'hand') {
                if (payload.raised) setTileBadge(from, '✋', 0);
                else {
                    const tile = document.querySelector(`#video-stage [data-user-id="${from}"]`);
                    const badge = tile ? tile.querySelector('.badge') : null;
                    if (badge && badge.textContent === '✋') badge.remove();
                }
            } else if (payload.type === 'sync-request') {
                if (!sfuMode && shouldInitiateOffer(from)) {
                    const pcHello = peerConnections.get(from);
                    const ice = pcHello ? pcHello.iceConnectionState : 'new';
                    if (!pcHello || (pcHello.signalingState === 'stable' && ice !== 'connected' && ice !== 'completed')) {
                        createOfferFor(from).catch(() => {});
                    }
                }
                // Empty track lists make `.some()` false — never tell peers "mic/cam off" during setup race.
                const aTracks = localStream ? localStream.getAudioTracks() : [];
                const vTracks = localStream ? localStream.getVideoTracks() : [];
                const micOn = aTracks.length === 0 ? true : aTracks.some((t) => t.enabled);
                const camOn = vTracks.length === 0 ? true : vTracks.some((t) => t.enabled);
                sendSignal('system', { type: 'mediastate', sub: 'mic', enabled: micOn }, from);
                sendSignal('system', { type: 'mediastate', sub: 'video', enabled: camOn }, from);
                if (isScreenSharing) {
                    sendSignal('system', { type: 'screenshare', active: true }, from);
                }
            }
        } else if (payload.type === 'screenshare') {
            activeScreenShareUserId = payload.active ? from : (parseInt(activeScreenShareUserId, 10) === from ? null : activeScreenShareUserId);
            refreshGridLayout();
            rebindRemoteMediaFromPeer(from);
        } else if (payload.type === 'chat' && payload.message) {
            addChatLine(payload.from_name || `User ${from}`, payload.message, false);
            // Feed peer chat to the AI assistant so it can answer questions / wake words.
            if (window.slayAgent) {
                const senderName = payload.from_name || `User ${from}`;
                const agentName = (window.slayAgent.cfg && window.slayAgent.cfg.assistantDisplayName) || 'Ultra Looper AI Assistant';
                if (senderName !== agentName) {
                    try { window.slayAgent.ingest(senderName, payload.message, 'chat'); } catch (_) {}
                }
            }
        } else if (payload.type === 'recording_state') {
            const status = document.getElementById('meetingStatusText');
            if (payload.active) {
                status.innerHTML = '<span style="color:#ef4444;font-weight:800;">● REC</span> • Live';
                notifyOk('Recording started by host');
            } else {
                status.textContent = 'Meeting Live • Secured';
                notifyOk('Recording stopped');
            }
        } else if (payload.type === 'wb_draw' && Array.isArray(payload.points)) {
            payload.points.forEach(p => slayBoard.draw(null, true, p));
        } else if (payload.type === 'wb_clear') {
            slayBoard.clear();
        } else if (payload.type === 'file_shared' && payload.file) {
            renderFileShared(payload.from_name || `User ${from}`, payload.file, false);
        } else if (payload.type === 'mute_all') {
            if (localStream && !isHostUser) {
                localStream.getAudioTracks().forEach(t => { t.enabled = false; });
                if (sfuMode && window.SlayMeetLiveKit) {
                    SlayMeetLiveKit.setMicEnabled(false).catch(() => {});
                }
                const btn = document.getElementById('toggle-audio');
                const label = btn ? btn.querySelector('.btn-label') : null;
                if (label) label.textContent = 'Mic Off';
                btn.setAttribute('aria-pressed', 'false');
                updateTileMediaState(SlayMeetConfig.userId, 'mic', false);
                notifyErr('Host muted everyone');
            }
        } else if (payload.type === 'caption') {
            if (typeof renderCaption === 'function') {
                renderCaption(from, payload.from_name || `User ${from}`, payload.text, payload.isFinal);
            }
            if (payload.isFinal && window.slayAgent) {
                try { window.slayAgent.ingest(payload.from_name || `User ${from}`, payload.text.trim(), 'caption'); } catch (_) {}
            }
        }
        return;
    }

    if (sfuMode && (s.signal_type === 'offer' || s.signal_type === 'answer' || s.signal_type === 'ice')) {
        return;
    }

    const pc = getPeer(from);
    const meta = peerMeta.get(from);
    if (!pc || !meta) return;
    if (s.signal_type === 'offer') {
        const readyForOffer = !meta.makingOffer && (pc.signalingState === 'stable' || meta.isSettingRemoteAnswerPending);
        const offerCollision = !readyForOffer;
        meta.ignoreOffer = !meta.polite && offerCollision;
        if (meta.ignoreOffer) return;
        meta.isSettingRemoteAnswerPending = false;
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);
        await sendSignal('answer', { sdp: answer.sdp, type: answer.type }, from);
        await flushQueuedIce(from, pc);
        kickSignalPoll();
        return;
    }
    if (s.signal_type === 'answer') {
        meta.isSettingRemoteAnswerPending = true;
        await pc.setRemoteDescription(new RTCSessionDescription(payload));
        meta.isSettingRemoteAnswerPending = false;
        await flushQueuedIce(from, pc);
        return;
    }
    if (s.signal_type === 'ice') {
        const list = Array.isArray(payload.candidates)
            ? payload.candidates
            : (payload.candidate ? [payload.candidate] : []);
        if (!list.length) return;
        if (!pc.remoteDescription) {
            const q = queuedIce.get(from) || [];
            list.forEach((c) => q.push(c));
            queuedIce.set(from, q);
            return;
        }
        for (const raw of list) {
            try { await pc.addIceCandidate(new RTCIceCandidate(raw)); } catch (_) {}
        }
    }
}

async function pollSignals() {
    if (isPolling) return 0;
    isPolling = true;
    let received = 0;
    try {
        const longPoll = !sseConnected && peersNeedFastPoll();
        const data = await api(
            '/api/slaymeet/signal_poll.php?room='
            + encodeURIComponent(SlayMeetConfig.room)
            + '&since_id='
            + encodeURIComponent(lastSignalId)
            + (longPoll ? '&long_poll=1' : ''),
            { headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken } }
        );
        received = await ingestSignalBatch(data.signals || []);
        lastSignalId = parseInt(data.last_id || lastSignalId, 10);
    } finally {
        isPolling = false;
    }
    return received;
}

function scheduleSignalPoll() {
    if (signalPollTimer) clearTimeout(signalPollTimer);
    if (sseConnected && signalPollFallbackOnly && !peersNeedFastPoll()) {
        signalPollTimer = setTimeout(async () => {
            if (!roomSignalingActive) return;
            try { await pollSignals(); } catch (_) { isPolling = false; }
            scheduleSignalPoll();
        }, 12000);
        return;
    }
    signalPollTimer = setTimeout(async () => {
        if (!roomSignalingActive) return;
        let count = 0;
        try {
            count = await pollSignals();
        } catch (_) {
            isPolling = false;
        }
        if (sseConnected && !peersNeedFastPoll()) {
            signalPollDelayMs = 12000;
        } else if (peersNeedFastPoll()) {
            signalPollDelayMs = 220;
        } else if (count > 0) {
            signalPollDelayMs = 450;
        } else {
            signalPollDelayMs = Math.min(1400, signalPollDelayMs + 80);
        }
        scheduleSignalPoll();
    }, signalPollDelayMs);
}

async function join() {
    console.group('SlayMeet Join Flow');
    console.log('[1/4] Validating configuration...');
    if (!SlayMeetConfig.room) {
        console.groupEnd();
        return notifyErr('Room token missing');
    }
    if (!navigator.mediaDevices || !window.RTCPeerConnection) {
        console.groupEnd();
        notifyErr('WebRTC is not supported in this browser.');
        return;
    }
    console.log('[2/4] Setting up local media...');
    await setupLocalMedia();
    console.log('[3/4] Authenticating with room API...');
    const fd = new FormData();
    fd.append('room', SlayMeetConfig.room);
    const data = await api('/api/slaymeet/join_room.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
        body: fd
    });
    if (data.waiting) {
        return { waiting: true, admission_status: data.admission_status || 'pending' };
    }
    console.log('[4/4] Joining stage and syncing peers...');
    renderState(data);
    roomSignalingActive = true;
    const participants = data.participants || [];
    const lkOk = await tryConnectLiveKit(data);
    if (!lkOk) {
        await connectMeshPeers(participants);
    }
    beginRealtimeLoops();

    joined = true;
    if (SlayMeetConfig.isDmCall) {
        startMeetCallTimer();
        updateDmCallPeerHeader(participants);
    }
    resetSpeakingBroadcastState();
    refreshMeetStatusBar();
    console.log('Successfully joined SlayMeet.');
    console.groupEnd();
    return { waiting: false };
}

async function pollAdmissionUntilApproved() {
    if (waitingAdmissionPoll) clearInterval(waitingAdmissionPoll);
    waitingAdmissionPoll = setInterval(async () => {
        try {
            const res = await api('/api/slaymeet/check_admission.php?room=' + encodeURIComponent(SlayMeetConfig.room), {
                headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken }
            });
            if (res.admitted || res.admission_status === 'admitted') {
                if (admissionJoinInProgress) return;
                admissionJoinInProgress = true;
                let joinRes = null;
                try {
                    joinRes = await join();
                } catch (err) {
                    admissionJoinInProgress = false;
                    const waitingNote = document.getElementById('prejoin-waiting-note');
                    if (waitingNote) {
                        waitingNote.classList.add('show');
                        waitingNote.textContent = 'Host admitted you, but join setup failed. Click Join Now again.';
                    }
                    notifyErr(err.message || 'Admitted by host, but failed to join. Please try again.');
                    return;
                }
                if (joinRes && joinRes.waiting) {
                    admissionJoinInProgress = false;
                    const waitingNote = document.getElementById('prejoin-waiting-note');
                    if (waitingNote) {
                        waitingNote.classList.add('show');
                        waitingNote.textContent = 'Approval is syncing. Please wait a few seconds.';
                    }
                    document.getElementById('prejoin-join').disabled = false;
                    return;
                }
                clearInterval(waitingAdmissionPoll);
                waitingAdmissionPoll = null;
                admissionJoinInProgress = false;
                document.getElementById('prejoin-waiting-note')?.classList.remove('show');
                document.getElementById('prejoin-join').disabled = false;
                hidePrejoin();
                document.getElementById('meetingStatusText').textContent = 'Connected';
                beginRealtimeLoops();
                notifyOk('Host admitted you to the meeting');
                return;
            }
            if (res.admission_status === 'denied') {
                clearInterval(waitingAdmissionPoll);
                waitingAdmissionPoll = null;
                admissionJoinInProgress = false;
                notifyErr('Host denied the join request');
                window.location.href = SlayMeetConfig.siteUrl + '/dashboard/chat.php';
            }
        } catch (_) {}
    }, 4500);
}

function beginRealtimeLoops() {
    if (realtimeLoopsStarted) return;
    realtimeLoopsStarted = true;
    if (signalTimer) clearInterval(signalTimer);
    if (signalPollTimer) clearTimeout(signalPollTimer);
    if (speakerTimer) clearInterval(speakerTimer);
    if (stateTimer) clearInterval(stateTimer);
    if (sessionPingTimer) clearInterval(sessionPingTimer);
    signalPollDelayMs = 200;
    // Keep PHP session warm during long calls (avoids 401s on SlayGuardAPI after idle).
    sessionPingTimer = setInterval(() => {
        fetch(SlayMeetConfig.siteUrl + '/api/ping.php', { credentials: 'include' }).catch(() => {});
    }, 4 * 60 * 1000);
    nudgeRemoteAudioPlayback();
    setTimeout(nudgeRemoteAudioPlayback, 600);
    startSignalStream();
    pollSignals().catch(() => { isPolling = false; });
    scheduleSignalPoll();
    speakerTimer = setInterval(() => {
        if (!sfuMode) monitorActiveSpeakers();
    }, 450);
    stateTimer = setInterval(() => {
        refreshState().then((data) => {
            if (sfuMode) return;
            const activeIds = new Set((data.participants || []).map(p => parseInt(p.user_id, 10)));
            for (const uid of [...peerConnections.keys()]) {
                if (!activeIds.has(parseInt(uid, 10))) removePeer(uid);
            }
            (data.participants || []).forEach((p) => {
                const pid = parseInt(p.user_id, 10);
                if (!pid || pid === SlayMeetConfig.userId) return;
                if (!peerConnections.has(pid) && shouldInitiateOffer(pid)) {
                    createOfferFor(pid).catch(() => {});
                }
            });
        }).catch(() => {});
    }, 1500);
}
async function setupPrejoinPreview() {
    const help = document.getElementById('prejoin-help-text');
    const preview = document.getElementById('prejoin-preview');
    const micId = (document.getElementById('prejoin-mic') || {}).value || '';
    const camId = (document.getElementById('prejoin-cam') || {}).value || '';
    if (prejoinStream) {
        prejoinStream.getTracks().forEach(t => t.stop());
        prejoinStream = null;
    }
    try {
        try {
            const audioOpts = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
            if (micId) audioOpts.deviceId = { exact: micId };
            
            prejoinStream = await navigator.mediaDevices.getUserMedia({
                audio: audioOpts,
                video: prejoinCameraEnabled ? (camId ? { deviceId: { exact: camId } } : true) : false
            });
        } catch (exactErr) {
            // Saved device IDs may become invalid. Fall back to browser defaults.
            prejoinStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
        }
        
        const videoTrack = prejoinStream.getVideoTracks()[0];
        if (videoTrack) {
            videoTrack.enabled = prejoinCameraEnabled;
            if (typeof rawCameraTrack !== 'undefined' && rawCameraTrack) {
                rawCameraTrack.stop();
            }
            rawCameraTrack = videoTrack.clone();
            
            if (preview) preview.srcObject = prejoinStream;
            // Background processing disabled for rollback
        }
        
        if (help) help.textContent = 'Preview ready. Click "Join Now" to enter meeting.';
    } catch (e) {
        console.error("Prejoin error:", e);
        if (help) help.textContent = 'Permission denied or device unavailable. Allow camera/mic in browser site settings, then click Retry.';
        throw e;
    }
}
function hidePrejoin() {
    const modal = document.getElementById('prejoin-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.add('hidden');
    }
    if (prejoinStream) {
        prejoinStream.getTracks().forEach(t => t.stop());
        prejoinStream = null;
    }
}
async function refreshState() {
    const data = await api('/api/slaymeet/room_state.php?room=' + encodeURIComponent(SlayMeetConfig.room), {
        headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken }
    });
    renderState(data);
    return data;
}
async function leave() {
    if (waitingAdmissionPoll) {
        clearInterval(waitingAdmissionPoll);
        waitingAdmissionPoll = null;
    }
    stopSignalStream();
    if (signalPollTimer) clearTimeout(signalPollTimer);
    signalPollTimer = null;
    if (signalTimer) clearInterval(signalTimer);
    if (stateTimer) clearInterval(stateTimer);
    if (speakerTimer) clearInterval(speakerTimer);
    if (sessionPingTimer) {
        clearInterval(sessionPingTimer);
        sessionPingTimer = null;
    }
    forceSpeakingBroadcastOff();
    if (sfuMode && window.SlayMeetLiveKit) {
        try { await SlayMeetLiveKit.disconnect(); } catch (_) {}
        sfuMode = false;
    }
    for (const uid of peerConnections.keys()) removePeer(uid);
    stopAudioAnalyzer(SlayMeetConfig.userId);
    if (localStream) {
        localStream.getTracks().forEach(t => t.stop());
    }
    if (typeof rawCameraTrack !== 'undefined' && rawCameraTrack) { rawCameraTrack.stop(); rawCameraTrack = null; }
    if (typeof cameraTrackRef !== 'undefined' && cameraTrackRef) { cameraTrackRef.stop(); cameraTrackRef = null; }
    const fd = new FormData();
    fd.append('room', SlayMeetConfig.room);
    if (SlayMeetConfig.isDmCall) {
        await slaylyEndDmCallRecord('ended');
        await slaylySetMeetPresence(false);
    }
    await api('/api/slaymeet/leave_room.php', { method: 'POST', headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken }, body: fd });
    notifyOk('You left the room');
    window.location.href = SlayMeetConfig.siteUrl + '/dashboard/chat.php';
}
// refresh hidden

document.getElementById('toggle-drawer').addEventListener('click', () => {
    smOpenRail('people');
});

function smIsMobileRail() {
    return window.matchMedia('(max-width: 768px)').matches;
}

function smOpenRail(panel) {
    if (SlayMeetConfig.isDmCall) return;
    const tab = panel || 'people';
    smSwitchRailPanel(tab);
    document.getElementById('meet-body')?.classList.add('rail-open');
    document.querySelectorAll('.sm-topbar__rail-btns .sm-rail-tab').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-rail') === tab);
    });
}

function smCloseRail() {
    document.getElementById('meet-body')?.classList.remove('rail-open');
    document.querySelectorAll('.sm-topbar__rail-btns .sm-rail-tab').forEach((b) => b.classList.remove('is-active'));
}

function smSwitchRailPanel(panel) {
    const name = panel === 'chat' ? 'Chat' : 'People';
    document.querySelectorAll('#sm-rail .sm-rail__panel').forEach((el) => {
        el.classList.toggle('is-active', el.getAttribute('data-panel') === panel);
    });
    document.querySelectorAll('#sm-rail .sm-rail__tabs .sm-rail-tab').forEach((btn) => {
        btn.classList.toggle('is-active', btn.getAttribute('data-rail-panel') === panel);
    });
    const title = document.getElementById('sm-rail-title');
    if (title) title.textContent = name;
    const sheetTitle = document.getElementById('sm-rail-sheet-title');
    if (sheetTitle) sheetTitle.textContent = name;
}

document.getElementById('sm-rail-tab-people')?.addEventListener('click', () => smOpenRail('people'));
document.getElementById('sm-rail-tab-chat')?.addEventListener('click', () => smOpenRail('chat'));
document.getElementById('close-drawer')?.addEventListener('click', smCloseRail);

document.querySelectorAll('#sm-rail .sm-rail__tabs .sm-rail-tab').forEach((btn) => {
    btn.addEventListener('click', () => smSwitchRailPanel(btn.getAttribute('data-rail-panel') || 'people'));
});

document.getElementById('meet-body')?.addEventListener('click', (e) => {
    if (!smIsMobileRail()) return;
    const body = document.getElementById('meet-body');
    if (body?.classList.contains('rail-open') && e.target === body) {
        smCloseRail();
    }
});
document.getElementById('admit-guests-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    document.getElementById('admit-queue-panel').classList.toggle('open');
});
document.getElementById('admit-queue-list').addEventListener('click', async (e) => {
    const target = e.target.closest('.admit-btn');
    if (!target) return;
    const participantUserId = parseInt(target.getAttribute('data-user-id') || '0', 10);
    const action = String(target.getAttribute('data-action') || '').trim();
    if (!participantUserId || !['admit', 'deny'].includes(action)) return;
    target.disabled = true;
    try {
        await hostUpdateAdmission(participantUserId, action);
        // Drop from queue immediately so UI matches reality if refreshState hits rate limits or lags.
        pendingRequests = pendingRequests.filter((p) => parseInt(p.user_id, 10) !== participantUserId);
        renderAdmissionQueue();
        try {
            await refreshState();
        } catch (refreshErr) {
            console.warn('[SlayMeet] refreshState after admission:', refreshErr);
        }
        notifyOk(action === 'admit' ? 'Guest admitted' : 'Guest denied');
    } catch (err) {
        target.disabled = false;
        notifyErr(err.message || 'Failed to update admission');
    }
});
document.addEventListener('click', (e) => {
    const panel = document.getElementById('admit-queue-panel');
    const btn = document.getElementById('admit-guests-btn');
    if (!panel || !btn) return;
    if (panel.contains(e.target) || btn.contains(e.target)) return;
    panel.classList.remove('open');
});

document.getElementById('leave').addEventListener('click', leave);
const audioUnlockBtn = document.getElementById('audio-unlock-btn');
if (audioUnlockBtn) {
    audioUnlockBtn.addEventListener('click', () => {
        unlockMeetingAudioOnGesture();
    });
}
document.addEventListener('pointerdown', () => { nudgeRemoteAudioPlayback(); }, { passive: true });
document.addEventListener('keydown', () => { nudgeRemoteAudioPlayback(); }, { passive: true });
document.getElementById('toggle-audio').addEventListener('click', async () => {
    if (!localStream) return;
    localStream.getAudioTracks().forEach(t => { t.enabled = !t.enabled; });
    const on = localStream.getAudioTracks().some(t => t.enabled);
    if (sfuMode && window.SlayMeetLiveKit) {
        try { await SlayMeetLiveKit.setMicEnabled(on); } catch (_) {}
    }
    const btn = document.getElementById('toggle-audio');
    const label = btn ? btn.querySelector('.btn-label') : null;
    if (label) label.textContent = on ? 'Mic' : 'Mic Off';
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    updateTileMediaState(SlayMeetConfig.userId, 'mic', on);
    sendSignal('system', { type: 'mediastate', sub: 'mic', enabled: on });
    if (typeof updateSpeechRecognitionState === 'function') updateSpeechRecognitionState(on);
    if (!on) {
        forceSpeakingBroadcastOff();
        setSpeaking(SlayMeetConfig.userId, false);
    }
});
document.getElementById('toggle-video').addEventListener('click', async () => {
    if (!localStream) return;
    const btn = document.getElementById('toggle-video');
    const isCurrentlyOn = btn.getAttribute('aria-pressed') !== 'false';
    const on = !isCurrentlyOn;
    
    if (on) {
        try {
            if (sfuMode && window.SlayMeetLiveKit) {
                const camId = localStorage.getItem('slaymeet_cam_device') || '';
                if (camId) await SlayMeetLiveKit.switchCamera(camId);
                await SlayMeetLiveKit.setCameraEnabled(true);
                isScreenSharing = false;
                activeScreenShareUserId = null;
                refreshGridLayout();
            } else {
                const camId = localStorage.getItem('slaymeet_cam_device') || '';
                const newStream = await navigator.mediaDevices.getUserMedia({ video: camId ? { deviceId: { exact: camId } } : true, audio: false });
                const newTrack = newStream.getVideoTracks()[0];
                if (newTrack) {
                    isScreenSharing = false;
                    activeScreenShareUserId = null;
                    await replaceTrack('video', newTrack);
                    cameraTrackRef = newTrack;
                    refreshGridLayout();
                }
            }
        } catch (e) {
            notifyErr('Camera start failed');
            return;
        }
    } else {
        if (sfuMode && window.SlayMeetLiveKit) {
            try { await SlayMeetLiveKit.setCameraEnabled(false); } catch (_) {}
        }
        localStream.getVideoTracks().forEach(t => {
            t.enabled = false;
            if (!sfuMode) t.stop();
        });
        if (!sfuMode) {
            if (typeof rawCameraTrack !== 'undefined' && rawCameraTrack) { rawCameraTrack.enabled = false; rawCameraTrack.stop(); rawCameraTrack = null; }
            if (typeof cameraTrackRef !== 'undefined' && cameraTrackRef) { cameraTrackRef.enabled = false; cameraTrackRef.stop(); cameraTrackRef = null; }
        }
    }
    
    const label = btn ? btn.querySelector('.btn-label') : null;
    if (label) label.textContent = on ? 'Cam' : 'Cam Off';
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    updateTileMediaState(SlayMeetConfig.userId, 'video', on);
    sendSignal('system', { type: 'mediastate', sub: 'video', enabled: on });
});
document.getElementById('share-screen').addEventListener('click', async () => {
    if (!navigator.mediaDevices?.getDisplayMedia || !localStream) return;
    const btn = document.getElementById('share-screen');
    try {
        if (!isScreenSharing) {
            if (sfuMode && window.SlayMeetLiveKit) {
                await SlayMeetLiveKit.setScreenShareEnabled(true);
                isScreenSharing = true;
                activeScreenShareUserId = SlayMeetConfig.userId;
                const label = btn ? btn.querySelector('.btn-label') : null;
                if (label) label.textContent = 'Stop';
                btn.setAttribute('aria-pressed', 'true');
                refreshGridLayout();
                sendSignal('system', { type: 'screenshare', active: true });
                return;
            }
            const display = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
            const screenTrack = display.getVideoTracks()[0];
            if (!screenTrack) return;
            try {
                screenTrack.contentHint = 'detail';
            } catch (_) {}
            screenTrack.onended = async () => {
                if (!cameraTrackRef) return;
                isScreenSharing = false;
                activeScreenShareUserId = null;
                await replaceTrack('video', cameraTrackRef.clone());
                const label = btn ? btn.querySelector('.btn-label') : null;
                if (label) label.textContent = 'Share';
                btn.setAttribute('aria-pressed', 'false');
                refreshGridLayout();
                sendSignal('system', { type: 'screenshare', active: false });
            };
            isScreenSharing = true;
            activeScreenShareUserId = SlayMeetConfig.userId;
            await replaceTrack('video', screenTrack);
            const label = btn ? btn.querySelector('.btn-label') : null;
            if (label) label.textContent = 'Stop';
            btn.setAttribute('aria-pressed', 'true');
            refreshGridLayout();
            sendSignal('system', { type: 'screenshare', active: true });
        } else if (sfuMode && window.SlayMeetLiveKit) {
            await SlayMeetLiveKit.setScreenShareEnabled(false);
            isScreenSharing = false;
            activeScreenShareUserId = null;
            const label = btn ? btn.querySelector('.btn-label') : null;
            if (label) label.textContent = 'Share';
            btn.setAttribute('aria-pressed', 'false');
            refreshGridLayout();
            sendSignal('system', { type: 'screenshare', active: false });
        } else if (cameraTrackRef) {
            isScreenSharing = false;
            activeScreenShareUserId = null;
            await replaceTrack('video', cameraTrackRef.clone());
            const label = btn ? btn.querySelector('.btn-label') : null;
            if (label) label.textContent = 'Share';
            btn.setAttribute('aria-pressed', 'false');
            refreshGridLayout();
            sendSignal('system', { type: 'screenshare', active: false });
        }
    } catch (e) {
        notifyErr(e.message || 'Screen share failed');
    }
});
document.getElementById('mic-device').addEventListener('change', async (e) => {
    try {
        const id = e.target.value || undefined;
        localStorage.setItem('slaymeet_mic_device', id || '');
        if (sfuMode && window.SlayMeetLiveKit) {
            await SlayMeetLiveKit.switchMicrophone(id || '');
            return;
        }
        const audioOpts = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        if (id) audioOpts.deviceId = { exact: id };
        const stream = await navigator.mediaDevices.getUserMedia({ audio: audioOpts, video: false });
        const track = stream.getAudioTracks()[0];
        if (!track) return;
        await replaceTrack('audio', track);
    } catch (err) {
        notifyErr('Mic switch failed');
    }
});
document.getElementById('cam-device').addEventListener('change', async (e) => {
    try {
        const id = e.target.value || undefined;
        localStorage.setItem('slaymeet_cam_device', id || '');
        if (sfuMode && window.SlayMeetLiveKit) {
            await SlayMeetLiveKit.switchCamera(id || '');
            await SlayMeetLiveKit.setCameraEnabled(true);
            isScreenSharing = false;
            activeScreenShareUserId = null;
            return;
        }
        const stream = await navigator.mediaDevices.getUserMedia({ video: id ? { deviceId: { exact: id } } : true, audio: false });
        const track = stream.getVideoTracks()[0];
        if (!track) return;
        isScreenSharing = false;
        activeScreenShareUserId = null;
        await replaceTrack('video', track);
        cameraTrackRef = track;
        const btn = document.getElementById('share-screen');
        const label = btn ? btn.querySelector('.btn-label') : null;
        if (label) label.textContent = 'Share';
        btn.setAttribute('aria-pressed', 'false');
        refreshGridLayout();
    } catch (err) {
        notifyErr('Camera switch failed');
    }
});

function closeDockMoreMenu() {
    const menu = document.getElementById('dock-more-menu');
    const btn = document.getElementById('dock-more-btn');
    if (!menu || !btn) return;
    menu.classList.remove('is-open');
    menu.setAttribute('aria-hidden', 'true');
    btn.setAttribute('aria-expanded', 'false');
}

(function initDockMoreMenu() {
    const trigger = document.getElementById('dock-more-btn');
    const menu = document.getElementById('dock-more-menu');
    if (!trigger || !menu) return;
    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const open = !menu.classList.contains('is-open');
        menu.classList.toggle('is-open', open);
        menu.setAttribute('aria-hidden', open ? 'false' : 'true');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.dock-more-wrap')) closeDockMoreMenu();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDockMoreMenu();
    });
})();

document.getElementById('send-reaction').addEventListener('click', async () => {
    closeDockMoreMenu();
    const emoji = window.prompt('Reaction emoji:', '👍');
    if (!emoji) return;
    setTileBadge(SlayMeetConfig.userId, emoji, 2500);
    try {
        await sendSignal('system', { type: 'reaction', emoji: String(emoji).slice(0, 4) });
    } catch (_) {}
});
document.getElementById('raise-hand').addEventListener('click', async () => {
    handRaised = !handRaised;
    const btn = document.getElementById('raise-hand');
    btn.setAttribute('aria-pressed', handRaised ? 'true' : 'false');
    const label = btn ? btn.querySelector('.btn-label') : null;
    if (label) label.textContent = handRaised ? 'Lower' : 'Hand';
    if (handRaised) setTileBadge(SlayMeetConfig.userId, '✋', 0);
    else {
        const tile = document.querySelector(`#video-stage [data-user-id="${SlayMeetConfig.userId}"]`);
        const badge = tile ? tile.querySelector('.badge') : null;
        if (badge && badge.textContent === '✋') badge.remove();
    }
    try {
        await sendSignal('system', { type: 'hand', raised: handRaised });
    } catch (_) {}
});
document.getElementById('toggle-recording').addEventListener('click', () => {
    if (recorder.isRecording) {
        recorder.stop();
    } else {
        recorder.start();
    }
});
document.getElementById('meet-chat-send').addEventListener('click', async () => {
    const input = document.getElementById('meet-chat-input');
    const msg = String(input.value || '').trim();
    if (!msg) return;
    input.value = '';
    addChatLine(SlayMeetConfig.userName, msg, true);
    try {
        await sendSignal('system', { type: 'chat', message: msg.slice(0, 500), from_name: SlayMeetConfig.userName });
    } catch (_) {}
    // Let the AI assistant (if present) answer questions typed in chat.
    if (window.slayAgent) { try { window.slayAgent.ingest(SlayMeetConfig.userName, msg, 'chat'); } catch (_) {} }
});

// ── AI assistant (Ultra Looper / Teena) ──────────────────────────────────────
let slayAgentInviting = false;
async function inviteAiAssistant() {
    if (typeof closeDockMoreMenu === 'function') closeDockMoreMenu();
    if (typeof SlayMeetAgent === 'undefined') { notifyErr('AI assistant unavailable — the agent script failed to load. Refresh the page and try again.'); return; }
    if (window.slayAgent) { notifyOk('Teena is already in this meeting — ask her anything in chat.'); openMeetChatPanel(); return; }
    if (slayAgentInviting) return;
    slayAgentInviting = true;
    notifyOk('Inviting Teena to the meeting…');
    try {
        const fd = new FormData();
        fd.append('room', SlayMeetConfig.room);
        fd.append('csrf_token', SlayMeetConfig.csrfToken);
        const res = await fetch(`${SlayMeetConfig.siteUrl}/api/slaymeet/invite_agent.php`, {
            method: 'POST',
            headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
            body: fd,
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success || !data.bot_token) {
            throw new Error((data && data.message) || ('Could not invite the AI assistant (HTTP ' + res.status + ').'));
        }
        window.slayAgent = new SlayMeetAgent({
            siteUrl: SlayMeetConfig.siteUrl,
            botToken: data.bot_token,
            companyId: SlayMeetConfig.companyId,
            userId: SlayMeetConfig.userId,
            roomToken: SlayMeetConfig.room,
            hostSpeakerName: SlayMeetConfig.userName,
            assistantWakeName: 'Teena',
            assistantDisplayName: 'Ultra Looper AI Assistant',
            speakEnabled: true,
            listenMode: 'wake',
            addChatLine: (name, text) => addChatLine(name, text, false),
            sendSignal: (scope, payload) => sendSignal('system', payload),
            notifyOk: (m) => notifyOk(m),
        });
        try {
            await window.slayAgent.start();
        } catch (startErr) {
            window.slayAgent = null; // allow retry
            throw startErr;
        }
        // Make Teena's presence obvious: open chat so the greeting is visible.
        openMeetChatPanel();
        if (data.lobby_registered) {
            notifyOk("Teena is in the Waiting Room. Click \u201CAdmit guests\u201D to let her join, then ask her in chat.");
            if (typeof refreshState === 'function') { refreshState().catch(() => {}); }
        } else {
            const warn = (data.lobby_warning || '').trim();
            notifyOk('Teena joined the chat. ' + (warn ? '(Note: ' + warn + ')' : 'Ask her anything in chat.'));
        }
        
        // Start speech engine for the AI if the mic is currently unmuted
        const isMicOn = localStream && localStream.getAudioTracks().some(t => t.enabled);
        updateSpeechRecognitionState(isMicOn);
    } catch (e) {
        console.error('[InviteAI]', e);
        notifyErr('Could not bring Teena in: ' + (e && e.message ? e.message : 'unknown error'));
    } finally {
        slayAgentInviting = false;
    }
}

/** Open the chat rail so the host can see Teena's messages. */
function openMeetChatPanel() {
    try {
        if (typeof smOpenRail === 'function') smOpenRail('chat');
    } catch (_) { /* ignore */ }
}
document.getElementById('invite-ai')?.addEventListener('click', inviteAiAssistant);

// Persist the AI transcript when the host leaves / closes the tab.
window.addEventListener('pagehide', () => {
    if (SlayMeetConfig.isDmCall) {
        slaylySetMeetPresence(false);
        slaylyEndDmCallRecord('ended');
    }
    if (window.slayAgent && typeof window.slayAgent.saveTranscriptBeacon === 'function') {
        try { window.slayAgent.saveTranscriptBeacon(); } catch (_) {}
    }
});
document.getElementById('refresh').addEventListener('click', async () => {
    closeDockMoreMenu();
    const btn = document.getElementById('refresh');
    btn.disabled = true;
    btn.style.opacity = '0.5';
    notifyOk('Refreshing connection sync...');
    try {
        const data = await refreshState();
        if (!sfuMode) {
            const participants = data.participants || [];
            for (const p of participants) {
                const pid = parseInt(p.user_id, 10);
                if (pid && pid !== SlayMeetConfig.userId && shouldInitiateOffer(pid)) {
                    await createOfferFor(pid);
                }
            }
        }
        setTimeout(() => {
            btn.disabled = false;
            btn.style.opacity = '1';
        }, 2000);
    } catch (e) {
        notifyErr('Sync failed');
        btn.disabled = false;
        btn.style.opacity = '1';
    }
});
document.getElementById('meet-chat-input').addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('meet-chat-send').click();
    }
});
document.getElementById('copy-link').addEventListener('click', async () => {
    const link = SlayMeetConfig.siteUrl + '/meet?room=' + encodeURIComponent(SlayMeetConfig.room);
    try {
        await navigator.clipboard.writeText(link);
        notifyOk('Invite link copied');
    } catch (_) {
        window.prompt('Copy this invite link:', link);
    }
});
document.getElementById('add-people').addEventListener('click', async () => {
    const link = SlayMeetConfig.siteUrl + '/meet?room=' + encodeURIComponent(SlayMeetConfig.room);
    const text = `Join my SlayMeet: ${link}`;
    try {
        if (navigator.share) {
            await navigator.share({ title: 'Join SlayMeet', text, url: link });
            return;
        }
    } catch (_) {}
    window.prompt('Share this invite message:', text);
});

document.getElementById('toggle-whiteboard').addEventListener('click', () => {
    closeDockMoreMenu();
    const overlay = document.getElementById('whiteboard-overlay');
    if (!overlay) return;
    const opening = overlay.style.display === 'none';
    overlay.style.display = opening ? 'flex' : 'none';
    overlay.setAttribute('aria-hidden', opening ? 'false' : 'true');
    if (opening) {
        slayBoard.resize();
    }
});

document.getElementById('meet-file-btn').addEventListener('click', () => {
    document.getElementById('meet-file-input').click();
});

document.getElementById('meet-file-input').addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        uploadSharedFile(e.target.files[0]);
    }
});

// Drag & Drop for Chat Sidebar
const chatLog = document.getElementById('meet-chat-log');
chatLog.addEventListener('dragover', (e) => {
    e.preventDefault();
    chatLog.style.background = 'rgba(99, 102, 241, 0.05)';
});
chatLog.addEventListener('dragleave', () => {
    chatLog.style.background = '';
});
chatLog.addEventListener('drop', (e) => {
    e.preventDefault();
    chatLog.style.background = '';
    if (e.dataTransfer.files.length > 0) {
        uploadSharedFile(e.dataTransfer.files[0]);
    }
});
document.getElementById('prejoin-refresh').addEventListener('click', () => {
    setupPrejoinPreview().catch(() => {});
});
document.getElementById('prejoin-toggle-video').addEventListener('click', () => {
    prejoinCameraEnabled = !prejoinCameraEnabled;
    if (!prejoinCameraEnabled && prejoinStream) {
        prejoinStream.getVideoTracks().forEach(t => { t.enabled = false; t.stop(); });
        if (typeof rawCameraTrack !== 'undefined' && rawCameraTrack) { rawCameraTrack.enabled = false; rawCameraTrack.stop(); rawCameraTrack = null; }
    } else if (prejoinCameraEnabled) {
        setupPrejoinPreview().catch(() => {});
    }
    updatePrejoinCameraUi();
});
document.getElementById('prejoin-mic').addEventListener('change', (e) => {
    localStorage.setItem('slaymeet_mic_device', e.target.value || '');
    setupPrejoinPreview().catch(() => {});
});
document.getElementById('prejoin-cam').addEventListener('change', (e) => {
    localStorage.setItem('slaymeet_cam_device', e.target.value || '');
    setupPrejoinPreview().catch(() => {});
});
// Shared join routine used by both the manual "Join Now" button and the
// Teams-style instant join for internal DM calls.
async function slaylySetMeetPresence(inCall) {
    try {
        const fd = new FormData();
        if (inCall) {
            fd.append('chat_status', 'in_call');
        } else {
            fd.append('restore_presence', '1');
        }
        await fetch(SlayMeetConfig.siteUrl + '/api/chat/presence_ping.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
            body: fd,
            credentials: 'same-origin'
        });
    } catch (_) {}
}

async function slaylyEndDmCallRecord(status) {
    if (!SlayMeetConfig.isDmCall) return;
    try {
        const fd = new FormData();
        fd.append('room_token', SlayMeetConfig.room);
        fd.append('status', status || 'ended');
        await fetch(SlayMeetConfig.siteUrl + '/api/slaymeet/end_call.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
            body: fd,
            credentials: 'same-origin'
        });
    } catch (_) {}
}

async function performJoin() {
    const joinBtn = document.getElementById('prejoin-join');
    const waitingNote = document.getElementById('prejoin-waiting-note');
    if (joinBtn) joinBtn.disabled = true;
    const res = await join();
    populateDevices().catch(() => {});
    if (res && res.waiting) {
        if (waitingNote) waitingNote.classList.add('show');
        if (joinBtn) joinBtn.textContent = 'Waiting for host...';
        await pollAdmissionUntilApproved();
        return;
    }
    hidePrejoin();
    document.getElementById('meetingStatusText').textContent = 'Connected';
    if (SlayMeetConfig.isDmCall) {
        slaylySetMeetPresence(true);
    }
    beginRealtimeLoops();
    nudgeRemoteAudioPlayback();
}

document.getElementById('prejoin-join').addEventListener('click', async () => {
    unlockMeetingAudioOnGesture();
    try {
        await performJoin();
    } catch (e) {
        notifyErr(e.message || 'Failed to join');
        const joinBtn = document.getElementById('prejoin-join');
        if (joinBtn) joinBtn.disabled = false;
    }
});

// ── SlayMeet Local Speech Recognition (STT for AI Agent) ────────────────────
let localSpeechRecognition = null;
let isSpeechRecognitionActive = false;
let isCaptionsEnabled = false;
let captionClearTimer = null;

function renderCaption(userId, name, text, isFinal) {
    if (!isCaptionsEnabled) return;
    const container = document.getElementById('captions-container');
    if (!container) return;
    container.style.display = 'block';
    container.innerHTML = `<strong>${escHtml(name)}:</strong> <span>${escHtml(text)}</span>`;
    if (captionClearTimer) clearTimeout(captionClearTimer);
    captionClearTimer = setTimeout(() => {
        container.innerHTML = '';
        container.style.display = 'none';
    }, 4000);
}

document.getElementById('toggle-captions')?.addEventListener('click', () => {
    isCaptionsEnabled = !isCaptionsEnabled;
    const btn = document.getElementById('toggle-captions');
    if (btn) btn.querySelector('.btn-label').textContent = isCaptionsEnabled ? 'Hide CC' : 'Captions (CC)';
    if (typeof closeDockMoreMenu === 'function') closeDockMoreMenu();
    if (isCaptionsEnabled) {
        notifyOk('Live captions enabled');
    } else {
        document.getElementById('captions-container').style.display = 'none';
    }
    
    // Dynamically start/stop the engine based on whether it is now needed
    const isMicOn = localStream && localStream.getAudioTracks().some(t => t.enabled);
    updateSpeechRecognitionState(isMicOn);
});

function initLocalSpeechRecognition() {
    if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
        console.warn('SpeechRecognition API not supported in this browser.');
        return;
    }
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    localSpeechRecognition = new SpeechRecognition();
    localSpeechRecognition.continuous = true;
    localSpeechRecognition.interimResults = true;
    localSpeechRecognition.lang = 'en-US';

    localSpeechRecognition.onresult = (event) => {
        let interimTranscript = '';
        let finalStr = '';
        for (let i = event.resultIndex; i < event.results.length; ++i) {
            if (event.results[i].isFinal) {
                finalStr += event.results[i][0].transcript;
            } else {
                interimTranscript += event.results[i][0].transcript;
            }
        }
        const text = finalStr || interimTranscript;
        if (!text) return;

        // Broadcast caption
        sendSignal('system', { 
            type: 'caption', 
            text: text, 
            isFinal: !!finalStr,
            from_name: SlayMeetConfig.userName 
        });

        // Render locally
        renderCaption(SlayMeetConfig.userId, SlayMeetConfig.userName, text, !!finalStr);

        // Ingest locally
        if (finalStr && window.slayAgent) {
            try { window.slayAgent.ingest(SlayMeetConfig.userName, finalStr.trim(), 'caption'); } catch (_) {}
        }
    };

    let lastError = null;
    localSpeechRecognition.onerror = (event) => {
        // Only log non-routine errors to avoid spamming the console
        if (event.error !== 'no-speech') {
            console.warn('SpeechRecognition error:', event.error);
        }
        lastError = event.error;
    };

    localSpeechRecognition.onend = () => {
        if (isSpeechRecognitionActive && localStream) {
            const on = localStream.getAudioTracks().some(t => t.enabled);
            if (on) {
                // If it was a no-speech error, back off for a second to prevent tight looping
                const delay = (lastError === 'no-speech' || lastError === 'network') ? 1000 : 100;
                lastError = null;
                setTimeout(() => {
                    if (isSpeechRecognitionActive && localStream && localStream.getAudioTracks().some(t => t.enabled)) {
                        try { localSpeechRecognition.start(); } catch (_) {}
                    }
                }, delay);
            }
        }
    };
}

function updateSpeechRecognitionState(isMicOn) {
    if (!localSpeechRecognition) return;
    isSpeechRecognitionActive = isMicOn;
    
    // Only run speech recognition if actually needed by a feature (captions or AI)
    const isNeeded = isCaptionsEnabled || (window.slayAgent !== null && typeof window.slayAgent !== 'undefined');
    
    if (isMicOn && isNeeded) {
        try { localSpeechRecognition.start(); } catch (_) {}
    } else {
        try { localSpeechRecognition.stop(); } catch (_) {}
    }
}

// Outgoing DM call overlay — caller sees "Calling…" on top of this window while
// the meeting connects in the background (same window, no second tab).
function smInitials(name) {
    name = String(name || '').trim();
    if (!name) return '?';
    const parts = name.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
    return name.slice(0, 2).toUpperCase();
}

function smShowOutgoingCallOverlay() {
    const oc = SlayMeetConfig.outgoingCall || {};
    if (!oc.active || !oc.callId) return;
    if (document.getElementById('slaymeet-outgoing-call-overlay')) return;
    const avatarRaw = String(oc.peerAvatar || '').trim();
    const initials = escHtml(smInitials(oc.peerName));
    // Show the real photo when available; otherwise an initials monogram (Teams style) — never a blank placeholder.
    const avatarHtml = avatarRaw
        ? `<img class="slaymeet-call-avatar" src="${escHtml(avatarRaw)}" alt="">`
        : `<div class="slaymeet-call-avatar slaymeet-call-avatar--initials">${initials}</div>`;
    const overlay = document.createElement('div');
    overlay.id = 'slaymeet-outgoing-call-overlay';
    overlay.className = 'slaymeet-call-overlay slaymeet-call-overlay--outgoing is-visible';
    overlay.setAttribute('aria-live', 'polite');
    overlay.innerHTML = `
        <div class="slaymeet-call-screen">
            <div class="slaymeet-call-top">
                <span class="slaymeet-call-peer-name">${escHtml(oc.peerName || 'Team member')}</span>
                <span class="slaymeet-call-timer" id="sm-outgoing-timer">00:00</span>
            </div>
            <div class="slaymeet-call-stage">
                <div class="slaymeet-call-avatar-wrap">
                    ${avatarHtml}
                </div>
                <p class="slaymeet-call-status">Calling…</p>
            </div>
            <div class="slaymeet-call-actions">
                <button type="button" class="slaymeet-call-btn slaymeet-call-btn--cancel" id="sm-outgoing-cancel">
                    <span class="slaymeet-call-btn-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.68 13.31a16 16 0 0 0 3.41 2.6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7 2 2 0 0 1 1.72 2v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.42 19.42 0 0 1-3.33-2.67m-2.67-3.34a19.79 19.79 0 0 1-3.07-8.63A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91"></path><line x1="23" y1="1" x2="1" y2="23"></line></svg>
                    </span>
                    Cancel
                </button>
            </div>
        </div>`;
    document.body.appendChild(overlay);
    // If the photo URL fails to load, fall back to the initials monogram.
    const imgEl = overlay.querySelector('img.slaymeet-call-avatar');
    if (imgEl) {
        imgEl.addEventListener('error', () => {
            const div = document.createElement('div');
            div.className = 'slaymeet-call-avatar slaymeet-call-avatar--initials';
            div.textContent = smInitials(oc.peerName);
            imgEl.replaceWith(div);
        });
    }

    let ring = null;
    try {
        ring = new Audio(SlayMeetConfig.siteUrl + '/assets/Ringtone/incoming-ring.mp3');
        ring.loop = true;
        ring.volume = 0.5;
        ring.play().catch(() => {});
    } catch (_) {}

    const startedAt = Date.now();
    const fmt = (ms) => { const s = Math.floor(ms / 1000); const m = Math.floor(s / 60); const r = s % 60; return `${m < 10 ? '0' : ''}${m}:${r < 10 ? '0' : ''}${r}`; };
    const timerEl = overlay.querySelector('#sm-outgoing-timer');
    const tick = setInterval(() => { if (timerEl) timerEl.textContent = fmt(Date.now() - startedAt); }, 1000);

    let pollTimer = null;
    const stopAll = () => {
        clearInterval(tick);
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (ring) { try { ring.pause(); ring.currentTime = 0; } catch (_) {} }
    };
    const hideOverlay = () => { stopAll(); overlay.remove(); };
    const backToChat = async () => {
        stopAll();
        if (SlayMeetConfig.isDmCall) {
            await slaylyEndDmCallRecord('rejected');
            await slaylySetMeetPresence(false);
        }
        window.location.href = SlayMeetConfig.siteUrl + '/dashboard/chat.php';
    };

    overlay.querySelector('#sm-outgoing-cancel').addEventListener('click', async () => {
        try {
            const fd = new FormData();
            fd.append('call_id', String(oc.callId));
            await fetch(SlayMeetConfig.siteUrl + '/api/slaymeet/cancel_call.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': SlayMeetConfig.csrfToken },
                body: fd
            });
        } catch (_) {}
        backToChat();
    });

    let iter = 0;
    const maxIter = 200; // ~5 min at 1.5s
    const pollOnce = async () => {
        try {
            const r = await fetch(SlayMeetConfig.siteUrl + '/api/slaymeet/poll_call_status.php?call_id=' + encodeURIComponent(oc.callId), { credentials: 'same-origin' });
            const d = await r.json();
            if (!d || !d.success) return;
            if (d.status === 'accepted') {
                hideOverlay();
                unlockMeetingAudioOnGesture();
            } else if (d.status === 'rejected') {
                if (typeof notifyErr === 'function') notifyErr('Call declined');
                setTimeout(backToChat, 1500);
                stopAll();
            }
        } catch (_) {}
    };
    pollTimer = setInterval(() => {
        iter++;
        if (iter > maxIter) { hideOverlay(); if (typeof notifyOk === 'function') notifyOk('No answer yet — you are in the room. Share the link or keep waiting.'); return; }
        pollOnce();
    }, 1500);
    setTimeout(pollOnce, 500);
}

updatePrejoinCameraUi();
if (SlayMeetConfig.isDmCall) {
    slaylySetMeetPresence(true);
    // Internal 1:1 call — Microsoft Teams style: no lobby. The callee already
    // tapped Accept, so connect instantly (mic on, camera off). The browser may
    // still show its native permission prompt the first time, but there is no
    // app-level "check permissions / Join Now" screen.
    hidePrejoin();
    unlockMeetingAudioOnGesture();
    if (SlayMeetConfig.outgoingCall && SlayMeetConfig.outgoingCall.active) {
        smShowOutgoingCallOverlay();
    }
    performJoin().catch((e) => {
        // Permission denied or media failure — fall back to the manual lobby.
        console.warn('[SlayMeet] DM instant-join failed; showing lobby', e);
        const modal = document.getElementById('prejoin-modal');
        if (modal) { modal.style.display = ''; modal.classList.remove('hidden'); }
        const joinBtn = document.getElementById('prejoin-join');
        if (joinBtn) joinBtn.disabled = false;
        populateDevices().then(() => setupPrejoinPreview()).catch(() => {});
        if (e && e.message) notifyErr(e.message);
    });
} else {
    populateDevices()
        .then(() => setupPrejoinPreview())
        .catch(() => {});
}
</script>
</body>
</html>
