<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Pre-Wave8 bugfix assertion failed: ' . $label);
    }
};
$expectRuntimeException = static function (callable $operation, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label . ' must reject');
    } catch (RuntimeException $exception) {
        if (str_contains($exception->getMessage(), 'must reject')) throw $exception;
        $assert(true, $label . ' rejects unsafe input');
    }
};

$root = dirname(__DIR__, 2);

// P1: active challenges are purpose-bound; the existing ledger/acceptance choreography remains the activation boundary.
$assert(ChallengeStateMachine::challengeTransitionAllowed('registration', 'active', 'used'), 'registration active challenge can be consumed');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'active', 'verified_reset'), 'registration cannot transition into reset-only state');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'pending_delivery', 'active'), 'pending registration challenge cannot activate without provider acceptance');
$assert(ChallengeStateMachine::challengeTransitionAllowed('registration', 'pending_delivery', 'active', true), 'accepted registration provider delivery may activate its pending challenge');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'pending_delivery', 'active', true), 'accepted recovery provider delivery may activate its pending challenge');
$assert(ChallengeStateMachine::challengeTransitionAllowed('registration', 'pending_delivery', 'revoked'), 'definitively rejected pending registration challenge can be revoked');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('unknown', 'pending_delivery', 'active', true), 'unknown purpose cannot activate pending challenge');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'active', 'verified_reset'), 'password reset can move to reset-only state');
$assert(ChallengeStateMachine::challengeTransitionAllowed('password_reset', 'verified_reset', 'used'), 'reset-only capability can be consumed');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('registration', 'active', 'verified_reset'), 'reset-only transition remains unavailable to registration purpose');
$assert(!ChallengeStateMachine::challengeTransitionAllowed('unknown', 'active', 'used'), 'unknown purposes cannot transition');
$challengeRepository = file_get_contents($root . '/app/repositories/VerificationChallengeRepository.php');
$verificationService = file_get_contents($root . '/app/services/VerificationService.php');
$recoveryService = file_get_contents($root . '/app/services/RecoveryService.php');
$assert(is_string($challengeRepository) && str_contains($challengeRepository, "'pending_delivery'") && str_contains($challengeRepository, 'createPendingDelivery') && !str_contains($challengeRepository, 'public function create(array $challenge)'), 'challenge repository exposes pending-first insertion only, not direct active creation');
$assert(is_string($challengeRepository) && str_contains($challengeRepository, 'Pending Challenge and prepared delivery ledger must share a caller-owned transaction') && str_contains($challengeRepository, 'Challenge activation must share the accepted-ledger transaction'), 'pending insert and activation require caller-owned atomic transactions');
$assert(is_string($challengeRepository) && str_contains($challengeRepository, 'activatePendingDelivery') && str_contains($challengeRepository, "state = 'active'"), 'challenge activation is a conditional pending-to-active transition');
$assert(is_string($challengeRepository) && str_contains($challengeRepository, "WHERE id = ? AND purpose = ? AND state = 'pending_delivery'"), 'challenge activation is restricted to the exact pending record and purpose');
$assert(is_string($verificationService) && strpos($verificationService, 'if ($result->status !== VerificationDeliveryResult::ACCEPTED)') < strpos($verificationService, '$challenges->activatePendingDelivery(' ) , 'registration activates the prepared challenge only after provider acceptance');
$assert(is_string($recoveryService) && strpos($recoveryService, 'if ($result->status !== VerificationDeliveryResult::ACCEPTED)') < strpos($recoveryService, '$this->challenges->activatePendingDelivery(' ) , 'recovery activates the prepared challenge only after provider acceptance');
$assert(is_string($verificationService) && str_contains($verificationService, 'tryMarkUnknownAfterAcceptedPersistenceFailure'), 'accepted-but-unpersisted OTP is fail-closed for reconciliation');
$assert(is_string($recoveryService) && str_contains($recoveryService, 'The prepared record remains and prevents blind retry'), 'recovery prepared/unknown delivery blocks blind retry');
$assert(is_string($verificationService) && str_contains($verificationService, "['rejected', 'not_configured']") && str_contains($verificationService, 'revokePendingDelivery'), 'definite registration delivery failures revoke pending challenge');
$assert(is_string($recoveryService) && str_contains($recoveryService, "['rejected', 'not_configured']") && str_contains($recoveryService, 'revokePendingDelivery'), 'definite recovery delivery failures revoke pending challenge');
$assert(is_string($verificationService) && str_contains($verificationService, 'VerificationPolicy::expiresAt($acceptedAt)'), 'registration TTL begins at provider acceptance time');
$assert(is_string($recoveryService) && str_contains($recoveryService, "activatePendingDelivery(\$challengeId, 'password_reset', \$acceptedAt)"), 'recovery activates its pending challenge only after acceptance');
$assert(is_string($challengeRepository) && str_contains($challengeRepository, 'VerificationPolicy::expiresAt($acceptedAt)'), 'registration and recovery TTL starts are calculated by activation at provider acceptance time');

