<?php
declare(strict_types=1);

/** Sends only to the destination resolved by W4-P3 from a server-side linked Attempt. */
final class TelegramVerificationTransport implements VerificationTransportInterface
{
    public function __construct(private readonly TelegramBotApiClient $client)
    {
    }

    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult
    {
        if ($delivery->channel() !== 'telegram') {
            return VerificationDeliveryResult::rejected('TRANSPORT_CHANNEL_MISMATCH');
        }
        if (!$this->client->isConfigured()) return VerificationDeliveryResult::notConfigured();
        return $this->client->sendVerificationMessage($delivery->destination(), $delivery->code());
    }
}
