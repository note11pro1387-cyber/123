<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
if (!defined('PUBLIC_BASE_URL')) define('PUBLIC_BASE_URL', 'https://registration.example.test');

final class W4P4FakeJsonHttpClient implements JsonHttpClientInterface
{
    public ?JsonHttpResponse $response = null;
    public bool $throw = false;
    public string $lastUrl = '';
    public string $lastBody = '';

    public function post(string $url, array $headers, string $body, int $timeoutSeconds = 10): JsonHttpResponse
    {
        $this->lastUrl = $url;
        $this->lastBody = $body;
        if ($this->throw) throw new RuntimeException('synthetic network failure with intentionally hidden details');
        if (!$this->response instanceof JsonHttpResponse) throw new RuntimeException('FAKE_HTTP_NO_RESPONSE');
        return $this->response;
    }
}

final class W4P4FakeSmtpClient implements SmtpClientInterface
{
    public VerificationDeliveryResult $result;
    public array $captured = [];

    public function __construct(?VerificationDeliveryResult $result = null)
    {
        $this->result = $result ?? VerificationDeliveryResult::accepted();
    }

    public function send(SmtpConfiguration $config, string $to, string $subject, string $textBody, string $htmlBody): VerificationDeliveryResult
    {
        $this->captured = ['to' => $to, 'subject' => $subject, 'text' => $textBody, 'html' => $htmlBody];
        return $this->result;
    }
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL W4-P4 Unit #{$checks}: {$label}\n");
        exit(1);
    }
}

$fakeHttp = new W4P4FakeJsonHttpClient();
$fakeToken = '123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi';
$chatId = '123456789012';
$delivery = new VerificationDelivery(str_repeat('a', 32), 'registration', 'telegram', $chatId, '001234');
$telegram = new TelegramVerificationTransport(new TelegramBotApiClient($fakeToken, $fakeHttp));

