<?php
declare(strict_types=1);

/**
 * Shared SlayMeet helpers (room URL, waiting-room schema, bot process spawn).
 */
final class SlayMeetHelpers
{
    /**
     * Public web base URL (includes subfolder e.g. /slayly on XAMPP).
     * Never use HTTP_ORIGIN — it omits the path.
     */
    public static function requestBaseUrl(): string
    {
        $base = '';
        if (defined('SITE_URL') && (string) SITE_URL !== '') {
            $base = rtrim((string) SITE_URL, '/');
        }

        if ($base === '') {
            $base = self::baseFromScriptName() ?? 'http://localhost/slayly';
        }

        return self::ensureSubfolderOnLocalhost($base);
    }

    /**
     * Canonical UltraMeet page URL (rewrite route /ultrameet; legacy /slaymeet 301s here).
     *
     * @param array<string, scalar|null> $query extra query params (bot, bot_token, …)
     */
    public static function meetingPageUrl(string $publicToken, array $query = []): string
    {
        $token = trim($publicToken);
        $url = self::requestBaseUrl() . '/meet?room=' . rawurlencode($token);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $url .= '&' . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $url;
    }

    /** On XAMPP, SITE_URL is sometimes http://localhost without /slayly — repair it. */
    private static function ensureSubfolderOnLocalhost(string $base): string
    {
        $parsed = parse_url($base);
        $path = (string) ($parsed['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return $base;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        $isLocal = $host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0;
        if (!$isLocal) {
            return $base;
        }

        $inferred = self::detectSubfolderFromDocRoot();
        if ($inferred !== '') {
            $scheme = $parsed['scheme'] ?? 'http';

            return $scheme . '://' . ($parsed['host'] ?? 'localhost') . $inferred;
        }

        return rtrim($base, '/') . '/slayly';
    }

    private static function detectSubfolderFromDocRoot(): string
    {
        $docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $projectRoot = str_replace('\\', '/', dirname(__DIR__, 3));
        $docRoot = rtrim($docRoot, '/');
        if ($docRoot === '' || stripos($projectRoot, $docRoot) !== 0) {
            return '';
        }

        return rtrim(substr($projectRoot, strlen($docRoot)), '/');
    }

    private static function baseFromScriptName(): ?string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === '') {
            return null;
        }

        $patterns = [
            '#^(.+)/(public/)?api/#',
            '#^(.+)/(public/)?(?:dashboard/)?slaymeet\.php$#i',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $script, $m)) {
                continue;
            }
            $path = rtrim((string) $m[1], '/');
            $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
            if ($host === '') {
                return null;
            }
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
            $scheme = $https ? 'https' : 'http';

