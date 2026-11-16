# MR NEON — Telegram Integration (W4-P4)

## Scope and state

This patch supplies a Telegram Bot API client, a `VerificationTransportInterface` adapter, private-chat `/start` linking against the existing `registration_attempts.telegram_link_nonce_hash`, a webhook controller, database-backed update idempotency, and a manual-only webhook manager. No Bot Token, webhook secret, or real destination is included. Provider calls were not made during development.

## Link flow

1. `POST ?action=register` creates the W4-P2 Attempt and stores only the SHA-256 hash of its 256-bit linking nonce.
2. The controller removes `telegramLinkNonce` from the API response. It returns `telegramLinkUrl` only if bot username/token syntax, webhook secret, and a public HTTPS base URL are configured. The nonce is only embedded in Telegram's official `https://t.me/<bot>?start=<parameter>` deep link; the parameter is 64 hex characters, within Telegram's 64-character start-parameter limit.
3. The user opens the bot in a private chat and presses Start. Only a Telegram Update delivered to the configured webhook and authenticated by Telegram's configured secret header can consume the nonce.
4. `TelegramLinkWebhookService` reserves `(bot, update_id)` in the existing `idempotency_keys` table in the same transaction as the Attempt update. Repeated same-body Update IDs are acknowledged without mutation; changed payloads with a previously used Update ID are never applied.
5. The database update matches the nonce hash and clears `telegram_link_nonce_hash` atomically. The linked Telegram user/chat IDs are taken only from the validated private-chat Update, never from browser input.
6. The browser can call `POST ?action=telegramLinkStatus` to retrieve only a boolean and can request a verification code only after the status endpoint confirms linking. W4-P3 still validates the Attempt/destination and only its Challenge service controls OTP state.

A linked Telegram destination is not an OTP verification and does not create a user/session. Linking and OTP verification remain independent proofs.

## Runtime variables

- `MRNEON_TELEGRAM_BOT_TOKEN`: private token from BotFather; rotate any previously exposed token.
- `MRNEON_TELEGRAM_BOT_USERNAME`: username of the same Bot, without `@`, ending in `bot`.
- `MRNEON_TELEGRAM_WEBHOOK_SECRET`: new private secret with at least 32 characters, only `[A-Za-z0-9_-]`.
- `MRNEON_PUBLIC_BASE_URL`: public HTTPS deployment base URL; no query/fragment/credentials.

Do not place credentials in source, query strings, browser storage, or logs. The application uses the existing `MRNEON_TELEGRAM_BOT_TOKEN` name; the notifier's administrator destination is not consulted by the OTP adapter.

## Provider semantics

A valid Bot API `sendMessage` response with `ok: true`, a numeric `message_id`, and a matching `result.chat.id` is `accepted`. It means Telegram accepted the Bot API request—not that a user read the message. A provider-declared rejection is `rejected`; connection/response uncertainty is `unknown`; absent or invalid configuration is `not_configured`. Unknown is never retried automatically.

## Limitations

- Bot identity and webhook registration are not live-verified until the manual CLI operation is run.
- Webhook ingress requires a public HTTPS endpoint. `localhost`, local IP addresses and plain HTTP do not create an operational deep link.
- No Bot API request is issued automatically during application bootstrap.

## Immutable link binding

The one-time nonce is accepted only while the Attempt has no linked Telegram identity. The compare-and-consume update also requires all linked identity fields to be NULL. Once linked, an idempotent registration retry reports the existing link state and does not issue a new link token. Changing Telegram destination requires a separate owner-approved new registration flow; a repeated `/start` cannot replace an already-linked identity.

## Official protocol references

- Telegram Bot API HTTPS request/response contract and `Update.update_id`: https://core.telegram.org/bots/api
- Telegram bot deep-link parameter rules and private-chat `/start <parameter>` behavior: https://core.telegram.org/bots/features

The deep-link token is opaque, random, single-use and short-lived; `update_id` is only a Telegram-delivery idempotency key and never proves a user identity on its own.
