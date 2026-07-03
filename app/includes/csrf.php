<?php
if (
    session_status() === PHP_SESSION_NONE
    && !(defined('SLAYLY_SKIP_SESSION') && SLAYLY_SKIP_SESSION)
) {
    session_start();
}

/**
 * Generate CSRF Token
 * Returns the token string to be used in forms
 */
function generateCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 * Returns true if valid, throws Exception if invalid
 */
function verifyCsrfToken($token = null)
{
    // If no token passed, try to auto-detect from all possible sources
    if ($token === null || $token === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        // Normalize headers to lowercase for reliable lookup
        $normHeaders = [];
        foreach ($headers as $key => $value) {
            $normHeaders[strtolower($key)] = $value;
        }

        $token = $normHeaders['x-csrf-token'] ??
            $_SERVER['HTTP_X_CSRF_TOKEN'] ??
            $_POST['csrf_token'] ??
            $_GET['csrf_token'] ??
            null;

        // Try JSON body if still not found
        if (!$token && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
            $jsonInput = json_decode(file_get_contents('php://input'), true);
            $token = $jsonInput['csrf_token'] ?? null;
        }
    }

    if (!isset($_SESSION['csrf_token']) || !$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        throw new Exception("CSRF Validation Failed. Please refresh the page and try again.");
    }
    return true;
}

/**
 * Read X-CSRF-Token from request headers (case-insensitive).
 */
function slayly_csrf_header_token(): string
{
    if (!function_exists('getallheaders')) {
        return '';
    }
    foreach (getallheaders() as $key => $value) {
        if (strtolower((string) $key) === 'x-csrf-token') {
            return trim((string) $value);
        }
    }
    return '';
}

/**
 * Resolve token from header first, then optional decoded JSON body key csrf_token.
 */
function slayly_csrf_resolve_token(?array $jsonBody): string
{
    $h = slayly_csrf_header_token();
    if ($h !== '') {
        return $h;
    }
    if (is_array($jsonBody) && isset($jsonBody['csrf_token'])) {
        return trim((string) $jsonBody['csrf_token']);
    }
    if (is_array($jsonBody) && isset($jsonBody['csrf'])) {
        return trim((string) $jsonBody['csrf']);
    }
    return '';
}

/**
 * Verify CSRF when php://input was already read (JSON endpoints). Does not read the body again.
 */
function verifyCsrfTokenValue(string $token): void
{
    $t = trim($token);
    if (!isset($_SESSION['csrf_token']) || $t === '' || !hash_equals($_SESSION['csrf_token'], $t)) {
        throw new Exception("CSRF Validation Failed. Please refresh the page and try again.");
    }
}
?>
