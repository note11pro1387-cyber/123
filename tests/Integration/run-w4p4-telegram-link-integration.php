<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "BLOCKED: pdo_mysql is unavailable; Telegram link database integration was not run.\n");
    exit(77);
}
$expectedName = getenv('MRNEON_TEST_DB_NAME');
$configuredName = getenv('MRNEON_DB_NAME');
if (!is_string($expectedName) || $expectedName === '' || $configuredName !== $expectedName
    || preg_match('/(?:test|sandbox)/i', $expectedName) !== 1
    || getenv('MRNEON_ALLOW_W4P4_TEST_MUTATION') !== '1') {
    fwrite(STDERR, "BLOCKED: require matching MRNEON_DB_NAME/MRNEON_TEST_DB_NAME for a dedicated test/sandbox DB and MRNEON_ALLOW_W4P4_TEST_MUTATION=1. No mutation attempted.\n");
    exit(77);
}

$pdo = null;
$attemptId = null;
$updateId = null;
$botScope = 'mrneon_test_bot';
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Telegram link integration assertion failed: ' . $label);
};

try {
    $pdo = Database::connection();
    $actualName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($actualName) || !hash_equals($expectedName, $actualName)) {
        fwrite(STDERR, "BLOCKED: SELECT DATABASE() did not match the explicit test DB; no mutation attempted.\n");
        exit(77);
    }
    $schema = (new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations'))->verifyChecksums();
    if (($schema['current_version'] ?? null) !== '018' || ($schema['pending'] ?? ['missing']) !== []
        || ($schema['checksum_errors'] ?? ['missing']) !== []) {
        fwrite(STDERR, "BLOCKED: checksum-verified migrations 001–018 are required; no mutation attempted.\n");
        exit(77);
    }

    $suffix = bin2hex(random_bytes(5));
    $rawNonce = bin2hex(random_bytes(32));
    $nonceHash = hash('sha256', $rawNonce);
    $attemptId = (new RegistrationAttemptRepository($pdo))->create([
        'full_name' => 'Synthetic W4P4 Telegram Link Test',
        'username' => 'w4tg' . $suffix,
        'phone' => '+989' . (string) random_int(100000000, 999999999),
        'password_hash' => password_hash('Synthetic-only registration passphrase ' . $suffix, PASSWORD_DEFAULT),
        'selected_channel' => 'telegram',
        'telegram_link_nonce_hash' => $nonceHash,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 1200),
    ]);
    $updateId = random_int(1000000000, 1900000000);
    $telegramId = (string) random_int(100000000, 900000000);
    $update = [
        'update_id' => $updateId,
        'message' => [
            'message_id' => random_int(1, 1000000),
            'from' => ['id' => (int) $telegramId, 'is_bot' => false, 'first_name' => 'Synthetic'],
            'chat' => ['id' => (int) $telegramId, 'type' => 'private', 'first_name' => 'Synthetic'],
            'date' => time(),
            'text' => '/start ' . $rawNonce,
        ],
    ];
    $service = new TelegramLinkWebhookService($pdo, $botScope);
    $first = $service->handleUpdate($update);
    $assert(($first['state'] ?? '') === 'linked' && ($first['linked'] ?? false) === true, 'valid private /start links the intended open Attempt');

    $attempt = (new RegistrationAttemptRepository($pdo))->findForCompletion($attemptId);
    $assert(is_array($attempt) && ($attempt['linked_telegram_user_id'] ?? null) === $telegramId
        && ($attempt['linked_telegram_chat_id'] ?? null) === $telegramId, 'only Telegram IDs from validated Update are bound');
    $assert(is_array($attempt) && ($attempt['telegram_link_nonce_hash'] ?? null) === null, 'raw nonce is never stored and nonce hash is atomically consumed');
    $attemptRepository = new RegistrationAttemptRepository($pdo);
    $assert($attemptRepository->replaceTelegramNonceHash($attemptId, hash('sha256', bin2hex(random_bytes(32)))) === false,
        'a linked Attempt cannot receive a fresh link nonce');
    $assert($attemptRepository->linkTelegramIdentity($attemptId, (string) ((int) $telegramId + 1), (string) ((int) $telegramId + 1), $nonceHash) === false,
        'direct compare-and-consume update cannot overwrite an existing Telegram identity');

    $duplicate = $service->handleUpdate($update);
    $assert(($duplicate['state'] ?? '') === 'duplicate', 'exact duplicate update is idempotently acknowledged');

    $mismatch = $update;
    $mismatch['message']['text'] = '/start ' . str_repeat('f', 64);
    $mismatchResult = $service->handleUpdate($mismatch);
    $assert(($mismatchResult['state'] ?? '') === 'duplicate_mismatch', 'same update_id with different body cannot replace first result');

    $replay = $update;
    $replay['update_id'] = $updateId + 1;
    $replay['message']['message_id'] = $update['message']['message_id'] + 1;
    $replayResult = $service->handleUpdate($replay);
    $assert(($replayResult['state'] ?? '') === 'ignored', 'consumed nonce cannot link a second Update');

    $wrongChatType = $update;
    $wrongChatType['update_id'] = $updateId + 2;
    $wrongChatType['message']['chat']['type'] = 'group';
    $ignoredGroup = $service->handleUpdate($wrongChatType);
    $assert(($ignoredGroup['state'] ?? '') === 'ignored', 'group update cannot link Telegram identity');

    fwrite(STDOUT, "PASS W4-P4 Telegram Link DB Integration: {$checks} assertions (synthetic disposable rows only).\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL W4-P4 Telegram Link DB Integration: " . preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', get_class($exception) . ': ' . $exception->getMessage()) . "\n");
    exit(1);
} finally {
    if ($pdo instanceof PDO && $attemptId !== null) {
        try {
            // Delete only this test's synthetic idempotency keys and audit row; never DROP tables/databases.
            if ($updateId !== null) {
                $deleteKey = $pdo->prepare('DELETE FROM idempotency_keys WHERE scope_type = ? AND scope_id = ? AND route = ? AND key_hash IN (?, ?, ?)');
                $deleteKey->execute([
                    'telegram_update', $botScope, 'webhook',
                    hash('sha256', (string) $updateId),
                    hash('sha256', (string) ($updateId + 1)),
                    hash('sha256', (string) ($updateId + 2)),
                ]);
            }
            $audit = $pdo->prepare("DELETE FROM audit_logs WHERE action = 'registration.telegram_linked' AND subject_type = 'registration_attempt' AND subject_id = ?");
            $audit->execute([$attemptId]);
            $attempt = $pdo->prepare('DELETE FROM registration_attempts WHERE id = ? AND full_name = ? AND username LIKE ?');
            $attempt->execute([$attemptId, 'Synthetic W4P4 Telegram Link Test', 'w4tg%']);
        } catch (Throwable $cleanupException) {
            fwrite(STDERR, "WARNING: synthetic test cleanup needs review; no schema/database drop was attempted.\n");
        }
    }
}