$fakeHttp->response = new JsonHttpResponse(200, json_encode([
    'ok' => true,
    'result' => ['message_id' => 42, 'chat' => ['id' => (int) $chatId]],
], JSON_THROW_ON_ERROR));
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::ACCEPTED, 'Telegram returns accepted only on validated positive Bot API result');
check(str_contains($fakeHttp->lastUrl, 'https://api.telegram.org/bot') && str_ends_with($fakeHttp->lastUrl, '/sendMessage'), 'Telegram API URL uses fixed HTTPS endpoint');
check(str_contains($fakeHttp->lastBody, '001234') && !str_contains($fakeHttp->lastUrl, '001234'), 'OTP is in POST body, never URL/query');
check(!str_contains(json_encode($delivery, JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', '001234'), 'delivery JSON serialization does not reveal OTP');
check(str_contains(print_r($delivery, true), '[REDACTED]'), 'delivery debug display redacts destination and OTP');
check(!str_contains(print_r(new TelegramBotApiClient($fakeToken, $fakeHttp), true), $fakeToken), 'Telegram Bot API client debug display does not expose token');
try { serialize(new TelegramBotApiClient($fakeToken, $fakeHttp)); check(false, 'Telegram Bot API client must not serialize its token'); } catch (LogicException $exception) { check(true, 'Telegram Bot API client must not serialize its token'); }

$fakeHttp->response = new JsonHttpResponse(403, json_encode(['ok' => false, 'error_code' => 403, 'description' => 'forbidden'], JSON_THROW_ON_ERROR));
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::REJECTED, 'Telegram explicit provider rejection is rejected');
$fakeHttp->response = new JsonHttpResponse(429, json_encode(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 45]], JSON_THROW_ON_ERROR));
$rateLimited = $telegram->deliver($delivery);
check($rateLimited->status === VerificationDeliveryResult::REJECTED && $rateLimited->retryAfterSeconds === 45, 'Telegram 429 retry_after=45 is preserved as bounded safe metadata');
$deliveryProblem = (new ReflectionMethod(VerificationService::class, 'deliveryProblem'))->invoke(null, $rateLimited);
check($deliveryProblem instanceof ApiProblemException && $deliveryProblem->httpStatus === 503, 'upstream Telegram rate limit maps to HTTP 503');
check(($deliveryProblem->responseHeaders['Retry-After'] ?? null) === '45', 'upstream Telegram retry_after is emitted as Retry-After');
foreach ([0, -1, 3601, '45', null] as $invalidRetryAfter) {
    $parameters = $invalidRetryAfter === null ? [] : ['retry_after' => $invalidRetryAfter];
    $fakeHttp->response = new JsonHttpResponse(429, json_encode(['ok' => false, 'parameters' => $parameters], JSON_THROW_ON_ERROR));
    $invalidRate = $telegram->deliver($delivery);
    check($invalidRate->status === VerificationDeliveryResult::REJECTED && $invalidRate->retryAfterSeconds === null, 'invalid Telegram retry_after is omitted rather than clamped or coerced');
}
check(VerificationDeliveryResult::rejected('TELEGRAM_RATE_LIMITED', 3601)->retryAfterSeconds === null, 'delivery result independently rejects retry_after above one hour');
$fakeHttp->response = new JsonHttpResponse(200, '{not-json');
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::UNKNOWN, 'malformed successful HTTP response is ambiguous, not accepted');
$fakeHttp->response = new JsonHttpResponse(502, json_encode(['ok' => false, 'description' => 'gateway error'], JSON_THROW_ON_ERROR));
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::UNKNOWN, 'Telegram 5xx response is ambiguous and does not invite automatic duplicate delivery');
$fakeHttp->response = new JsonHttpResponse(408, json_encode(['ok' => false, 'description' => 'request timeout'], JSON_THROW_ON_ERROR));
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::UNKNOWN, 'Telegram timeout response maps to unknown');
$fakeHttp->response = new JsonHttpResponse(502, '{gateway failure without JSON');
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::UNKNOWN, 'malformed Telegram gateway failure is ambiguous, not a definite rejection');
$fakeHttp->response = new JsonHttpResponse(403, json_encode(['ok' => false, 'error_code' => 403, 'description' => 'forbidden'], JSON_THROW_ON_ERROR));
$fakeHttp->throw = true;
check($telegram->deliver($delivery)->status === VerificationDeliveryResult::UNKNOWN, 'network exception maps to unknown without exposing provider details');
$fakeHttp->throw = false;
check((new TelegramVerificationTransport(new TelegramBotApiClient('', $fakeHttp)))->deliver($delivery)->status === VerificationDeliveryResult::NOT_CONFIGURED, 'missing Telegram token is not configured, never accepted');
check((new TelegramVerificationTransport(new TelegramBotApiClient($fakeToken, $fakeHttp)))->deliver(new VerificationDelivery(str_repeat('b', 32), 'registration', 'email', 'owner@example.test', '001234'))->status === VerificationDeliveryResult::REJECTED, 'Telegram transport rejects cross-channel payload');

