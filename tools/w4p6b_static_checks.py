#!/usr/bin/env python3
"""Dependency-free static guardrails for W4-P6B registration UI integration."""
from html.parser import HTMLParser
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
html_path = ROOT / "components/auth-modals.html"
auth_path = ROOT / "js/auth.js"
policy_path = ROOT / "js/registration-validation.js"
html = html_path.read_text(encoding="utf-8")
auth = auth_path.read_text(encoding="utf-8")
policy = policy_path.read_text(encoding="utf-8")
checks = 0

class IdParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = []
        self.tags = []
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        self.tags.append((tag, attrs))
        if attrs.get("id"):
            self.ids.append(attrs["id"])

def require(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

parser = IdParser()
parser.feed(html)
duplicates = sorted({value for value in parser.ids if parser.ids.count(value) > 1})
require(not duplicates, f"duplicate IDs remain: {duplicates}")
require(html.count('id="registerModal"') == 1, "exactly one registration modal must exist")
require(html.count('id="registerForm"') == 1, "exactly one active registration form must exist")
require('id="termsAcceptBtn"' in html and 'متوجه شدم' in html, "Terms close/understood action must remain present")
require('id="regEmailRequirement"' in html, "email required/optional indicator missing")
require('name="registrationChannel" value="email" required' in html, "email channel radio missing")
require('name="registrationChannelDisabled" value="sms" disabled' in html, "SMS must remain disabled/coming soon")
require('id="regTelegramUsername"' not in html, "legacy Telegram username form must not be reactivated")
require('this.syncChannelRequirements();' in auth and 'emailRequired = selectedChannel === "email"' in auth, "email required state must follow the selected channel")
require('termsAccepted: document.getElementById("agreeTerms").checked === true' in auth, "Terms API payload must use explicit checkbox state")
accept_start = auth.find('document.getElementById("termsAcceptBtn")?.addEventListener')
accept_end = auth.find('\n    });', accept_start)
accept_block = auth[accept_start:accept_end]
require(accept_start >= 0 and 'agreeTerms' not in accept_block, "understood action must not create consent")
require('inspectRegistrationPassword(input.value)' in auth, "password validation must use shared UI policy helper")
password_group = html[html.find('id="regPassword"'):html.find('id="regPasswordConfirm"')]
require('class="validation-message"' in password_group, "password validation message element is required for custom validator path")
require('!DOM.termsModal?.classList.contains("active")' in auth, "Escape in Terms must not also close registration")
require('minCharacters: 15' in policy and 'maxUtf8Bytes: 1024' in policy, "password policy boundaries missing")
require('Array.from(password).length' in policy, "password length must count Unicode code points")
require('/[\\u0000-\\u001F\\u007F]/' in policy, "ASCII control-character rejection missing")
require('res.data.selectedChannel !== selectedChannel' in auth, "register response channel mismatch must fail closed")
require('this.telegramLinked = res.data.selectedChannel !== "telegram" || res.data.telegramLinked === true' in auth, "Telegram link state must depend on server confirmation")
require('?action=sendVerificationCode' in auth and '?action=resendVerificationCode' in auth, "verification actions are unexpectedly missing")
require('?action=verifyRegisterCode' in auth, "registration verification action is unexpectedly missing")
require('requestId' in (ROOT / "js/api-client.js").read_text(encoding="utf-8"), "API client request correlation contract appears missing")
print(f"PASS: {checks} W4-P6B static guardrail assertions")