// P2: transaction/idempotency contracts are checked statically; true concurrency needs disposable MariaDB integration.
$adminController = file_get_contents($root . '/app/http/controllers/AdminProjectController.php');
$adminJs = file_get_contents($root . '/js/admin.js');
$assert(is_string($adminController) && preg_match("/case 'adminSendMessage':([\\s\\S]*?)case 'adminUpdate':/", $adminController, $adminMessageBlock) === 1, 'admin message route block is present');
if (isset($adminMessageBlock[1])) {
    $block = $adminMessageBlock[1];
    $assert(substr_count($block, 'beginTransaction()') === 1, 'admin send message has one transaction and no nested transaction');
    $assert(str_contains($block, '->reserve(') && str_contains($block, '->complete('), 'admin message reserves and completes idempotency in the business transaction');
    $assert(strpos($block, '->create($projectId') < strpos($block, '->complete('), 'message persistence precedes idempotency completion');
    $assert(str_contains($block, 'createForUser(') && str_contains($block, '->append('), 'notification and audit are inside the idempotency transaction');
    $assert(str_contains($block, "header('Idempotency-Replayed: true')"), 'canonical replay emits Idempotency-Replayed header');
    $assert(str_contains($adminController, 'IDEMPOTENCY_KEY_REUSED') && str_contains($adminController, 'IDEMPOTENCY_IN_PROGRESS'), 'same key with another payload and in-progress outcomes fail closed');
}
$assert(is_string($adminJs) && str_contains($adminJs, 'replyPendingOperations = new Map()'), 'Admin UI keeps pending idempotency operations in memory');
$assert(is_string($adminJs) && str_contains($adminJs, 'adminReplyPendingStorageKey') && str_contains($adminJs, 'crypto.subtle.digest') && str_contains($adminJs, 'sessionStorage?.setItem'), 'Admin UI persists only the text fingerprint and stable idempotency key across refreshes');
$replyMethodStart = is_string($adminJs) ? strpos($adminJs, 'async sendProjectReply') : false;
$replyDeletePos = $replyMethodStart === false ? false : strpos($adminJs, 'this.replyPendingOperations.delete(id)', $replyMethodStart);
$replyCatchPos = $replyMethodStart === false ? false : strpos($adminJs, '} catch (error) {', $replyMethodStart);
$assert(is_int($replyDeletePos) && is_int($replyCatchPos) && $replyDeletePos < $replyCatchPos, 'Admin UI only clears key on successful path before catch');
$assert(is_string($adminJs) && str_contains($adminJs, 'headers: { "Idempotency-Key": operation.key }'), 'Admin UI sends the stable idempotency key header');

// P3/P9: customer projection is owner-scoped and excludes internal notes and unsafe HTML insertion.
$projectRepository = file_get_contents($root . '/app/repositories/ProjectRepository.php');
$eventRepository = file_get_contents($root . '/app/repositories/ProjectEventRepository.php');
$customerController = file_get_contents($root . '/app/http/controllers/CustomerController.php');
$authJs = file_get_contents($root . '/js/auth.js');
$assert(is_string($projectRepository) && str_contains($projectRepository, 'p.owner_user_id = ?') && str_contains($projectRepository, 'AS status_reason'), 'project reason lookup is owner-scoped');
$assert(is_string($projectRepository) && str_contains($projectRepository, "'needs_information'") && str_contains($projectRepository, "'rejected'") && str_contains($projectRepository, "'cancelled'"), 'project status reason projection uses the explicit allowlist');
$assert(is_string($eventRepository) && str_contains($eventRepository, 'p.owner_user_id = ?') && str_contains($eventRepository, 'AS customer_message') && !str_contains($eventRepository, 'internal_notes'), 'customer timeline does not join or expose internal notes');
$assert(is_string($eventRepository) && str_contains($eventRepository, '$this->utf8Length($message)'), 'ProjectEvent length validation counts Unicode code points');
$assert(is_string($customerController) && str_contains($customerController, '$reasonLength !== false && $reasonLength <= 500'), 'Customer DTO rejects invalid UTF-8 and bounds reason length');
$assert(is_string($authJs) && str_contains($authJs, 'value.textContent = project.statusReason'), 'Customer status reason is rendered with textContent');
$assert(is_string($authJs) && str_contains($authJs, 'statusReason === null'), 'Customer project detail validates nullable statusReason');

