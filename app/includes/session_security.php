<?php

/**
 * Scoped guest sessions: strip guest identity outside meet routes.
 */
function slaymeet_is_meet_route(): bool
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return str_contains($uri, '/meet')
        || str_contains($uri, '/api/slaymeet/');
}

function slaymeet_enforce_guest_session_scope(): void
{
    if (empty($_SESSION['slaymeet_guest_session'])) {
        return;
    }
    if (slaymeet_is_meet_route()) {
        return;
    }
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['company_id'],
        $_SESSION['role'],
        $_SESSION['slaymeet_guest_session']
    );
}

slaymeet_enforce_guest_session_scope();
