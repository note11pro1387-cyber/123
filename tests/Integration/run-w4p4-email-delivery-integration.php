<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$recipient = getenv('MRNEON_TEST_EMAIL_TO');
if (getenv('MRNEON_ALLOW_LIVE_EMAIL_TEST') !== '1' || !is_string($recipient)
    || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\0]/', $recipient)) {
    fwrite(STDERR, "NOT RUN: set MRNEON_ALLOW_LIVE_EMAIL_TEST=1 and MRNEON_TEST_EMAIL_TO to an owner-controlled test inbox. No email was sent.\n");
    exit(77);
}
$config = SmtpConfiguration::fromEnvironment();
if (!$config instanceof SmtpConfiguration) {
    fwrite(STDERR, "NOT RUN: valid SMTP environment configuration is not available. No email was sent.\n");
    exit(77);
}

try {
    // This synthetic delivery is deliberately not connected to any real registration Attempt or Challenge.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $delivery = new VerificationDelivery(bin2hex(random_bytes(16)), 'registration', 'email', $recipient, $code);
    $result = (new SmtpVerificationTransport($config, new NativeSmtpClient()))->deliver($delivery);
    if ($result->status === VerificationDeliveryResult::ACCEPTED) {
        fwrite(STDOUT, "PASS: SMTP server accepted the synthetic test message; inbox delivery/read is not verified.\n");
        exit(0);
    }
    if ($result->status === VerificationDeliveryResult::UNKNOWN) {
        fwrite(STDERR, 'UNKNOWN: SMTP final delivery result is ambiguous (' . $result->safeCode . '); do not blindly retry.' . "\n");
        exit(75);
    }
    if ($result->status === VerificationDeliveryResult::NOT_CONFIGURED) {
        fwrite(STDERR, "NOT RUN: SMTP adapter is not configured; no email was sent.\n");
        exit(77);
    }
    fwrite(STDERR, 'FAIL: SMTP adapter rejected the test (' . $result->safeCode . ').' . "\n");
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: test runner aborted (' . get_class($exception) . '). No raw exception details were emitted.' . "\n");
    exit(1);
}
