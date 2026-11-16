<?php
declare(strict_types=1);

final class PrivateStorage
{
    public static function ensureBackupDirectory(): string
    {
        $configured = getenv('MRNEON_BACKUP_DIR');
        $path = is_string($configured) ? trim($configured) : '';
        if ($path === '' || !self::isAbsolute($path)) {
            throw new RuntimeException('Set MRNEON_BACKUP_DIR to an absolute private directory outside the application/web root.');
        }
        self::assertNoSymlinkComponents($path);
        if (!is_dir($path)) {
            if (!@mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException('Private backup directory could not be created.');
            }
        }
        self::assertNoSymlinkComponents($path);
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Private backup directory is not usable.');
        }
        self::assertOutsideProjectRoot($real);
        if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($real, 0700)) {
            throw new RuntimeException('Private backup directory permissions could not be restricted.');
        }
        return $real;
    }

    public static function resolveExistingPrivateFile(string $path): string
    {
        if (trim($path) === '' || str_contains($path, "\0") || !self::isAbsolute($path)) {
            throw new RuntimeException('Backup file path is invalid.');
        }
        self::assertNoSymlinkComponents($path);
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException('Backup file is missing or unreadable.');
        }
        self::assertOutsideProjectRoot($real);
        return $real;
    }

    public static function assertOutsideProjectRoot(string $path): void
    {
        if (trim($path) === '' || str_contains($path, "\0") || !self::isAbsolute($path)) {
            throw new RuntimeException('Private path must be an absolute, canonicalizable path.');
        }
        self::assertNoSymlinkComponents($path);
        $realPath = realpath($path);
        if ($realPath === false || (!is_dir($realPath) && !is_file($realPath))) {
            throw new RuntimeException('Private path must exist and resolve to a canonical filesystem object.');
        }
        $root = realpath(MRNEON_PROJECT_ROOT);
        if ($root === false) throw new RuntimeException('Application root cannot be resolved.');
        $normalize = static function (string $value): string {
            $value = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value);
            $trimmed = rtrim(DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value, DIRECTORY_SEPARATOR);
            return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
        };
        $normalizedPath = $normalize($realPath);
        $normalizedRoot = $normalize($root);
        if ($normalizedPath === $normalizedRoot || str_starts_with($normalizedPath, $normalizedRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private backup files must be stored outside the application directory/web-root candidate.');
        }
    }

    /** Reject symlinked existing path components before canonicalization. This reduces redirection risk but cannot eliminate TOCTOU races or every Windows junction/bind-mount case. */
    private static function assertNoSymlinkComponents(string $path): void
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (DIRECTORY_SEPARATOR === '\\') {
            // Avoid backslash-heavy regexes here: normalize first, then parse the drive root.
            if (strlen($path) < 3 || !preg_match('/^[A-Za-z]:$/D', substr($path, 0, 2)) || $path[2] !== DIRECTORY_SEPARATOR) {
                throw new RuntimeException('Private path must use an absolute drive path on Windows.');
            }
            $cursor = substr($path, 0, 2) . DIRECTORY_SEPARATOR;
            $parts = explode(DIRECTORY_SEPARATOR, substr($path, 3));
        } else {
            if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Private path must be absolute.');
            }
            $cursor = DIRECTORY_SEPARATOR;
            $parts = explode(DIRECTORY_SEPARATOR, ltrim($path, DIRECTORY_SEPARATOR));
        }
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                $cursor = dirname($cursor);
                continue;
            }
            $cursor = rtrim($cursor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Private storage paths must not contain symbolic links.');
            }
            $stat = @lstat($cursor);
            if (is_array($stat) && (((int) ($stat['mode'] ?? 0) & 0170000) === 0120000)) {
                throw new RuntimeException('Private storage paths must not contain symbolic links.');
            }
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
