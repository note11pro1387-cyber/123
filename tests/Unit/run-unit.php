<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/bin/_bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Unit check failed: ' . $label);
};

$assert(class_exists(ChallengeStateMachine::class), 'CLI bootstrap autoloads the challenge lifecycle state machine');

$assert(LegacyUserMapper::canonicalIranianMobile('09121234567') === '+989121234567', 'Iranian local phone normalization');
$assert(LegacyUserMapper::canonicalIranianMobile('+989121234567') === '+989121234567', 'Iranian canonical phone acceptance');
$assert(LegacyUserMapper::canonicalIranianMobile('۰۹۱۲۱۲۳۴۵۶۷') === '+989121234567', 'Persian digit normalization');
$assert(LegacyUserMapper::canonicalIranianMobile('12345') === null, 'invalid phone rejected');
$assert(LegacyUserMapper::legacyLocalTimeToUtc('2026-10-12 00:00:00') === '2026-10-11 20:30:00.000000', 'legacy Tehran local time converted to UTC');
$remoteDatabaseConfig = Database::configuration(['host' => 'db.example.invalid']);
$assert($remoteDatabaseConfig['require_tls'] === true, 'remote database defaults to verified TLS requirement');
$localDatabaseConfig = Database::configuration(['host' => '127.0.0.1']);
$assert($localDatabaseConfig['require_tls'] === false, 'loopback development database does not force TLS by default');

$synthetic = [
    'id' => 'SYNTHETIC-USER-01',
    'fullName' => 'Synthetic Wave Two User',
    'phone' => '09121234567',
    'email' => 'wave2-unit@example.com',
    'telegramId' => '@SyntheticWaveTwoBot',
    'username' => 'Synthetic_User01',
    'password' => password_hash('SYNTHETIC TEST PASSWORD ONLY', PASSWORD_DEFAULT),
    'verified' => true,
    'createdAt' => '2026-10-12 00:00:00',
    'lastLogin' => '2026-10-12 01:00:00',
    'futureLegacyField' => ['fixture' => 'SYNTHETIC TEST DATA'],
];
$mapper = new LegacyUserMapper();
$mappedResult = $mapper->map($synthetic);
$assert($mappedResult['mapped'] !== null && $mappedResult['errors'] === [], 'valid synthetic record maps');
$mapped = $mappedResult['mapped'];
$assert($mapped['phone'] === '+989121234567', 'mapped phone canonical');
$assert($mapped['account_status'] === 'pending' && $mapped['legacy_verified'] === 1, 'legacy verification does not auto-activate account');
$assert($mapped['legacy_telegram_username'] === 'SyntheticWaveTwoBot', 'legacy Telegram handle preserved as unverified metadata');
$assert(is_string($mapped['legacy_extra_json']) && str_contains($mapped['legacy_extra_json'], 'futureLegacyField'), 'unmapped source fields preserved in restricted metadata');
$assert(!str_contains($mapped['legacy_source_hash'], $synthetic['password']), 'source fingerprint does not contain password hash literal');
$reordered = array_reverse($synthetic, true);
$mappedAgain = $mapper->map($reordered)['mapped'];
$assert($mappedAgain !== null && hash_equals($mapped['legacy_source_hash'], $mappedAgain['legacy_source_hash']), 'source fingerprint is stable under key reordering');
$bad = $synthetic;
$bad['phone'] = 'not-a-phone';
$assert($mapper->map($bad)['mapped'] === null, 'invalid phone marks record invalid');
$bad = $synthetic;
$bad['password'] = 'not-a-password-hash';
$assert($mapper->map($bad)['mapped'] === null, 'plaintext password is not accepted as a stored hash');
$bad = $synthetic;
$bad['createdAt'] = 'not-a-date';
$assert($mapper->map($bad)['mapped'] === null, 'invalid timestamp marks record invalid');
$bad = $synthetic;
$bad['futureLegacyField'] = ['recoveryToken' => 'SYNTHETIC SECRET VALUE'];
$badResult = $mapper->map($bad);
$assert($badResult['mapped'] === null && in_array('unknown_sensitive_field_requires_manual_review', $badResult['errors'], true), 'unknown sensitive source fields are held for manual review');

$syntheticDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mrneon-wave2-unit-' . bin2hex(random_bytes(4));
if (!mkdir($syntheticDir, 0700, true)) throw new RuntimeException('Could not create temporary synthetic fixture directory.');
try {
    $sourceFile = $syntheticDir . DIRECTORY_SEPARATOR . 'users.json';
    $fixtureJson = json_encode([$synthetic], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents($sourceFile, $fixtureJson);
    $beforeHash = hash_file('sha256', $sourceFile);
    $importer = new LegacyJsonImportService(null, $syntheticDir);
    $dryReport = $importer->run(false, false);
    $assert(($dryReport['status'] ?? '') === 'DRY_RUN_READY' && ($dryReport['source_counts']['valid'] ?? 0) === 1, 'source-only importer dry-run maps valid synthetic user');
    $assert(hash_equals((string) $beforeHash, (string) hash_file('sha256', $sourceFile)), 'dry-run does not mutate source JSON');
    file_put_contents($sourceFile, '{malformed-json');
    $badJsonReport = $importer->run(false, false);
    $assert(($badJsonReport['status'] ?? '') === 'BLOCKED_INVALID_JSON', 'malformed JSON is blocked without fallback to an empty list');
    file_put_contents($sourceFile, json_encode([$synthetic, $synthetic], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $duplicateReport = $importer->run(false, false);
    $assert(($duplicateReport['status'] ?? '') === 'BLOCKED_SOURCE_DATA_QUALITY' && ($duplicateReport['source_counts']['duplicate_in_source'] ?? 0) === 1, 'duplicate source accounts are reported, not silently merged');
    file_put_contents($sourceFile, json_encode([array_merge($synthetic, ['phone' => 'not-a-number'])], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $invalidReport = $importer->run(false, false);
    $assert(($invalidReport['status'] ?? '') === 'BLOCKED_SOURCE_DATA_QUALITY' && ($invalidReport['source_counts']['invalid'] ?? 0) === 1, 'invalid legacy record is explicitly counted and blocks import');
    file_put_contents($sourceFile, $fixtureJson);
    $policyBlockedReport = $importer->run(true, true, false);
    $assert(($policyBlockedReport['status'] ?? '') === 'BLOCKED_LEGACY_ACCOUNT_POLICY_ACK_REQUIRED', 'apply requires explicit acknowledgement to import legacy accounts as pending');
    $dbBlockedReport = $importer->run(true, true, true);
    $assert(($dbBlockedReport['status'] ?? '') === 'BLOCKED_DB_UNAVAILABLE', 'approved apply remains blocked without a real database connection');
} finally {
    foreach (glob($syntheticDir . DIRECTORY_SEPARATOR . '*') ?: [] as $fixturePath) @unlink($fixturePath);
    @rmdir($syntheticDir);
}

$migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);
$assert(count($files) === 18, 'expected 18 versioned migrations through W4-P3 plus pending delivery state');
$catalog = SchemaCatalog::tables();
$assert(count($catalog) === 17 && count(array_unique($catalog)) === 17, 'schema catalog has 17 unique application tables');
$parsedTables = [];
foreach ($files as $file) {
    $sql = file_get_contents($file);
    $assert(is_string($sql), 'migration readable: ' . basename($file));
    if (basename($file) === '018_add_pending_delivery_challenge_state.sql') {
        $assert(preg_match('/^\s*ALTER\s+TABLE\s+verification_challenges\s+MODIFY\s+COLUMN\s+state\s+ENUM/i', $sql) === 1, 'migration 018 is additive enum alteration');
        $assert(str_contains($sql, "'pending_delivery'") && str_contains($sql, "'active'") && str_contains($sql, "'verified_reset'"), 'migration 018 preserves existing challenge states and adds pending');
    } else {
        $assert(preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?/i', $sql, $match) === 1, 'migration has a single CREATE TABLE root: ' . basename($file));
        $parsedTables[] = strtolower($match[1]);
    }
    $assert(substr_count($sql, ';') === 1 && str_ends_with(trim($sql), ';'), 'migration contains one SQL statement: ' . basename($file));
}
$assert($parsedTables === $catalog, 'migration order matches schema catalog and restore dependencies');
$challengeSql = file_get_contents($migrationsDir . '/005_create_verification_challenges.sql');
$assert(is_string($challengeSql) && str_contains($challengeSql, 'recovery_token_hash') && str_contains($challengeSql, "'verified_reset'"), 'recovery authorization remains purpose-scoped on challenge');
$assert(!is_dir(dirname(__DIR__, 2) . '/database/backups'), 'backup output is not configured inside migration source');

echo 'PASS: ' . $checks . " unit/static contract checks; SYNTHETIC TEST DATA only.\n";