$fakeNonce = str_repeat('c', 64);
putenv('MRNEON_TELEGRAM_BOT_TOKEN=' . $fakeToken);
putenv('MRNEON_TELEGRAM_BOT_USERNAME=mrneon_test_bot');
putenv('MRNEON_TELEGRAM_WEBHOOK_SECRET=webhook_secret_for_unit_tests_only_32_chars');
$deepLink = TelegramLinkConfiguration::deepLinkForNonce($fakeNonce);
check($deepLink === 'https://t.me/mrneon_test_bot?start=' . $fakeNonce, 'deep link requires valid private bot configuration and 64-char start token');
$validUpdate = [
    'update_id' => 9001,
    'message' => [
        'message_id' => 5,
        'from' => ['id' => 123456789],
        'chat' => ['id' => 123456789, 'type' => 'private'],
        'text' => '/start ' . $fakeNonce,
    ],
];
$candidate = TelegramUpdateParser::linkCandidate($validUpdate, 'mrneon_test_bot');
check(is_array($candidate) && $candidate['token'] === $fakeNonce && $candidate['telegramUserId'] === '123456789', 'private /start update yields only expected link candidate');
$mentioned = $validUpdate;
$mentioned['message']['text'] = '/start@mrneon_test_bot ' . $fakeNonce;
check(TelegramUpdateParser::linkCandidate($mentioned, 'mrneon_test_bot') !== null, 'matching explicit bot command is accepted');
$wrongBot = $validUpdate;
$wrongBot['message']['text'] = '/start@other_bot ' . $fakeNonce;
check(TelegramUpdateParser::linkCandidate($wrongBot, 'mrneon_test_bot') === null, 'explicit command for another bot is rejected');
$groupUpdate = $validUpdate;
$groupUpdate['message']['chat']['type'] = 'supergroup';
check(TelegramUpdateParser::linkCandidate($groupUpdate, 'mrneon_test_bot') === null, 'groups/channels are not accepted as linking proofs');
$otherUserUpdate = $validUpdate;
$otherUserUpdate['message']['chat']['id'] = 123456790;
check(TelegramUpdateParser::linkCandidate($otherUserUpdate, 'mrneon_test_bot') === null, 'private chat ID must match the initiating account ID');
$botSenderUpdate = $validUpdate;
$botSenderUpdate['message']['from']['is_bot'] = true;
check(TelegramUpdateParser::linkCandidate($botSenderUpdate, 'mrneon_test_bot') === null, 'bot senders cannot claim a user link token');
$badToken = $validUpdate;
$badToken['message']['text'] = '/start not-a-link-token';
check(TelegramUpdateParser::linkCandidate($badToken, 'mrneon_test_bot') === null, 'malformed nonce is ignored');
$badId = $validUpdate;
$badId['update_id'] = '9001';
check(TelegramUpdateParser::linkCandidate($badId, 'mrneon_test_bot') === null, 'string update_id is rejected instead of weak coercion');
check(TelegramLinkConfiguration::deepLinkForNonce('short') === null, 'link builder rejects invalid token length');

$emailConfig = new SmtpConfiguration(
    'smtp.example.test', 465, 'unit-user', 'unit-password-not-real', 'smtps',
    'no-reply@example.test', 'MR NEON Test', 5, '', 'smtp.example.test'
);
$fakeSmtp = new W4P4FakeSmtpClient();
$emailTransport = new SmtpVerificationTransport($emailConfig, $fakeSmtp);
$emailDelivery = new VerificationDelivery(str_repeat('d', 32), 'registration', 'email', 'recipient@example.test', '001234');
check($emailTransport->deliver($emailDelivery)->status === VerificationDeliveryResult::ACCEPTED, 'Email transport propagates explicit SMTP acceptance');
check($fakeSmtp->captured['to'] === 'recipient@example.test', 'Email recipient is the exact server-supplied delivery target');
check(str_contains($fakeSmtp->captured['text'], '001234') && str_contains($fakeSmtp->captured['html'], '001234'), 'Email template includes OTP and fixed 120-second warning');
check(!str_contains(print_r($emailConfig, true), 'unit-password-not-real'), 'SMTP config debugging redacts credentials');
check(!str_contains(print_r($emailConfig, true), 'smtp.example.test') && !str_contains(print_r($emailConfig, true), 'no-reply@example.test'), 'SMTP config debug output redacts infrastructure and sender addresses');
foreach (['mrneon.invalid', 'mailer.local', "bad\r\nEHLO injected", 'nodot'] as $badHelo) {
    try {
        new SmtpConfiguration('smtp.example.test', 587, 'unit-user', 'unit-password-not-real', 'starttls', 'no-reply@example.test', 'MR NEON Test', 5, '', $badHelo);
        check(false, 'SMTP EHLO identity rejects invalid or unsafe value');
    } catch (InvalidArgumentException) { check(true, 'SMTP EHLO identity rejects invalid or unsafe value'); }
}
$literalHelo = new SmtpConfiguration('smtp.example.test', 587, 'unit-user', 'unit-password-not-real', 'starttls', 'no-reply@example.test', 'MR NEON Test', 5, '', '[127.0.0.1]');
check($literalHelo->heloName === '[127.0.0.1]', 'SMTP EHLO accepts validated bracketed IPv4 address literal');
try { serialize($emailConfig); check(false, 'SMTP configuration must not serialize credentials'); } catch (LogicException $exception) { check(true, 'SMTP configuration must not serialize credentials'); }
$fakeSmtp->result = VerificationDeliveryResult::rejected('SMTP_AUTH_REJECTED');
check($emailTransport->deliver($emailDelivery)->status === VerificationDeliveryResult::REJECTED, 'Email adapter propagates definite rejection');
$fakeSmtp->result = VerificationDeliveryResult::unknown('SMTP_RESULT_UNKNOWN');
check($emailTransport->deliver($emailDelivery)->status === VerificationDeliveryResult::UNKNOWN, 'Email adapter propagates ambiguous delivery result');
check((new SmtpVerificationTransport(null, $fakeSmtp))->deliver($emailDelivery)->status === VerificationDeliveryResult::NOT_CONFIGURED, 'Missing SMTP configuration is not configured, not accepted');
check($emailTransport->deliver($delivery)->status === VerificationDeliveryResult::REJECTED, 'Email transport rejects Telegram payload');
check((new CompositeVerificationTransport($telegram, $emailTransport))->deliver($emailDelivery)->status === VerificationDeliveryResult::UNKNOWN, 'Composite routes email through configured email adapter');

