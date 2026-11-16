#!/usr/bin/env python3
"""W4-P5 static contract checks; no database or external Provider is contacted."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
checks = 0
failures = []

def check(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if condition:
        print(f"PASS W4-P5 Static #{checks}: {label}")
    else:
        failures.append(label)
        print(f"FAIL W4-P5 Static #{checks}: {label}")

def read(path: str) -> str:
    try:
        return (ROOT / path).read_text(encoding="utf-8")
    except OSError:
        return ""

service = read("app/services/RecoveryService.php")
controller = read("app/http/controllers/RecoveryController.php")
challenges = read("app/repositories/VerificationChallengeRepository.php")
users = read("app/repositories/UserRepository.php")
sessions = read("app/repositories/AuthSessionRepository.php")
policy = read("app/services/RecoveryPolicy.php")
password_policy = read("app/validators/PasswordPolicy.php")
otp_verifier = read("app/security/OtpVerifier.php")
token_gen = read("app/security/ResetTokenGenerator.php")
route = read("api.php")
legacy = read("core/auth-core.php")
js = read("js/auth.js")
modal = read("components/auth-modals.html")
schema = read("database/migrations/005_create_verification_challenges.sql")
unit = read("tests/Unit/run-w4p5-unit.php")
integration = read("tests/Integration/run-w4p5-recovery-integration.php")

for path in [
    "app/services/RecoveryService.php", "app/http/controllers/RecoveryController.php",
    "app/services/RecoveryPolicy.php", "app/security/ResetTokenGenerator.php",
    "tests/Unit/run-w4p5-unit.php", "tests/Integration/run-w4p5-recovery-integration.php",
    "docs/PASSWORD-RECOVERY.md", "docs/RESET-ONLY-AUTHORIZATION.md", "docs/SESSION-REVOCATION.md",
    "reports/W4-P5-ACCEPTANCE-MATRIX.md", "reports/W4-P5-TEST-REPORT.md",
    "reports/W4-P5-DELTA-DECISION-LEDGER.md", "W4-P5-SUMMARY.md", "W4-P5-CHANGED-FILES.md", "W4-P6-HANDOFF.md",
]:
    check((ROOT / path).is_file(), f"required W4-P5 artifact exists: {path}")

check("password_reset" in schema and "recovery_token_hash" in schema and "recovery_expires_at" in schema and "recovery_used_at" in schema, "existing challenge schema supports password-reset purpose and reset-only fields")
check("recovery_token_hash" in schema and "UNIQUE KEY uq_challenges_recovery_token_hash" in schema, "reset capability hash has a database unique constraint")
check("MigrationRunner" not in service and "CREATE TABLE" not in service.upper() and not any((ROOT / "database/migrations").glob("018*")), "no unnecessary Recovery migration or runtime DDL was introduced")
check("public function startRecovery" in service and "public function verifyCode" in service and "public function resetPassword" in service, "Recovery has separate start, verify and reset service operations")
check("password_reset" in service and "lockVerifiedRecoveryChannel" in service and "findVerifiedRecoveryTarget" in service, "Challenge Purpose and target channel are resolved and revalidated server-side")
check("GENERIC_START_MESSAGE" in policy and "genericStartResponse" in service and "'challengeRef' => $reference" in service, "start response is generic and uses a pseudonymous reference, not a database Challenge ID")
check("START_FLOOR_MICROSECONDS = 1000000" in service, "generic start applies a minimum response floor while documenting it is not constant-time")
check("RateLimitMiddleware::enforceRecoveryStart" in service and "enforceRecoveryVerify" in service and "enforceRecoveryReset" in service, "all three Recovery stages use existing DB-backed rate limiting")
check("hash_hkdf" in otp_verifier and "recovery-reference:v1" in otp_verifier and "hash_equals" in otp_verifier, "challenge reference is keyed, versioned and pseudonymous")
check("random_bytes(self::TOKEN_BYTES)" in token_gen and "hash('sha256', $token)" in token_gen and "recovery_token_hash" in challenges, "reset-only capability uses 256-bit randomness and stores only SHA-256 hash")
check("DEFAULT_RESET_AUTHORIZATION_SECONDS = 600" in policy and "OTP_TTL_SECONDS" in policy, "reset-only TTL is independent from fixed OTP TTL")
check("UPDATE verification_challenges SET state = 'verified_reset'" in challenges and "recovery_used_at = NULL" in challenges, "OTP verification creates reset-only state atomically")
check("UPDATE verification_challenges SET state = 'used'" in challenges and "recovery_used_at IS NULL" in challenges and "recovery_expires_at > UTC_TIMESTAMP(6)" in challenges, "reset-only token is expiry-checked and single-use via conditional update")
check("PasswordPolicy::validate" in service and "PasswordPolicy::hash" in service, "reset reuses central Password Policy and standard password hashing")
check("revokeAllForUser($userId, 'password_reset')" in service and "auth_sessions" in sessions and "revoked_at IS NULL" in sessions, "all registered sessions are revoked inside password-reset transaction")
check("beginTransaction()" in service and "commit()" in service and "rollBack()" in service, "service owns explicit transactions and rollback paths")
check("LOCK_WAIT_SECONDS" in service and "GET_LOCK" in service and "RELEASE_LOCK" in service, "identity/user concurrency is serialized with bounded MySQL named locks")
check("HTTP_X_RESET_AUTHORIZATION" in controller and "X-Reset-Authorization" in js, "reset-only token travels in a dedicated request header")
check("$_GET['resetToken']" not in controller and "localStorage" not in js[js.find("export class ForgotPasswordSystem"):], "Reset-only token is not read from URL or stored in browser storage")
check("authenticated' => false" in service and "sessionEstablished' => false" in service and "SessionManager::establishWithinTransaction" not in controller, "successful reset is not login and does not mint a normal session")
check("RECOVERY_FLOW_UPDATED" in controller and "RECOVERY_FLOW_UPDATED" in legacy, "legacy combined OTP/password handler is fail-closed")
check("RecoveryController())->dispatch($action)" in route and "'resetPassword' => 'POST'" in read("app/http/middleware/MethodPolicy.php"), "all Recovery actions route through controller and reset is POST-only")
check("findVerifiedRecoveryTarget" in users and "c.is_verified = 1" in users and "provider_subject_id" in users and "hash_equals($destination, (string) $row['provider_subject_id'])" in users and "c.provider_subject_id = c.channel_value" in users, "only verified Email/Telegram channel bindings with consistent private Telegram IDs can recover an active account")
check("!in_array($method, ['email', 'telegram'], true)" in service and "RecoveryService::normalizeIdentifier('sms'" in unit, "SMS is not an accepted Recovery method")
check("X-Reset-Authorization" in js and "this.resetAuthorizationToken = token" in js and "clearSensitiveFlow" in js, "UI keeps reset-only token in memory and clears it on flow completion/close")
check("minimum 15" in modal.lower() or "حداقل ۱۵ کاراکتر" in modal, "Recovery UI communicates the central 15-character password policy")
check("MRNEON_ALLOW_W4P5_TEST_MUTATION" in integration and "pdo_mysql is unavailable" in integration and "SELECT DATABASE()" in integration, "DB integration test is opt-in, local-test-schema guarded and confirms database identity")
check("DROP DATABASE" not in integration.upper() and "TRUNCATE " not in integration.upper(), "integration test performs no database drop or truncate")
check("PASS W4-P5 Unit" in unit and "OTP خام" not in service, "dedicated unit test exists and service does not emit raw OTP through explicit logging")

if failures:
    print(f"W4-P5 Static Checks: {len(failures)}/{checks} failed", file=sys.stderr)
    sys.exit(1)
print(f"W4-P5 Static Checks: {checks}/{checks} passed. This does not imply database/provider integration.")
