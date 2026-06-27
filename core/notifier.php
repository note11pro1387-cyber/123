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
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId ?? TELEGRAM_ADMIN_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode !== 200 || $error) {
            error_log("Telegram CURL error: $error (HTTP $httpCode)");
            return false;
        }
        
        $response = json_decode($result, true);
        if (!($response['ok'] ?? false)) {
            error_log("Telegram API error: " . ($response['description'] ?? 'Unknown'));
            return false;
        }
        return true;
    }
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data),
            'timeout' => 15,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
    ];
    $result = @file_get_contents($url, false, stream_context_create($options));
    return $result !== false;
}

function sendVerificationToTelegram(string $telegramId, string $code, string $fullName, string $type = 'register'): bool {
    $typeText = $type === 'register' ? 'ثبت‌نام' : 'بازیابی رمز';
    
    $message = "🔐 *کد تایید $typeText در MR NEON*\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "👋 *سلام $fullName عزیز!*\n\n";
    $message .= "🔑 *کد تایید ۶ رقمی شما:*\n";
    $message .= "```\n$code\n```\n\n";
    $message .= "⏰ *تاریخ:* " . toPersianDateTime(date('Y-m-d H:i:s')) . "\n";
    $message .= "⏳ *اعتبار:* ۱۰ دقیقه\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "📱 *برای دریافت کد از ربات Notifier:*\n\n";
    $message .= "1️⃣ دکمه *Start* را در ربات بزنید\n";
    $message .= "2️⃣ کد تایید خود را از ربات دریافت کنید\n";
    $message .= "3️⃣ کد را در سایت وارد کنید\n\n";
    $message .= "🤖 [ربات Notifier](https://t.me/MR_NEON_Notifier_bot)\n";
    $message .= "━━━━━━━━━━━━━━━━━━\n";
    $message .= "_⚠️ این کد را با کسی به اشتراک نگذارید._";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '🤖 استارت ربات Notifier', 'url' => 'https://t.me/MR_NEON_Notifier_bot']
            ],
            [
                ['text' => '📱 باز کردن در اپ موبایل', 'url' => 'tg://resolve?domain=MR_NEON_Notifier_bot']
            ],
            [
                ['text' => '🌐 باز کردن در وب', 'url' => 'https://web.telegram.org/k/#@MR_NEON_Notifier_bot']
            ]
        ]
    ];
    
    return sendTelegramMessage($message, $keyboard);
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
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ تایید سریع', 'callback_data' => 'approve_' . $project['id']],
                ['text' => '❌ رد کردن', 'callback_data' => 'reject_' . $project['id']]
            ],
            [
                ['text' => '👁️ مشاهده جزئیات کامل', 'url' => SITE_URL . '/?admin_project=' . $project['id']]
            ]
        ]
    ];
    
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