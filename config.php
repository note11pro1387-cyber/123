<?php
/**
 * MR NEON runtime configuration.
 * Secrets are read from the process/Apache environment only.
 * Do not put credentials in this file, public assets, or the repository.
 */

date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/** Return a trimmed environment value, or an empty string when unset. */
function mrneonEnv(string $name): string
{
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
}

require_once __DIR__ . '/app/support/PublicBaseUrl.php';

/** Validate the explicitly configured public URL; never infer it from HTTP_HOST. */
function mrneonPublicBaseUrl(): string
{
    return mrneonNormalizePublicBaseUrl(mrneonEnv('MRNEON_PUBLIC_BASE_URL'));
}

define('DATA_DIR', __DIR__ . '/data');
define('RATE_LIMIT_FILE', DATA_DIR . '/rate_limits.json');
define('VERIFICATION_FILE', DATA_DIR . '/verification_codes.json');
define('SESSION_TIMEOUT', 86400);

define('TELEGRAM_BOT_TOKEN', mrneonEnv('MRNEON_TELEGRAM_BOT_TOKEN'));
define('TELEGRAM_ADMIN_CHAT_ID', mrneonEnv('MRNEON_TELEGRAM_ADMIN_CHAT_ID'));
define('TELEGRAM_NOTIFIER_BOT', mrneonEnv('MRNEON_TELEGRAM_NOTIFIER_BOT'));
define('PUBLIC_BASE_URL', mrneonPublicBaseUrl());
// Kept as a compatibility alias for existing internal callers; it is empty until configured.
define('SITE_URL', PUBLIC_BASE_URL);

// Keep the existing data directory and its contents. Do not seed or reset runtime files here.
if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
    error_log('MR NEON runtime data directory could not be created.');
}