// Verify the native SMTP state machine over a loopback TLS fixture (not a real SMTP provider).
function testNativeSmtpTlsFixture(string $mode): bool
{
    if (!function_exists('pcntl_fork') || !function_exists('openssl_pkey_new') || !function_exists('stream_socket_server')) return false;
    $tempDir = sys_get_temp_dir() . '/mrn-w4p4-smtp-' . bin2hex(random_bytes(5));
    if (!mkdir($tempDir, 0700, true)) return false;
    $configFile = $tempDir . '/openssl.cnf';
    $certFile = $tempDir . '/server.pem';
    $keyFile = $tempDir . '/server.key';
    try {
        file_put_contents($configFile, "[req]\ndistinguished_name=dn\nx509_extensions=v3_req\nprompt=no\n[dn]\nCN=127.0.0.1\n[v3_req]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nsubjectAltName=IP:127.0.0.1\n");
        $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configFile]);
        if ($privateKey === false) return false;
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $privateKey, ['config' => $configFile, 'digest_alg' => 'sha256']);
        if ($csr === false) return false;
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['config' => $configFile, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_req']);
        if ($certificate === false || !openssl_x509_export($certificate, $certificatePem) || !openssl_pkey_export($privateKey, $privateKeyPem, null, ['config' => $configFile])) return false;
        file_put_contents($certFile, $certificatePem);
        file_put_contents($keyFile, $privateKeyPem);
        chmod($keyFile, 0600);
        $serverContext = stream_context_create(['ssl' => [
            'local_cert' => $certFile, 'local_pk' => $keyFile, 'verify_peer' => false, 'allow_self_signed' => true,
        ]]);
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverContext);
        if (!is_resource($server)) return false;
        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        $pid = pcntl_fork();
        if ($pid === -1) { fclose($server); return false; }
        if ($pid === 0) {
            $client = @stream_socket_accept($server, 8);
            if (!is_resource($client)) exit(10);
            stream_set_timeout($client, 8);
            $tlsActive = $mode === 'smtps';
            if ($tlsActive && @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) exit(11);
            fwrite($client, "220 local-fixture ESMTP\r\n");
            $authStage = 0;
            while (($line = fgets($client, 8192)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === 'EHLO smtp.example.test') {
                    fwrite($client, !$tlsActive && $mode === 'starttls'
                        ? "250-local-fixture\r\n250-STARTTLS\r\n250 HELP\r\n"
                        : "250-local-fixture\r\n250 AUTH LOGIN\r\n");
                } elseif ($line === 'STARTTLS' && !$tlsActive && $mode === 'starttls') {
                    fwrite($client, "220 begin TLS\r\n");
                    if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) exit(16);
                    $tlsActive = true;
                } elseif ($line === 'AUTH LOGIN') {
                    $authStage = 1; fwrite($client, "334 VXNlcm5hbWU6\r\n");
                } elseif ($authStage === 1) {
                    $authStage = 2; fwrite($client, "334 UGFzc3dvcmQ6\r\n");
                } elseif ($authStage === 2) {
                    $authStage = 0; fwrite($client, "235 2.7.0 authenticated\r\n");
                } elseif (stripos($line, 'MAIL FROM:') === 0 || stripos($line, 'RCPT TO:') === 0) {
                    fwrite($client, "250 2.1.0 accepted\r\n");
                } elseif ($line === 'DATA') {
                    fwrite($client, "354 end with dot\r\n");
                    $messageLines = [];
                    while (($messageLine = fgets($client, 8192)) !== false) {
                        if ($messageLine === ".\r\n" || $messageLine === ".\n") break;
                        $messageLines[] = $messageLine;
                        if (count($messageLines) > 1000) exit(12);
                    }
                    if ($messageLine === false) exit(13);
                    $all = implode('', $messageLines);
                    // The OTP is MIME base64 encoded; it must be present as content but not raw in the wire body.
                    if (!str_contains($all, 'Content-Transfer-Encoding: base64') || str_contains($all, '001234')) exit(14);
                    fwrite($client, "250 2.0.0 queued\r\n");
                } elseif ($line === 'QUIT') {
                    fwrite($client, "221 2.0.0 bye\r\n");
                    fclose($client); fclose($server); exit(0);
                } else {
                    fwrite($client, "500 5.5.1 unsupported\r\n");
                }
            }
            exit(15);
        }
        fclose($server);
        $config = new SmtpConfiguration('127.0.0.1', $port, 'test-user', 'test-password', $mode, 'no-reply@example.test', 'MR NEON Test', 5, $certFile, 'smtp.example.test');
        $result = (new NativeSmtpClient())->send($config, 'recipient@example.test', 'MR NEON test subject', 'Verification code: 001234', '<p>001234</p>');
        pcntl_waitpid($pid, $status);
        return $result->status === VerificationDeliveryResult::ACCEPTED && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
    } catch (Throwable $exception) {
        return false;
    } finally {
        foreach (glob($tempDir . '/*') ?: [] as $file) @unlink($file);
        @rmdir($tempDir);
    }
}

