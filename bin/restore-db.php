<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$options = getopt('', ['file:', 'target-db:', 'allow-empty-target', 'allow-non-test-target', 'help']);
if (isset($options['help'])) {
    echo "Usage: php bin/restore-db.php --file=/private/backup.ndjson.gz --target-db=mrneon_test_restore --allow-empty-target [--allow-non-test-target]\n";
    echo "Restore requires matching migration checksums and an empty target schema. No overwrite/truncate is performed.\n";
    exit(0);
}
if (!isset($options['file'], $options['target-db']) || !isset($options['allow-empty-target'])) {
    fwrite(STDERR, "RESTORE_BLOCKED: --file, --target-db and --allow-empty-target are all required.\n"); exit(2);
}
try {
    $pdo = Database::connection();
    $result = (new BackupManager($pdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->restore(
        (string) $options['file'], (string) $options['target-db'], true, isset($options['allow-non-test-target'])
    );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(($result['status'] ?? '') === 'PASS' ? 0 : 4);
} catch (Throwable $exception) {
    fwrite(STDERR, 'RESTORE_FAILED: ' . preg_replace('/[^A-Z0-9_ .:-]/i', '', $exception->getMessage()) . PHP_EOL);
    exit(5);
}
