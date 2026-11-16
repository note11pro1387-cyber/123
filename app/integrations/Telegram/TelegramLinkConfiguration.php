<?php
declare(strict_types=1);

/** Produces only a bot deep link when all deployment-side linking prerequisites are present. */
final class TelegramLinkConfiguration
{
    public static function deepLinkForNonce(string $nonce): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce)) return null;
        $bot = self::botUsername();
        if ($bot === '' || !(new TelegramBotApiClient())->isConfigured() || self::webhookSecret() === '' || self::publicWebhookUrl() === null) return null;
        return 'https://t.me/' . $bot . '?start=' . $nonce;
    }

    public static function botUsername(): string
    {
        $value = trim((string) (getenv('MRNEON_TELEGRAM_BOT_USERNAME') ?: ''));
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/D', $value) || !preg_match('/bot$/i', $value)) return '';
        return $value;
    }

    public static function webhookSecret(): string
    {
        $value = (string) (getenv('MRNEON_TELEGRAM_WEBHOOK_SECRET') ?: '');
        if (strlen($value) < 32 || strlen($value) > 256 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return '';
        return $value;
    }

    public static function publicWebhookUrl(): ?string
    {
        $base = defined('PUBLIC_BASE_URL') ? (string) PUBLIC_BASE_URL : '';
        $parts = parse_url($base);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) return null;
        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP) !== false) return null;
        $secret = self::webhookSecret();
        $bot = self::botUsername();
        if ($secret === '' || $bot === '' || !(new TelegramBotApiClient())->isConfigured()) return null;
        return rtrim($base, '/') . '/api.php?action=telegramWebhook';
    }
}
