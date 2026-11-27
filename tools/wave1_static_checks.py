#!/usr/bin/env python3
"""Reproducible, redacted Wave 1 static acceptance checks for the MR NEON source root."""
from __future__ import annotations

import re
import subprocess
import sys
import tempfile
from collections import Counter, defaultdict
from pathlib import Path


EXPECTED_LEGACY_ACTIONS = {
    "login",
    "forgotPassword",
    "verifyResetCode",
    "getProjects",
    "getProjectById",
    "updateProject",
    "deleteProject",
    "getNotifications",
    "markNotificationRead",
    "changeUsername",
    "changePassword",
}

# These actions must terminate at their current controller dispatches, not the Legacy policy.
EXPECTED_CONTROLLER_ACTIONS = {
    "AdminAuthController": {"adminLogin"},
    "TelegramWebhookController": {"telegramWebhook"},
    "TelegramLinkController": {"telegramLinkStatus"},
    "RegistrationController": {"register"},
    "RecoveryController": {"forgotPassword", "verifyResetCode", "resetPassword", "verifyPasswordResetCode"},
    "VerificationController": {"sendVerificationCode", "verifyRegisterCode", "resendVerificationCode"},
    "AuthController": {"getSession", "login", "logout"},
    "ProjectController": {"saveNeo", "getActiveProjectsCount"},
    "CustomerController": {
        "getProjects", "getProjectById", "sendMessage", "getNotifications",
        "markNotificationRead", "markCustomerMessagesRead",
    },
    "AdminProjectController": {
        "getAll", "getAllUsers", "getAdminUserDetail", "getAdminStats",
        "getAdminProjectDetail", "adminUpdate", "adminUpdateUser", "deleteRequest",
        "adminSendMessage", "markAdminMessagesRead", "getAdminNotifications",
        "getAdminMessages", "getAdminAuditLog", "getAdminSettingsSummary",
        "markAdminNotificationRead", "exportProjectsCsv",
    },
}

EXPECTED_CONTROLLER_PREEMPTED_POLICY_ACTIONS = {
    "login",
    "forgotPassword",
    "verifyResetCode",
    "getProjects",
    "getProjectById",
    "getNotifications",
    "markNotificationRead",
}

EXPECTED_EFFECTIVE_LEGACY_ACTIONS = {
    "updateProject",
    "deleteProject",
    "changeUsername",
    "changePassword",
}

RECOVERY_LIMIT_DEFAULTS = {
    "MRNEON_RECOVERY_START_IP_LIMIT_PER_HOUR": 20,
    "MRNEON_RECOVERY_START_IDENTITY_LIMIT_PER_HOUR": 5,
    "MRNEON_RECOVERY_VERIFY_IP_LIMIT_PER_HOUR": 60,
    "MRNEON_RECOVERY_VERIFY_CHALLENGE_LIMIT_PER_HOUR": 15,
    "MRNEON_RECOVERY_RESET_IP_LIMIT_PER_HOUR": 30,
    "MRNEON_RECOVERY_RESET_TOKEN_LIMIT_PER_HOUR": 10,
}


def fail(message: str) -> None:
    print(f"FAIL: {message}")
    raise SystemExit(1)


def require(condition: bool, message: str) -> None:
    if not condition:
        fail(message)
    print(f"PASS: {message}")


def extract_legacy_policy_actions(api: str) -> tuple[list[str], str]:
    start = api.find("$actionPolicy = [")
    if start < 0:
        fail("could not locate the explicit $actionPolicy array")
    end = api.find("\n];", start)
    if end < 0:
        fail("could not find the end of the $actionPolicy array")
    block = api[start:end + 3]
    names = re.findall(r"^\s*'([A-Za-z][A-Za-z0-9]*)'\s*=>\s*\['code'\s*=>", block, re.M)
    return names, block


