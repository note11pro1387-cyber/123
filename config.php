<?php
/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: config.php
 * 🎯 Responsibility: ثوابت امنیتی، پیکربندی اولیه و تنظیمات محیط
 * 🔌 Dependencies: ندارد
 * 🔌 Dependants: api.php (و تمام فایل‌های core از طریق api.php)
 * 🚫 Constraints: فقط شامل define() و تنظیمات است. بدون هیچ منطق اجرایی (بدون توابع Handler).
 * 📦 Exported: تمام ثوابت تعریف‌شده (define) و تنظیمات ini/error
 * 🧠 AI NOTE: این فایل نباید هیچگاه فراخوانی مستقیم توابع پیچیده یا Handler داشته باشد. فقط ثوابت و مقادیر اولیه.
 */

date_default_timezone_set('Asia/Tehran');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/data/php_errors.log');

define('API_KEY', 'MR_13871390');
define('ADMIN_USERNAME', 'MR_NEON');
define('ADMIN_PASSWORD', 'MR_13871390');
define('DATA_DIR', __DIR__ . '/data');
define('RATE_LIMIT_FILE', DATA_DIR . '/rate_limits.json');
define('VERIFICATION_FILE', DATA_DIR . '/verification_codes.json');
define('SESSION_TIMEOUT', 86400);

define('TELEGRAM_BOT_TOKEN', '8882197997:AAHMWpllnj_E2pSDSK__KdPFjC45s1DOaAM');
define('TELEGRAM_ADMIN_CHAT_ID', '6001850394');
define('TELEGRAM_NOTIFIER_BOT', 'MR_NEON_Notifier_bot');
define('SITE_URL', (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}