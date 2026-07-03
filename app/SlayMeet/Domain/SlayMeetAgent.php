<?php
declare(strict_types=1);

/**
 * SlayMeet AI agent helpers (Joinly-inspired: server brain, signed bot sessions, transcript segments).
 */
final class SlayMeetAgent
{
    private const TOKEN_TTL_SEC = 7200;

    public static function signingSecret(): string
    {
        $s = getenv('SLAYMEET_BOT_SECRET') ?: getenv('DAEMON_TOKEN') ?: '';
        if ($s === '') {
            throw new RuntimeException('SLAYMEET_BOT_SECRET must be set in environment.');
        }

        return $s;
    }

    /**
     * @return array{token: string, exp: int}
     */
    public static function issueBotToken(int $roomId, int $companyId, int $inviterUserId, string $roomToken): array
    {
        $exp = time() + self::TOKEN_TTL_SEC;
        $payload = json_encode([
            'rid' => $roomId,
            'cid' => $companyId,
            'uid' => $inviterUserId,
            'rt' => $roomToken,
            'exp' => $exp,
        ]);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode bot token payload');
        }
        $b64 = self::b64UrlEncode($payload);
        $sig = hash_hmac('sha256', $b64, self::signingSecret(), true);
        $token = $b64 . '.' . self::b64UrlEncode($sig);

        return ['token' => $token, 'exp' => $exp];
    }

    /**
     * @return array{room_id: int, company_id: int, inviter_user_id: int, room_token: string, exp: int}|null
     */
    public static function validateBotToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strpos($token, '.') === false) {
            return null;
        }
        [$b64, $sigB64] = explode('.', $token, 2);
        $expected = self::b64UrlEncode(hash_hmac('sha256', $b64, self::signingSecret(), true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }
        $json = self::b64UrlDecode($b64);
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        return [
            'room_id' => (int) ($data['rid'] ?? 0),
            'company_id' => (int) ($data['cid'] ?? 0),
            'inviter_user_id' => (int) ($data['uid'] ?? 0),
            'room_token' => (string) ($data['rt'] ?? ''),
            'exp' => (int) ($data['exp'] ?? 0),
        ];
    }

    public static function systemPrompt(?string $brandName = null): string
    {
        $kbPath = __DIR__ . '/ultralooper_kb.txt';
        $kbContent = file_exists($kbPath) ? file_get_contents($kbPath) : '';

        $prompt = <<<PROMPT
You are Ultra Looper AI Assistant on a live UltraMeet call for UltraLooper.

Identity (strict):
- Your name is "Ultra Looper AI Assistant". Introduce yourself only with that name.
- Never call yourself Slayly, Slayly AI, or Slayly AI Assistant — those names are outdated and forbidden.
- Never call yourself a copilot, CoPilot, or any variant — that term is not allowed.
- "Teena" is only an optional wake alias users may say; do not present yourself as Teena unless clarifying you are Ultra Looper AI Assistant.

Personality:
- Warm, calm, and confident — like a sharp chief of staff on the call.
- Use plain language; no product jargon, no instructions about "two minutes", wake windows, or how to use the app unless asked.
- Default to 1–3 sentences unless the host asks for a summary, notes, or action items (then use short bullets).
- You listen to meeting chat and captions; refer to "the room" or "this meeting" naturally.
- If unsure, say so briefly and offer one clear next step.
- Never pretend to control external apps, calendars, or email unless the host explicitly connected tools.
- Do not repeat your introduction; greet once if needed, then answer questions directly.

ULTRALOOPER KNOWLEDGE BASE:
{$kbContent}

You respond when someone says Teena, UltraLooper, UltraAssistant, asks for help ("help", "help me", "help us"), or asks a direct question.
PROMPT;

        $brandName = trim((string) $brandName);
        if ($brandName !== '') {
            $safe = str_replace(["\n", "\r"], ' ', $brandName);
            $prompt .= "\n\nYou are on a live call for **{$safe}**. Represent that company professionally; align answers with the company context below.";
        }

        return $prompt;
    }

    /**
     * Load Brand Intelligence + RAG for a meeting room's company.
     *
     * @param list<array{role: string, text: string}> $history
     */
    /**
     * @param list<array{role: string, text: string}> $history
     * @return array{prefix: string, brand_name: string}
     */
    public static function buildCompanyContextBundle(
        int $companyId,
        int $inviterUserId,
        string $userMessage,
        string $meetingContext,
        array $history
    ): array {
        $brandContext = trim((string) (getenv('SLAYMEET_BRAND_CONTEXT') ?: ''));
        if ($brandContext !== '') {
            return ['prefix' => $brandContext, 'brand_name' => trim((string) (getenv('SLAYMEET_BRAND_NAME') ?: 'Your team'))];
        }

        return ['prefix' => '', 'brand_name' => ''];
    }

    /**
     * @param list<array{role: string, text: string}> $history
     */
    public static function buildGeminiContents(array $history, string $userMessage, ?string $companyContext = null, ?string $brandName = null): array
    {
        $systemText = self::systemPrompt($brandName);
        if ($companyContext !== null && trim($companyContext) !== '') {
            $systemText .= "\n\n---\n" . trim($companyContext);
        }

        $ack = 'Understood. I am Ultra Looper AI Assistant — calm, clear, and ready to help this meeting.';
        if ($brandName !== null && trim($brandName) !== '') {
            $ack = 'Understood. I am Ultra Looper AI Assistant on the call for ' . trim($brandName) . ' — calm, clear, and ready to help.';
        }

        $contents = [
            ['role' => 'user', 'parts' => [['text' => $systemText]]],
            ['role' => 'model', 'parts' => [['text' => $ack]]],
        ];
        foreach ($history as $row) {
            $role = ($row['role'] ?? '') === 'model' ? 'model' : 'user';
            $text = trim((string) ($row['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        return $contents;
    }

    /**
     * @param list<array{role: string, text: string}> $history
     */
    public static function respond(
        array $history,
        string $userMessage,
        string $meetingContext = '',
        int $companyId = 0,
        int $inviterUserId = 0
    ): string {
        require_once __DIR__ . '/../../includes/gemini_helper.php';

        $companyCtx = self::buildCompanyContextBundle($companyId, $inviterUserId, $userMessage, $meetingContext, $history);
        $companyBlock = $companyCtx['prefix'];
        $brandName = $companyCtx['brand_name'] !== '' ? $companyCtx['brand_name'] : null;

        $msg = $userMessage;
        if ($meetingContext !== '') {
            $msg = "Recent meeting context:\n" . $meetingContext . "\n\nUser said:\n" . $userMessage;
        }

        $prompt = '';
        foreach (self::buildGeminiContents($history, $msg, $companyBlock !== '' ? $companyBlock : null, $brandName) as $block) {
            $role = $block['role'] === 'model' ? 'Assistant' : 'User';
            $text = $block['parts'][0]['text'] ?? '';
            $prompt .= $role . ": " . $text . "\n\n";
        }

        try {
            return trim(callGeminiRobust($prompt, false, 'gemini-2.5-flash-lite'));
        } catch (Throwable $e) {
            error_log('[SlayMeetAgent] ' . $e->getMessage());

            return 'I am having trouble thinking right now — please try again in a moment.';
        }
    }

    private static function b64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $b64): ?string
    {
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
