<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

function importUsage(): void
{
    echo "MR NEON JSON -> DB importer (default: dry-run, users only)\n";
    echo "Usage: php bin/migrate-json.php [--source=/private/snapshot/data] [--report=/private/reports/import.json]\n";
    echo "       php bin/migrate-json.php --apply --allow-partial-source --allow-legacy-accounts-pending [--source=...]\n";
    echo "Options: --apply writes to the configured DB; --allow-partial-source acknowledges missing project/notification/reset sources; --allow-legacy-accounts-pending explicitly acknowledges that imported accounts remain pending.\n";
}

$options = getopt('', ['apply', 'allow-partial-source', 'allow-legacy-accounts-pending', 'source:', 'report:', 'help']);
if (isset($options['help'])) { importUsage(); exit(0); }
$apply = isset($options['apply']);
$defaultSource = MRNEON_PROJECT_ROOT . '/data';
$requestedSource = isset($options['source']) ? (string) $options['source'] : $defaultSource;
$sourceDirectory = realpath($requestedSource);
if ($sourceDirectory === false || !is_dir($sourceDirectory)) {
    fwrite(STDERR, "SOURCE_ERROR: configured source directory does not exist or is not readable.\n");
    exit(2);
}

$pdo = null;
$connectionError = null;
if ($apply && isset($options['allow-legacy-accounts-pending']) && isset($options['allow-partial-source'])) {
    try { $pdo = Database::connection(); }
    catch (Throwable $exception) { $connectionError = $exception->getMessage(); }
}
$service = new LegacyJsonImportService($pdo, $sourceDirectory);
try {
    $report = $service->run($apply, isset($options['allow-partial-source']), isset($options['allow-legacy-accounts-pending']));
} catch (Throwable $exception) {
    // Report only a generic class/code; never emit DSN, exception trace or record values.
    $report = [
        'report_version' => 1,
        'run_id' => bin2hex(random_bytes(8)),
        'mode' => $apply ? 'APPLY' : 'DRY_RUN',
        'status' => 'IMPORT_ERROR',
        'error_type' => get_class($exception),
        'message' => 'Importer stopped safely. Review configuration/schema and server-side diagnostics.',
    ];
}
if ($connectionError !== null) {
    $report['target']['status'] = 'UNAVAILABLE';
    $report['status'] = 'BLOCKED_DB_UNAVAILABLE';
    $report['message'] = 'Apply was not performed because the database connection is unavailable. No JSON source was changed.';
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if (isset($options['report'])) {
    $reportPath = (string) $options['report'];
    if ($reportPath === '' || str_contains($reportPath, "\0")) {
        fwrite(STDERR, "REPORT_ERROR: invalid report path.\n"); exit(2);
    }
    $handle = @fopen($reportPath, 'xb');
    if ($handle === false) {
        fwrite(STDERR, "REPORT_ERROR: report path already exists or is not writable; use a new private path.\n"); exit(2);
    }
    chmod($reportPath, 0600);
    fwrite($handle, $json);
    fclose($handle);
} else {
    echo $json;
}
$status = (string) ($report['status'] ?? 'IMPORT_ERROR');
if (str_starts_with($status, 'BLOCKED') || $status === 'IMPORT_ERROR' || $status === 'IMPORT_ROLLED_BACK' || $status === 'POST_IMPORT_COUNT_MISMATCH') exit(3);
exit(0);
