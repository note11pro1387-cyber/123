from pathlib import Path
from playwright.sync_api import sync_playwright
import re, json
ROOT = Path(__file__).resolve().parents[2]
html = (ROOT / 'components/auth-modals.html').read_text(encoding='utf-8')
auth = (ROOT / 'js/auth.js').read_text(encoding='utf-8')
policy = (ROOT / 'js/registration-validation.js').read_text(encoding='utf-8').replace('export const ', 'const ').replace('export function ', 'function ')
auth = re.sub(r'^import\s+\{[^}]+\}\s+from\s+[\'\"][^\'\"]+[\'\"]\s*;\s*\n', '', auth, flags=re.M)
auth = re.sub(r'^AuthSystem\.checkSession\(\);\s*$', '/* isolated harness omits session bootstrap */', auth, flags=re.M)
auth = re.sub(r'^export\s+', '', auth, flags=re.M)
prelude = r'''
const CONFIG = {apiEndpoint:'api.php'};
const DOM = {
 authModal:document.getElementById('authModal'), authForm:document.getElementById('authForm'),
 registerModal:document.getElementById('registerModal'), registerForm:document.getElementById('registerForm'),
 termsModal:document.getElementById('termsModal'), termsLink:document.getElementById('termsLink'),
 forgotPasswordModal:document.getElementById('forgotPasswordModal'), forgotPasswordForm:document.getElementById('forgotPasswordForm'),
 userPanelModal:document.getElementById('userPanelModal'),
};
function sanitizeInput(v){return String(v??'');}
const apiRequest=async()=>({authenticated:false}); const getServerSession=async()=>({authenticated:false});
const syntheticCalls=[];
const mode={start:'success', verify:'success', reset:'success', delayStart:false, resetErrorCode:'', lastEnvelopeMessage:'DELIVERED TO KNOWN ACCOUNT', retryAfter:'2'};
const TOKEN='a'.repeat(64); const CHALLENGE='b'.repeat(32);
function result(status,envelope,headers={}) { return {ok:status>=200&&status<300,status,headers:new Headers(headers),json:async()=>envelope}; }
const apiFetch=async(url,options={})=>{
 const action=new URL(url,'https://harness.invalid/').searchParams.get('action'); let body={}; try{body=JSON.parse(options.body||'{}')}catch{}
 const headers={}; if(action==='resetPassword') for(const [k,v] of new Headers(options.headers||{}).entries()) headers[k]=v;
 syntheticCalls.push({action,body,headers,signalPassed:!!options.signal});
 const response=()=>{
  if(action==='forgotPassword') {
   if(mode.start==='delay') return result(202,{success:true,data:{challengeRef:CHALLENGE,ttlSeconds:120,message:mode.lastEnvelopeMessage},meta:{requestId:'rid-start'}});
   if(mode.start==='rate') return result(429,{success:false,error:'PRIVATE provider/recipient detail',code:'RATE_LIMITED'},{'Retry-After':mode.retryAfter});
   if(mode.start==='malformed') return result(202,{success:true,data:{challengeRef:'bad',ttlSeconds:120,message:mode.lastEnvelopeMessage},meta:{requestId:'rid-start'}});
   if(mode.start==='error') return result(503,{success:false,error:'SQL HOST AND ACCOUNT EXISTS',code:'RECOVERY_UNAVAILABLE'});
   return result(202,{success:true,data:{challengeRef:CHALLENGE,ttlSeconds:120,message:mode.lastEnvelopeMessage},meta:{requestId:'rid-start'}});
  }
  if(action==='verifyResetCode') {
   if(mode.verify==='invalid') return result(422,{success:false,error:'SQL/provider/account-identifying detail',code:'RECOVERY_CODE_INVALID_OR_EXPIRED'});
   if(mode.verify==='malformed') return result(200,{success:true,data:{passwordResetAllowed:true},meta:{requestId:'rid-verify'}});
   if(mode.verify==='server-error') return result(503,{success:false,error:'secret provider internals',code:'RECOVERY_UNAVAILABLE'});
   return result(200,{success:true,data:{resetAuthorizationToken:TOKEN,expiresInSeconds:600,passwordResetAllowed:true},meta:{requestId:'rid-verify'}});
  }
  if(action==='resetPassword') {
   if(mode.reset==='policy') return result(422,{success:false,error:'Server detail is not user copy',code:'PASSWORD_POLICY_VIOLATION'});
   if(mode.reset==='too-long') return result(422,{success:false,error:'bcrypt internals',code:'PASSWORD_TOO_LONG_FOR_RUNTIME'});
   if(mode.reset==='invalid-token') return result(401,{success:false,error:'Raw token detail',code:'RESET_AUTHORIZATION_INVALID_OR_EXPIRED'});
   if(mode.reset==='unknown') return result(0,{success:false,error:'Request timed out',code:'REQUEST_TIMEOUT'});
   if(mode.reset==='server-error') return result(503,{success:false,error:'Database transaction error',code:'RECOVERY_UNAVAILABLE'});
   return result(200,{success:true,data:{passwordReset:true,authenticated:false,sessionEstablished:false,message:'reset done'},meta:{requestId:'rid-reset'}});
  }
  return result(404,{success:false,error:'synthetic route missing',code:'NOT_FOUND'});
 };
 if(action==='forgotPassword' && mode.delayStart) return new Promise(resolve=>setTimeout(()=>resolve(response()),120));
 return response();
};
window.notificationSystem={info(){},success(){},error(){},warning(){}};
window.authSystem={open(){window.__loginOpenCount=(window.__loginOpenCount||0)+1;}};
'''
postlude = r'''
window.__test={ForgotPasswordSystem,calls:syntheticCalls,mode,TOKEN,CHALLENGE};
window.forgotPasswordSystem=new ForgotPasswordSystem();
window.__test.system=window.forgotPasswordSystem;
'''
script=prelude+'\n'+policy+'\n'+auth+'\n'+postlude
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage'])
 page=browser.new_page(viewport={'width':390,'height':844},locale='fa-IR')
 errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body>'+html+'</body></html>',wait_until='domcontentloaded')
 page.add_script_tag(content=script)
 page.evaluate('window.__test.system.open()')
 # Markup/accessible structure checks.
 assert page.locator('#forgotPasswordModal[role="dialog"][aria-modal="true"]').count()==1
 assert page.locator('#forgotPasswordTitle').count()==1
 assert page.locator('#forgotPasswordModal form').count()==1
 assert page.locator('form form').count()==0
 ids=page.locator('[id]').evaluate_all("els=>{const a=els.map(e=>e.id);return [...new Set(a.filter((x,i)=>a.indexOf(x)!==i))]}")
 assert not ids, f'duplicate DOM ids: {ids}'
 assert page.locator('#fpResetSection input[maxlength]').count()==0, 'password input must not be silently maxlength-truncated'
 # Native/JS input validity and supported channels.
 page.locator('#fpMethod').select_option('email'); page.locator('#fpIdentifier').fill('not-an-email')
 before=page.evaluate('window.__test.calls.length'); page.evaluate('window.__test.system.handleRequestCode()')
 assert page.evaluate('window.__test.calls.length')==before
 assert 'معتبر' in page.locator('#fpStatus').inner_text()
 page.locator('#fpIdentifier').fill('member@example.invalid')
 # Start must not echo provider response details; exact 202 + shape required.
 page.evaluate("window.__test.mode.lastEnvelopeMessage='ارسال قطعی به member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("window.__test.system.step==='verify'",timeout=3000)
 start_call=page.evaluate("window.__test.calls.find(c=>c.action==='forgotPassword')")
 assert start_call['body']=={'identifier':'member@example.invalid','method':'email'}
 assert 'userId' not in start_call['body'] and 'challengeRef' not in page.locator('#fpCodeInfo').inner_text()
 assert 'ارسال قطعی' not in page.locator('#fpCodeInfo').inner_text()
 assert 'اگر اطلاعات واردشده' in page.locator('#fpCodeInfo').inner_text()
 assert page.locator('#fpCode').evaluate('e=>e.required')
 # Persian and Arabic-Indic numerals are normalized to ASCII before contract submission.
 page.locator('#fpCode').fill('۱۲۳٤٥٦'); page.locator('#fpCode').dispatch_event('input')
 assert page.locator('#fpCode').input_value()=='123456'
 page.evaluate("window.__test.mode.verify='invalid'; window.__test.system.handleVerifyCode()")
 page.wait_for_function("window.__test.system.step==='request'",timeout=3000)
 assert 'SQL' not in page.locator('#fpStatus').inner_text() and 'provider' not in page.locator('#fpStatus').inner_text()
 # Retry-after is honored as bounded, user-safe guidance; no auto-retry is performed.
 page.evaluate("window.__test.mode.start='rate'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("document.getElementById('fpStatus').textContent.includes('2 ثانیه')",timeout=3000)
 assert len([c for c in page.evaluate('window.__test.calls') if c['action']=='forgotPassword'])==2
 assert 'PRIVATE' not in page.locator('#fpStatus').inner_text()
 assert page.locator('#forgotPasswordForm button[type=submit]').is_disabled()
 calls_at_cooldown=len(page.evaluate('window.__test.calls'))
 page.wait_for_timeout(2200)
 assert not page.locator('#forgotPasswordForm button[type=submit]').is_disabled()
 assert len(page.evaluate('window.__test.calls'))==calls_at_cooldown, 'Retry-After cooldown must not auto-retry'
 # Malformed success never advances to the next stage.
 page.evaluate("window.__test.mode.start='malformed'; window.__test.system.handleRequestCode()")
 page.wait_for_timeout(100)
 assert page.evaluate('window.__test.system.step')=='request'
 # Malformed OTP success is not trusted and must clear the attempt rather than advance.
 page.evaluate("window.__test.mode.start='success'; window.__test.mode.verify='malformed'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("window.__test.system.step==='verify'",timeout=3000)
 page.locator('#fpCode').fill('123456'); page.evaluate('window.__test.system.handleVerifyCode()')
 page.wait_for_function("window.__test.system.step==='request'",timeout=3000)
 assert page.evaluate('window.__test.system.resetAuthorizationToken')=='' and page.evaluate('window.__test.system.challengeRef')==''
 assert 'کد تأیید نشد' in page.locator('#fpStatus').inner_text()
 # A clean start and valid OTP yield a reset-only token in memory only.
 page.evaluate("window.__test.mode.start='success'; window.__test.mode.verify='success'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("window.__test.system.step==='verify'",timeout=3000)
 page.locator('#fpCode').fill('123456')
 page.evaluate('window.__test.system.handleVerifyCode()')
 page.wait_for_function("window.__test.system.step==='reset'",timeout=3000)
 assert page.evaluate('window.__test.system.resetAuthorizationToken')==page.evaluate('window.__test.TOKEN')
 storage_count=page.evaluate("(()=>{try{return Object.keys(localStorage).length+Object.keys(sessionStorage).length}catch{return -1}})()")
 recovery_source=auth[auth.find('class ForgotPasswordSystem {'):auth.find('// ============================================================\n// 📜 مودال قوانین')]
 if storage_count == -1:
  # page.set_content uses an opaque origin in this harness; runtime storage reads are unavailable here.
  assert 'localStorage' not in recovery_source and 'sessionStorage' not in recovery_source and 'indexedDB' not in recovery_source
 else:
  assert storage_count == 0, f'unexpected storage entries: {storage_count}'
 assert 'localStorage' not in recovery_source and 'sessionStorage' not in recovery_source and 'indexedDB' not in recovery_source
 assert page.evaluate('location.href.includes(window.__test.TOKEN)') is False
 assert page.locator('[data-reset-authorization], [data-token]').count()==0
 verify_call=page.evaluate("window.__test.calls.filter(c=>c.action==='verifyResetCode').slice(-1)[0]")
 assert verify_call['body']=={'identifier':'member@example.invalid','method':'email','challengeRef':page.evaluate('window.__test.CHALLENGE'),'code':'123456'}
 # Server policy failures retain the token for correction but never display provider/raw errors.
 page.locator('#fpNewPassword').fill('a'*15); page.locator('#fpConfirmPassword').fill('a'*15)
 page.evaluate("window.__test.mode.reset='policy'; window.__test.system.handleResetPassword()")
 page.wait_for_function("document.getElementById('fpStatus').textContent.includes('سیاست امنیتی سرور')",timeout=3000)
 assert 'Server detail' not in page.locator('#fpStatus').inner_text()
 assert page.evaluate('window.__test.system.step')=='reset'
 assert page.evaluate('window.__test.system.resetAuthorizationToken')==page.evaluate('window.__test.TOKEN')
 # Policy mismatch and over-byte inputs never call resetPassword.
 page.locator('#fpConfirmPassword').fill('different password')
 count_before=page.evaluate("window.__test.calls.filter(c=>c.action==='resetPassword').length")
 page.evaluate('window.__test.system.handleResetPassword()')
 assert page.evaluate("window.__test.calls.filter(c=>c.action==='resetPassword').length")==count_before
 page.locator('#fpNewPassword').fill('😀'*257); page.locator('#fpConfirmPassword').fill('😀'*257)
 page.evaluate('window.__test.system.handleResetPassword()')
 assert page.evaluate("window.__test.calls.filter(c=>c.action==='resetPassword').length")==count_before
 # Token invalid/expired is terminal; unknown outcome must not re-use a single-use capability.
 page.locator('#fpNewPassword').fill('A'*15); page.locator('#fpConfirmPassword').fill('A'*15)
 page.evaluate("window.__test.mode.reset='unknown'; window.__test.system.handleResetPassword()")
 page.wait_for_function("window.__test.system.resetAuthorizationToken==='' && window.__test.system.step==='request'",timeout=3000)
 assert 'تأیید نشد' in page.locator('#fpStatus').inner_text()
 count_after=page.evaluate("window.__test.calls.filter(c=>c.action==='resetPassword').length")
 assert count_after==count_before+1, 'ambiguous reset outcome must not auto-retry'
 # Establish token again, then test terminal clear on close and no auto-login.
 page.evaluate("window.__test.mode.reset='success'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("window.__test.system.step==='verify'",timeout=3000)
 page.locator('#fpCode').fill('123456'); page.evaluate('window.__test.system.handleVerifyCode()')
 page.wait_for_function("window.__test.system.step==='reset'",timeout=3000)
 page.locator('#fpNewPassword').fill('A'*15); page.locator('#fpConfirmPassword').fill('A'*15)
 page.evaluate('window.__test.system.handleResetPassword()')
 page.wait_for_function("window.__test.system.resetAuthorizationToken==='' && !document.getElementById('forgotPasswordModal').classList.contains('active')",timeout=3000)
 reset_call=page.evaluate("window.__test.calls.filter(c=>c.action==='resetPassword').slice(-1)[0]")
 assert reset_call['headers'].get('x-reset-authorization')==page.evaluate('window.__test.TOKEN')
 assert reset_call['body']=={'newPassword':'A'*15} and 'resetAuthorizationToken' not in reset_call['body'] and 'userId' not in reset_call['body']
 page.wait_for_function('(window.__loginOpenCount||0)===1',timeout=1500)
 assert page.evaluate('window.__loginOpenCount||0')==1, 'successful reset only reopens ordinary login; it does not establish session'
 # Invalid/unsupported method and server error never produce a success or expose internal detail.
 page.evaluate('window.__test.system.open()')
 start_count=page.evaluate("window.__test.calls.filter(c=>c.action==='forgotPassword').length")
 page.evaluate("document.getElementById('fpMethod').value=''; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 assert page.evaluate("window.__test.calls.filter(c=>c.action==='forgotPassword').length")==start_count
 page.evaluate("window.__test.mode.start='error'; document.getElementById('fpMethod').value='email'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode()")
 page.wait_for_function("document.getElementById('fpStatus').textContent.includes('وجود حساب')",timeout=3000)
 assert 'SQL' not in page.locator('#fpStatus').inner_text() and page.evaluate('window.__test.system.step')=='request'
 # A duplicate submit while the first request is pending must result in one API call.
 page.evaluate("window.__test.mode.start='success'; window.__test.mode.delayStart=true; window.__test.system.open(); document.getElementById('fpMethod').value='email'; document.getElementById('fpIdentifier').value='member@example.invalid'")
 duplicate_before=page.evaluate("window.__test.calls.filter(c=>c.action==='forgotPassword').length")
 page.evaluate("window.__test.system.handleRequestCode(); window.__test.system.handleRequestCode()")
 page.wait_for_function("window.__test.system.step==='verify'",timeout=3000)
 assert page.evaluate("window.__test.calls.filter(c=>c.action==='forgotPassword').length")==duplicate_before+1
 page.evaluate("window.__test.mode.delayStart=false; window.__test.system.close()")
 # Accessibility: keyboard Tab wraps within the dialog and close restores the invoking focus.
 page.evaluate("window.__p6cOpener=document.createElement('button'); window.__p6cOpener.id='p6cOpener'; document.body.appendChild(window.__p6cOpener); window.__p6cOpener.focus(); window.__test.system.open()")
 page.wait_for_function("document.activeElement.id==='fpIdentifier'",timeout=3000)
 page.locator('#fpBackToLoginBtn').focus(); page.keyboard.press('Tab')
 assert page.evaluate("document.activeElement.classList.contains('auth-close')")
 page.keyboard.press('Shift+Tab')
 assert page.evaluate("document.activeElement.id==='fpBackToLoginBtn'")
 page.evaluate('window.__test.system.close()')
 page.wait_for_function("document.activeElement.id==='p6cOpener'",timeout=3000)
 # Closing during a delayed start aborts/invalidate stale response and restores controls.
 page.evaluate("window.__test.mode.start='delay'; window.__test.mode.delayStart=true; window.__test.system.open(); document.getElementById('fpMethod').value='email'; document.getElementById('fpIdentifier').value='member@example.invalid'; window.__test.system.handleRequestCode(); setTimeout(()=>window.__test.system.close(),25)")
 page.wait_for_timeout(180)
 assert page.evaluate('window.__test.system.step')=='request'
 assert page.locator('#forgotPasswordModal').evaluate("e=>e.hasAttribute('hidden') && e.getAttribute('aria-hidden')==='true'")
 assert not page.locator('#forgotPasswordForm button[type=submit]').is_disabled()
 assert page.evaluate('window.__test.system.challengeRef')=='' and page.evaluate('window.__test.system.resetAuthorizationToken')==''
 # Escape closes only this modal; a later document listener must not also fire.
 page.evaluate("window.__test.mode.delayStart=false; window.__test.system.open(); window.__otherEscape=false; document.addEventListener('keydown',()=>window.__otherEscape=true,true)")
 page.keyboard.press('Escape')
 assert page.locator('#forgotPasswordModal').evaluate("e=>e.hasAttribute('hidden')")
 assert page.evaluate('window.__otherEscape') is False
 assert not errors, errors
 print(json.dumps({'status':'PASS','assertion_groups':19,'browser':'Chromium / Playwright set_content harness','scope':'real effective P6B auth.js class and Recovery markup, API fixture only','limitations':'not full-site XAMPP/Apache E2E; no live DB/Auth, Telegram or Email integration; localStorage runtime inspection unavailable in opaque origin, source/static persistence guards were used'},ensure_ascii=False,indent=2))
 browser.close()
