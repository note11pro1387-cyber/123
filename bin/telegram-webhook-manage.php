<?php
declare(strict_types=1);

// This is an operator CLI, never an HTTP endpoint. Guard before loading config or bootstrap.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Explicit, manual-only Telegram webhook manager. Never accepts token/secret as CLI arguments. */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';

$command = (string) ($argv[1] ?? '');
if (!in_array($command, ['set', 'delete', 'info'], true)) {
    fwrite(STDERR, "Usage: php bin/telegram-webhook-manage.php <set|delete|info>\n");
    exit(64);
}
$client = new TelegramBotApiClient();
$botUsername = TelegramLinkConfiguration::botUsername();
if (!$client->isConfigured() || $botUsername === '') {
    fwrite(STDERR, "NOT CONFIGURED: set the private Telegram Bot token and bot username.\n");
    exit(78);
}
try {
    $me = $client->call('getMe', [], 10);
    if (!is_array($me) || ($me['is_bot'] ?? false) !== true || !is_string($me['username'] ?? null)
        || strcasecmp($me['username'], $botUsername) !== 0) {
        fwrite(STDERR, "CONFIGURATION MISMATCH: configured username does not match the Bot API identity.\n");
        exit(78);
    }
    if ($command === 'set') {
        $url = TelegramLinkConfiguration::publicWebhookUrl();
        $secret = TelegramLinkConfiguration::webhookSecret();
        if ($url === null || $secret === '') {
            fwrite(STDERR, "NOT CONFIGURED: a public HTTPS base URL and a 32+ character webhook secret are required.\n");
            exit(78);
        }
        $result = $client->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
            'max_connections' => 10,
        ], 12);
        if ($result !== true) throw new RuntimeException('TELEGRAM_WEBHOOK_SET_NOT_CONFIRMED');
        fwrite(STDOUT, "PASS: Telegram confirmed webhook registration. URL and secrets were not printed.\n");
        exit(0);
    }
    if ($command === 'delete') {
        $result = $client->call('deleteWebhook', ['drop_pending_updates' => false], 12);
        if ($result !== true) throw new RuntimeException('TELEGRAM_WEBHOOK_DELETE_NOT_CONFIRMED');
        fwrite(STDOUT, "PASS: Telegram confirmed webhook removal; pending updates were preserved.\n");
        exit(0);
    }
    $info = $client->call('getWebhookInfo', [], 10);
    if (!is_array($info)) throw new RuntimeException('TELEGRAM_WEBHOOK_INFO_INVALID');
    $expectedUrl = TelegramLinkConfiguration::publicWebhookUrl();
    $actualUrl = is_string($info['url'] ?? null) ? $info['url'] : '';
    fwrite(STDOUT, 'Webhook configured: ' . ($actualUrl !== '' ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, 'Matches configured endpoint: ' . ($expectedUrl !== null && hash_equals($expectedUrl, $actualUrl) ? 'yes' : 'no') . "\n");
    fwrite(STDOUT, 'Pending updates: ' . (is_int($info['pending_update_count'] ?? null) ? $info['pending_update_count'] : 'unknown') . "\n");
    // Do not print URL or last_error_message: provider diagnostics can disclose deployment details.
    exit($expectedUrl !== null && hash_equals($expectedUrl, $actualUrl) ? 0 : 1);
} catch (TelegramBotApiException $exception) {
    fwrite(STDERR, 'FAILED: ' . $exception->safeCode . "\n");
    exit($exception->outcomeUnknown ? 75 : 1);
} catch (Throwable $exception) {
    $safe = preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $exception->getMessage()) ? $exception->getMessage() : 'OPERATION_FAILED';
    fwrite(STDERR, 'FAILED: ' . $safe . "\n");
    exit(1);
}
