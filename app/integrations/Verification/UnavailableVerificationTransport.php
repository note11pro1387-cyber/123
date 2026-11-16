<?php
declare(strict_types=1);

/** Production-safe default until W4-P4 registers a real Email/Telegram adapter. */
final class UnavailableVerificationTransport implements VerificationTransportInterface
{
    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        return VerificationDeliveryResult::notConfigured();
    }
}
