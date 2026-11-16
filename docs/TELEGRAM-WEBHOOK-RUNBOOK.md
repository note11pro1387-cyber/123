# Telegram Webhook Operations Runbook

## Preconditions

1. Configure the Bot Token privately in the PHP/Apache process environment. Never put it in the command arguments.
2. Set `MRNEON_TELEGRAM_BOT_USERNAME` to the matching BotFather username, without `@`.
3. Generate a new, independent, high-entropy `MRNEON_TELEGRAM_WEBHOOK_SECRET` with at least 32 allowed ASCII characters.
4. Set `MRNEON_PUBLIC_BASE_URL` to the deployed HTTPS URL. The resulting endpoint is `/api.php?action=telegramWebhook` under that base path.
5. Ensure PHP can establish HTTPS with certificate/hostname verification and that the server database has migrations through 018.

The web application checks `X-Telegram-Bot-Api-Secret-Token` using constant-time comparison, bounds the JSON body to 64 KiB, accepts only private `/start <nonce>` linking messages, and stores Update idempotency with the existing `idempotency_keys` table. Non-matching or irrelevant updates are acknowledged as ignored to avoid provider retry loops; transient database failure returns HTTP 503 so Telegram can retry safely.

## Manual commands

Run from the project root, with environment variables already set in the PHP CLI process:

```powershell
php bin/telegram-webhook-manage.php info
php bin/telegram-webhook-manage.php set
# Later, only for planned maintenance:
php bin/telegram-webhook-manage.php delete
```

The CLI verifies `getMe` against the configured username first. `set` uses `allowed_updates=["message"]` and preserves pending updates; `delete` also preserves pending updates. No command argument accepts a token/secret, and the command does not print webhook URLs or provider error descriptions. Webhook registration is always manual; it is never triggered by the web bootstrap.

## Local development

A local XAMPP endpoint is not reachable by Telegram merely because it works in a browser. Do not register a localhost webhook. Use a controlled public HTTPS tunnel only if the owner approves it and its URL is explicitly configured; otherwise keep linking unavailable and use local fixtures.

## Troubleshooting

- `NOT CONFIGURED`: verify private env variables are present, the secret meets the syntax/length rule, and the base URL is public HTTPS.
- `CONFIGURATION MISMATCH`: the supplied username does not match Telegram `getMe`; no webhook change is made.
- `TELEGRAM_AUTH_REJECTED` / `TELEGRAM_ACCESS_REJECTED`: rotate/recheck token permissions in the owner console; never include the token in a bug report.
- HTTP 503 from webhook: likely transient database/storage failure; inspect sanitized application logs and check idempotency/schema state before retrying manually.
- Do not paste Bot Token, webhook secret, complete Update payload, Telegram chat IDs or OTP into tickets/logs.
