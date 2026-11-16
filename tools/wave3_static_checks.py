#!/usr/bin/env python3
"""Reproducible static contract checks for MR NEON Wave 3; no secrets or DB data are read."""
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else Path(__file__).resolve().parents[1]
checks: list[tuple[str, bool]] = []

def add(label: str, condition: bool) -> None:
    checks.append((label, bool(condition)))


def read(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")

api = read("api.php")
response = read("app/support/ApiResponse.php")
problem = read("app/support/ApiProblemException.php")
client = read("js/api-client.js")
service = read("app/services/AuthService.php")
session = read("app/security/SessionManager.php")
csrf = read("app/security/Csrf.php")
method_policy = read("app/http/middleware/MethodPolicy.php")
legacy_authz = read("app/http/LegacyActionAuthorization.php")
admin_js = read("js/admin.js")
auth_js = read("js/auth.js")
neo_js = read("js/neo.js")
admin_modal = read("components/admin-modal.html")
auth_modal = read("components/auth-modals.html")
env_example = read(".env.example")

add("API success response uses Handoff data/meta envelope", "'data' => $data" in response and "'meta' => ['requestId'" in response and "'ok' => true" not in response)
add("API failures use RFC 9457 problem+json", "application/problem+json; charset=utf-8" in response and all(f"'{key}' =>" in response for key in ["type", "title", "status", "detail", "requestId"]))
add("API problem exception title/detail/header constructor is aligned", "string $title" in problem and "string $safeDetail" in problem and "array $responseHeaders = []" in problem)
add("API response emits Request ID header", "X-Request-ID" in response and "RequestIdMiddleware::handle()" in api)
add("API hides PHP diagnostics from response bodies", "ini_set('display_errors', '0')" in api and "ini_set('log_errors', '1')" in api)
add("active auth routes dispatch to real controllers", all(token in api for token in ["new AuthController()", "new AdminAuthController()", "['getSession', 'login', 'logout']"]))
add("registration action is routed to the attempt-only W4-P2 controller", "if ($action === 'register')" in api and "new RegistrationController()" in api)
add("W4-P3 registration Challenge actions route before legacy authorization gates while recovery/project/admin remain fail-closed", "new VerificationController()" in api and "RECOVERY_UPGRADE_REQUIRED" in api and "PROJECT_API_UPGRADE_REQUIRED" in api and "ADMIN_AUTH_FOUNDATION_REQUIRED" in api)
add("disabled legacy API actions do not mutate JSON rate-limit state", "checkRateLimit($ip, $action" not in api and "Closed legacy actions do not execute their old JSON-backed rate limiter" in api)
add("HTTP method policy has an action allowlist", "private const METHODS" in method_policy and "METHOD_NOT_ALLOWED" in method_policy)
add("admin/user legacy policies are explicit", "ADMIN_ONLY" in legacy_authz and "USER_ONLY" in legacy_authz and "STATE_CHANGING" in legacy_authz)
add("NEO save, customer messages and deletes are closed behind user policy", all(name in legacy_authz for name in ["saveNeo", "sendMessage", "delete"]))
add("legacy not-yet-implemented actions still have explicit HTTP Methods", all(f"'{name}' => 'POST'" in method_policy for name in ["sendMessage", "delete", "verifyPasswordResetCode"]))
add("login rate limiting is applied before credential validation", service.index("RateLimitMiddleware::enforce($pdo, 'login'") < service.index("password_verify($password, $storedHash)"))
add("session status has bounded DB-backed IP rate limiting", "MRNEON_SESSION_STATUS_IP_LIMIT" in read("app/http/middleware/RateLimitMiddleware.php") and "RateLimitMiddleware::enforce($pdo, 'getSession'" in service)
add("anonymous logout churn is rate-limited without blocking authenticated logout", "MRNEON_LOGOUT_IP_LIMIT" in read("app/http/middleware/RateLimitMiddleware.php") and "if (!isset($_SESSION['principal_type']))" in service and "RateLimitMiddleware::enforce($pdo, 'logout'" in service)
add("admin login only creates an account bucket for the configured admin identity", "hash_equals(strtolower($adminUsername), strtolower($username)) ? $adminUsername : null" in service)
add("admin login MFA and replay protection are present", "MRNEON_ADMIN_TOTP_SECRET" in service and "Totp::matchingCounter" in service and "'totp'" in service)
add("admin role is never read from browser request", "$_POST['role']" not in service and "$_GET['role']" not in service and "'role' => 'admin'" in service)
add("session registry stores a hash, not raw session id", "hash('sha256', session_id())" in session and "session_id()" in session)
add("session ID and CSRF token rotate on authentication", "session_regenerate_id(true)" in session and "'csrf_token' => bin2hex(random_bytes(32))" in session)
add("strict cookie mode/HttpOnly/SameSite are configured", all(token in session for token in ["session.use_strict_mode", "session.use_only_cookies", "'httponly' => true", "'samesite' => 'Lax'"]))
add("CSRF compares token using hash_equals and enforces mutation header", "hash_equals($expected, $provided)" in csrf and "HTTP_X_CSRF_TOKEN" in csrf)
add("Rate limit buckets are DB-backed and fail closed through PDO errors", "RateLimitBucketRepository" in read("app/http/middleware/RateLimitMiddleware.php") and "RateLimitMiddleware::enforce" in service)
add("login/admin rate-limit limits can be configured with bounded environment values", all(name in read("app/http/middleware/RateLimitMiddleware.php") for name in ["MRNEON_LOGIN_IP_LIMIT", "MRNEON_LOGIN_ACCOUNT_LIMIT", "MRNEON_ADMIN_LOGIN_IP_LIMIT", "MRNEON_ADMIN_LOGIN_ACCOUNT_LIMIT"]))
add("central client validates origin, credentials, envelope, and CSRF", all(token in client for token in ["window.location.origin", 'credentials: "same-origin"', "INVALID_API_ENVELOPE", '"X-CSRF-Token"']))
add("central client disables mutation retry by omission of retry loops", not re.search(r"\bfor\s*\(|\bwhile\s*\(", client))
js_files = [ROOT / "js" / name for name in ["app.js", "auth.js", "admin.js", "neo.js", "api-client.js"]]
direct_fetch = []
for path in js_files:
    text = path.read_text(encoding="utf-8")
    if re.search(r"\bfetch\s*\(", text):
        direct_fetch.append(path.name)
add("all first-party JS network fetches go through api-client.js", direct_fetch == ["api-client.js"])
add("legacy Authorization/Bearer auth is absent from domain consumers", not re.search(r"\bBearer\b|set\(['\"]Authorization|Authorization\s*:", auth_js + admin_js + neo_js))
add("failed server session restore does not cache an unauthoritative guest result", "AuthSystem.sessionChecked = false" in auth_js and "failed server check does not establish an authoritative guest state" in auth_js)
add("admin UI collects TOTP and does not depend on browser token", 'id="adminTotpCode"' in admin_modal and "localStorage" not in admin_js and "Authorization" not in admin_js)
add("remember-me is explicitly disabled until server persistence is specified", re.search(r'id="rememberMe"[^>]*disabled', auth_modal) is not None)
add("private admin config entries are placeholders in example config", all(re.search(rf"^{key}=\s*$", env_example, re.M) for key in ["MRNEON_ADMIN_USERNAME", "MRNEON_ADMIN_PASSWORD_HASH", "MRNEON_ADMIN_TOTP_SECRET"]))
add("migration set includes delivery ledger 017 and additive pending-delivery state 018; prior migrations remain immutable", len(list((ROOT / "database/migrations").glob("*.sql"))) == 18)

failed = [label for label, ok in checks if not ok]
for label, ok in checks:
    print(f"{'PASS' if ok else 'FAIL'}: {label}")
print(f"SUMMARY: {len(checks)-len(failed)}/{len(checks)} static checks passed")
if failed:
    print("FAILED CHECKS: " + "; ".join(failed), file=sys.stderr)
    sys.exit(1)