def extract_controller_dispatches(api: str) -> dict[str, list[str]]:
    found: dict[str, list[str]] = defaultdict(list)

    direct_pattern = re.compile(
        r"if\s*\(\s*\$action\s*===\s*'(?P<action>[A-Za-z][A-Za-z0-9]*)'\s*\)"
        r"\s*\{\s*\(new\s+(?P<controller>[A-Za-z_][A-Za-z0-9_]*)\(\)\)"
        r"->dispatch[A-Za-z0-9_]*\s*\(",
        re.S,
    )
    for match in direct_pattern.finditer(api):
        found[match.group("controller")].append(match.group("action"))

    array_pattern = re.compile(
        r"if\s*\(\s*in_array\(\s*\$action\s*,\s*\[(?P<actions>.*?)\]"
        r"\s*,\s*true\s*\)\s*\)\s*\{\s*\(new\s+(?P<controller>[A-Za-z_][A-Za-z0-9_]*)\(\)\)"
        r"->dispatch[A-Za-z0-9_]*\s*\(",
        re.S,
    )
    for match in array_pattern.finditer(api):
        action_literals = re.findall(r"'([A-Za-z][A-Za-z0-9]*)'", match.group("actions"))
        found[match.group("controller")].extend(action_literals)

    return dict(found)


def check_gitignore_semantics(root: Path, gitignore_text: str) -> None:
    """Use git check-ignore in an isolated temporary repository; never touches the real repo."""
    git = subprocess.run(["git", "--version"], capture_output=True, text=True)
    if git.returncode != 0:
        fail("git is required to exercise .gitignore semantics in a temporary repository")

    with tempfile.TemporaryDirectory(prefix="mrneon-gitignore-test-") as temp_dir:
        repo = Path(temp_dir)
        init = subprocess.run(
            ["git", "-c", "init.defaultBranch=main", "init", "-q"],
            cwd=repo, capture_output=True, text=True,
        )
        if init.returncode != 0:
            fail("could not initialize the temporary Git repository for .gitignore checks")

        (repo / ".gitignore").write_text(gitignore_text, encoding="utf-8")

        ignored_paths = [
            ".env",
            ".env.production",
            "nested/.env",
            "nested/.env.staging",
            "application.log",
            "application.log.1",
            "logs/php/error.log",
            "database/dump.sql",
            "database/backups/snapshot.sql.gz",
            "storage/backup.sqlite",
            "storage/app.db",
            "private/credentials.key",
            "private/archive.p12",
            "private/archive.pfx",
            "private/archive.jks",
            "private/archive.keystore",
            "ssh/id_rsa",
            "ssh/id_ed25519",
            "tls/server-private.pem",
            "tls/server_key.pem",
            "tls/private.pem",
            "tls/privkey.pem",
            "tls/id_ed25519.pem",
            "tls/server-privkey.pem",
            "tls/api.private.pem",
            "node_modules/pkg/index.js",
            "dist/assets/app.js",
            "build/release/app.php",
            "coverage/lcov.info",
            "tmp/export.tmp",
            "exports/customer-export.csv",
        ]

        non_ignored_paths = [
            ".env.example",
            "tls/ca.pem",  # Public CA PEM files must not be blanket-ignored.
            "config.php",
            "js/app.js",
            "data/.htaccess",
            "docs/LOCAL-SETUP.md",
            "tests/Unit/run-unit.php",
            ".github/workflows/ci.yml",
        ]

        migrations = sorted((root / "database/migrations").rglob("*.sql"))
        if not migrations:
            fail("no current SQL migrations found to test against .gitignore")
        migration_relatives = [p.relative_to(root).as_posix() for p in migrations]
        migration_relatives.append("database/migrations/999_gitignore_semantic_fixture.sql")
        non_ignored_paths.extend(migration_relatives)

        all_paths = ignored_paths + non_ignored_paths
        for relative in all_paths:
            path = repo / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("temporary gitignore fixture\n", encoding="utf-8")

        for relative in ignored_paths:
            result = subprocess.run(
                ["git", "check-ignore", "--quiet", "--no-index", "--", relative],
                cwd=repo, capture_output=True, text=True,
            )
            if result.returncode != 0:
                fail(f".gitignore did not ignore required private/generated fixture: {relative}")

        for relative in non_ignored_paths:
            result = subprocess.run(
                ["git", "check-ignore", "--quiet", "--no-index", "--", relative],
                cwd=repo, capture_output=True, text=True,
            )
            if result.returncode == 0:
                fail(f".gitignore incorrectly hides a required source/document/migration file: {relative}")
            if result.returncode != 1:
                fail(f"git check-ignore failed unexpectedly for allowed path: {relative}")

    print(
        "PASS: .gitignore semantic behavior verified by git check-ignore in a temporary repository "
        f"({len(ignored_paths)} ignored fixtures; {len(non_ignored_paths)} allowed files including "
        f"{len(migrations)} existing SQL migrations)"
    )


