#!/usr/bin/env python3
"""W4-P6A source-contract checks; no DB/provider/runtime claims."""
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]

def read(rel):
    p = ROOT / rel
    if not p.is_file():
        raise AssertionError(f"missing required source: {rel}")
    return p.read_text(encoding="utf-8")

checks = []
def check(condition, message):
    if not condition:
        raise AssertionError(message)
    checks.append(message)

api = read("api.php")
methods = read("app/http/middleware/MethodPolicy.php")
reg = read("app/http/controllers/RegistrationController.php")
verify = read("app/http/controllers/VerificationController.php")
recovery = read("app/http/controllers/RecoveryController.php")
link = read("app/http/controllers/TelegramLinkController.php")
webhook = read("app/http/controllers/TelegramWebhookController.php")
client = read("js/api-client.js")
auth = read("js/auth.js")

active = {
    "register": "RegistrationController())->dispatch()",
    "telegramLinkStatus": "TelegramLinkController())->dispatchStatus()",
    "sendVerificationCode": "VerificationController())->dispatch($action)",
    "verifyRegisterCode": "VerificationController())->dispatch($action)",
    "resendVerificationCode": "VerificationController())->dispatch($action)",
    "forgotPassword": "RecoveryController())->dispatch($action)",
    "verifyResetCode": "RecoveryController())->dispatch($action)",
    "resetPassword": "RecoveryController())->dispatch($action)",
}
for action, route in active.items():
    check(action in methods and f"'{action}' => 'POST'" in methods, f"{action} is registered as POST")
    check(route in api, f"{action} routes to its active controller")

check("'getSession' => 'GET'" in methods and "'login' => 'POST'" in methods and "'logout' => 'POST'" in methods, "session/login/logout method contract is explicit")
check("ApiResponse::success($result, !empty($result['reusedExistingAttempt']) ? 200 : 201)" in reg, "registration status distinguishes new/reused attempt")
check("$action === 'verifyRegisterCode'" in verify and "ApiResponse::success($service->verifyRegistration($input, ApiRequest::clientIp()), 201)" in verify, "registration completion success is emitted only by verification service path")
check("$action === 'verifyPasswordResetCode'" in recovery and "410, 'RECOVERY_FLOW_UPDATED'" in recovery, "legacy combined Recovery action fails closed with 410")
check("verifyPasswordResetCode" not in re.findall(r"\?action=([A-Za-z0-9]+)", auth), "no first-party auth.js consumer calls the legacy Recovery action")
check("Csrf::assertValid()" in reg and "Csrf::assertValid()" in verify and "Csrf::assertValid()" in recovery and "Csrf::assertValid()" in link, "browser state-changing Auth/Recovery routes enforce CSRF")
check("TelegramLinkConfiguration::webhookSecret()" in webhook and "HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN" in webhook, "Telegram webhook uses provider secret header rather than browser CSRF")
check("X-Reset-Authorization" in auth and "localStorage" not in auth[auth.find("class ForgotPasswordSystem"):], "Recovery token is sent by header and not stored in browser localStorage")
check("includeResponseMeta = false)" in client and "status: response.status" in client and "payload.meta?.requestId" in client, "central client can preserve successful status/request ID for compatibility consumers")
check("status: result.status" in client and "requestId: result.requestId" in client, "apiFetch success adapter preserves actual status and request correlation")
check("redirect: \"error\"" in client and "credentials: \"same-origin\"" in client and "cache: \"no-store\"" in client, "central client retains same-origin/no-store/no-redirect controls")
check("if (!response.ok)" in client and "INVALID_API_ENVELOPE" in client and "INVALID_API_CONTENT_TYPE" in client, "HTTP failures and malformed success responses cannot become success")
check("function actionUrl(action)" in client and "url.origin !== window.location.origin" in client, "central client restricts action and same-origin destination")

print(f"PASS W4-P6A Contract: {len(checks)} checks")
for i, message in enumerate(checks, 1):
    print(f"PASS #{i}: {message}")