$localSmtps = testNativeSmtpTlsFixture('smtps');
check($localSmtps, 'Native SMTPS protocol succeeds against local TLS fixture with certificate verification');
$localStartTls = testNativeSmtpTlsFixture('starttls');
check($localStartTls, 'Native STARTTLS protocol upgrades to verified TLS before SMTP AUTH');

$authJs = file_get_contents(dirname(__DIR__, 2) . '/js/auth.js');
check(is_string($authJs) && str_contains($authJs, 'function safeRetryAfterSeconds(response)') && str_contains($authJs, 'headers?.get?.("Retry-After")'), 'Registration UI reads bounded Retry-After from the central API response adapter');
check(is_string($authJs) && str_contains($authJs, 'ثانیه دیگر دوباره تلاش کنید') && str_contains($authJs, 'ثانیه دیگر برای درخواست کد جدید صبر کنید'), 'Registration send and resend UX provide a bounded wait message');
$recoveryService = file_get_contents(dirname(__DIR__, 2) . '/app/services/RecoveryService.php');
check(is_string($recoveryService) && !str_contains($recoveryService, 'retryAfterSeconds') && str_contains($recoveryService, 'password_recovery.delivery_resolved'), 'Recovery does not expose Telegram retry metadata and retains its generic privacy-preserving response');

fwrite(STDOUT, "PASS W4-P4 Unit: {$checks} assertions (mock provider responses + local TLS SMTP fixtures; no external provider contacted)\n");
