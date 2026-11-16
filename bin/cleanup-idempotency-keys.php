<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function idempotencyCleanupUsage(): void
{
    echo "Usage: php bin/cleanup-idempotency-keys.php [--limit=200] [--apply --confirm-db=DATABASE_NAME]\n";
    echo "Default mode does not change application rows/schema. Apply requires cleanup + QA acknowledgement gates and exact database-name confirmation; production has a separate gate.\n";
}

$args = array_slice($argv, 1);
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    idempotencyCleanupUsage();
    exit(0);
}

$apply = false;
$limit = 200;
$confirmedDatabase = null;
foreach ($args as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--limit=(\d{1,4})$/D', $argument, $matches)) {
        $limit = (int) $matches[1];
    } elseif (preg_match('/^--confirm-db=([A-Za-z0-9_]{1,64})$/D', $argument, $matches)) {
        $confirmedDatabase = $matches[1];
    } else {
        fwrite(STDERR, "INVALID_ARGUMENT\n");
        idempotencyCleanupUsage();
        exit(2);
    }
}

if ($limit < 1 || $limit > 500) {
    fwrite(STDERR, "INVALID_LIMIT: use a batch limit from 1 to 500.\n");
    exit(2);
}

$config = Database::configuration();
$configuredDatabase = (string) ($config['name'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $configuredDatabase)) {
    fwrite(STDERR, "DATABASE_CONFIGURATION_UNAVAILABLE\n");
    exit(3);
}

if ($apply) {
    if (getenv('MRNEON_ALLOW_IDEMPOTENCY_CLEANUP') !== '1') {
        fwrite(STDERR, "WRITE_DISABLED: explicit MRNEON_ALLOW_IDEMPOTENCY_CLEANUP=1 is required for this invocation.\n");
        exit(3);
    }
    if (getenv('MRNEON_IDEMPOTENCY_CLEANUP_QA_APPROVED') !== '1') {
        fwrite(STDERR, "QA_GATE_NOT_ACKNOWLEDGED: cleanup apply requires explicit confirmation that final QA and owner approval are complete.\n");
        exit(3);
    }
    $environment = strtolower(trim((string) (getenv('MRNEON_ENV') ?: '')));
    if (!in_array($environment, ['local', 'test', 'staging', 'production'], true)) {
        fwrite(STDERR, "ENVIRONMENT_CONFIRMATION_REQUIRED: set MRNEON_ENV to the explicit target environment.\n");
        exit(3);
    }
    if ($environment === 'production' && getenv('MRNEON_ALLOW_PRODUCTION_IDEMPOTENCY_CLEANUP') !== '1') {
        fwrite(STDERR, "PRODUCTION_WRITE_DISABLED: a separate MRNEON_ALLOW_PRODUCTION_IDEMPOTENCY_CLEANUP=1 gate is required.\n");
        exit(3);
    }
    if (!is_string($confirmedDatabase) || !hash_equals($configuredDatabase, $confirmedDatabase)) {
        fwrite(STDERR, "DATABASE_CONFIRMATION_MISMATCH: pass --confirm-db with the exact configured database name.\n");
        exit(3);
    }
} elseif ($confirmedDatabase !== null) {
    fwrite(STDERR, "UNEXPECTED_DATABASE_CONFIRMATION: --confirm-db is only valid with --apply.\n");
    exit(2);
}

try {
    $pdo = Database::connection();
    $repository = new IdempotencyKeyRepository($pdo);
    $before = $repository->countExpiredByState();
    if (!$apply) {
        echo json_encode([
            'mode' => 'dry-run',
            'expired_completed' => $before['completed'],
            'expired_failed' => $before['failed'],
            'expired_processing_manual_review' => $before['processing'],
            'max_delete_batch' => $limit,
            'persistent_writes_performed' => false,
            'database_session_timezone_initialized_utc' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $deleted = $repository->deleteExpiredCompletedOrFailedBatch($limit);
    $after = $repository->countExpiredByState();
    echo json_encode([
        'mode' => 'apply',
        'deleted_expired_terminal_records' => $deleted,
        'expired_completed_remaining' => $after['completed'],
        'expired_failed_remaining' => $after['failed'],
        'expired_processing_manual_review' => $after['processing'],
        'persistent_writes_performed' => true,
        'database_session_timezone_initialized_utc' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    // Never print SQL, connection details, key material, or payloads.
    fwrite(STDERR, 'IDEMPOTENCY_CLEANUP_FAILED: ' . get_class($exception) . PHP_EOL);
    exit(5);
}
