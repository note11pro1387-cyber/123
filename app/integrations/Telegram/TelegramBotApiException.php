<?php
declare(strict_types=1);

final class TelegramBotApiException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly bool $outcomeUnknown,
        public readonly ?int $retryAfterSeconds = null
    ) {
        parent::__construct($safeCode);
        if ($retryAfterSeconds !== null && ($retryAfterSeconds < 1 || $retryAfterSeconds > 3600)) {
            throw new InvalidArgumentException('Telegram retry-after interval is outside the safe range.');
        }
    }
}
