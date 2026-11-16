#!/usr/bin/env python3
"""Fail-fast source contract checks for MR NEON W4-P3; not a database Integration substitute."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
checks = 0

def check(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if not condition:
        raise SystemExit(f"FAIL W4-P3 Static [{checks}]: {label}")

service = (ROOT / "app/services/VerificationService.php").read_text(encoding="utf-8")
completion = (ROOT / "app/services/RegistrationCompletionService.php").read_text(encoding="utf-8")
verifier = (ROOT / "app/security/OtpVerifier.php").read_text(encoding="utf-8")
policy = (ROOT / "app/services/VerificationPolicy.php").read_text(encoding="utf-8")
legacy = (ROOT / "core/auth-core.php").read_text(encoding="utf-8")
api = (ROOT / "api.php").read_text(encoding="utf-8")
controller = (ROOT / "app/http/controllers/VerificationController.php").read_text(encoding="utf-8")
delivery = (ROOT / "app/integrations/Verification/VerificationDelivery.php").read_text(encoding="utf-8")
transport = (ROOT / "app/integrations/Verification/UnavailableVerificationTransport.php").read_text(encoding="utf-8")
ledger = (ROOT / "database/migrations/017_create_verification_delivery_attempts.sql").read_text(encoding="utf-8")
unit = (ROOT / "tests/Unit/run-w4p3-unit.php").read_text(encoding="utf-8")
integration = (ROOT / "tests/Integration/run-w4p3-otp-integration.php").read_text(encoding="utf-8")
schema_catalog = (ROOT / "app/support/SchemaCatalog.php").read_text(encoding="utf-8")

check("random_int(0, 999999)" in (ROOT / "app/security/OtpGenerator.php").read_text(encoding="utf-8"), "OTP uses cryptographically secure random_int")
check("OTP_TTL_SECONDS = 120" in policy, "TTL is fixed at 120 seconds")
check("hash_hmac('sha256'" in verifier and "hash_equals(" in verifier, "OTP uses context HMAC and timing-safe comparison")
check("MRNEON_OTP_HMAC_KEY" in verifier and "assertConfigured" in verifier, "dedicated OTP HMAC key is required")
check("$this->transport = $transport ?? new UnavailableVerificationTransport()" in service, "production default transport is unavailable/fail-closed")
check("VerificationDeliveryResult::ACCEPTED" in service and "activatePendingDelivery($challengeId, 'registration', $acceptedAt)" in service, "pending Challenge activates only after accepted provider result")
check("$this->recordNonAcceptedDelivery" in service and "createPendingDelivery" in service, "pending Challenge and prepared ledger persist before provider I/O")
check("hasPreparedForRegistration($attemptId)" in service, "prepared outcomes block blind retries")
check("($latest['state'] ?? null) === 'unknown'" in service, "unknown delivery outcome blocks blind initial/resend retry")
check("VERIFICATION_DESTINATION_CHANGED" in service, "accepted delivery is pinned to the same canonical destination")
check("resendRetryAfter($latestAttemptAt, $now)" in service, "resend cooldown is central and enforced")
check("FOR UPDATE" in (ROOT / "app/repositories/VerificationChallengeRepository.php").read_text(encoding="utf-8"), "challenge verification has row-lock read")
check("consumeOnce($challengeId)" in completion and "consumeOnce($attemptId, $userId)" in completion, "challenge and attempt consumption are in completion transaction")
check("$this->pdo->inTransaction()" in completion, "completion requires caller-owned transaction")
check("registered' => true" in completion and "authenticated' => false" in completion and "sessionEstablished' => false" in completion, "no automatic fake session is returned")
check("__serialize()" in delivery and "LogicException" in delivery, "raw delivery envelope cannot be native-serialized")
check("notConfigured()" in transport and "VerificationDeliveryResult::notConfigured()" in transport, "default provider result is not_configured")
check("sendVerificationCode" in api and "VerificationController" in api, "verification API actions route to the new controller")
check("SessionManager::start()" in controller and "Csrf::assertValid()" in controller, "verification endpoints enforce session and CSRF")
check("jsonBody(8192)" in controller, "verification request body has a strict size limit")
check("$expiresAt = time() + 600" not in legacy, "legacy ten-minute OTP expiry code is removed")
check("'error_code' => 'LEGACY_OTP_DISABLED'" in legacy and "return false;" in legacy, "legacy send/verify helpers fail closed")
check("LEGACY_OTP_DISABLED" in legacy and "function handleSendVerificationCode(): void" in legacy and "function handleResendVerificationCode(): void" in legacy, "legacy send/resend handlers explicitly fail closed")
check("CREATE TABLE verification_delivery_attempts" in ledger, "migration adds delivery result ledger")
check("otp_value" not in ledger.lower() and "raw_otp" not in ledger.lower() and "code_hmac" not in ledger.lower() and "result_code" in ledger.lower(), "delivery ledger stores allowlisted outcome code but no OTP/MAC column")
check("CURRENT_SCHEMA_VERSION = '018'" in schema_catalog, "schema catalog reflects current migration 018")
pending_migration = (ROOT / "database/migrations/018_add_pending_delivery_challenge_state.sql").read_text(encoding="utf-8")
challenge_repo = (ROOT / "app/repositories/VerificationChallengeRepository.php").read_text(encoding="utf-8")
check("pending_delivery" in pending_migration and "MODIFY COLUMN state ENUM" in pending_migration, "migration 018 adds non-consumable pending_delivery state")
check("createPendingDelivery" in challenge_repo and "activatePendingDelivery" in challenge_repo and "revokePendingDelivery" in challenge_repo, "challenge repository implements pending, accepted activation and definite failure paths")
check("MRNEON_ALLOW_W4P3_TEST_MUTATION" in integration and "SELECT DATABASE()" in integration, "integration test requires explicit disposable DB identity")
check("BLOCKED: pdo_mysql is unavailable" in integration, "integration test reports unavailable driver as blocked")
check("unknown prior delivery blocks blind initial-send retry" in integration, "integration runner includes unknown-outcome retry barrier")
check("LEGACY_OTP_DISABLED" in unit and "sendVerificationCode" in unit, "unit test covers disabled legacy OTP helper")
check("W4-P4" in (ROOT / "W4-P4-HANDOFF.md").read_text(encoding="utf-8"), "explicit W4-P4 handoff exists")

print(f"PASS W4-P3 Static: {checks} checks")
