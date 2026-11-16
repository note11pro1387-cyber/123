#!/usr/bin/env python3
"""Static P6C Recovery UI security/contract guardrails; no server or provider calls."""
from html.parser import HTMLParser
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
html = (ROOT / 'components/auth-modals.html').read_text(encoding='utf-8')
auth = (ROOT / 'js/auth.js').read_text(encoding='utf-8')
controller = (ROOT / 'app/http/controllers/RecoveryController.php').read_text(encoding='utf-8')
api = (ROOT / 'js/api-client.js').read_text(encoding='utf-8')
policy = (ROOT / 'js/registration-validation.js').read_text(encoding='utf-8')
checks = 0

class MarkupParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.ids = []
        self.stack = []
        self.forms = 0
        self.nested_forms = 0
        self.dialogs = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if a.get('id'):
            self.ids.append(a['id'])
        if tag == 'form':
            self.forms += 1
            if 'form' in self.stack:
                self.nested_forms += 1
        if tag == 'div' and a.get('id') == 'forgotPasswordModal':
            self.dialogs.append(a)
        if tag not in {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}:
            self.stack.append(tag)
    def handle_endtag(self, tag):
        if tag in self.stack:
            idx = len(self.stack) - 1 - self.stack[::-1].index(tag)
            self.stack = self.stack[:idx]

def require(condition, message):
    global checks
    if not condition:
        raise AssertionError(message)
    checks += 1

p = MarkupParser(); p.feed(html)
duplicates = sorted({v for v in p.ids if p.ids.count(v) > 1})
require(not duplicates, f'duplicate DOM ids: {duplicates}')
require('id="forgotPasswordModal"' in html and 'id="forgotPasswordForm"' in html, 'Recovery modal/form missing')
require(len(p.dialogs) == 1 and p.dialogs[0].get('role') == 'dialog' and p.dialogs[0].get('aria-modal') == 'true', 'Recovery dialog semantics missing')
require(p.nested_forms == 0, 'nested forms found in auth modal markup')
require(all(f'id="{x}"' in html for x in ['fpRequestSection','fpCodeSection','fpResetSection']), 'three Recovery stages missing')
require('role="status" aria-live="polite" aria-atomic="true"' in html and 'id="fpStatus"' in html, 'accessible Recovery status region missing')
require('id="fpRestartFromCodeBtn"' in html and 'id="fpRestartFromResetBtn"' in html, 'restart/change-method controls missing')
require('value="sms" disabled' in html or 'value="sms" disabled' in html.lower() or 'SMS' not in html[html.find('id="forgotPasswordModal"'):], 'Recovery must not enable SMS')
require('id="fpNewPassword"' in html and 'id="fpConfirmPassword"' in html, 'reset password/confirmation fields missing')
reset_fields = html[html.find('id="fpNewPassword"'):html.find('id="fpConfirmPassword"') + 200]
require('maxlength=' not in reset_fields, 'reset password must not be silently truncated by maxlength')
require('inspectRegistrationPassword(password)' in auth, 'Recovery does not use central client password policy')
require('minCharacters: 15' in policy and 'maxUtf8Bytes: 1024' in policy, 'central policy boundaries not present')

start = auth.find('  async handleRequestCode()')
verify = auth.find('  async handleVerifyCode()')
reset = auth.find('  async handleResetPassword()')
end = auth.find('\n}\n\n// ============================================================\n// 📜 مودال قوانین', reset)
require(start >= 0 and verify > start and reset > verify and end > reset, 'Recovery stage methods not found in expected order')
recovery = auth[auth.find('export class ForgotPasswordSystem'):end]
require('?action=forgotPassword' in recovery and '?action=verifyResetCode' in recovery and '?action=resetPassword' in recovery, 'one or more current Recovery actions missing')
require('status !== 202' in recovery and 'challengeRef' in recovery and 'ttlSeconds !== 120' in recovery, 'start-stage status/schema is not validated')
require('passwordResetAllowed === true' in recovery and 'expiresInSeconds >= 60' in recovery and 'expiresInSeconds <= 900' in recovery, 'verify-stage reset capability schema/bounds missing')
require('headers: { "X-Reset-Authorization": this.resetAuthorizationToken }' in recovery, 'reset-only token header is missing')
require('body: JSON.stringify({ newPassword: password })' in recovery, 'reset payload is not reset-only')
require('authenticated !== false' in recovery and 'sessionEstablished !== false' in recovery and 'passwordReset !== true' in recovery, 'successful reset does not require all three non-login success flags')
require('this.resetAuthorizationToken = token' in recovery and 'clearSensitiveFlow()' in recovery, 'memory token store or clear routine missing')
require('localStorage' not in recovery and 'sessionStorage' not in recovery and 'indexedDB' not in recovery, 'reset-only token flow references persistent browser storage')
require('data-reset-authorization' not in recovery and 'console.log' not in recovery and 'console.error' not in recovery, 'token flow includes a suspect DOM-data or logging sink')
require('Retry-After' in recovery and 'retryBlockedUntil' in recovery and 'applyRetryCooldown()' in recovery, 'bounded Retry-After UX guard missing')
require('new AbortController()' in recovery and 'flowGeneration' in recovery and 'isCurrent(generation)' in recovery, 'abort/stale-response protection missing')
require('stopImmediatePropagation()' in recovery and 'event.key === "Escape"' in recovery, 'top-modal Escape handling missing')
require('if (stage === "start")' in recovery and 'وجود حساب یا وضعیت کانال نمایش داده نمی‌شود' in recovery, 'non-enumerating safe start error copy missing')
require('SQL' not in recovery and 'provider internals' not in recovery and 'error.detail' not in recovery, 'raw server/provider error details may be surfaced')
require('resetAuthorizationToken' not in (ROOT / 'js/api-client.js').read_text(encoding='utf-8'), 'central API client must not persist/transform reset capability')
require('RECOVERY_FLOW_UPDATED' in controller and ('410' in controller or 'GONE' in controller), 'legacy combined recovery endpoint fail-closed contract not evident')
require('verifyPasswordResetCode' not in auth, 'client must not call legacy combined recovery handler')
require('response.headers' in api or 'headers' in api, 'central API client response/header integration not found')

print(f'PASS: {checks} W4-P6C static security/contract assertions; no DB/provider calls.')