def main() -> int:
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    if not (root / "api.php").is_file() or not (root / "config.php").is_file():
        fail("source root must contain api.php and config.php")

    api = (root / "api.php").read_text(encoding="utf-8")
    config = (root / "config.php").read_text(encoding="utf-8")
    notifier = (root / "core/notifier.php").read_text(encoding="utf-8")
    app = (root / "js/app.js").read_text(encoding="utf-8")
    auth = (root / "js/auth.js").read_text(encoding="utf-8")
    admin = (root / "js/admin.js").read_text(encoding="utf-8")
    neo = (root / "js/neo.js").read_text(encoding="utf-8")

    public_files = [
        p for folder in (root / "js", root / "components") if folder.exists()
        for p in folder.rglob("*") if p.is_file() and p.suffix.lower() in {".js", ".html"}
    ]
    browser_text = "\n".join(p.read_text(encoding="utf-8", errors="replace") for p in public_files)

    require(not re.search(r"(?im)^\s*apiKey\s*:", app), "no API key setting in browser app config")
    require(not re.search(r"(?im)^\s*adminCredentials\s*:", app), "no static Admin credentials in browser app config")
    require("X-API-Key" not in browser_text and "CONFIG.apiKey" not in browser_text,
            "no API-key header or CONFIG.apiKey reference in public JS/HTML")
    require(not re.search(r"define\s*\(\s*['\"](?:API_KEY|ADMIN_PASSWORD|TELEGRAM_BOT_TOKEN)['\"]\s*,\s*['\"][^'\"]+['\"]", config),
            "no literal API/Admin-password/Bot-token config assignment")
    require("mrneonEnv('MRNEON_TELEGRAM_BOT_TOKEN')" in config and "mrneonEnv('MRNEON_PUBLIC_BASE_URL')" in config,
            "runtime provider/base URL settings are read from environment")
    require("$_SERVER['HTTP_HOST']" not in config,
            "public base URL is not derived from the request Host header")

    require("Access-Control-Allow-Origin" not in api and "Access-Control-Allow-Credentials" not in api,
            "no reflective/credentialed CORS headers")
    require("verifyApiKey(): void" not in api and "if (!in_array($action, $publicActions)) verifyApiKey()" not in api,
            "legacy browser API-key gate removed rather than reused as authentication")
    require("if (!isset($actionPolicy[$action]))" in api and "jsonError($actionPolicy[$action]['message']" in api,
            "remaining Legacy actions terminate through the explicit fail-closed policy")

    legacy_actions, policy_block = extract_legacy_policy_actions(api)
    duplicate_legacy = sorted(name for name, count in Counter(legacy_actions).items() if count > 1)
    actual_legacy = set(legacy_actions)
    missing_legacy = sorted(EXPECTED_LEGACY_ACTIONS - actual_legacy)
    extra_legacy = sorted(actual_legacy - EXPECTED_LEGACY_ACTIONS)
    details = []
    if missing_legacy:
        details.append(f"missing={missing_legacy}")
    if extra_legacy:
        details.append(f"extra={extra_legacy}")
    if duplicate_legacy:
        details.append(f"duplicates={duplicate_legacy}")
    require(
        not missing_legacy and not extra_legacy and not duplicate_legacy,
        "Legacy $actionPolicy matches the exact approved 11-action set" + (f" ({'; '.join(details)})" if details else ""),
    )

    # Only dispatches physically before $actionPolicy are active controller routes.
    # Some of their names intentionally remain in the historical policy array; they are
    # classified as pre-empted, not as effective Legacy actions.
    policy_start = api.find("$actionPolicy = [")
    actual_controllers = extract_controller_dispatches(api[:policy_start])
    controller_missing = sorted(set(EXPECTED_CONTROLLER_ACTIONS) - set(actual_controllers))
    controller_extra = sorted(set(actual_controllers) - set(EXPECTED_CONTROLLER_ACTIONS))
    controller_differences = []
    for controller in sorted(set(EXPECTED_CONTROLLER_ACTIONS) | set(actual_controllers)):
        expected = EXPECTED_CONTROLLER_ACTIONS.get(controller, set())
        actual_list = actual_controllers.get(controller, [])
        actual = set(actual_list)
        duplicates = sorted(name for name, count in Counter(actual_list).items() if count > 1)
        missing = sorted(expected - actual)
        extra = sorted(actual - expected)
        if missing or extra or duplicates:
            controller_differences.append(
                f"{controller}: missing={missing}, extra={extra}, duplicates={duplicates}"
            )
    controller_action_set = set().union(*EXPECTED_CONTROLLER_ACTIONS.values())
    preempted_policy_actions = actual_legacy & controller_action_set
    effective_legacy_actions = actual_legacy - controller_action_set
    preempted_mismatch = sorted(preempted_policy_actions ^ EXPECTED_CONTROLLER_PREEMPTED_POLICY_ACTIONS)
    effective_mismatch = sorted(effective_legacy_actions ^ EXPECTED_EFFECTIVE_LEGACY_ACTIONS)
    require(
        not controller_missing and not controller_extra and not controller_differences
        and not preempted_mismatch and not effective_mismatch,
        "Controller routes are matched separately from the exact 11 policy declarations; "
        "7 pre-empted names are not counted as effective Legacy actions"
        + (f" (controllers_missing={controller_missing}; controllers_extra={controller_extra}; "
           f"details={controller_differences}; pre-empted mismatch={preempted_mismatch}; "
           f"effective Legacy mismatch={effective_mismatch})" if
           controller_missing or controller_extra or controller_differences or preempted_mismatch or effective_mismatch else ""),
    )
    require(
        "if ($action === 'register')" in api
        and "new RegistrationController()" in api
        and "register" not in legacy_actions
        and actual_controllers.get("RegistrationController") == ["register"],
        "registration remains on the dedicated attempt-only RegistrationController route",
    )

    require("CURLOPT_SSL_VERIFYPEER => true" in notifier and "CURLOPT_SSL_VERIFYHOST => 2" in notifier,
            "Telegram cURL certificate and hostname validation enabled")
    require("'verify_peer' => true" in notifier and "'verify_peer_name' => true" in notifier,
            "Telegram stream certificate and hostname validation enabled")
    require(not re.search(r"CURLOPT_SSL_VERIFYPEER\s*,\s*false|CURLOPT_SSL_VERIFYHOST\s*,\s*false|'verify_peer'\s*=>\s*false|'verify_peer_name'\s*=>\s*false", notifier),
            "no explicitly insecure Telegram TLS verification flags")
    verification_body = notifier.split("function sendVerificationToTelegram", 1)[1].split("function notifyNewProject", 1)[0]
    require("return false;" in verification_body and "sendTelegramMessage(" not in verification_body,
            "OTP is not sent to the default administrative chat while verified Telegram linking is absent")

    require('"neo_requests"' not in neo[neo.find('async _submit()'):neo.find('// 🎉 مودال تشکر')],
            "NEO submit has no local-storage success fallback")
    require("return;" in neo[neo.find('async _submit()'):neo.find('// 🎉 مودال تشکر')],
            "NEO exits after failed submission before receipt/thank-you behavior")
    preflight = neo[neo.find('static async canOpenNEO()'):neo.find('static loadDraft(')]
    require("capacity_unavailable" in preflight and "if (!resp.ok || payload.success === false)" in preflight,
            "NEO preflight fails closed when server capacity cannot be verified")
    mark_start = auth.find("async markNotifRead(")
    mark_end = auth.find("\n  updateStats()", mark_start + 1) if mark_start >= 0 else -1
    mark_method = auth[mark_start:mark_end] if mark_start >= 0 and mark_end > mark_start else ""
    require(
        'const result = await apiRequest("markNotificationRead"' in mark_method
        and "result?.read !== true" in mark_method
        and "this.notifications = this.notifications.map" in mark_method
        and mark_method.find("result?.read !== true") < mark_method.find("this.notifications = this.notifications.map"),
        "customer notification state changes only after server confirmation",
    )
    require('setItem("mrneon_user_session"' not in auth and 'setItem("mrneon_admin_session"' not in admin,
            "legacy browser-held bearer/admin session writes removed")
    require("localStorage.removeItem(key)" in auth and "sessionStorage.removeItem(key)" in auth,
            "legacy browser-held session keys are cleared by AuthSystem")
    archive_start = admin.find("async archiveProject(")
    archive_end = admin.find("\n  async openProjectDetail(", archive_start + 1) if archive_start >= 0 else -1
    archive_method = admin[archive_start:archive_end] if archive_start >= 0 and archive_end > archive_start else ""
    api_client = (root / "js/api-client.js").read_text(encoding="utf-8")
    require(
        'await apiRequest("deleteRequest"' in archive_method
        and "catch (error)" in archive_method
        and "بایگانی پروژه تأیید نشد" in archive_method
        and "if (!response.ok)" in api_client
        and "throw apiError" in api_client,
        "Admin archive uses the centralized API client, whose non-2xx response is rejected",
    )
    require("window.authSystem?.open()" not in admin,
            "Admin login never falls back into the customer login flow")

    root_htaccess = (root / ".htaccess").read_text(encoding="utf-8") if (root / ".htaccess").is_file() else ""
    require("config(?:\\.[^.]+)?\\.php" in root_htaccess,
            "Apache root deny pattern also covers private config variants")
    require((root / ".htaccess").is_file() and (root / "data/.htaccess").is_file() and (root / "core/.htaccess").is_file(),
            "Apache rules exist for config, runtime data, and core modules")

    gitignore_text = (root / ".gitignore").read_text(encoding="utf-8")
    require("!/.env.example" in gitignore_text,
            ".gitignore retains the root environment template exception")
    check_gitignore_semantics(root, gitignore_text)

    env_example = (root / ".env.example").read_text(encoding="utf-8")
    rate_limit = (root / "app/http/middleware/RateLimitMiddleware.php").read_text(encoding="utf-8")
    for name, default in RECOVERY_LIMIT_DEFAULTS.items():
        assignment = re.search(rf"(?m)^\s*{re.escape(name)}=(\d+)\s*$", env_example)
        code_default = re.search(
            rf"self::limit\(\s*['\"]{re.escape(name)}['\"]\s*,\s*(\d+)\s*,",
            rate_limit,
        )
        require(
            assignment is not None and int(assignment.group(1)) == default
            and code_default is not None and int(code_default.group(1)) == default,
            f"{name} is documented with the exact RateLimitMiddleware default",
        )
    require("Documentation only" in env_example and "PHP does NOT load this file automatically" in env_example,
            ".env.example remains documentation-only and is not treated as an automatic PHP loader")
    require("MRNEON_ENV=local" in env_example and "MRNEON_SESSION_COOKIE_SECURE=0" in env_example
            and re.search(r"(?im)^#.*Production.*HTTPS.*Secure", env_example) is not None,
            "Local environment/session sample is explicitly distinguished from secure Production settings")
    require("MRNEON_BACKUP_DIR=\n" in env_example,
            "MRNEON_BACKUP_DIR stays empty in the portable template")

    workflow_path = root / ".github/workflows/ci.yml"
    workflow = workflow_path.read_text(encoding="utf-8") if workflow_path.is_file() else ""
    require("tools/wave1_static_checks.py" in workflow, "baseline CI runs the Wave 1 static contract checker")
    require(workflow_path.is_file(), "baseline PHP/JavaScript CI template exists")
    require(
        "gitleaks dir --redact=100 --exit-code 1" in workflow
        and "gitleaks git --redact=100 --exit-code 1" in workflow
        and "--report-format template" in workflow
        and '--report-template "$GITLEAKS_SAFE_TEMPLATE"' in workflow
        and "--report-path \"$report_path\"" in workflow
        and all(token in workflow for token in ["{{ .RuleID }}", "{{ .File }}", "{{ .StartLine }}", "{{ .EndLine }}", "{{ .Commit }}", "{{ .Fingerprint }}"])
        and all(token not in workflow for token in ["{{ .Match }}", "{{ .Secret }}", "{{ .Line }}"]),
        "CI runs fail-closed redacted Gitleaks scans and exposes only safe rule/file/line diagnostics")
    require(
        'release_url="https://github.com/gitleaks/gitleaks/releases/download/v8.30.1"' in workflow
        and 'gitleaks_8.30.1_checksums.txt' in workflow
        and "sha256sum --check --strict selected-checksum.txt" in workflow
        and "curl --fail" in workflow and "--proto '=https'" in workflow,
        "Gitleaks v8.30.1 binary is downloaded over HTTPS and verified with the official release checksum")
    secret_job = workflow.split("\n  static-and-unit:", 1)[0]
    require(
        "continue-on-error" not in secret_job and "--exclude" not in secret_job
        and "upload-artifact" not in secret_job and "allowlist" not in secret_job.lower(),
        "Secret Scanner has no soft-fail, broad exclusions, public allowlist, or secret-bearing artifact upload")
    require("fetch-depth: 0" in workflow and "fetch-tags: true" in workflow
            and "persist-credentials: false" in workflow,
            "Secret Scanner checks full available history without persisting checkout credentials")
    require("actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1" in workflow
            and "# v7.0.1 (Node 24 runtime)" in workflow
            and "actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1" in workflow
            and "# v7.1.0 (Node 24 runtime)" in workflow,
            "Checkout and setup-node are pinned to verified Node 24-compatible full commit SHAs")
    require("node-version: '22'" in workflow,
            "project Node.js version remains 22; only the GitHub Action runtime was upgraded")
    require("shivammathur/setup-php@eb7c497e18156a6bbabfef1d3a82760b9eda3962" in workflow
            and "# v2.40.0; action.yml uses node24" in workflow,
            "setup-php v2.40.0 is pinned to its verified Node 24-compatible full commit SHA")

    print(
        "SUMMARY: all static Wave 1 checks passed "
        f"({len(legacy_actions)} declared policy entries, "
        f"{len(EXPECTED_CONTROLLER_PREEMPTED_POLICY_ACTIONS)} pre-empted by controllers, "
        f"{len(EXPECTED_EFFECTIVE_LEGACY_ACTIONS)} effective Legacy actions, "
        f"{sum(len(v) for v in EXPECTED_CONTROLLER_ACTIONS.values())} controller routes)."
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
