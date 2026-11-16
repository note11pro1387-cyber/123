from pathlib import Path
from playwright.sync_api import sync_playwright
import re, json
ROOT=Path(__file__).resolve().parents[2]
html=(ROOT/'components/auth-modals.html').read_text(encoding='utf-8')
auth=(ROOT/'js/auth.js').read_text(encoding='utf-8')
policy=(ROOT/'js/registration-validation.js').read_text(encoding='utf-8')
# Load the production auth module source into a browser DOM harness by removing ES-module imports/exports only.
# The website bootstrap side effect is omitted; dependencies are explicitly bound to synthetic fixtures.
auth=re.sub(r'^import\s+\{[^}]+\}\s+from\s+[\'\"][^\'\"]+[\'\"]\s*;\s*\n', '', auth, flags=re.M)
auth=re.sub(r'^AuthSystem\.checkSession\(\);\s*$', '/* omitted isolated session bootstrap */', auth, flags=re.M)
auth=re.sub(r'^export\s+', '', auth, flags=re.M)
policy=policy.replace('export const ', 'const ').replace('export function ', 'function ')
prelude=r'''
Object.defineProperty(window, 'sessionStorage', { configurable: true, value: { getItem(){return null;}, setItem(){}, removeItem(){} } });
const CONFIG = { apiEndpoint: 'api.php' };
const DOM = {
 authModal: document.getElementById('authModal'), authForm: document.getElementById('authForm'),
 registerModal: document.getElementById('registerModal'), registerForm: document.getElementById('registerForm'),
 termsModal: document.getElementById('termsModal'), termsLink: document.getElementById('termsLink'),
 forgotPasswordModal: document.getElementById('forgotPasswordModal'), forgotPasswordForm: document.getElementById('forgotPasswordForm'),
 userPanelModal: document.getElementById('userPanelModal'),
};
function sanitizeInput(v) { return String(v ?? ''); }
const apiRequest = async () => ({ authenticated: false });
const getServerSession = async () => ({ authenticated: false });
let syntheticCalls = [];
let syntheticMode = { resendFail: false, verifyFail: false, mismatchChannel: false };
const apiFetch = async (url, options={}) => {
 const action = new URL(url, 'https://harness.invalid/').searchParams.get('action');
 let body={}; try { body=JSON.parse(options.body || '{}'); } catch {}
 syntheticCalls.push({action,body});
 if (action === 'register') return {ok:true,status:201,json:async()=>({success:true,data:{attemptId:'1'.repeat(32),state:'pending_verification',verificationStatus:'not_started',selectedChannel:syntheticMode.mismatchChannel?'telegram':body.selectedChannel,telegramLinked:body.selectedChannel==='telegram'}})};
 if (action === 'sendVerificationCode') return {ok:true,status:202,json:async()=>({success:true,data:{state:'active',channel:'email',challengeId:'2'.repeat(32),ttlSeconds:120,expiresAt:'2026-10-13T00:02:00Z',reusedActiveChallenge:false,resendAvailableAt:'2026-10-13T00:00:30Z'}})};
 if (action === 'resendVerificationCode' && syntheticMode.resendFail) return {ok:false,status:429,json:async()=>({success:false,error:'درخواست مجدد هنوز در بازهٔ انتظار است.',code:'VERIFICATION_RESEND_COOLDOWN'})};
 if (action === 'verifyRegisterCode' && syntheticMode.verifyFail) return {ok:false,status:400,json:async()=>({success:false,error:'کد تأیید معتبر نیست.',code:'VERIFICATION_CODE_INVALID'})};
 if (action === 'telegramLinkStatus') return {ok:true,status:200,json:async()=>({success:true,data:{linked:true}})};
 return {ok:false,status:404,json:async()=>({success:false,error:'Synthetic route not configured',code:'SYNTHETIC_ROUTE_NOT_CONFIGURED'})};
};
window.notificationSystem = { info(){}, success(){}, error(){}, warning(){} };
'''
postlude=r'''
window.__test = {
 RegistrationSystem, TermsModal,
 registrationSystem: new RegistrationSystem(),
 termsModal: new TermsModal(),
 calls: syntheticCalls,
 mode: syntheticMode,
};
window.termsModal = window.__test.termsModal;
window.registrationSystem = window.__test.registrationSystem;
window.__test.registrationSystem.open();
'''
script=prelude+'\n'+policy+'\n'+auth+'\n'+postlude
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage'])
 page=b.new_page(viewport={'width':390,'height':844},locale='fa-IR')
 errors=[]
 page.on('pageerror',lambda e: errors.append(str(e)))
 doc='<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>MR NEON P6B isolated browser harness</title></head><body>'+html+'</body></html>'
 page.set_content(doc,wait_until='domcontentloaded')
 page.add_script_tag(content=script)
 # The harness executes the production auth classes and production HTML against synthetic API responses.
 assert page.locator('#registerModal').count()==1
 ids=page.locator('[id]').evaluate_all("els => { const ids=els.map(x=>x.id); return [...new Set(ids.filter((x,i)=>ids.indexOf(x)!==i))]; }")
 assert not ids, f'duplicate ids: {ids}'
 cb=page.locator('#agreeTerms'); assert not cb.is_checked()
 # Dismissal paths never set consent.
 page.locator('#termsLink').click(force=True); page.locator('#termsAcceptBtn').click(force=True); assert not cb.is_checked()
 page.locator('#termsLink').click(force=True); page.locator('.terms-close').click(force=True); assert not cb.is_checked()
 page.locator('#termsLink').click(force=True); page.locator('.terms-backdrop').click(force=True,position={'x':1,'y':1}); assert not cb.is_checked()
 page.locator('#termsLink').click(force=True); page.keyboard.press('Escape'); assert not cb.is_checked()
 # Email is channel-dependent, and switching back to Telegram makes it optional.
 email=page.locator('input[name="registrationChannel"][value="email"]'); tg=page.locator('input[name="registrationChannel"][value="telegram"]')
 email.check(force=True); assert page.locator('#regEmail').evaluate('x=>x.required'); assert 'اجباری' in page.locator('#regEmailRequirement').inner_text(); assert not page.locator('#regEmail').evaluate('x=>x.checkValidity()')
 tg.check(force=True); assert not page.locator('#regEmail').evaluate('x=>x.required'); assert 'اختیاری' in page.locator('#regEmailRequirement').inner_text()
 # Unicode password checks are reflected immediately in the real registration UI.
 pwd=page.locator('#regPassword')
 for val, valid, text in [('a'*8,False,'8'),('a'*14,False,'14'),('a'*15,True,'15'),('😀'*15,True,'emoji count'),('😀'*257,False,'1024-byte cap'),('a'*14+'\n',False,'control chars')]:
  pwd.fill(val); result=page.evaluate('''() => { const n=document.querySelector('#regPassword'); const count=document.querySelector('#regPasswordPolicyHint').textContent; return {valid:window.__test.registrationSystem.validateField(n),hint:count}; }''')
  assert result['valid'] is valid, f'{text}: {result}'
 # Submit only after explicit consent, correct password, and email channel.
 page.locator('#regFullName').fill('کاربر آزمایشی'); page.locator('#regPhone').fill('09123456789'); page.locator('#regUsername').fill('test_user')
 pwd.fill('PassWord-Policy-15'); page.locator('#regPasswordConfirm').fill('PassWord-Policy-15')
 assert not cb.is_checked(); page.evaluate("document.querySelector('#agreeTerms').click()"); assert cb.is_checked()
 email.check(force=True); page.locator('#regEmail').fill('p6b.synthetic@example.invalid')
 page.locator('#registerForm').dispatch_event('submit')
 page.wait_for_function("window.__test.calls.some(x=>x.action==='register')",timeout=3000)
 register_call=page.evaluate("window.__test.calls.find(x=>x.action==='register')")
 assert register_call['body']['termsAccepted'] is True
 assert register_call['body']['selectedChannel']=='email'
 assert register_call['body']['email']=='p6b.synthetic@example.invalid'
 assert page.locator('.pending-verification-section').count()==1
 page.locator('#startVerificationBtn').click(force=True)
 page.wait_for_function("window.__test.calls.some(x=>x.action==='sendVerificationCode')",timeout=3000)
 assert 'تضمین رسیدن ایمیل نیست' in page.locator('#verifyDeliveryDescription').inner_text()
 # Rate-limit and OTP invalid fixtures must keep status as errors, not success.
 page.evaluate('window.__test.mode.resendFail=true')
 page.locator('#resendCodeBtn').click(force=True)
 page.wait_for_timeout(100)
 assert 'بازهٔ انتظار' in page.locator('#verifyExpiryStatus').inner_text()
 page.evaluate('window.__test.mode.verifyFail=true')
 page.locator('#verifyCode').fill('123456'); page.locator('#registerForm').dispatch_event('submit'); page.wait_for_timeout(100)
 assert 'معتبر نیست' in page.locator('#verifyExpiryStatus').inner_text()
 # A server response claiming a different selected channel must not advance the flow.
 page.evaluate('window.__test.registrationSystem.close(); window.__test.registrationSystem.open(); window.__test.mode.mismatchChannel=true;')
 page.locator('#regFullName').fill('کاربر آزمایشی'); page.locator('#regPhone').fill('09123456789'); page.locator('#regUsername').fill('test_user')
 page.locator('#regPassword').fill('PassWord-Policy-15'); page.locator('#regPasswordConfirm').fill('PassWord-Policy-15')
 page.locator('input[name="registrationChannel"][value="email"]').check(force=True)
 page.locator('#regEmail').fill('p6b.synthetic@example.invalid')
 page.evaluate("document.querySelector('#agreeTerms').click()")
 page.locator('#registerForm').dispatch_event('submit'); page.wait_for_timeout(100)
 assert page.evaluate('window.__test.registrationSystem.step')=='info', 'mismatched server channel must not advance state'
 assert page.locator('.pending-verification-section').count()==0, 'mismatched server channel must not look successful'
 assert len([x for x in page.evaluate('window.__test.calls') if x['action']=='register'])==2
 assert not errors, errors
 print(json.dumps({'status':'PASS','checks':'20+ browser DOM assertions plus multi-case policy checks','browser':'Chromium / Playwright set_content harness','scope':'Actual changed auth.js classes and auth-modals.html, real DOM event/validation behavior','limitations':'Page navigation to local HTTP/file URLs is blocked by container browser policy (ERR_BLOCKED_BY_ADMINISTRATOR), so this is not full-site XAMPP/Apache E2E. API responses are synthetic fixtures; no DB or real provider contacted.','apiActionsObserved':page.evaluate('window.__test.calls.map(x=>x.action)')},ensure_ascii=False,indent=2))
 b.close()
