<?php
declare(strict_types=1);

final class SmtpVerificationTransport implements VerificationTransportInterface
{
    public function __construct(
        private readonly ?SmtpConfiguration $config = null,
        private readonly ?SmtpClientInterface $client = null
    ) {
    }

    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        if ($delivery->channel() !== 'email') return VerificationDeliveryResult::rejected('TRANSPORT_CHANNEL_MISMATCH');
        if (!$this->config instanceof SmtpConfiguration) return VerificationDeliveryResult::notConfigured();
        $destination = $delivery->destination();
        if (filter_var($destination, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\0]/', $destination)) {
            return VerificationDeliveryResult::rejected('SMTP_RECIPIENT_INVALID');
        }
        if (!in_array($delivery->purpose(), ['registration', 'password_reset'], true)) {
            return VerificationDeliveryResult::rejected('SMTP_PURPOSE_UNSUPPORTED');
        }

        $subject = $delivery->purpose() === 'registration' ? 'کد تأیید ثبت‌نام MR NEON' : 'کد تأیید MR NEON';
        $code = $delivery->code();
        $text = "کد تأیید شما: {$code}\nاین کد دقیقاً ۱۲۰ ثانیه پس از پذیرش ارسال معتبر است.\nکد را در اختیار دیگران قرار ندهید. اگر این درخواست را آغاز نکرده‌اید، پیام را نادیده بگیرید.";
        $escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body>'
            . '<main><h1>تأیید حساب MR NEON</h1><p>کد تأیید شما:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">'
            . $escapedCode . '</p><p>این کد دقیقاً ۱۲۰ ثانیه پس از پذیرش ارسال معتبر است.</p>'
            . '<p>کد را در اختیار دیگران قرار ندهید. اگر این درخواست را آغاز نکرده‌اید، این پیام را نادیده بگیرید.</p></main></body></html>';
        try {
            return ($this->client ?? new NativeSmtpClient())->send($this->config, $destination, $subject, $text, $html);
        } catch (Throwable $exception) {
            // An exception can happen after DATA was accepted by the server; never blindly retry.
            return VerificationDeliveryResult::unknown('SMTP_RESULT_UNKNOWN');
        }
    }
}
