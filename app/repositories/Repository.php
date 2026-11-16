<?php
declare(strict_types=1);

abstract class Repository
{
    public function __construct(protected PDO $pdo)
    {
    }

    protected function positiveId(int $id, string $label = 'id'): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException($label . ' must be a positive integer.');
        }
        return $id;
    }

    protected function pageArgs(int $page, int $perPage, int $maxPerPage = 100): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > $maxPerPage) {
            throw new InvalidArgumentException('Invalid pagination bounds.');
        }
        return [($page - 1) * $perPage, $perPage];
    }

    protected function jsonObject(array $value, string $label = 'metadata'): string
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($encoded) > 65536) throw new InvalidArgumentException('The ' . $label . ' payload exceeds the 64 KiB limit.');
            return $encoded;
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid ' . $label . ' JSON value.');
        }
    }

    protected function rejectSensitiveKeys(array $value, string $label): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:password|\botp\b|reset.?token|recovery.?token|session.?id|session.?token|cookie|csrf|bot.?token|smtp|api.?key|secret|authorization)/i', $key)) {
                throw new InvalidArgumentException('Sensitive credential-like field is not allowed in ' . $label . '.');
            }
            if (is_array($item)) $this->rejectSensitiveKeys($item, $label);
        }
    }

    protected function utf8Length(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? PHP_INT_MAX : $count;
    }

    protected function allowedValue(string $value, array $allowed, string $label): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported ' . $label . '.');
        }
        return $value;
    }

    protected function nonEmptyString(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || $this->utf8Length($value) > $maxLength) {
            throw new InvalidArgumentException('Invalid ' . $label . '.');
        }
        return $value;
    }
}