// P5: defense-in-depth path handling. This proves local path logic only, not Host DocumentRoot denial.
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-prewave8-private-' . bin2hex(random_bytes(6));
if (!mkdir($tempRoot, 0700, true)) throw new RuntimeException('Could not create private-storage test directory.');
$oldBackupDir = getenv('MRNEON_BACKUP_DIR');
try {
    $privateDir = $tempRoot . DIRECTORY_SEPARATOR . 'private';
    if (!mkdir($privateDir, 0700, true)) throw new RuntimeException('Could not create private-storage fixture.');
    $file = $privateDir . DIRECTORY_SEPARATOR . 'backup-fixture.bin';
    file_put_contents($file, 'synthetic backup fixture');
    PrivateStorage::assertOutsideProjectRoot($privateDir);
    $assert(PrivateStorage::resolveExistingPrivateFile($file) === realpath($file), 'canonical regular file outside Project Root is accepted');
    $insideProject = MRNEON_PROJECT_ROOT . DIRECTORY_SEPARATOR . 'data';
    $expectRuntimeException(static fn() => PrivateStorage::assertOutsideProjectRoot($insideProject), 'Project Root containment guard');
    $insideTraversal = $insideProject . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'data';
    $expectRuntimeException(static fn() => PrivateStorage::assertOutsideProjectRoot($insideTraversal), 'canonical Project Root traversal guard');
    $symlink = $tempRoot . DIRECTORY_SEPARATOR . 'private-link';
    if (function_exists('symlink') && @symlink($privateDir, $symlink)) {
        $expectRuntimeException(static fn() => PrivateStorage::assertOutsideProjectRoot($symlink . DIRECTORY_SEPARATOR . 'backup-fixture.bin'), 'symlinked parent component guard');
    } else {
        $assert(true, 'symlink test is unsupported on this filesystem and is recorded as conditional');
    }
    putenv('MRNEON_BACKUP_DIR=' . $privateDir);
    $assert(PrivateStorage::ensureBackupDirectory() === realpath($privateDir), 'configured private backup directory resolves canonically');
} finally {
    if ($oldBackupDir === false) putenv('MRNEON_BACKUP_DIR'); else putenv('MRNEON_BACKUP_DIR=' . $oldBackupDir);
    foreach (glob($tempRoot . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_link($path) || is_file($path)) @unlink($path);
        elseif (is_dir($path)) { foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $child) @unlink($child); @rmdir($path); }
    }
    @rmdir($tempRoot);
}
$assert(str_contains((string) file_get_contents($root . '/.htaccess'), 'docs|reports|tests|tools'), 'Apache rules include private docs/report/test/tool directories');
$assert(str_contains((string) file_get_contents($root . '/docs/SECURITY-AND-DEPLOYMENT.md'), 'BLOCKED_PENDING_HOST_VERIFICATION'), 'deployment docs keep real DocumentRoot proof blocked');
$assert(str_contains((string) file_get_contents($root . '/docs/SECURITY-AND-DEPLOYMENT.md'), '`.htaccess` is Apache-only'), 'Nginx equivalence and limitation are documented');

