<?php
declare(strict_types=1);

/** Ephemeral transport envelope. Sensitive OTP/target are private and never appear in JSON serialization. */
final class VerificationDelivery
{
    public function __construct(
        private readonly string $challengeId,
        private readonly string $purpose,
        private readonly string $channel,
        private readonly string $destination,
        private readonly string $code
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $challengeId)
            || !in_array($purpose, ['registration', 'password_reset'], true)
            || !in_array($channel, ['email', 'telegram'], true)
            || trim($destination) === '' || strlen($destination) > 255
            || !OtpGenerator::isValidFormat($code)) {
            throw new InvalidArgumentException('Verification delivery contract is invalid.');
        }
    }

    public function challengeId(): string { return $this->challengeId; }
    public function purpose(): string { return $this->purpose; }
    public function channel(): string { return $this->channel; }
    public function destination(): string { return $this->destination; }
    public function code(): string { return $this->code; }

    /** Sensitive delivery envelopes must never be copied into serialized logs or queues. */
    public function __serialize(): array
    {
        throw new LogicException('Verification delivery envelopes are intentionally non-serializable.');
    }

    /** Debug tooling must never reveal the target or the OTP. */
    public function __debugInfo(): array
    {
        return [
            'challengeId' => $this->challengeId,
            'purpose' => $this->purpose,
            'channel' => $this->channel,
            'destination' => '[REDACTED]',
            'code' => '[REDACTED]',
        ];
    }
}
