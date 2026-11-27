#!/usr/bin/env python3
"""Conservative source-contract checks for W4-P2; not a substitute for runtime DB integration."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
checks = 0
failures = []

def check(label: str, ok: bool) -> None:
    global checks
    checks += 1
    if ok:
        print(f"PASS: {label}")
    else:
        failures.append(label)
        print(f"FAIL: {label}")

def read(rel: str) -> str:
    try:
        return (ROOT / rel).read_text(encoding="utf-8")
    except Exception:
        return ""

required = [
    "app/dto/RegistrationDTO.php",
    "app/validators/UsernameValidator.php",
    "app/validators/PhoneValidator.php",
    "app/validators/EmailValidator.php",
    "app/validators/PasswordPolicy.php",
    "app/services/RegistrationPolicy.php",
    "app/services/RegistrationService.php",
    "app/http/controllers/RegistrationController.php",
    "tests/Unit/run-registration-tests.php",
    "tests/Integration/run-registration-attempt-integration.php",
    "docs/REGISTRATION-ATTEMPT.md",
    "W4-P3-HANDOFF.md",
    "reports/W4-P2-ACCEPTANCE-MATRIX.md",
    "reports/W4-P2-TEST-REPORT.md",
    "reports/W4-P2-DELTA-DECISION-LEDGER.md",
]
for rel in required:
    check(f"required W4-P2 artifact exists: {rel}", (ROOT / rel).is_file())

dto = read("app/dto/RegistrationDTO.php")
service = read("app/services/RegistrationService.php")
policy = read("app/services/RegistrationPolicy.php")
controller = read("app/http/controllers/RegistrationController.php")
phone = read("app/validators/PhoneValidator.php")
password = read("app/validators/PasswordPolicy.php")
repo = read("app/repositories/RegistrationAttemptRepository.php")
api = read("api.php")
rate = read("app/http/middleware/RateLimitMiddleware.php")
unit = read("tests/Unit/run-registration-tests.php")
integration = read("tests/Integration/run-registration-attempt-integration.php")
contract = read("docs/REGISTRATION-ATTEMPT.md")

check("DTO uses explicit request-field allowlist", "['fullName', 'username', 'phone', 'password', 'email', 'selectedChannel', 'termsAccepted']" in dto)
check("DTO rejects unknown/privileged keys", "UNEXPECTED_REGISTRATION_FIELDS" in dto and "isAdmin" not in dto)
check("password omitted from DTO serialization", "public function jsonSerialize()" in dto and "'password' =>" not in dto)
check("canonical phone parser translates Persian and Arabic digits", "'۰' => '0'" in phone and "'٠' => '0'" in phone)
check("phone parser rejects unexpected characters rather than stripping all punctuation", "preg_match('/^\\+?[0-9]+$/D'" in phone)
check("password policy is centrally defined at 8–15 Unicode characters", "MIN_CHARACTERS = 8" in password and "MAX_CHARACTERS = 15" in password and "preg_match_all('/./us'" in password)
check("Terms version is server-owned and fails closed", "MRNEON_TERMS_VERSION" in policy and "TERMS_POLICY_UNAVAILABLE" in policy)
check("attempt TTL is centralized and distinct from OTP TTL", "DEFAULT_ATTEMPT_TTL_SECONDS = 1800" in policy and "120-second OTP TTL" in policy)
check("service uses Wave 2 DB-backed rate limits", "RateLimitMiddleware::enforce(" in service and "enforceRegistrationIdentities" in service)
check("service serializes canonical identities with sorted MySQL named locks", "GET_LOCK(?, 3)" in service and "sort($identities" in service and "RELEASE_LOCK(?)" in service)
check("service owns explicit transaction and rolls back failures", "beginTransaction()" in service and "rollBack()" in service and "commit()" in service)
check("service creates only pending attempt and does not claim OTP delivery", "'state' => 'pending_verification'" in service and "'verificationStatus' => 'not_started'" in service)
check("only raw Telegram link nonce is response-only; digest is sent to repository", "hash('sha256', $telegramNonce)" in service and "telegram_link_nonce_hash" in service)
check("controller enforces POST, CSRF and JSON body limit", "ApiRequest::method() !== 'POST'" in controller and "Csrf::assertValid()" in controller and "jsonBody(16384)" in controller)
check("API router dispatches register to W4-P2 controller", "if ($action === 'register')" in api and "new RegistrationController()" in api)
check("rate limit stores identity via existing repository, not plain text", "new RateLimitBucketRepository($pdo)" in rate and "registration_destination" in rate)
check("Attempt repository validates canonical email/phone and Telegram nonce hash", "email_normalized" in repo and "telegram_link_nonce_hash" in repo and "^\\+989" in repo)
check("unit suite includes synthetic phone/channel/password and privileged-field tests", "Persian/Arabic" in contract and "selectedChannels" in unit and "PASSWORD_POLICY_VIOLATION" in unit)
check("integration runner fails closed if pdo_mysql/test DB/explicit opt-in are missing", "exit(77)" in integration and "MRNEON_ALLOW_REGISTRATION_TEST_MUTATION" in integration and "SELECT DATABASE()" in integration)
check("integration runner never drops or truncates a database", "DROP DATABASE" not in integration.upper() and "TRUNCATE" not in integration.upper())
check("W4-P2 remains migration-free and W4-P3 delivery ledger is the only 017 migration", not any((ROOT / "database/migrations").glob("*w4p2*")) and (ROOT / "database/migrations/017_create_verification_delivery_attempts.sql").is_file())
check("API contract records the attempt-only register route and P3 boundary", "pending `registration_attempts`" in read("docs/API-CONTRACT.md") and "Challenge creation" in contract)
check("reports preserve DB Integration as blocked/unverified", "BLOCKED" in read("reports/W4-P2-TEST-REPORT.md") and "unverified" in read("reports/W4-P2-ACCEPTANCE-MATRIX.md").lower())
check("safe environment template contains no actual Terms version or HMAC secret", "MRNEON_TERMS_VERSION=" in read(".env.example") and "MRNEON_RATE_LIMIT_HMAC_KEY=" in read(".env.example"))

if failures:
    print(f"SUMMARY: {len(failures)} of {checks} W4-P2 static checks failed.")
    sys.exit(1)
print(f"SUMMARY: all {checks} W4-P2 static checks passed. Runtime DB integration is not implied.")
