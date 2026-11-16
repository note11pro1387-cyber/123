<?php
declare(strict_types=1);

/**
 * W4-P4 adapter contract. Return ACCEPTED only after provider-level acceptance is known.
 * Throwing after a network call is considered UNKNOWN, never REJECTED. Implementations must
 * not log or persist VerificationDelivery::code or its destination.
 */
interface VerificationTransportInterface
{
    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult;
}
