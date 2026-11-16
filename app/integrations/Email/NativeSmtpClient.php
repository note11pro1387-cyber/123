<?php
declare(strict_types=1);

/** Minimal SMTP client for authenticated SMTP over mandatory TLS; no clear-text mode exists. */
final class NativeSmtpClient implements SmtpClientInterface
{
    public function send(SmtpConfiguration $config, string $to, string $subject, string $textBody, string $htmlBody): VerificationDeliveryResult
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n\0]/', $to)) {
            return VerificationDeliveryResult::rejected('SMTP_RECIPIENT_INVALID');
        }
        $socket = null;
        $dataTerminatorSent = false;
        try {
            $hostForSocket = filter_var($config->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $config->host . ']' : $config->host;
            $scheme = $config->encryption === 'smtps' ? 'ssl://' : 'tcp://';
            $sslOptions = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $config->host,
                'SNI_enabled' => true,
            ];
            if ($config->caFile !== '') $sslOptions['cafile'] = $config->caFile;
            $context = stream_context_create(['ssl' => $sslOptions]);
            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client(
                $scheme . $hostForSocket . ':' . $config->port,
                $errno,
                $errstr,
                $config->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if (!is_resource($socket)) return VerificationDeliveryResult::rejected('SMTP_CONNECT_FAILED');
            stream_set_timeout($socket, $config->timeoutSeconds);
            if (!$this->expect($socket, [220])) return VerificationDeliveryResult::rejected('SMTP_GREETING_REJECTED');
            $ehlo = $this->command($socket, 'EHLO ' . $config->heloName, [250]);
            if (!$ehlo['ok']) return VerificationDeliveryResult::rejected('SMTP_EHLO_REJECTED');

            if ($config->encryption === 'starttls') {
                $capabilities = implode("\n", $ehlo['lines']);
                if (!preg_match('/(?:^|\n)STARTTLS(?:\s|$)/i', $capabilities)) {
                    return VerificationDeliveryResult::rejected('SMTP_STARTTLS_UNAVAILABLE');
                }
                if (!$this->command($socket, 'STARTTLS', [220])['ok']) return VerificationDeliveryResult::rejected('SMTP_STARTTLS_REJECTED');
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                $crypto = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($crypto !== true) return VerificationDeliveryResult::rejected('SMTP_TLS_NEGOTIATION_FAILED');
                $ehlo = $this->command($socket, 'EHLO ' . $config->heloName, [250]);
                if (!$ehlo['ok']) return VerificationDeliveryResult::rejected('SMTP_EHLO_REJECTED');
            }

            if (!$this->command($socket, 'AUTH LOGIN', [334])['ok']) return VerificationDeliveryResult::rejected('SMTP_AUTH_REJECTED');
            if (!$this->command($socket, base64_encode($config->username), [334])['ok']) return VerificationDeliveryResult::rejected('SMTP_AUTH_REJECTED');
            if (!$this->command($socket, base64_encode($config->passwordForProtocol()), [235])['ok']) return VerificationDeliveryResult::rejected('SMTP_AUTH_REJECTED');
            if (!$this->command($socket, 'MAIL FROM:<' . $config->fromAddress . '>', [250])['ok']) return VerificationDeliveryResult::rejected('SMTP_SENDER_REJECTED');
            if (!$this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251])['ok']) return VerificationDeliveryResult::rejected('SMTP_RECIPIENT_REJECTED');
            if (!$this->command($socket, 'DATA', [354])['ok']) return VerificationDeliveryResult::rejected('SMTP_DATA_REJECTED');

            $message = $this->buildMessage($config, $to, $subject, $textBody, $htmlBody);
            if (!$this->writeAll($socket, $message . "\r\n.\r\n")) {
                // The socket may have accepted the message even if the final response was lost.
                return VerificationDeliveryResult::unknown('SMTP_RESULT_UNKNOWN');
            }
            $dataTerminatorSent = true;
            $reply = $this->readReply($socket);
            if ($reply === null) return VerificationDeliveryResult::unknown('SMTP_RESULT_UNKNOWN');
            if ($reply['code'] !== 250) return VerificationDeliveryResult::rejected('SMTP_MESSAGE_REJECTED');
            // Server acceptance is the strongest result available; QUIT cannot undo it.
            $this->writeAll($socket, "QUIT\r\n");
            return VerificationDeliveryResult::accepted();
        } catch (Throwable $exception) {
            return $dataTerminatorSent
                ? VerificationDeliveryResult::unknown('SMTP_RESULT_UNKNOWN')
                : VerificationDeliveryResult::rejected('SMTP_PROTOCOL_FAILED');
        } finally {
            if (is_resource($socket)) fclose($socket);
        }
    }

    /** @return array{ok:bool,lines:list<string>} */
    private function command($socket, string $command, array $expectedCodes): array
    {
        if (preg_match('/[\r\n\0]/', $command) || !$this->writeAll($socket, $command . "\r\n")) return ['ok' => false, 'lines' => []];
        $reply = $this->readReply($socket);
        return ['ok' => is_array($reply) && in_array($reply['code'], $expectedCodes, true), 'lines' => is_array($reply) ? $reply['lines'] : []];
    }

    private function expect($socket, array $codes): bool
    {
        $reply = $this->readReply($socket);
        return is_array($reply) && in_array($reply['code'], $codes, true);
    }

    /** @return array{code:int,lines:list<string>}|null */
    private function readReply($socket): ?array
    {
        $lines = [];
        $total = 0;
        $expectedCode = null;
        for ($index = 0; $index < 50; $index++) {
            $line = fgets($socket, 1025);
            if (!is_string($line)) return null;
            $total += strlen($line);
            if ($total > 32768 || !preg_match('/^(\d{3})([ -])(.*)\r?\n$/D', $line, $matches)) return null;
            $code = (int) $matches[1];
            if ($expectedCode !== null && $code !== $expectedCode) return null;
            $expectedCode = $code;
            $lines[] = (string) $matches[3];
            if ($matches[2] === ' ') return ['code' => $code, 'lines' => $lines];
        }
        return null;
    }

    private function writeAll($socket, string $data): bool
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($socket, substr($data, $offset));
            if (!is_int($written) || $written < 1) return false;
            $offset += $written;
        }
        return true;
    }

    private function buildMessage(SmtpConfiguration $config, string $to, string $subject, string $textBody, string $htmlBody): string
    {
        $boundary = '=_MRNEON_' . bin2hex(random_bytes(18));
        $fromName = '=?UTF-8?B?' . base64_encode($config->fromName) . '?=';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $domain = substr(strrchr($config->fromAddress, '@') ?: '@localhost.invalid', 1);
        $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>';
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: ' . $messageId,
            'From: ' . $fromName . ' <' . $config->fromAddress . '>',
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($textBody), 76, "\r\n")),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n")),
            '--' . $boundary . '--',
        ];
        return implode("\r\n", $headers);
    }
}
