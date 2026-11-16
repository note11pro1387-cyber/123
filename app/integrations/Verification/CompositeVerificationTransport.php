<?php
declare(strict_types=1);

/** Routes a trusted, server-resolved delivery to exactly one provider adapter. */
final class CompositeVerificationTransport implements VerificationTransportInterface
{
    public function __construct(
        private readonly VerificationTransportInterface $telegram,
        private readonly VerificationTransportInterface $email
    ) {
    }

    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        $transport = match ($delivery->channel()) {
            'telegram' => $this->telegram,
            'email' => $this->email,
            default => null,
        };
        if (!$transport instanceof VerificationTransportInterface) {
            return VerificationDeliveryResult::rejected('TRANSPORT_CHANNEL_UNSUPPORTED');
        }
        try {
            $result = $transport->deliver($delivery);
            return $result instanceof VerificationDeliveryResult ? $result : VerificationDeliveryResult::unknown('TRANSPORT_RESULT_INVALID');
        } catch (Throwable $exception) {
            return VerificationDeliveryResult::unknown('TRANSPORT_RESULT_UNKNOWN');
        }
    }
}
