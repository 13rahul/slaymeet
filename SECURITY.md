# Security Policy

## Reporting

Open a private security advisory on GitHub or email the maintainer. Do not post exploit details in public issues.

## Sensitive configuration

- `SLAYMEET_BOT_SECRET` — signs in-room AI bot tokens; must be long and random
- `GEMINI_API_KEY` — never commit to git
- `LIVEKIT_API_SECRET` — protects media room access
- Default admin password — change immediately after install

## CSRF

State-changing API routes require `X-CSRF-Token` header or `csrf_token` in JSON body when using session auth.

## Scope

This OSS extract is single-tenant v1. Run behind HTTPS in production; configure TURN if users are behind strict corporate firewalls.
