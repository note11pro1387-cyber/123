<?php
declare(strict_types=1);

/** Source inventory deliberately reports structure and counts, never record values. */
final class LegacyJsonSourceInspector
{
    public const PERSISTENT_SOURCE = 'users.json';
    public const EPHEMERAL_SOURCES = [
        'pending_registrations.json' => 'NOT_IMPORTED_SECURITY_SENSITIVE_PENDING_DRAFTS',
        'verification_codes.json' => 'NOT_IMPORTED_RAW_OR_LEGACY_OTP',
        'sessions.json' => 'NOT_IMPORTED_LEGACY_SESSIONS_MUST_BE_REAUTHENTICATED',
        'rate_limits.json' => 'NOT_IMPORTED_EPHEMERAL_RATE_LIMIT_STATE',
        'password_resets.json' => 'NOT_IMPORTED_SECURITY_TRANSIENT_RESET_TOKENS',
    ];
    public const MISSING_SOURCES = [
        'requests.json' => 'Missing project source in the supplied snapshot; no project import is possible.',
        'notifications.json' => 'Missing notification source in the supplied snapshot; no notification import is possible.',
    ];

    public function __construct(private string $sourceDirectory)
    {
    }

    public function inspect(): array
    {
        $sources = [];
        $jsonFiles = glob(rtrim($this->sourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($jsonFiles, SORT_STRING);
        foreach ($jsonFiles as $path) {
            $name = basename($path);
            $entry = ['file' => $name, 'bytes' => filesize($path) ?: 0, 'parse_status' => 'NOT_TESTED', 'root_type' => null, 'record_count' => null, 'record_keys' => [], 'policy' => 'NOT_IMPORTED_UNCLASSIFIED'];
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                $entry['parse_status'] = 'READ_ERROR';
                $sources[] = $entry;
                continue;
            }
            try {
                $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $entry['parse_status'] = 'PARSED';
                $entry['root_type'] = get_debug_type($value);
                if (is_array($value)) {
                    $entry['record_count'] = count($value);
                    $first = null;
                    if (array_is_list($value)) {
                        foreach ($value as $candidate) {
                            if (is_array($candidate)) { $first = $candidate; break; }
                        }
                    } elseif ($value !== []) {
                        // Object-style files may be keyed by tokens/hashes; never publish keys.
                        $first = reset($value);
                    }
                    if (is_array($first)) {
                        $entry['record_keys'] = array_values(array_map('strval', array_keys($first)));
                        sort($entry['record_keys'], SORT_STRING);
                    }
                }
            } catch (JsonException) {
                $entry['parse_status'] = 'INVALID_JSON';
            }
            if ($name === self::PERSISTENT_SOURCE) {
                $entry['policy'] = 'PERSISTENT_ACCOUNT_DATA_CANDIDATE';
            } elseif (isset(self::EPHEMERAL_SOURCES[$name])) {
                $entry['policy'] = self::EPHEMERAL_SOURCES[$name];
            }
            $sources[] = $entry;
        }

        $seen = array_column($sources, 'file');
        $missing = [];
        foreach (self::MISSING_SOURCES as $file => $reason) {
            if (!in_array($file, $seen, true)) $missing[] = ['file' => $file, 'status' => 'MISSING_SOURCE', 'reason' => $reason];
        }
        $logPath = $this->sourceDirectory . DIRECTORY_SEPARATOR . 'php_errors.log';
        $log = null;
        if (is_file($logPath)) {
            $logSize = filesize($logPath) ?: 0;
            $lines = 0;
            $handle = fopen($logPath, 'rb');
            if ($handle !== false) {
                while (!feof($handle)) {
                    $line = fgets($handle);
                    if ($line !== false) $lines++;
                }
                fclose($handle);
            }
            $log = ['file' => 'php_errors.log', 'bytes' => $logSize, 'line_count' => $lines, 'policy' => 'OPERATIONAL_LOG_NOT_IMPORTED_OR_REPRINTED'];
        }
        return ['source_directory_basename' => basename($this->sourceDirectory), 'sources' => $sources, 'missing_sources' => $missing, 'log' => $log];
    }
}
