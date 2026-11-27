# MR NEON — بازطراحی احراز هویت (Change 3)

این بسته فایل‌های کامل و مسیرمحورِ تغییرکرده را برای جایگزینی در ریشه پروژه MR NEON در بر دارد.

## فایل‌های اصلی محصول

1. `components/auth-modals.html` — مودال ورود، ثبت‌نام دو مرحله‌ای، شرایط استفاده و بازیابی رمز با SVG و RTL.
2. `css/components.css` — استایل‌های مستقل Auth v2 در انتهای فایل؛ بلوک‌های قبلی حفظ شده‌اند.
3. `js/auth.js` — هماهنگی UI با جریان‌های واقعی Auth/Registration/Recovery، فوکوس، Wizard و کنترل نمایش فیلدها.
4. `js/registration-validation.js` — سیاست مشترک رابط برای رمز ۸ تا ۱۵ نویسه Unicode.
5. `app/validators/PasswordPolicy.php` — اعمال همان سیاست در سرور.

## فایل‌های هم‌راستاسازی

فایل‌های تست واحد/Integration، دو بررسی Static و دو سند قرارداد نیز به‌روزرسانی شده‌اند تا دیگر سیاست قدیمی ۱۵ نویسه حداقل را انتظار نداشته باشند و fixtureهای کاربر قدیمی بتوانند رمز legacy طولانی داشته باشند.

## نصب

1. از همهٔ ۱۴ فایل موجود در بسته نسخهٔ پشتیبان تهیه کنید.
2. ZIP را از **ریشه مخزن** استخراج و فایل‌ها را با مسیرهای داخل ZIP جایگزین کنید.
3. کش سایت/مرورگر را پاک کنید (`Ctrl + Shift + R`).
4. برای شروع ورود، دکمهٔ ورود فعلی هدر را استفاده کنید؛ رویداد آن در `js/app.js` حفظ شده و نیازی به تغییر `app.js` نیست.

## قراردادها و محدودیت‌های مهم

- هیچ endpoint، فیلد payload، نام action یا DTO ثبت‌نام تغییر نکرده است. Payload همان هفت فیلد `fullName`, `phone`, `email`, `username`, `password`, `selectedChannel`, `termsAccepted` را نگه می‌دارد.
- ترتیب ثبت‌نام همان ثبت موقت، اتصال امن Telegram در صورت نیاز، درخواست OTP و تأیید OTP است. ثبت‌نام موفق به معنی ورود خودکار نیست.
- بازیابی رمز از مسیر موجود استفاده می‌کند؛ Reset token همچنان فقط در حافظه نگه‌داری می‌شود و Reset به معنی ایجاد نشست ورود نیست.
- کانال SMS غیرفعال می‌ماند. شماره موبایل فقط با قالب محلی `09xxxxxxxxx` نمایش/دریافت می‌شود و بک‌اند آن را طبق قرارداد خودش canonical می‌کند.
- ایمیل پشتیبان در حالت Telegram تأییدنشده است؛ تا زمان تأیید مستقل، مسیر بازیابی محسوب نمی‌شود.
- سیاست رمز عبور برای ثبت‌نام و Reset: حداقل ۸ و حداکثر ۱۵ code point در Unicode، UTF-8 معتبر، حداکثر ۱۰۲۴ بایت و بدون کنترل‌کاراکتر ASCII. سیاست ورود به حساب‌های قبلی تغییر نکرده است.
- `MRNEON_TERMS_VERSION` و `RegistrationPolicy` عمداً تغییر نکرده‌اند. متن Terms داخل UI بازنویسی شده اما **پیش‌نویس محصولی است و باید توسط مالک/مشاور حقوقی تأیید شود**. تا زمانی که نسخهٔ مورد تأیید به‌صورت server-side تنظیم نشده، رفتار fail-closed فعلی بک‌اند باید حفظ شود؛ نسخهٔ Terms را خودکار تغییر ندهید.
- `index.php`, `js/app.js`, `global.css`, `.env`, endpointها، Migrationها و schema دیتابیس تغییر نکرده‌اند. هیچ dependency جدیدی اضافه نشده است.

## اعتبارسنجی اجراشده

- `node --check js/auth.js`
- `node --check js/registration-validation.js`
- `node --check tests/Unit/run-w4p6b-password-policy-tests.mjs`
- `php -l app/validators/PasswordPolicy.php`
- `php -l` روی فایل‌های تست PHP تغییرکرده
- `node tests/Unit/run-w4p6b-password-policy-tests.mjs` — ۱۶ assertion موفق
- `php tests/Unit/run-registration-tests.php` — ۶۲ assertion موفق
- `php tests/Unit/run-w4p5-unit.php` — ۲۴۷ assertion موفق

تست‌های DB/Provider/End-to-End کامل اجرا نشده‌اند؛ تست‌های Integration فقط از نظر Syntax کنترل شده‌اند. هیچ DB واقعی، ارسال Telegram/Email، Migration یا تغییر محیط استقرار اجرا نشده است.
