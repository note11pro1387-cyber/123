<?php
/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: core/notifier.php
 * 🎯 Responsibility: ارسال نوتیفیکیشن‌های تلگرام (وب‌هوک تلگرام، کد تایید، اطلاع‌رسانی پروژه و کاربر جدید)
 * 🔌 Dependencies: config.php (برای ثوابت TELEGRAM_*)
 * 🔌 Dependants: core/auth-core.php (برای sendVerificationToTelegram), core/project-core.php (برای notifyNewProject)
 * 🚫 Constraints: منطق مربوط به احراز هویت یا پروژه را در این فایل پیاده‌سازی نکن. فقط ارسال پیام.
 * 📦 Exported: sendTelegramMessage, sendVerificationToTelegram, notifyNewProject, notifyNewUser
 * 🧠 AI NOTE: این فایل مستقیماً با API تلگرام ارتباط دارد. تغییر در ساختار پیام‌ها فقط در این فایل انجام شود.
 */

function sendTelegramMessage(string $text, ?array $keyboard = null, ?string $chatId = null): bool {
    $token = defined('TELEGRAM_BOT_TOKEN') ? trim((string) TELEGRAM_BOT_TOKEN) : '';
    $destination = trim((string) ($chatId ?? (defined('TELEGRAM_ADMIN_CHAT_ID') ? TELEGRAM_ADMIN_CHAT_ID : '')));
    if ($token === '' || $destination === '') {
        // Unconfigured provider: do not simulate acceptance or log credentials/payloads.
        error_log('Telegram delivery unavailable: server-side provider configuration is missing.');
        return false;
    }

    // Fixed HTTPS host; never construct this URL from request input.
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $data = [
        'chat_id' => $destination,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== null) {
        $encodedKeyboard = json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedKeyboard === false) {
            error_log('Telegram delivery unavailable: reply markup encoding failed.');
            return false;
        }
        $data['reply_markup'] = $encodedKeyboard;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            error_log('Telegram delivery unavailable: cURL initialization failed.');
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = ($result === false);
        curl_close($ch);
        if ($failed || $httpCode !== 200 || !is_string($result)) {
            // cURL's raw error can include URL fragments; keep logs deliberately generic.
            error_log('Telegram delivery failed at transport/HTTP layer.');
            return false;
        }
        $response = json_decode($result, true);
        if (!is_array($response) || ($response['ok'] ?? false) !== true) {
            error_log('Telegram delivery was not accepted by the provider.');
            return false;
        }
        return true;
    }

    // Stream fallback preserves certificate-chain and hostname verification.
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ];
    $result = @file_get_contents($url, false, stream_context_create($options));
    if (!is_string($result)) {
        error_log('Telegram delivery failed at stream transport layer.');
        return false;
    }
    $response = json_decode($result, true);
    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        error_log('Telegram delivery was not accepted by the provider.');
        return false;
    }
    return true;
}

function sendVerificationToTelegram(string $telegramId, string $code, string $fullName, string $type = 'register'): bool {
    // A public @username is not a verified Telegram destination. The former
    // implementation sent this OTP to the default admin chat. Fail closed until
    // Wave 4 implements one-time deep-link linking and a verified chat_id binding.
    error_log('Telegram verification delivery is disabled until verified account linking is implemented.');
    return false;
}

