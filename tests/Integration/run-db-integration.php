<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bin/_bootstrap.php';

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "BLOCKED: pdo_mysql driver is not installed; no database tests were attempted.\n");
    exit(77);
}
$config = Database::configuration();
$dbName = (string) $config['name'];
$restoreName = trim((string) (getenv('MRNEON_TEST_RESTORE_DB') ?: ''));
if (!preg_match('/(^|_)(test|sandbox)(_|$)/i', $dbName) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $restoreName) || !preg_match('/(^|_)(test|sandbox)(_|$)/i', $restoreName)) {
    fwrite(STDERR, "BLOCKED: use explicitly isolated test database names and set MRNEON_TEST_RESTORE_DB.\n");
    exit(77);
}

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Integration check failed: ' . $label);
};
$sourcePdo = null;
$targetPdo = null;
$backupDir = null;
$backupResult = null;
$fixtureLegacyId = 'SYNTHETIC-WAVE2-BACKUP-FIXTURE';
$corruptBackupPath = null;
$importFixtureDir = null;
$importFixtureLegacyId = null;
try {
    // Create only a test-named restore database. Credentials come only from environment.
    $adminPdo = Database::connect(['name' => 'mysql']);
    $adminPdo->exec('CREATE DATABASE IF NOT EXISTS `' . $restoreName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    unset($adminPdo);
    $sourcePdo = Database::connection();
    $targetPdo = Database::connect(['name' => $restoreName]);
    $sourceRunner = new MigrationRunner($sourcePdo, MRNEON_PROJECT_ROOT . '/database/migrations');
    $sourceRunner->apply();
    $targetRunner = new MigrationRunner($targetPdo, MRNEON_PROJECT_ROOT . '/database/migrations');
    $targetRunner->apply();

    foreach (SchemaCatalog::dataTablesInRestoreOrder() as $table) {
        $sourceCount = (int) $sourcePdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($sourceCount !== 0) throw new RuntimeException('Source test database must be empty before the integration suite.');
        $targetCount = (int) $targetPdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        if ($targetCount !== 0) throw new RuntimeException('Restore test database must be empty before the integration suite.');
    }
    $assert(count(SchemaCatalog::tables()) === 17, 'all expected table definitions are catalogued through W4-P3');
    $statusBefore = $sourceRunner->verifyChecksums();
    $sourceRunner->apply();
    $statusAfter = $sourceRunner->verifyChecksums();
    $assert($statusBefore['current_version'] === SchemaCatalog::CURRENT_SCHEMA_VERSION && $statusAfter['pending'] === [], 'migration apply is repeatable');
    $assert($statusAfter['checksum_errors'] === [], 'migration checksums are stable');

    $sourcePdo->beginTransaction();
    try {
        $users = new UserRepository($sourcePdo);
        $channels = new UserChannelRepository($sourcePdo);
        $projects = new ProjectRepository($sourcePdo);
        $milestones = new ProjectMilestoneRepository($sourcePdo);
        $events = new ProjectEventRepository($sourcePdo);
        $messages = new ProjectMessageRepository($sourcePdo);
        $notes = new InternalNoteRepository($sourcePdo);
        $attempts = new RegistrationAttemptRepository($sourcePdo);
        $challenges = new VerificationChallengeRepository($sourcePdo);
        $sessions = new AuthSessionRepository($sourcePdo);
        $idempotency = new IdempotencyKeyRepository($sourcePdo);
        // Process-local synthetic key for the isolated test database only; never a production credential.
        putenv('MRNEON_RATE_LIMIT_HMAC_KEY=' . str_repeat('SYNTHETIC-TEST-HMAC-KEY-', 3));
        $limits = new RateLimitBucketRepository($sourcePdo);
        $outbox = new OutboxRepository($sourcePdo);
        $audit = new AuditLogRepository($sourcePdo);

        $hash = password_hash('SYNTHETIC TEST PASSWORD ONLY', PASSWORD_DEFAULT);
        $user1 = $users->create(['full_name'=>'Synthetic Test User One','username'=>'Wave2TestUser1','phone'=>'+989121234561','password_hash'=>$hash,'account_status'=>'active']);
        $user2 = $users->create(['full_name'=>'Synthetic Test User Two','username'=>'Wave2TestUser2','phone'=>'+989121234562','password_hash'=>$hash,'account_status'=>'active']);
        $channelId = $channels->create($user1['id'], 'email', 'wave2-integration@example.com', true, null, true);
        $assert($channelId > 0 && count($channels->findVerifiedForUser($user1['id'], 'email')) === 1, 'verified channel can be queried for its owner');

        $duplicateRejected = false;
        try {
            $users->create(['full_name'=>'Synthetic Duplicate','username'=>'wAVE2tESTuSER1','phone'=>'+989121234563','password_hash'=>$hash,'account_status'=>'active']);
        } catch (PDOException $exception) {
            $duplicateRejected = $exception->getCode() === '23000';
        }
        $assert($duplicateRejected, 'case-normalized duplicate username rejected by unique constraint');

        $project = $projects->create($user1['id'], 'Synthetic Brief Project', 'website', ['fixture'=>'SYNTHETIC TEST DATA']);
        $assert(preg_match('/^[a-f0-9]{32}$/D', $project['public_id']) === 1 && preg_match('/^MN-[A-HJ-NP-Z2-9]{12}$/D', $project['tracking_code']) === 1, 'server-generated public ID and tracking code formats');
        $assert($milestones->createDefaults($project['id']) === 6, 'six initial milestones created');
        $milestoneRows = $milestones->listForProject($project['id']);
        $assert(count($milestoneRows) === 6 && array_sum(array_column($milestoneRows, 'weight')) === 100, 'milestone weights cover 100 percent');
        $milestones->setStatus((int) $milestoneRows[0]['id'], 'completed');
        $assert($milestones->completedWeightPercent($project['id']) === 10, 'project progress derives from completed milestone weights');
        $events->append($project['id'], 'system', null, 'project_created', null, 'submitted', 'Synthetic integration event', ['fixture'=>'SYNTHETIC TEST DATA']);
        $ownerEvents = $events->listForOwnerProject($project['id'], $user1['id']);
        $otherOwnerEvents = $events->listForOwnerProject($project['id'], $user2['id']);
        $assert(count($ownerEvents) === 1 && count($otherOwnerEvents) === 0, 'customer event query enforces project ownership in SQL');
        $invalidEventStatusRejected = false;
        try { $events->append($project['id'], 'system', null, 'invalid_test_event', null, 'not-a-project-status'); }
        catch (InvalidArgumentException) { $invalidEventStatusRejected = true; }
        $assert($invalidEventStatusRejected, 'event repository rejects status outside the approved state enum');
        $messageId = $messages->create($project['id'], 'admin', 'synthetic-admin', 'Synthetic customer-visible message');
        $notes->create($project['id'], 'synthetic-admin', 'Synthetic internal-only note');
        $ownerMessages = $messages->listForOwnerProject($project['id'], $user1['id']);
        $otherOwnerMessages = $messages->listForOwnerProject($project['id'], $user2['id']);
        $assert(count($ownerMessages) === 1 && (int) $ownerMessages[0]['id'] === $messageId, 'customer message repository returns only messages');
        $assert(count($otherOwnerMessages) === 0, 'customer message query enforces project ownership in SQL');
        $assert(count($notes->listForProject($project['id'])) === 1 && !array_key_exists('internal_notes', $ownerMessages[0]), 'internal note is separately stored from customer messages');

        $attemptId = $attempts->create(['full_name'=>'Synthetic Pending User','username'=>'Wave2Pending1','phone'=>'+989121234564','email_value'=>'pending-wave2@example.com','password_hash'=>$hash,'selected_channel'=>'email','expires_at'=>gmdate('Y-m-d H:i:s', time()+600)]);
        $assert($attempts->findOpen($attemptId) !== null && isset($attempts->findForCompletion($attemptId)['password_hash']), 'registration attempt supports private finalization read');
        $preparedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $challengeId = $challenges->createPendingDelivery(['purpose'=>'registration','subject_type'=>'registration_attempt','registration_attempt_id'=>$attemptId,'channel_type'=>'email','destination_value'=>'pending-wave2@example.com','destination_normalized'=>'pending-wave2@example.com','code_hmac'=>hash('sha256','SYNTHETIC OTP HMAC'),'expires_at'=>$preparedAt->format('Y-m-d H:i:s.u')], $preparedAt);
        $assert($challenges->findActive($challengeId, 'registration', 'registration_attempt', $attemptId) === null, 'pending registration Challenge is not consumable before provider acceptance');
        $assert($challenges->activatePendingDelivery($challengeId, 'registration', $preparedAt), 'accepted registration fixture activates pending Challenge in the same transaction');
        $active = $challenges->findActive($challengeId, 'registration', 'registration_attempt', $attemptId);
        $assert(is_array($active) && !array_key_exists('raw_code', $active), 'challenge query exposes no raw OTP value');
        $assert($challenges->recordFailedAttempt($challengeId), 'challenge failed attempt increments atomically');
        $assert($challenges->consumeOnce($challengeId) && !$challenges->consumeOnce($challengeId), 'registration challenge can be consumed only once');

        $resetPreparedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $resetChallenge = $challenges->createPendingDelivery(['purpose'=>'password_reset','subject_type'=>'user','user_id'=>$user1['id'],'user_channel_id'=>$channelId,'channel_type'=>'email','destination_value'=>'wave2-integration@example.com','destination_normalized'=>'wave2-integration@example.com','code_hmac'=>hash('sha256','SYNTHETIC RESET HMAC'),'expires_at'=>$resetPreparedAt->format('Y-m-d H:i:s.u')], $resetPreparedAt);
        $assert($challenges->findActive($resetChallenge, 'password_reset', 'user', (string) $user1['id']) === null, 'pending reset Challenge is not consumable before provider acceptance');
        $assert($challenges->activatePendingDelivery($resetChallenge, 'password_reset', $resetPreparedAt), 'accepted recovery fixture activates pending Challenge in the same transaction');
        $resetToken = 'SYNTHETIC-RECOVERY-TOKEN-FOR-INTEGRATION-TEST';
        $assert($challenges->consumeForPasswordReset($resetChallenge, hash('sha256', $resetToken), 300), 'reset OTP transitions to scoped reset authorization');
        $assert($challenges->consumeRecoveryAuthorization($resetToken) === $user1['id'], 'reset authorization resolves only its bound user');
        $assert($challenges->consumeRecoveryAuthorization($resetToken) === null, 'reset authorization is one-time');

        $sessionHash = hash('sha256', 'SYNTHETIC-SESSION-ID');
        $sessions->create($sessionHash, 'user', (string) $user1['id'], $user1['id'], gmdate('Y-m-d H:i:s', time()+3600));
        $assert($sessions->findActive($sessionHash) !== null, 'session registry stores and finds only the session hash');
        $assert($sessions->revoke($sessionHash, 'integration_test') && $sessions->findActive($sessionHash) === null, 'session revocation is enforced');

        $key = 'SYNTHETIC-IDEMPOTENCY-KEY';
        $requestHash = hash('sha256', 'SYNTHETIC REQUEST BODY');
        $assert($idempotency->reserve('user', (string) $user1['id'], 'POST:/api/v1/projects', $key, $requestHash, gmdate('Y-m-d H:i:s', time()+3600)), 'idempotency key reservation succeeds once');
        $assert(!$idempotency->reserve('user', (string) $user1['id'], 'POST:/api/v1/projects', $key, $requestHash, gmdate('Y-m-d H:i:s', time()+3600)), 'duplicate idempotency reservation does not create a second key');
        $assert($idempotency->complete('user', (string) $user1['id'], 'POST:/api/v1/projects', $key, $requestHash, 201, ['publicId'=>$project['public_id']]), 'idempotency response completes using safe canonical payload');
        $assert(($idempotency->find('user', (string) $user1['id'], 'POST:/api/v1/projects', $key)['state'] ?? '') === 'completed', 'completed idempotency record can be read');

        $assert((int) $limits->consume('account', 'SYNTHETIC-LOGIN-BUCKET', 60)['counter'] === 1, 'rate limit bucket starts at one');
        $assert((int) $limits->consume('account', 'SYNTHETIC-LOGIN-BUCKET', 60)['counter'] === 2, 'rate limit counter increments atomically');
        $outboxId = $outbox->enqueue('project_created', 'project', (string) $project['id'], 'email', ['projectPublicId'=>$project['public_id']], 'synthetic-outbox-event-1');
        $assert($outboxId !== '' && count($outbox->findDue(25)) === 1, 'outbox records due work without sending it');
        $auditId = $audit->append('system', null, 'wave2.integration_test', 'test', 'synthetic', null, null, null, ['fixture'=>'SYNTHETIC TEST DATA']);
        $assert($auditId > 0, 'audit event persists safe metadata');

        $badForeignKeyRejected = false;
        try {
            $channels->create(999999999, 'email', 'fk-negative@example.com', false, null, false);
        } catch (PDOException $exception) {
            $badForeignKeyRejected = $exception->getCode() === '23000';
        }
        $assert($badForeignKeyRejected, 'foreign key rejects orphan channel owner');
    } finally {
        if ($sourcePdo->inTransaction()) $sourcePdo->rollBack();
    }

    $sourcePdo->beginTransaction();
    $backupUser = (new UserRepository($sourcePdo))->create([
        'full_name' => 'Synthetic Backup Fixture', 'username' => 'Wave2BackupFixture',
        'phone' => '+989121234569', 'password_hash' => password_hash('SYNTHETIC BACKUP TEST ONLY', PASSWORD_DEFAULT),
        'legacy_id' => $fixtureLegacyId, 'account_status' => 'pending',
    ]);
    $sourcePdo->commit();

    $backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-wave2-backup-' . bin2hex(random_bytes(4));
    if (!mkdir($backupDir, 0700, true)) throw new RuntimeException('Could not create isolated backup test directory.');
    chmod($backupDir, 0700);
    $backupResult = (new BackupManager($sourcePdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->create($backupDir, $dbName);
    $assert(($backupResult['status'] ?? '') === 'PASS' && is_file($backupResult['path']) && is_file($backupResult['path'] . '.sha256'), 'backup file and SHA-256 sidecar created');

    $restoreResult = (new BackupManager($targetPdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->restore($backupResult['path'], $restoreName, true, false);
    $assert(($restoreResult['status'] ?? '') === 'PASS' && ($restoreResult['integrity_status'] ?? '') === 'PASS', 'restore to matching empty test database passes integrity gate');
    $assert((new UserRepository($targetPdo))->findByLegacyId($fixtureLegacyId) !== null, 'synthetic backup record restored exactly to target');
    $assert(($restoreResult['row_counts']['users'] ?? -1) === 1, 'restore row counts match backup manifest');

    $nonEmptyRestoreRejected = false;
    try { (new BackupManager($targetPdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->restore($backupResult['path'], $restoreName, true, false); }
    catch (RuntimeException) { $nonEmptyRestoreRejected = true; }
    $assert($nonEmptyRestoreRejected && (int) $targetPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'restore refuses non-empty target without changing rows');

    $corruptBackupPath = $backupDir . DIRECTORY_SEPARATOR . 'synthetic-corrupt.ndjson.gz';
    if (!copy($backupResult['path'], $corruptBackupPath)) throw new RuntimeException('Could not create synthetic corrupt-backup fixture.');
    $corruptBytes = file_get_contents($corruptBackupPath);
    if (!is_string($corruptBytes) || strlen($corruptBytes) < 16) throw new RuntimeException('Synthetic backup fixture is too short to corrupt.');
    $corruptBytes[12] = chr(ord($corruptBytes[12]) ^ 1);
    file_put_contents($corruptBackupPath, $corruptBytes);
    file_put_contents($corruptBackupPath . '.sha256', hash_file('sha256', $backupResult['path']) . '  ' . basename($corruptBackupPath) . PHP_EOL);
    $corruptBackupRejected = false;
    try { (new BackupManager($targetPdo, MRNEON_PROJECT_ROOT . '/database/migrations'))->restore($corruptBackupPath, $restoreName, true, false); }
    catch (RuntimeException) { $corruptBackupRejected = true; }
    $assert($corruptBackupRejected && (int) $targetPdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1, 'corrupt backup checksum is rejected before any restore write');

    $importFixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-wave2-import-' . bin2hex(random_bytes(4));
    if (!mkdir($importFixtureDir, 0700, true)) throw new RuntimeException('Could not create synthetic import fixture directory.');
    $importFixtureLegacyId = 'SYNTHETIC-WAVE2-IMPORT-' . bin2hex(random_bytes(3));
    $importUser = [
        'id' => $importFixtureLegacyId,
        'fullName' => 'Synthetic Import Fixture',
        'phone' => '+989121234570',
        'email' => 'wave2-import-fixture@example.com',
        'username' => 'Wave2Import1',
        'password' => password_hash('SYNTHETIC IMPORT TEST ONLY', PASSWORD_DEFAULT),
        'verified' => true,
        'createdAt' => '2026-10-12 00:00:00',
        'lastLogin' => '',
    ];
    $importSourcePath = $importFixtureDir . DIRECTORY_SEPARATOR . 'users.json';
    file_put_contents($importSourcePath, json_encode([$importUser], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $importSourceHash = hash_file('sha256', $importSourcePath);
    $importService = new LegacyJsonImportService($sourcePdo, $importFixtureDir);
    $importApplied = $importService->run(true, true, true);
    $assert(($importApplied['status'] ?? '') === 'APPLIED_PARTIAL_IMPORT' && ($importApplied['destination_counts']['inserted_users'] ?? 0) === 1 && ($importApplied['destination_counts']['inserted_channels'] ?? 0) === 1, 'approved synthetic JSON user import writes one user and one unverified email channel');
    $importedRowStmt = $sourcePdo->prepare('SELECT id, account_status, legacy_verified FROM users WHERE legacy_id = ? LIMIT 1');
    $importedRowStmt->execute([$importFixtureLegacyId]);
    $importedRow = $importedRowStmt->fetch();
    $assert(is_array($importedRow) && $importedRow['account_status'] === 'pending' && (int) $importedRow['legacy_verified'] === 1, 'legacy verified flag does not activate imported account');
    $unverifiedEmailStmt = $sourcePdo->prepare("SELECT COUNT(*) FROM user_channels WHERE user_id = ? AND channel_type = 'email' AND is_verified = 0");
    $unverifiedEmailStmt->execute([(int) $importedRow['id']]);
    $assert((int) $unverifiedEmailStmt->fetchColumn() === 1, 'legacy email remains unverified and cannot be used for recovery');
    $importRepeated = $importService->run(true, true, true);
    $assert(($importRepeated['status'] ?? '') === 'APPLIED_PARTIAL_IMPORT' && ($importRepeated['destination_counts']['already_imported_identical'] ?? 0) === 1 && ($importRepeated['destination_counts']['inserted_users'] ?? -1) === 0, 're-running an identical JSON import is idempotent');
    $assert(hash_equals((string) $importSourceHash, (string) hash_file('sha256', $importSourcePath)), 'apply importer leaves source JSON unchanged');

    // Clean only uniquely identified synthetic fixtures and artifacts from explicitly test-named databases.
    $delete = $sourcePdo->prepare('DELETE FROM users WHERE legacy_id = ?');
    $delete->execute([$fixtureLegacyId]);
    $delete = $targetPdo->prepare('DELETE FROM users WHERE legacy_id = ?');
    $delete->execute([$fixtureLegacyId]);
    if (is_array($backupResult)) {
        @unlink($backupResult['path'] . '.sha256');
        @unlink($backupResult['path']);
    }
    if (is_string($importFixtureLegacyId) && $sourcePdo instanceof PDO) {
        $cleanup = $sourcePdo->prepare('DELETE FROM user_channels WHERE user_id = (SELECT id FROM users WHERE legacy_id = ? LIMIT 1)');
        $cleanup->execute([$importFixtureLegacyId]);
        $cleanup = $sourcePdo->prepare('DELETE FROM users WHERE legacy_id = ?');
        $cleanup->execute([$importFixtureLegacyId]);
    }
    if (is_string($importFixtureDir) && is_dir($importFixtureDir)) { foreach (glob($importFixtureDir . DIRECTORY_SEPARATOR . '*') ?: [] as $fixturePath) @unlink($fixturePath); @rmdir($importFixtureDir); }
    if (is_string($corruptBackupPath)) { @unlink($corruptBackupPath . '.sha256'); @unlink($corruptBackupPath); }
    if (is_string($backupDir) && is_dir($backupDir)) @rmdir($backupDir);
    echo 'PASS: ' . $checks . " MariaDB integration checks; database names were explicitly test-scoped; SYNTHETIC TEST DATA only.\n";
    exit(0);
} catch (Throwable $exception) {
    if ($sourcePdo instanceof PDO && $sourcePdo->inTransaction()) $sourcePdo->rollBack();
    // No SQL, source values, DSN or credentials are emitted.
    fwrite(STDERR, 'FAIL: ' . preg_replace('/[^A-Z0-9_ .:-]/i', '', $exception->getMessage()) . PHP_EOL);
    exit(1);
} finally {
    if (is_array($backupResult)) {
        @unlink((string) $backupResult['path'] . '.sha256');
        @unlink((string) $backupResult['path']);
    }
    if (is_string($corruptBackupPath)) { @unlink($corruptBackupPath . '.sha256'); @unlink($corruptBackupPath); }
    if (is_string($importFixtureLegacyId) && $sourcePdo instanceof PDO) {
        try {
            $cleanup = $sourcePdo->prepare('DELETE FROM user_channels WHERE user_id = (SELECT id FROM users WHERE legacy_id = ? LIMIT 1)');
            $cleanup->execute([$importFixtureLegacyId]);
            $cleanup = $sourcePdo->prepare('DELETE FROM users WHERE legacy_id = ?');
            $cleanup->execute([$importFixtureLegacyId]);
        } catch (Throwable) {}
    }
    if (is_string($importFixtureDir) && is_dir($importFixtureDir)) { foreach (glob($importFixtureDir . DIRECTORY_SEPARATOR . '*') ?: [] as $fixturePath) @unlink($fixturePath); @rmdir($importFixtureDir); }
    if (is_string($backupDir) && is_dir($backupDir)) @rmdir($backupDir);
}
