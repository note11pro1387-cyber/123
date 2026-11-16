#!/usr/bin/env python3
"""Focused static acceptance checks for the W4-P4 patch; not a substitute for provider/DB tests."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
checks = []

def require(condition: bool, label: str) -> None:
    checks.append((label, bool(condition)))


def read(path: str) -> str:
    file = ROOT / path
    return file.read_text(encoding='utf-8') if file.is_file() else ''

bot = read('app/integrations/Telegram/TelegramBotApiClient.php')
https = read('app/integrations/Http/NativeHttpsJsonClient.php')
parser = read('app/integrations/Telegram/TelegramUpdateParser.php')
link = read('app/integrations/Telegram/TelegramLinkWebhookService.php')
webhook = read('app/http/controllers/TelegramWebhookController.php')
email = read('app/integrations/Email/NativeSmtpClient.php')
email_config = read('app/integrations/Email/SmtpConfiguration.php')
email_transport = read('app/integrations/Email/SmtpVerificationTransport.php')
factory = read('app/services/VerificationTransportFactory.php')
register_controller = read('app/http/controllers/RegistrationController.php')
verification_controller = read('app/http/controllers/VerificationController.php')
repo = read('app/repositories/RegistrationAttemptRepository.php')
api = read('api.php')
example = read('.env.example')

require(bool(bot and https and parser and link and webhook and email and email_config and email_transport and factory), 'all required adapter/link/webhook files exist')
require("https://api.telegram.org" in bot and "sendMessage" in bot, 'Telegram API host/method are fixed to Bot API')
require("$httpStatus === 408 || $httpStatus >= 500" in bot and "TELEGRAM_PROVIDER_RESULT_UNKNOWN" in bot, 'Telegram 408 and server errors remain ambiguous to prevent blind duplicate delivery')
require("verify_peer" in https and "verify_peer_name" in https and "follow_location" in https, 'native HTTPS enforces certificate/hostname verification and disables redirects')
require('HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' in webhook and 'hash_equals(' in webhook, 'webhook requires Telegram secret header with constant-time comparison')
require("!== 'private'" in parser and r'\/start' in parser and '^[a-f0-9]{64}$' in parser, 'link parser accepts strict one-time nonce only in private /start updates')
require("$candidate['token']" in link and 'hash(\'sha256\'' in link and 'findTelegramAttemptByNonceHashForUpdate' in link, 'webhook binds a hashed token to a locked open Attempt')
require("reserve('telegram_update'" in link and 'duplicate_mismatch' in link and 'beginTransaction' in link, 'webhook idempotency and transaction are enforced')
require('telegram_link_nonce_hash = NULL' in repo and "selected_channel = 'telegram'" in repo, 'Telegram link is compare-and-consume and channel-bound')
require('linked_telegram_user_id IS NULL' in repo and 'linked_telegram_chat_id IS NULL' in repo and 'telegram_linked_at IS NULL' in repo, 'once linked, the Telegram identity cannot be overwritten by a fresh nonce')
registration_service = read('app/services/RegistrationService.php')
require('$telegramLinked' in registration_service and 'Do not issue a new link token' in registration_service and "'telegramLinked' =>" in registration_service, 'idempotent registration retry reuses a proven Telegram binding without issuing a new link token')
require('linked_telegram_chat_id' in repo and 'MRNEON_TELEGRAM_ADMIN_CHAT_ID' not in link + bot, 'user OTP destination derives from registration Attempt, not admin destination')
require('MRNEON_EMAIL_PASSWORD' in email_config and "'starttls'" in email_config and "'smtps'" in email_config, 'SMTP config reads secret from environment and permits TLS-only modes')
require('verify_peer' in email and 'verify_peer_name' in email and 'enable_crypto' in email.lower(), 'SMTP TLS always verifies certificate and hostname')
require('AUTH LOGIN' in email and 'STARTTLS' in email and 'SMTP_RESULT_UNKNOWN' in email, 'SMTP performs authenticated TLS protocol and has ambiguous outcome handling')
require('VerificationDeliveryResult::accepted()' not in email_transport and 'VerificationDeliveryResult::notConfigured()' in email_transport, 'Email transport relies on explicit SMTP acceptance and not-configured behavior')
require('VerificationTransportInterface' in factory and 'TelegramVerificationTransport' in factory and 'SmtpVerificationTransport' in factory, 'factory composes actual transports behind existing interface')
require('telegramLinkNonce' in register_controller and "unset($result['telegramLinkNonce'])" in register_controller, 'raw Telegram link nonce is removed from public registration response')
require('VerificationTransportFactory::fromEnvironment()' in verification_controller, 'existing VerificationService is wired to environment-configured transport')
require("'telegramWebhook'" in api and "'telegramLinkStatus'" in api, 'webhook/link-status routes are connected to existing API entry point')
require('MRNEON_TELEGRAM_WEBHOOK_SECRET=' in example and 'MRNEON_TELEGRAM_BOT_TOKEN=' in example, 'environment template contains only blank placeholders for Telegram secrets')
require('MRNEON_EMAIL_PASSWORD=' in example and 'MRNEON_EMAIL_TLS_CA_FILE=' in example, 'environment template documents SMTP secret and optional CA path')
secret_lines = re.findall(r'(?m)^(MRNEON_TELEGRAM_BOT_TOKEN|MRNEON_TELEGRAM_WEBHOOK_SECRET|MRNEON_EMAIL_PASSWORD)=(.*)$', example)
require(len(secret_lines) == 3 and all(value == '' for _, value in secret_lines), 'environment example contains no populated provider secrets')
require('DROP DATABASE' not in link.upper() and 'DROP TABLE' not in link.upper(), 'link service contains no destructive DDL')
require('W4-P4' in read('W4-P5-HANDOFF.md') and 'W4-P5' in read('W4-P5-HANDOFF.md'), 'handoff exists and keeps Recovery outside current scope')
require((ROOT / 'docs/TELEGRAM-INTEGRATION.md').is_file() and (ROOT / 'docs/TELEGRAM-WEBHOOK-RUNBOOK.md').is_file(), 'Telegram operational docs exist')
require((ROOT / 'docs/EMAIL-INTEGRATION.md').is_file() and (ROOT / 'docs/VERIFICATION-TRANSPORT-CONTRACT.md').is_file(), 'Email and transport contract docs exist')
require((ROOT / 'tests/Integration/run-w4p4-telegram-link-integration.php').is_file(), 'guarded synthetic DB integration runner exists')
require((ROOT / 'tests/Integration/run-w4p4-email-delivery-integration.php').is_file() and 'MRNEON_ALLOW_LIVE_EMAIL_TEST' in read('tests/Integration/run-w4p4-email-delivery-integration.php'), 'real SMTP integration runner requires explicit opt-in and owner-controlled test inbox')
require('MRNEON_ALLOW_W4P4_TEST_MUTATION' in read('tests/Integration/run-w4p4-telegram-link-integration.php'), 'DB integration requires explicit test-mutation opt-in')
require("SELECT DATABASE()" in read('tests/Integration/run-w4p4-telegram-link-integration.php') and "'018'" in read('tests/Integration/run-w4p4-telegram-link-integration.php'), 'DB integration confirms database identity and checked schema version')
require('Mailer' not in factory or 'SmtpVerificationTransport' in factory, 'transport factory has no silent mock/provider fallback')

failures = [label for label, passed in checks if not passed]
for index, (label, passed) in enumerate(checks, 1):
    print(f"{'PASS' if passed else 'FAIL'} W4-P4 static #{index}: {label}")
print(f"W4-P4 Static Checks: {len(checks) - len(failures)}/{len(checks)} passed")
if failures:
    sys.exit(1)
