<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

function migrationUsage(): void
{
    echo "MR NEON database migration CLI\n";
    echo "Usage: php bin/migrate.php status|check|apply\n";
    echo "  status  Show applied and pending migrations (read-only)\n";
    echo "  check   Verify schema versions/checksums (read-only)\n";
    echo "  apply   Apply pending versioned migrations (writes schema)\n";
}

$command = $argv[1] ?? 'help';
if (in_array($command, ['help', '--help', '-h'], true)) {
    migrationUsage();
    exit(0);
}
if (!in_array($command, ['status', 'check', 'apply'], true)) {
    migrationUsage();
    fwrite(STDERR, "Unknown migration command.\n");
    exit(2);
}
try {
    $pdo = Database::connection();
    $runner = new MigrationRunner($pdo, MRNEON_PROJECT_ROOT . '/database/migrations');
    if ($command === 'apply') {
        $result = $runner->apply();
        echo json_encode(['command' => 'apply', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $result = $command === 'check' ? $runner->verifyChecksums() : $runner->status();
    echo json_encode(['command' => $command, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($command === 'check' && (!$result['schema_exists'] || $result['pending'] !== [] || $result['checksum_errors'] !== [])) exit(3);
    exit($result['checksum_errors'] !== [] ? 4 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'DATABASE_MIGRATION_ERROR: ' . preg_replace('/[^A-Z0-9_ -]/i', '', $exception->getMessage()) . PHP_EOL);
    exit(5);
}