            return $scheme . '://' . $host . $path;
        }

        return null;
    }

    public static function isLocalDevRequest(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return $host === 'localhost'
            || strpos($host, 'localhost:') === 0
            || $host === '127.0.0.1'
            || strpos($host, '127.0.0.1:') === 0;
    }

    public static function waitingRoomSchemaReady(mysqli $conn): bool
    {
        $check = $conn->query("SHOW COLUMNS FROM slay_meet_participants LIKE 'admission_status'");

        return $check && $check->num_rows > 0;
    }

    /** Self-heal signaling table + indexes for high-volume poll queries. */
    public static function ensureSignalingSchema(mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS slay_meet_signals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                from_user_id INT NOT NULL,
                to_user_id INT NULL DEFAULT NULL,
                signal_type VARCHAR(24) NOT NULL,
                payload_json MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_room_poll (room_id, id),
                INDEX idx_room_target (room_id, to_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        foreach (
            [
                'CREATE INDEX idx_room_poll ON slay_meet_signals (room_id, id)',
                'CREATE INDEX idx_room_target ON slay_meet_signals (room_id, to_user_id, id)',
                'CREATE INDEX idx_created_at ON slay_meet_signals (created_at)',
            ] as $ddl
        ) {
            try {
                $conn->query($ddl);
            } catch (\Throwable $e) {
                // Index may already exist.
            }
        }
    }

    /** Drop signaling rows for one room (e.g. when meeting ends). */
    public static function pruneRoomSignals(mysqli $conn, int $roomId): int
    {
        if ($roomId <= 0) {
            return 0;
        }
        self::ensureSignalingSchema($conn);
        $stmt = $conn->prepare('DELETE FROM slay_meet_signals WHERE room_id = ?');
        $stmt->bind_param('i', $roomId);
        $stmt->execute();
        $n = (int) $stmt->affected_rows;
        $stmt->close();

        return $n;
    }

    /** Drop consumed signaling rows so poll queries stay fast at scale. */
    public static function pruneStaleSignals(mysqli $conn, int $olderThanHours = 6): int
    {
        self::ensureSignalingSchema($conn);
        $hours = max(1, min(168, $olderThanHours));
        $conn->query("
            DELETE s FROM slay_meet_signals s
            INNER JOIN slay_meet_rooms r ON r.id = s.room_id
            WHERE r.status = 'ended'
               OR s.created_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
        ");

        return (int) $conn->affected_rows;
    }

    /** Active room id for a participant, or null if not authorized. */
    public static function resolveActiveRoomMember(mysqli $conn, int $userId, string $roomToken): ?int
    {
        $roomToken = trim($roomToken);
        if ($roomToken === '' || $userId <= 0) {
            return null;
        }
        self::ensureSignalingSchema($conn);
        $stmt = $conn->prepare("
            SELECT r.id AS room_id
            FROM slay_meet_rooms r
            JOIN slay_meet_participants p ON p.room_id = r.id AND p.user_id = ? AND p.is_active = 1
            WHERE r.public_token = ?
            LIMIT 1
        ");
        $stmt->bind_param('is', $userId, $roomToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['room_id'] : null;
    }

    /**
     * @return array{signals: list<array<string, mixed>>, last_id: int}
     */
    public static function fetchSignalsForMember(mysqli $conn, int $roomId, int $userId, int $sinceId, int $limit = 250): array
    {
        $sinceId = max(0, $sinceId);
        $limit = max(1, min(500, $limit));
        $stmt = $conn->prepare("
            SELECT id, from_user_id, to_user_id, signal_type, payload_json, created_at
            FROM slay_meet_signals
            WHERE room_id = ?
              AND id > ?
              AND from_user_id <> ?
              AND (to_user_id IS NULL OR to_user_id = ?)
            ORDER BY id ASC
            LIMIT ?
        ");
        $stmt->bind_param('iiiii', $roomId, $sinceId, $userId, $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $signals = [];
        $maxId = $sinceId;
        while ($r = $res->fetch_assoc()) {
            $r['payload'] = json_decode((string) $r['payload_json'], true);
            unset($r['payload_json']);
            $signals[] = $r;
            $rid = (int) $r['id'];
            if ($rid > $maxId) {
                $maxId = $rid;
            }
        }
        $stmt->close();

        return ['signals' => $signals, 'last_id' => $maxId];
    }

    /**
     * @return array{signals: list<array<string, mixed>>, last_id: int}|null
     */
    public static function pollMemberSignals(mysqli $conn, int $userId, string $roomToken, int $sinceId, int $limit = 250): ?array
    {
        $roomId = self::resolveActiveRoomMember($conn, $userId, $roomToken);
        if ($roomId === null) {
            return null;
        }

        return self::fetchSignalsForMember($conn, $roomId, $userId, $sinceId, $limit);
    }

    /**
     * Log Slayly AI into an isolated bot session (SLAYMEET_BOT cookie) for lobby join.
     *
     * @return array{ok: bool, message?: string, user_id?: int}
     */
    /**
     * Remove leftover Slayly AI rows from a prior invite (same room URL reused).
     * Host must click Invite again for a fresh lobby entry.
     */
    public static function clearStaleAiParticipants(mysqli $conn, string $roomToken): void
    {
        $roomToken = trim($roomToken);
        if ($roomToken === '') {
            return;
        }
        $stmt = $conn->prepare('SELECT id FROM slay_meet_rooms WHERE public_token = ? LIMIT 1');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('s', $roomToken);
        $stmt->execute();
        $room = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$room) {
            return;
        }
        $roomId = (int) $room['id'];
        $hasAdmission = self::waitingRoomSchemaReady($conn);
        $sql = $hasAdmission
            ? "
            UPDATE slay_meet_participants p
            INNER JOIN users u ON u.id = p.user_id
            SET p.is_active = 0,
                p.left_at = NOW(),
                p.admission_status = 'denied'
            WHERE p.room_id = ?
              AND u.role = 'guest'
              AND u.name IN ('Teena', 'Slayly AI')
              AND (
                  p.is_active = 1
                  OR p.admission_status IN ('pending', 'denied')
              )
        "
            : "
            UPDATE slay_meet_participants p
            INNER JOIN users u ON u.id = p.user_id
            SET p.is_active = 0, p.left_at = NOW()
            WHERE p.room_id = ?
              AND u.role = 'guest'
              AND u.name IN ('Teena', 'Slayly AI')
              AND p.is_active = 1
        ";
        $upd = $conn->prepare($sql);
        if ($upd) {
            $upd->bind_param('i', $roomId);
            $upd->execute();
            $upd->close();
        }
    }

    /**
     * Resolve or create the shared Slayly AI guest user for a company.
     */
    public static function resolveSlaylyAiGuestUserId(mysqli $conn, int $companyId): int
    {
        if ($companyId <= 0) {
            return 0;
        }
        $botName = 'Teena';
        $stmt = $conn->prepare("
            SELECT id FROM users
            WHERE company_id = ? AND role = 'guest'
              AND name IN ('Teena', 'Slayly AI')
            ORDER BY id DESC
            LIMIT 1
        ");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return (int) ($row['id'] ?? 0);
        }

        $email = 'slayly_ai_' . $companyId . '@slaymeet.local';
        $pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $ins = $conn->prepare("
            INSERT INTO users
            (name, email, password, company_id, team_role, role, status, is_verified, onboarding_completed, force_password_reset, created_at)
            VALUES (?, ?, ?, ?, 'teammate', 'guest', 'active', 1, 1, 0, NOW())
        ");
        if (!$ins) {
            return 0;
        }
        $ins->bind_param('sssi', $botName, $email, $pass, $companyId);
        if (!$ins->execute()) {
            $ins->close();

            return 0;
        }
        $newId = (int) $conn->insert_id;
        $ins->close();

        return $newId;
    }

    /**
     * Put Slayly AI in the waiting lobby immediately (host Admit list), without relying on iframe/Node.
     *
     * @return array{ok: bool, message?: string, user_id?: int}
     */
    public static function registerBotInWaitingLobby(mysqli $conn, int $roomId, int $companyId): array
    {
        if ($roomId <= 0 || $companyId <= 0) {
            return ['ok' => false, 'message' => 'Invalid room or company'];
        }
        if (!self::waitingRoomSchemaReady($conn)) {
            return [
                'ok' => false,
                'message' => 'Waiting room is not enabled. Run database/migrations/007_slaymeet_waiting_room.php',
            ];
        }

        $botUserId = self::resolveSlaylyAiGuestUserId($conn, $companyId);
        if ($botUserId <= 0) {
            return ['ok' => false, 'message' => 'Could not create Slayly AI guest user'];
        }

        $role = 'participant';
        $requestedAt = date('Y-m-d H:i:s');
        $joinedAt = $requestedAt;

        $stmt = $conn->prepare("
            SELECT id FROM slay_meet_participants
            WHERE room_id = ? AND user_id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'Database error'];
        }
        $stmt->bind_param('ii', $roomId, $botUserId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $upd = $conn->prepare("
                UPDATE slay_meet_participants
                SET role = ?,
                    admission_status = 'pending',
                    requested_at = ?,
                    decided_at = NULL,
                    decided_by = NULL,
                    left_at = NULL,
                    is_active = 0,
                    joined_at = COALESCE(joined_at, ?)
                WHERE room_id = ? AND user_id = ?
            ");
            if (!$upd) {
                return ['ok' => false, 'message' => 'Database error'];
            }
            $upd->bind_param('sssii', $role, $requestedAt, $joinedAt, $roomId, $botUserId);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("
                INSERT INTO slay_meet_participants
                    (room_id, user_id, role, joined_at, requested_at, admission_status, decided_at, decided_by, is_active)
                VALUES (?, ?, ?, ?, ?, 'pending', NULL, NULL, 0)
            ");
            if (!$ins) {
                return ['ok' => false, 'message' => 'Database error'];
            }
            $ins->bind_param('iisss', $roomId, $botUserId, $role, $joinedAt, $requestedAt);
            $ins->execute();
            $ins->close();
        }

        return ['ok' => true, 'user_id' => $botUserId];
    }

    public static function appendBotInviteLog(string $rootDir, string $line): void
    {
        $logDir = $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'slaymeet-bot-' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, '[' . date('c') . '] [invite_agent] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function bootstrapBotGuestSession(string $roomToken, string $botToken): array
    {
        require_once __DIR__ . '/SlayMeetAgent.php';
        require_once __DIR__ . '/../../Core/Database.php';

        $roomToken = trim($roomToken);
        $botToken = trim($botToken);
        if ($roomToken === '' || $botToken === '') {
            return ['ok' => false, 'message' => 'Missing room or bot token'];
        }

        $claims = SlayMeetAgent::validateBotToken($botToken);
        if ($claims === null || ($claims['room_token'] ?? '') !== $roomToken) {
            return ['ok' => false, 'message' => 'Invalid bot token'];
        }

        try {
            $db = \Slayly\Core\Database::getInstance()->getConnection();
            $stmtRoom = $db->prepare('SELECT company_id FROM slay_meet_rooms WHERE public_token = :token LIMIT 1');
            $stmtRoom->execute([':token' => $roomToken]);
            $roomRow = $stmtRoom->fetch(\PDO::FETCH_ASSOC);
            if (!$roomRow || (int) ($roomRow['company_id'] ?? 0) <= 0) {
                return ['ok' => false, 'message' => 'Room not found'];
            }
            $roomCompanyId = (int) $roomRow['company_id'];

            $botName = 'Teena';
            $stmtUser = $db->prepare("
                SELECT id FROM users
                WHERE company_id = :cid AND role = 'guest'
                  AND name IN ('Teena', 'Slayly AI')
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtUser->execute([':cid' => $roomCompanyId]);
            $existing = $stmtUser->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                $gId = (int) $existing['id'];
            } else {
                $gEmail = 'slayly_ai_' . $roomCompanyId . '@slaymeet.local';
                $guestPass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    INSERT INTO users
                    (name, email, password, company_id, team_role, role, status, is_verified, onboarding_completed, force_password_reset, created_at)
                    VALUES
                    (:name, :email, :password, :company_id, 'teammate', 'guest', 'active', 1, 1, 0, NOW())
                ");
                $stmt->execute([
                    ':name' => $botName,
                    ':email' => $gEmail,
                    ':password' => $guestPass,
                    ':company_id' => $roomCompanyId,
                ]);
                $gId = (int) $db->lastInsertId();
            }

            if ($gId <= 0) {
                return ['ok' => false, 'message' => 'Could not create bot guest user'];
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = $gId;
            $_SESSION['user_name'] = $botName . ' (Guest)';
            $_SESSION['company_id'] = $roomCompanyId;
            $_SESSION['role'] = 'guest';
            $_SESSION['slaymeet_guest_session'] = true;
            $_SESSION['slaymeet_guest_name'] = $botName;
            $_SESSION['slaymeet_bot_runner'] = true;

            return ['ok' => true, 'user_id' => $gId];
        } catch (\Throwable $e) {
            error_log('[SlayMeet bootstrapBotGuestSession] ' . $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, mode: string, log_file: string}
     */
    public static function spawnMeetingBot(string $botUrl, string $rootDir): array
    {
        $scriptsDir = $rootDir . DIRECTORY_SEPARATOR . 'scripts';
        $botScript = $scriptsDir . DIRECTORY_SEPARATOR . 'slayly_meeting_bot.js';
        $logDir = $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'slaymeet-bot-' . date('Y-m-d') . '.log';

        if (!is_file($botScript)) {
            return ['ok' => false, 'mode' => 'missing_script', 'log_file' => $logFile];
        }

        $nodeBin = self::resolveNodeBinary();
        $homeDir = $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'www-data-home';
        if (is_dir($homeDir)) {
            putenv('HOME=' . $homeDir);
            putenv('PUPPETEER_CACHE_DIR=' . $rootDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'puppeteer-cache');
        }

        $escapedUrl = escapeshellarg($botUrl);
        $escapedScript = escapeshellarg($botScript);
        $escapedNode = escapeshellarg($nodeBin);
        $escapedLog = escapeshellarg($logFile);

        if (stristr(PHP_OS, 'WIN')) {
            $cmd = 'cmd /c start "" /B ' . $escapedNode . ' ' . $escapedScript . ' --url ' . $escapedUrl
                . ' >> ' . $escapedLog . ' 2>&1';
            @pclose(@popen($cmd, 'r'));

            return ['ok' => true, 'mode' => 'server_win', 'log_file' => $logFile];
        }

        $cmd = $escapedNode . ' ' . $escapedScript . ' --url ' . $escapedUrl . ' >> ' . $escapedLog . ' 2>&1 &';
        @exec($cmd);

        return ['ok' => true, 'mode' => 'server_linux', 'log_file' => $logFile];
    }

    private static function resolveNodeBinary(): string
    {
        if (stristr(PHP_OS, 'WIN')) {
            $out = [];
            @exec('where node 2>nul', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                return trim((string) $out[0]);
            }

            return 'node';
        }

        $nodeBin = '/usr/bin/node';
        if (is_executable($nodeBin)) {
            return $nodeBin;
        }

        return trim((string) shell_exec('command -v node 2>/dev/null')) ?: 'node';
    }

    /**
     * Preserve the logged-in host before a meet guest join overwrites PHPSESSID identity.
     */
    public static function snapshotHostSessionBeforeMeetGuest(): void
    {
        if (!empty($_SESSION['slaymeet_guest_session']) || empty($_SESSION['user_id'])) {
            return;
        }
        $role = strtolower(trim((string) ($_SESSION['role'] ?? 'user')));
        if ($role === 'guest') {
            return;
        }
        $_SESSION['slaymeet_host_snapshot'] = [
            'user_id' => (int) $_SESSION['user_id'],
            'user_name' => (string) ($_SESSION['user_name'] ?? 'You'),
            'company_id' => (int) ($_SESSION['company_id'] ?? 0),
            'role' => (string) ($_SESSION['role'] ?? 'user'),
            'profile_pic' => (string) ($_SESSION['profile_pic'] ?? ''),
        ];
    }

    /**
     * Restore host identity after dashboard → UltraMeet when a scoped guest session was still active.
     */
    public static function restoreHostSessionFromSnapshot(): bool
    {
        $snap = $_SESSION['slaymeet_host_snapshot'] ?? null;
        if (!is_array($snap) || (int) ($snap['user_id'] ?? 0) <= 0) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $snap['user_id'];
        $_SESSION['user_name'] = (string) ($snap['user_name'] ?? 'You');
        $_SESSION['company_id'] = (int) ($snap['company_id'] ?? 0);
        $_SESSION['role'] = (string) ($snap['role'] ?? 'user');
        if (array_key_exists('profile_pic', $snap)) {
            $_SESSION['profile_pic'] = (string) $snap['profile_pic'];
        }
        unset(
            $_SESSION['slaymeet_guest_session'],
            $_SESSION['slaymeet_guest_name'],
            $_SESSION['slaymeet_host_snapshot']
        );

        return true;
    }

    /** True when the current session user row is a disposable meet guest (Teena bot / guest join). */
    public static function sessionUserIsMeetGuest(): bool
    {
        if (!empty($_SESSION['slaymeet_guest_session'])) {
            return true;
        }
        $uid = (int) ($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        try {
            require_once __DIR__ . '/../../Core/Database.php';
            $pdo = \Slayly\Core\Database::getInstance()->getConnection();
            $st = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $st->execute([$uid]);
            $role = strtolower(trim((string) $st->fetchColumn()));

            return $role === 'guest';
        } catch (\Throwable $e) {
            error_log('[SlayMeetHelpers] sessionUserIsMeetGuest: ' . $e->getMessage());

            return !empty($_SESSION['slaymeet_guest_session']);
        }
    }

    /**
     * Dashboard host entry — restore real account or send to login before instant-room provisioning.
     */
    public static function ensureHostSessionForDashboardMeet(bool $wantsHostEntry): void
    {
        if (!$wantsHostEntry || !empty($_GET['bot'])) {
            return;
        }
        if (self::restoreHostSessionFromSnapshot()) {
            return;
        }
        if (!self::sessionUserIsMeetGuest()) {
            return;
        }
        $return = self::requestBaseUrl() . '/meet?host=1';
        header('Location: ' . self::requestBaseUrl() . '/login.php?redirect=' . rawurlencode($return));
        exit;
    }
}
