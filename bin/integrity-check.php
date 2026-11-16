<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php bin/integrity-check.php\nRead-only checks for schema, migration checksums, indexes, Foreign Keys, duplicates and row counts.\n";
    exit(0);
}
try {
    $pdo = Database::connection();
    $report = (new IntegrityChecker($pdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->run();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(($report['status'] ?? '') === 'PASS' ? 0 : 4);
} catch (Throwable $exception) {
    fwrite(STDERR, 'INTEGRITY_CHECK_FAILED: ' . preg_replace('/[^A-Z0-9_ .:-]/i', '', $exception->getMessage()) . PHP_EOL);
    exit(5);
}
