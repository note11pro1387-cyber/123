<?php
declare(strict_types=1);

/**
 * One-batch expired-session maintenance. Dry-run is the default.
 * Apply requires an owner-selected history cutoff and explicit confirmation.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = ['apply' => false, 'before' => null, 'batch' => 200, 'confirm' => null];
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') { $options['apply'] = true; continue; }
    if (preg_match('/^--before-utc=(.+)$/D', $argument, $matches)) { $options['before'] = $matches[1]; continue; }
    if (preg_match('/^--batch=([0-9]{1,3})$/D', $argument, $matches)) { $options['batch'] = (int) $matches[1]; continue; }
    if (preg_match('/^--confirm=(.+)$/D', $argument, $matches)) { $options['confirm'] = $matches[1]; continue; }
    fwrite(STDERR, "Usage: php bin/cleanup-expired-sessions.php [--before-utc=YYYY-MM-DDTHH:MM:SSZ] [--batch=1..500] [--apply --confirm=PURGE-EXPIRED-SESSIONS:YYYY-MM-DDTHH:MM:SSZ]\n");
    exit(64);
}
if ($options['batch'] < 1 || $options['batch'] > 500) {
    fwrite(STDERR, "INVALID BATCH: choose a value between 1 and 500.\n"); exit(64);
}
if ($options['before'] === null) {
    if ($options['apply']) { fwrite(STDERR, "BLOCKED: --apply requires an owner-selected --before-utc retention cutoff.\n"); exit(78); }
    fwrite(STDOUT, "DRY-RUN ONLY: no retention cutoff was supplied; no deletion candidates were selected.\n");
    fwrite(STDOUT, "Choose a UTC creation-time cutoff only after reviewing the Admin session-history requirement.\n");
    exit(0);
}
if (!preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/D', $options['before'], $matches)) {
    fwrite(STDERR, "INVALID CUTOFF: use YYYY-MM-DDTHH:MM:SSZ.\n"); exit(64);
}
$parsedCutoff = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $options['before'], new DateTimeZone('UTC'));
if (!$parsedCutoff instanceof DateTimeImmutable || $parsedCutoff->format('Y-m-d\TH:i:s\Z') !== $options['before']
    || $parsedCutoff > new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
    fwrite(STDERR, "INVALID CUTOFF: use a real UTC date/time that is not in the future.\n"); exit(64);
}
$cutoff = $matches[1] . ' ' . $matches[2];
if ($options['apply']) {
    $expected = 'PURGE-EXPIRED-SESSIONS:' . $options['before'];
    if (!is_string($options['confirm']) || !hash_equals($expected, $options['confirm'])) {
        fwrite(STDERR, "BLOCKED: confirmation phrase must include the exact selected cutoff.\n"); exit(78);
    }
    if (getenv('MRNEON_ALLOW_SESSION_CLEANUP') !== '1') {
        fwrite(STDERR, "BLOCKED: cleanup is not enabled in the private operator environment.\n"); exit(78);
    }
}
try {
    $pdo = Database::connection();
    $repository = new AuthSessionRepository($pdo);
    $eligible = $repository->countExpiredBeforeCreatedAt($cutoff);
    fwrite(STDOUT, 'Expired sessions older than selected cutoff: ' . $eligible . "\n");
    fwrite(STDOUT, 'Batch limit: ' . $options['batch'] . "\n");
    if (!$options['apply']) {
        fwrite(STDOUT, "DRY-RUN: no rows were changed. Re-run with --apply and the exact confirmation phrase to delete at most one batch.\n");
        exit(0);
    }
    if (!$pdo->beginTransaction()) throw new RuntimeException('Could not begin session cleanup transaction.');
    try {
        $deleted = $repository->deleteExpiredBeforeCreatedAtBatch($cutoff, $options['batch']);
        if (!$pdo->commit()) throw new RuntimeException('Session cleanup commit was not confirmed.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    fwrite(STDOUT, 'APPLIED: deleted ' . $deleted . ' expired session rows in one bounded batch. No identifiers were emitted.\n');
    exit(0);
} catch (Throwable $exception) {
    error_log('MR NEON session cleanup failed: type=' . get_class($exception));
    fwrite(STDERR, "FAILED: session cleanup did not complete; inspect private server logs by request time.\n");
    exit(1);
}
