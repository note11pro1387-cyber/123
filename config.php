<?php
/**
 * MR NEON configuration.
 * All credentials must be supplied by the server environment.
 */
date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('DATA_DIR', __DIR__ . '/data');
define('RATE_LIMIT_FILE', DATA_DIR . '/rate_limits.json');
define('VERIFICATION_FILE', DATA_DIR . '/verification_codes.json');
define('SESSION_TIMEOUT', 86400);
define('ADMIN_USERNAME', getenv('MR_NEON_ADMIN_USERNAME') ?: '');
define('ADMIN_PASSWORD', getenv('MR_NEON_ADMIN_PASSWORD') ?: '');
define('TELEGRAM_BOT_TOKEN', getenv('MR_NEON_TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_ADMIN_CHAT_ID', getenv('MR_NEON_TELEGRAM_ADMIN_CHAT_ID') ?: '');
define('TELEGRAM_NOTIFIER_BOT', 'MR_NEON_Notifier_bot');
define('MR_NEON_ALLOWED_ORIGINS', getenv('MR_NEON_ALLOWED_ORIGINS') ?: '');
define('MR_NEON_MAIL_FROM', getenv('MR_NEON_MAIL_FROM') ?: '');
define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0700, true) && !is_dir(DATA_DIR)) {
    error_log('MR NEON data directory could not be created.');
}
