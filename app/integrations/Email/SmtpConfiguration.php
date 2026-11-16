<?php
declare(strict_types=1);

/** Validated immutable SMTP runtime configuration. Secret values are never stringified. */
final class SmtpConfiguration
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        private readonly string $password,
        public readonly string $encryption,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly int $timeoutSeconds,
        public readonly string $caFile = '',
        public readonly string $heloName = ''
    ) {
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if ($host === '' || strlen($host) > 253 || (!$isIp && !$isDomain) || preg_match('/[\r\n\0\/]/', $host)
            || $port < 1 || $port > 65535 || trim($username) === '' || $password === ''
            || !in_array($encryption, ['starttls', 'smtps'], true)
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || strlen($fromAddress) > 254
            || $fromName === '' || strlen($fromName) > 200 || preg_match('/[\r\n\0]/', $fromName)
            || $timeoutSeconds < 3 || $timeoutSeconds > 15
            || !self::isValidHeloName($heloName)
            || ($caFile !== '' && (!is_file($caFile) || !is_readable($caFile)))) {
            throw new InvalidArgumentException('SMTP configuration is invalid.');
        }
    }

    public static function fromEnvironment(): ?self
    {
        $host = trim((string) (getenv('MRNEON_EMAIL_HOST') ?: ''));
        $portRaw = trim((string) (getenv('MRNEON_EMAIL_PORT') ?: ''));
        $username = trim((string) (getenv('MRNEON_EMAIL_USERNAME') ?: ''));
        $passwordRaw = getenv('MRNEON_EMAIL_PASSWORD');
        $password = is_string($passwordRaw) ? $passwordRaw : '';
        $encryption = strtolower(trim((string) (getenv('MRNEON_EMAIL_ENCRYPTION') ?: '')));
        $fromAddress = trim((string) (getenv('MRNEON_EMAIL_FROM_ADDRESS') ?: ''));
        $fromName = trim((string) (getenv('MRNEON_EMAIL_FROM_NAME') ?: ''));
        $timeoutRaw = trim((string) (getenv('MRNEON_EMAIL_TIMEOUT_SECONDS') ?: '10'));
        $caFile = trim((string) (getenv('MRNEON_EMAIL_TLS_CA_FILE') ?: ''));
        $heloName = trim((string) (getenv('MRNEON_EMAIL_HELO_NAME') ?: ''));
        if ($host === '' || $portRaw === '' || $username === '' || $password === '' || $encryption === '' || $fromAddress === '' || $fromName === '' || $heloName === '') {
            return null;
        }
        if (!ctype_digit($portRaw) || !ctype_digit($timeoutRaw)) return null;
        try {
            return new self($host, (int) $portRaw, $username, $password, $encryption, $fromAddress, $fromName, (int) $timeoutRaw, $caFile, $heloName);
        } catch (Throwable $exception) {
            return null;
        }
    }

    private static function isValidHeloName(string $value): bool
    {
        if ($value === '' || trim($value) !== $value || strlen($value) > 253 || preg_match('/[\r\n\0\s]/', $value)) return false;
        if (preg_match('/\.(?:local|invalid)$/i', $value)) return false;

        // RFC-style address literals must be bracketed. Hostnames must be explicit DNS-style names.
        if (preg_match('/^\[IPv6:([^\]]+)\]$/i', $value, $matches)) {
            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (preg_match('/^\[([^\]]+)\]$/', $value, $matches)) {
            return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        if (filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || !str_contains($value, '.')) return false;
        foreach (explode('.', $value) as $label) {
            if ($label === '' || strlen($label) > 63 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $label)) return false;
        }
        return true;
    }

    public function passwordForProtocol(): string { return $this->password; }

    public function __debugInfo(): array
    {
        return [
            'host' => '[CONFIGURED]', 'port' => $this->port, 'username' => '[REDACTED]',
            'password' => '[REDACTED]', 'encryption' => $this->encryption,
            'fromAddress' => '[REDACTED]', 'fromName' => '[REDACTED]',
            'timeoutSeconds' => $this->timeoutSeconds, 'caFile' => $this->caFile === '' ? '' : '[CONFIGURED]',
            'heloName' => '[CONFIGURED]',
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('SMTP configuration contains credentials and is intentionally non-serializable.');
    }
}
