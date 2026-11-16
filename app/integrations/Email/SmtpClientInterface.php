<?php
declare(strict_types=1);

interface SmtpClientInterface
{
    public function send(SmtpConfiguration $config, string $to, string $subject, string $textBody, string $htmlBody): VerificationDeliveryResult;
}
