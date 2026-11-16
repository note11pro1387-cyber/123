<?php
declare(strict_types=1);

/** Telegram Bot API client; never emits provider descriptions or credentials to logs/errors. */
final class TelegramBotApiClient
{
    private readonly string $token;
    private readonly JsonHttpClientInterface $http;

    public function __construct(?string $token = null, ?JsonHttpClientInterface $http = null)
    {
        $raw = $token ?? (string) (getenv('MRNEON_TELEGRAM_BOT_TOKEN') ?: '');
        $this->token = trim($raw);
        $this->http = $http ?? new NativeHttpsJsonClient();
    }

    public function isConfigured(): bool
    {
        return preg_match('/^[0-9]{5,16}:[A-Za-z0-9_-]{20,128}$/D', $this->token) === 1;
    }

    /** @return array<string,mixed>|bool Telegram's validated result object or boolean result. */
    public function call(string $method, array $payload = [], int $timeoutSeconds = 10): array|bool
    {
        if (!$this->isConfigured()) {
            throw new TelegramBotApiException('TELEGRAM_NOT_CONFIGURED', false);
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,63}$/D', $method)) {
            throw new InvalidArgumentException('Telegram Bot API method is invalid.');
        }
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $response = $this->http->post(
                'https://api.telegram.org/bot' . $this->token . '/' . $method,
                [],
                $body,
                $timeoutSeconds
            );
        } catch (TelegramBotApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TelegramBotApiException('TELEGRAM_NETWORK_RESULT_UNKNOWN', true);
        }

        $responseMayBeAmbiguous = ($response->statusCode >= 200 && $response->statusCode < 300)
            || $response->statusCode === 408 || $response->statusCode >= 500;
        try {
            $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new TelegramBotApiException('TELEGRAM_RESPONSE_INVALID', $responseMayBeAmbiguous);
        }
        if (!is_array($decoded) || !array_key_exists('ok', $decoded) || !is_bool($decoded['ok'])) {
            throw new TelegramBotApiException('TELEGRAM_RESPONSE_INVALID', $responseMayBeAmbiguous);
        }
        if ($decoded['ok'] !== true) {
            $httpStatus = $response->statusCode;
            // A gateway/server failure does not prove that sendMessage was not accepted.
            // Keep it ambiguous so callers do not retry and accidentally send duplicate OTPs.
            if ($httpStatus === 408 || $httpStatus >= 500) {
                throw new TelegramBotApiException('TELEGRAM_PROVIDER_RESULT_UNKNOWN', true);
            }
            $code = match (true) {
                $httpStatus === 401 => 'TELEGRAM_AUTH_REJECTED',
                $httpStatus === 403 => 'TELEGRAM_ACCESS_REJECTED',
                $httpStatus === 429 => 'TELEGRAM_RATE_LIMITED',
                $httpStatus >= 400 && $httpStatus < 500 => 'TELEGRAM_REQUEST_REJECTED',
                default => 'TELEGRAM_PROVIDER_REJECTED',
            };
            $retryAfter = null;
            if ($httpStatus === 429) {
                $candidate = $decoded['parameters']['retry_after'] ?? null;
                if (is_int($candidate) && $candidate >= 1 && $candidate <= 3600) {
                    $retryAfter = $candidate;
                }
            }
            throw new TelegramBotApiException($code, false, $retryAfter);
        }
        if ($response->statusCode < 200 || $response->statusCode >= 300 || !array_key_exists('result', $decoded)) {
            throw new TelegramBotApiException('TELEGRAM_RESPONSE_INCONSISTENT', true);
        }
        if (!is_array($decoded['result']) && !is_bool($decoded['result'])) {
            throw new TelegramBotApiException('TELEGRAM_RESPONSE_INVALID', true);
        }
        return $decoded['result'];
    }

    public function sendVerificationMessage(string $destinationChatId, string $code): VerificationDeliveryResult
    {
        if (!$this->isConfigured()) return VerificationDeliveryResult::notConfigured();
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $destinationChatId) || !OtpGenerator::isValidFormat($code)) {
            return VerificationDeliveryResult::rejected('TELEGRAM_DESTINATION_OR_MESSAGE_INVALID');
        }
        $text = "کد تأیید MR NEON: {$code}\nاین کد فقط ۱۲۰ ثانیه معتبر است. آن را در اختیار دیگران قرار ندهید.";
        try {
            $result = $this->call('sendMessage', [
                'chat_id' => $destinationChatId,
                'text' => $text,
                'protect_content' => true,
                'link_preview_options' => ['is_disabled' => true],
            ], 10);
        } catch (TelegramBotApiException $exception) {
            if ($exception->outcomeUnknown) return VerificationDeliveryResult::unknown($exception->safeCode);
            if ($exception->safeCode === 'TELEGRAM_NOT_CONFIGURED') return VerificationDeliveryResult::notConfigured();
            return VerificationDeliveryResult::rejected($exception->safeCode, $exception->retryAfterSeconds);
        }
        if (!is_array($result) || !isset($result['message_id'], $result['chat']['id'])
            || !is_int($result['message_id']) || $result['message_id'] < 1
            || !hash_equals($destinationChatId, (string) $result['chat']['id'])) {
            // Telegram may have accepted the send even when the response did not match our contract.
            return VerificationDeliveryResult::unknown('TELEGRAM_RESPONSE_INCONSISTENT');
        }
        return VerificationDeliveryResult::accepted();
    }

    /** Never expose the Bot token through debug logging or generic object serialization. */
    public function __debugInfo(): array
    {
        return ['token' => '[REDACTED]', 'httpClient' => get_debug_type($this->http)];
    }

    public function __serialize(): array
    {
        throw new LogicException('Telegram Bot API clients contain a secret and are intentionally non-serializable.');
    }

    public function configuredBotUsername(): string
    {
        return trim((string) (getenv('MRNEON_TELEGRAM_BOT_USERNAME') ?: ''));
    }
}
