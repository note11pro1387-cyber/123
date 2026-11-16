#!/usr/bin/env python3
"""HTTP-level smoke tests for the local PHP built-in server; uses synthetic requests only."""
from __future__ import annotations
import http.cookiejar
import hashlib
import json
import os
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

SITE = Path(__file__).resolve().parents[2]
checks = 0

def assert_true(condition: bool, label: str) -> None:
    global checks
    checks += 1
    if not condition:
        raise AssertionError(label)

def request(opener, url: str, method: str = "GET", payload=None, headers=None):
    raw = None if payload is None else json.dumps(payload).encode("utf-8")
    request_headers = {"Accept": "application/json, application/problem+json"}
    if raw is not None:
        request_headers["Content-Type"] = "application/json; charset=utf-8"
    request_headers.update(headers or {})
    req = urllib.request.Request(url, data=raw, method=method, headers=request_headers)
    try:
        response = opener.open(req, timeout=5)
        return response.status, response.headers, response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, exc.headers, exc.read()

with socket.socket() as sock:
    sock.bind(("127.0.0.1", 0))
    port = sock.getsockname()[1]
base = f"http://127.0.0.1:{port}"
env = os.environ.copy()
# Force an isolated, unconfigured DB context; this smoke test never targets a user's DB.
for key in list(env):
    if key.startswith("MRNEON_DB_") or key.startswith("MRNEON_ADMIN_") or key.startswith("MRNEON_SESSION_"):
        env.pop(key, None)
for key in ["MRNEON_RATE_LIMIT_HMAC_KEY", "MRNEON_PUBLIC_BASE_URL", "MRNEON_ENV"]:
    env.pop(key, None)
env["MRNEON_ENV"] = "local"
server = subprocess.Popen(
    ["php", "-S", f"127.0.0.1:{port}", "-t", str(SITE)],
    cwd=str(SITE), env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
)
try:
    for _ in range(50):
        if server.poll() is not None:
            raise RuntimeError("PHP built-in server exited before the smoke tests began.")
        try:
            with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                break
        except OSError:
            time.sleep(0.1)
    else:
        raise RuntimeError("PHP built-in server did not become ready.")

    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    status, headers, body = request(opener, base + "/api.php?action=getSession")
    payload = json.loads(body)
    assert_true(status == 503 and "application/problem+json" in headers.get("Content-Type", ""), "session bootstrap fails closed when the DB-backed limiter is unavailable")
    assert_true(payload.get("code") == "AUTH_SERVICE_UNAVAILABLE" and payload.get("requestId") and headers.get("X-Request-ID") == payload.get("requestId"), "database-unavailable session response is safe and correlated")
    assert_true("password" not in body.decode("utf-8", errors="ignore").lower() and "dsn" not in body.decode("utf-8", errors="ignore").lower(), "session DB failure does not expose diagnostic internals")
    csrf = "0" * 64  # Deliberately invalid synthetic token; Origin is rejected before token comparison.

    status, headers, body = request(opener, base + "/api.php?action=login", method="GET")
    err = json.loads(body)
    assert_true(status == 405 and "application/problem+json" in headers.get("Content-Type", ""), "wrong login method returns RFC 9457 405")
    assert_true(err.get("status") == 405 and err.get("code") == "METHOD_NOT_ALLOWED" and err.get("requestId"), "method error carries stable code and request ID")

    status, headers, body = request(opener, base + "/api.php?action=login", method="POST", payload={"username": "synthetic_user", "password": "synthetic-only"})
    err = json.loads(body)
    assert_true(status == 403 and err.get("code") == "CSRF_VALIDATION_FAILED", "login mutation without CSRF is rejected before database access")

    status, headers, body = request(opener, base + "/api.php?action=login", method="POST", payload={"username": "synthetic_user", "password": "synthetic-only"}, headers={"X-CSRF-Token": csrf})
    err = json.loads(body)
    assert_true(status == 403 and err.get("code") == "CSRF_VALIDATION_FAILED", "login rejects invalid token before evaluating credentials or connecting to DB")

    status, headers, body = request(opener, base + "/api.php?action=adminLogin", method="POST", payload={"username": "synthetic_admin", "password": "synthetic-only", "totpCode": "000000"})
    err = json.loads(body)
    assert_true(status == 403 and err.get("code") == "CSRF_VALIDATION_FAILED", "admin login also requires CSRF before credential evaluation")

    rate_limits_path = SITE / "data" / "rate_limits.json"
    rate_limits_hash_before = hashlib.sha256(rate_limits_path.read_bytes()).hexdigest() if rate_limits_path.is_file() else None
    status, headers, body = request(opener, base + "/api.php?action=register", method="POST", payload={"username": "synthetic_user"})
    err = json.loads(body)
    assert_true(status == 403 and err.get("code") == "CSRF_VALIDATION_FAILED", "disabled public mutation requires CSRF and does not reach a data write")

    status, headers, body = request(opener, base + "/api.php?action=register", method="POST", payload={"username": "synthetic_user"}, headers={"X-CSRF-Token": csrf, "Origin": "https://attacker.synthetic.invalid"})
    err = json.loads(body)
    assert_true(status == 403 and err.get("code") == "ORIGIN_REJECTED", "untrusted Origin rejected even with a valid CSRF token")
    rate_limits_hash_after = hashlib.sha256(rate_limits_path.read_bytes()).hexdigest() if rate_limits_path.is_file() else None
    assert_true(rate_limits_hash_before == rate_limits_hash_after, "disabled legacy mutations do not mutate the existing JSON rate-limit source")

    status, headers, body = request(opener, base + "/api.php?action=sendMessage")
    err = json.loads(body)
    assert_true(status == 405 and err.get("code") == "METHOD_NOT_ALLOWED", "unimplemented consumer action still enforces its POST method")

    status, headers, body = request(opener, base + "/api.php?action=sendMessage", method="POST", payload={"message": "synthetic"})
    err = json.loads(body)
    assert_true(status == 401 and err.get("code") == "AUTH_REQUIRED", "customer message action rejects guest before its not-implemented response")

    status, headers, body = request(opener, base + "/api.php?action=saveNeo", method="POST", payload={"synthetic": True})
    err = json.loads(body)
    assert_true(status == 401 and err.get("code") == "AUTH_REQUIRED", "NEO save action rejects guest before its disabled domain gate")

    status, headers, body = request(opener, base + "/api.php?action=getAll")
    err = json.loads(body)
    assert_true(status == 401 and err.get("code") == "AUTH_REQUIRED", "admin data action rejects unauthenticated caller before legacy gate")

    status, headers, body = request(opener, base + "/api.php?action=notARealAction")
    err = json.loads(body)
    assert_true(status == 404 and err.get("code") == "UNKNOWN_ACTION", "unknown action returns RFC 9457 404")

    status, headers, body = request(opener, base + "/api.php?action=getSession", method="OPTIONS")
    assert_true(status == 204 and not headers.get("Access-Control-Allow-Origin"), "OPTIONS response does not emit reflective CORS")

    status, headers, body = request(opener, base + "/api.php?action=logout", method="POST", payload={}, headers={"X-CSRF-Token": csrf})
    logout_error = json.loads(body)
    assert_true(status == 403 and logout_error.get("code") == "CSRF_VALIDATION_FAILED", "logout fails closed when a valid session token could not be issued by the unavailable DB-backed session endpoint")
    print(f"PASS: {checks} HTTP-level Wave 3 smoke checks; local synthetic requests only; DB login intentionally blocked.")
finally:
    server.terminate()
    try:
        server.wait(timeout=3)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait(timeout=3)