function notifyNewProject(array $project): void {
    $industryLabels = [
        'fashion' => '👗 مد و پوشاک', 'tech' => '💻 فناوری', 'food' => '🍕 رستوران',
        'health' => '🏥 پزشکی', 'education' => '🎓 آموزش', 'realestate' => '🏠 املاک',
        'travel' => '✈️ گردشگری', 'art' => '🎨 هنر', 'sport' => '⚽ ورزش', 'other' => '📦 سایر'
    ];
    
    $styleLabels = [
        'modern' => 'مدرن', 'classic' => 'کلاسیک', 'creative' => 'خلاقانه',
        'luxury' => 'لوکس', 'minimal' => 'مینیمال', 'cyberpunk' => 'سایبرپانک',
        'neumorphism' => 'نئومورفیسم', 'glassmorphism' => 'گلس‌مورفیسم', 'retro' => 'رترو',
        '3d' => 'سه‌بعدی', 'flat' => 'فلت', 'dark' => 'دارک', 'gradient' => 'گرادیانت'
    ];
    
    $timelineLabels = [
        'urgent' => '⚡ فوری (۷۲ ساعت)', 'normal' => '📅 عادی (۱-۲ هفته)',
        'flexible' => '📆 منعطف (۱ ماه)', 'agreement' => '🤝 توافقی'
    ];
    
    $featureLabels = [
        'payment' => '💳 پرداخت', 'membership' => '👤 عضویت', 'admin_panel' => '🎛️ پنل',
        'live_chat' => '💬 چت', 'booking' => '📅 رزرو', 'pwa' => '📱 PWA',
        'maps' => '🗺️ نقشه', 'gallery' => '🖼️ گالری', 'blog' => '📝 وبلاگ',
        'multilingual' => '🌍 چندزبانه', 'seo' => '📈 سئو', 'ai' => '🤖 AI',
        'cart' => '🛒 سبد خرید', 'dashboard' => '📊 داشبورد', 'video' => '🎥 ویدیو'
    ];
    
    $industry = $industryLabels[$project['industry']] ?? ($project['industry'] ?? '—');
    $style = $styleLabels[$project['style']] ?? ($project['style'] ?? '—');
    $timeline = $timelineLabels[$project['timeline']] ?? ($project['timeline'] ?? '—');
    $features = array_map(fn($f) => $featureLabels[$f] ?? $f, $project['features'] ?? []);
    $featureText = !empty($features) ? implode("\n  • ", $features) : '—';
    
    $message = "🚀 *درخواست جدید پروژه در MR NEON!*\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "👤 *مشتری:* " . ($project['fullName'] ?? '—') . "\n";
    $message .= "📱 *تماس:* " . ($project['phone'] ?? '—');
    if (!empty($project['email'])) {
        $message .= "\n📧 *ایمیل:* " . $project['email'];
    }
    if (!empty($project['messenger']) && !empty($project['messengerId'])) {
        $message .= "\n💬 *پیام‌رسان:* " . $project['messenger'] . " (" . $project['messengerId'] . ")";
    }
    $message .= "\n━━━━━━━━━━━━━━━━━━\n";
    $message .= "🎯 *صنعت:* $industry\n";
    if (!empty($project['purpose'])) {
        $message .= "🎯 *هدف:* " . $project['purpose'] . "\n";
    }
    $message .= "🎨 *سبک:* $style\n";
    $message .= "⏰ *زمان:* $timeline\n";
    $message .= "🔧 *امکانات:*\n  • $featureText\n";
    if (!empty($project['userNote'])) {
        $message .= "📝 *یادداشت:* " . substr($project['userNote'], 0, 300) . "\n";
    }
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "🔖 *کد پیگیری:* `" . $project['trackingCode'] . "`\n";
    $message .= "📅 *تاریخ:* " . toPersianDateTime($project['createdAt']) . "\n";
    $message .= "👤 *کاربر:* " . ($project['userId'] ? '✅ ثبت‌نام‌شده' : '👻 مهمان') . "\n";
    $message .= "━━━━━━━━━━━━━━━━━━";
    
    // Callback actions have no authenticated receiver in the current snapshot.
    // Do not expose dead quick-action controls; add a link only for an explicit base URL.
    $keyboard = ['inline_keyboard' => []];
    if (PUBLIC_BASE_URL !== '') {
        $keyboard['inline_keyboard'][] = [
            ['text' => '👁️ مشاهده جزئیات کامل', 'url' => PUBLIC_BASE_URL . '/?admin_project=' . rawurlencode((string) ($project['id'] ?? ''))]
        ];
    }
    if (empty($keyboard['inline_keyboard'])) {
        $keyboard = null;
    }

    sendTelegramMessage($message, $keyboard);
}

function notifyNewUser(string $fullName, string $username, string $phone, ?string $email, ?string $telegramId): void {
    $message = "✅ *کاربر جدید ثبت‌نام کرد*\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "👤 *نام:* $fullName\n";
    $message .= "🆔 *نام کاربری:* @$username\n";
    $message .= "📱 *تلفن:* $phone\n";
    if ($email) $message .= "📧 *ایمیل:* $email\n";
    if ($telegramId) $message .= "💬 *تلگرام:* $telegramId\n";
    $message .= "📅 *تاریخ:* " . toPersianDateTime(date('Y-m-d H:i:s')) . "\n";
    $message .= "━━━━━━━━━━━━━━━━━━";
    sendTelegramMessage($message);
}