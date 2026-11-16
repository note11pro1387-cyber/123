<?php
declare(strict_types=1);

final class VerificationTransportFactory
{
    public static function fromEnvironment(): VerificationTransportInterface
    {
        $telegram = new TelegramVerificationTransport(new TelegramBotApiClient());
        $email = new SmtpVerificationTransport(SmtpConfiguration::fromEnvironment());
        return new CompositeVerificationTransport($telegram, $email);
    }
}