// P6: the CLI tool has no implicit retention policy and cannot apply without explicit gates.
$cleanupCli = $root . '/bin/cleanup-expired-sessions.php';
$cleanupText = file_get_contents($cleanupCli);
$assert(is_string($cleanupText) && strpos($cleanupText, "PHP_SAPI !== 'cli'") < strpos($cleanupText, "require_once dirname(__DIR__)"), 'cleanup tool rejects HTTP before loading config/bootstrap');
$sessionRepoSource = file_get_contents($root . '/app/repositories/AuthSessionRepository.php');
$assert(is_string($sessionRepoSource) && str_contains($sessionRepoSource, 'expires_at <= UTC_TIMESTAMP(6)') && str_contains($sessionRepoSource, 'created_at <= ?'), 'cleanup selects only expired rows older than explicit owner cutoff');
$assert(is_string($sessionRepoSource) && str_contains($sessionRepoSource, 'ROW_NUMBER() OVER (PARTITION BY principal_type, principal_id') && str_contains($sessionRepoSource, 'history_rank > 20'), 'session cleanup preserves the 20 most recent history rows shown by Admin');
$assert(is_string($cleanupText) && str_contains($cleanupText, 'MRNEON_ALLOW_SESSION_CLEANUP') && str_contains($cleanupText, '--confirm='), 'cleanup apply requires independent environment and exact confirmation gates');
$assert(is_string($cleanupText) && strpos($cleanupText, "BLOCKED: confirmation phrase") < strpos($cleanupText, 'Database::connection()'), 'cleanup validates apply gates before opening database connection');
$assert(is_string($cleanupText) && str_contains($cleanupText, 'createFromFormat') && str_contains($cleanupText, 'not in the future'), 'cleanup requires a real non-future UTC cutoff');
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cleanupCli);
$output = [];
exec($command . ' 2>&1', $output, $exitCode);
$assert($exitCode === 0 && str_contains(implode("\n", $output), 'DRY-RUN ONLY') , 'cleanup command defaults to dry-run without selecting/deleting records');
$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cleanupCli) . ' --apply 2>&1', $output, $exitCode);
$assert($exitCode === 78 && str_contains(implode("\n", $output), 'owner-selected'), 'cleanup apply without explicit retention cutoff is blocked before DB connection');

// P7/P8/P9 and Admin modal static acceptance.
$smtpConfig = file_get_contents($root . '/app/integrations/Email/SmtpConfiguration.php');
$smtpClient = file_get_contents($root . '/app/integrations/Email/NativeSmtpClient.php');
$assert(is_string($smtpConfig) && str_contains($smtpConfig, 'MRNEON_EMAIL_HELO_NAME') && str_contains($smtpConfig, 'isValidHeloName'), 'SMTP EHLO identity is explicit and validated from Environment');
$assert(is_string($smtpClient) && substr_count($smtpClient, "'EHLO ' . \$config->heloName") === 2 && !str_contains($smtpClient, 'mrneon.invalid'), 'same explicit EHLO identity is used before and after STARTTLS');
$webhookCli = file_get_contents($root . '/bin/telegram-webhook-manage.php');
$assert(is_string($webhookCli) && strpos($webhookCli, "PHP_SAPI !== 'cli'") < strpos($webhookCli, 'require_once'), 'webhook manager rejects HTTP before any require');
$projectMessages = file_get_contents($root . '/app/repositories/ProjectMessageRepository.php');
$internalNotes = file_get_contents($root . '/app/repositories/InternalNoteRepository.php');
$assert(is_string($projectMessages) && str_contains($projectMessages, '$this->utf8Length($body) > 2000'), 'ProjectMessage repository enforces 2000 Unicode code points');
$assert(is_string($internalNotes) && substr_count($internalNotes, '$this->utf8Length($body) > 2000') === 2, 'InternalNote create/update enforce 2000 Unicode code points');
$legacyInspector = file_get_contents($root . '/app/support/LegacyJsonSourceInspector.php');
$assert(is_string($legacyInspector) && str_contains($legacyInspector, "'password_resets.json' => 'NOT_IMPORTED_SECURITY_TRANSIENT_RESET_TOKENS'"), 'password reset tokens are classified as transient security data');
$assert(is_string($legacyInspector) && !str_contains(substr($legacyInspector, strpos($legacyInspector, 'public const MISSING_SOURCES')), "'password_resets.json'"), 'password reset tokens are not reported as a missing import source');
$adminFragment = file_get_contents($root . '/components/admin-workspace.html');
$adminJsSource = file_get_contents($root . '/js/admin.js');
$adminCss = file_get_contents($root . '/css/components.css');
$assert(is_string($adminFragment) && preg_match('/id="adminDataModal"[\\s\\S]{0,80}hidden/', $adminFragment) === 1, 'Admin data modal starts hidden in the HTML contract');
$assert(is_string($adminJsSource) && str_contains($adminJsSource, 'ADMIN_DOM.adminDataModal.hidden = false') && str_contains($adminJsSource, 'ADMIN_DOM.adminDataModal.hidden = true'), 'Admin UI synchronizes native hidden state when opening and closing');
$assert(is_string($adminCss) && str_contains($adminCss, '.admin-data-modal[hidden]') && str_contains($adminCss, 'display: none !important'), 'Admin data modal CSS makes hidden state authoritative');

$wave8 = file_get_contents($root . '/MR-NEON-CONTINUATION-LEDGER.md');
$assert(is_string($wave8), 'continuation ledger remains available for Wave 8 handoff');

echo 'PASS: ' . $checks . " pre-Wave8 unit/static assertions; no DB/provider/HTTP cleanup executed.\n";
