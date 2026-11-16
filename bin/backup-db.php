<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php bin/backup-db.php\nSet MRNEON_BACKUP_DIR to an absolute private directory outside the application directory.\n";
    exit(0);
}
try {
    $pdo = Database::connection();
    $config = Database::configuration();
    $directory = PrivateStorage::ensureBackupDirectory();
    $result = (new BackupManager($pdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->create($directory, (string) $config['name']);
    unset($result['path']);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'BACKUP_FAILED: ' . preg_replace('/[^A-Z0-9_ .:-]/i', '', $exception->getMessage()) . PHP_EOL);
    exit(5);
}
