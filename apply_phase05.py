from pathlib import Path

ROOT = Path('/mnt/data/MR-NEON-PHASE05-WORK')

def replace_file(rel, old, new, label):
    p = ROOT / rel
    s = p.read_text(encoding='utf-8')
    n = s.count(old)
    if n != 1:
        raise SystemExit(f'{rel}: expected one match for {label}, found {n}')
    p.write_text(s.replace(old, new, 1), encoding='utf-8')

# 1) TourGuideV7: stable listener references and storage-safe seen marker.
replace_file('js/app.js',
'''    this.seen = localStorage.getItem("mrneon_tour_seen_v7");
    this.overlay = DOM.tourOverlay;''',
'''    try {
      this.seen = localStorage.getItem("mrneon_tour_seen_v7");
    } catch {
      // A restricted storage context must not prevent the public site from initializing.
      this.seen = "unavailable";
    }
    this._nextHandler = () => this.next();
    this._prevHandler = () => this.prev();
    this._skipHandler = () => this.end();
    this.overlay = DOM.tourOverlay;''', 'tour storage and handler fields')
replace_file('js/app.js',
'''    this.nextBtn.addEventListener("click", () => this.next());
    this.prevBtn.addEventListener("click", () => this.prev());
    this.skipBtn.addEventListener("click", () => this.end());''',
'''    this.nextBtn.addEventListener("click", this._nextHandler);
    this.prevBtn.addEventListener("click", this._prevHandler);
    this.skipBtn.addEventListener("click", this._skipHandler);''', 'tour stable event binding')
replace_file('js/app.js',
'''    this.nextBtn.removeEventListener("click", this.next);
    this.prevBtn.removeEventListener("click", this.prev);
    this.skipBtn.removeEventListener("click", this.end);''',
'''    this.nextBtn.removeEventListener("click", this._nextHandler);
    this.prevBtn.removeEventListener("click", this._prevHandler);
    this.skipBtn.removeEventListener("click", this._skipHandler);''', 'tour stable event unbinding')
replace_file('js/app.js',
'''    localStorage.setItem("mrneon_tour_seen_v7", "1");''',
'''    try {
      localStorage.setItem("mrneon_tour_seen_v7", "1");
    } catch {
      // Tour dismissal remains effective for this page even when persistent storage is blocked.
    }''', 'tour storage write')
replace_file('js/app.js',
'''    target.scrollIntoView({ behavior: "smooth", block: "center" });''',
'''    target.scrollIntoView({ behavior: CONFIG.isReducedMotion ? "auto" : "smooth", block: "center" });''', 'tour reduced-motion scrolling')

# 2) AuthSystem: coalesce session restore and prevent a late restore response overwriting a newer login/logout.
replace_file('js/auth.js',
'''  static currentSession = null;
  static sessionChecked = false;
  static sessionRestoreError = null;
  static legacyStorageCleaned = false;''',
'''  static currentSession = null;
  static sessionChecked = false;
  static sessionRestoreError = null;
  static sessionRestorePromise = null;
  static sessionRevision = 0;
  static legacyStorageCleaned = false;''', 'auth session lifecycle fields')
replace_file('js/auth.js',
'''  static applyServerSession(payload) {
    AuthSystem.sessionChecked = true;''',
'''  static applyServerSession(payload) {
    AuthSystem.sessionRevision += 1;
    AuthSystem.sessionChecked = true;''', 'auth session revision increment')
old = '''  static async restoreSession() {
    try {
      const payload = await getServerSession();
      AuthSystem.sessionRestoreError = null;
      return AuthSystem.applyServerSession(payload);
    } catch (error) {
      // A failed server check is not an authoritative anonymous state.
      AuthSystem.sessionChecked = false;
      AuthSystem.currentSession = null;
      AuthSystem.sessionRestoreError = error instanceof Error ? error : new Error("بررسی نشست انجام نشد.");
      return null;
    }
  }'''
new = '''  static async restoreSession() {
    if (AuthSystem.sessionRestorePromise) return AuthSystem.sessionRestorePromise;
    const revisionAtStart = AuthSystem.sessionRevision;
    const restorePromise = (async () => {
      try {
        const payload = await getServerSession();
        // A login/logout that completed while this request was in flight is newer authority.
        if (AuthSystem.sessionRevision !== revisionAtStart) return AuthSystem.currentSession;
        AuthSystem.sessionRestoreError = null;
        return AuthSystem.applyServerSession(payload);
      } catch (error) {
        // A failed server check is not proof that the user is anonymous. Do not overwrite
        // a newer explicit login/logout that completed while the restore was pending.
        if (AuthSystem.sessionRevision !== revisionAtStart) return AuthSystem.currentSession;
        AuthSystem.sessionChecked = false;
        AuthSystem.currentSession = null;
        AuthSystem.sessionRestoreError = error instanceof Error ? error : new Error("بررسی نشست انجام نشد.");
        return null;
      } finally {
        if (AuthSystem.sessionRestorePromise === restorePromise) AuthSystem.sessionRestorePromise = null;
      }
    })();
    AuthSystem.sessionRestorePromise = restorePromise;
    return restorePromise;
  }'''
replace_file('js/auth.js', old, new, 'coalesced session restore')
replace_file('js/auth.js',
'''      if (existingSession.type === "admin") {
        this.notification?.info("نشست مدیر معتبر است؛ عملیات دادهٔ پنل در موج‌های بعدی فعال می‌شود.");
      } else {
        window.userPanel?.openPanel();
      }''',
'''      if (existingSession.type === "admin") {
        await window.adminPanel?.openPanel();
      } else {
        window.userPanel?.openPanel();
      }''', 'restore admin session navigation')

# 3) Wire the existing admin login modal and improve truthful logout/session-expiry handling.
replace_file('components/admin-modal.html',
'''  id="adminLoginModal"
  role="dialog"''',
'''  id="adminLoginModal"
  hidden
  aria-hidden="true"
  role="dialog"''', 'admin login modal initial accessibility state')
replace_file('index.php',
'''            <a href="#contact" class="footer-link">تماس با ما</a>
          </div>''',
'''            <a href="#contact" class="footer-link">تماس با ما</a>
            <button type="button" class="footer-link" id="adminAccessBtn" aria-haspopup="dialog">ورود مدیریت</button>
          </div>''', 'admin modal entry point')

p = ROOT / 'js/admin.js'
s = p.read_text(encoding='utf-8')
old = '''    this.currentDetailId = null;
    this.detailReturnFocus = null;
    this.notification = window.notificationSystem;
    this.init();'''
new = '''    this.currentDetailId = null;
    this.currentDetailRequestSeq = 0;
    this.detailReturnFocus = null;
    this.loginReturnFocus = null;
    this.panelReturnFocus = null;
    this.replySendingId = null;
    this.noteSavingId = null;
    this.notification = window.notificationSystem;
    this.init();'''
if s.count(old) != 1: raise SystemExit('admin.js constructor state match issue')
s = s.replace(old, new, 1)
old = '''    DOM.adminLoginForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      void this.handleLogin();
    });
    DOM.adminDataClose?.addEventListener("click", () => this.closePanel());'''
new = '''    DOM.adminLoginForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      void this.handleLogin();
    });
    DOM.adminModalClose?.addEventListener("click", () => this.closeLoginModal());
    DOM.adminModalOverlay?.addEventListener("click", () => this.closeLoginModal());
    document.getElementById("adminAccessBtn")?.addEventListener("click", (event) => {
      this.openLoginModal(event.currentTarget);
    });
    DOM.adminDataClose?.addEventListener("click", () => this.closePanel());'''
if s.count(old) != 1: raise SystemExit('admin.js init login modal listeners match issue')
s = s.replace(old, new, 1)
old = '''    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      if (!document.getElementById("adminProjectDetailModal")?.hidden) {
        this.closeProjectDetail();
        return;
      }
      if (DOM.adminDataModal?.classList.contains("active")) this.closePanel();
    });'''
new = '''    document.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      if (!document.getElementById("adminProjectDetailModal")?.hidden) {
        this.closeProjectDetail();
        return;
      }
      if (DOM.adminDataModal?.classList.contains("active")) {
        this.closePanel();
        return;
      }
      if (DOM.adminLoginModal?.classList.contains("active")) this.closeLoginModal();
    });'''
if s.count(old) != 1: raise SystemExit('admin.js escape listener match issue')
s = s.replace(old, new, 1)
# Add methods before getAdminSession().
anchor = '''  getAdminSession() {'''
methods = '''  openLoginModal(trigger = null) {
    if (this.getAdminSession()) {
      void this.openPanel();
      return;
    }
    this.loginReturnFocus = trigger instanceof HTMLElement ? trigger : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    DOM.adminLoginModal?.removeAttribute("hidden");
    DOM.adminLoginModal?.classList.add("active");
    DOM.adminLoginModal?.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    window.setTimeout(() => document.getElementById("adminUsername")?.focus(), 0);
  }

  closeLoginModal(restoreFocus = true) {
    DOM.adminLoginModal?.classList.remove("active");
    DOM.adminLoginModal?.setAttribute("aria-hidden", "true");
    DOM.adminLoginModal?.setAttribute("hidden", "");
    const restoreTarget = this.loginReturnFocus;
    this.loginReturnFocus = null;
    this.syncBodyScrollLock();
    if (restoreFocus && restoreTarget?.isConnected) restoreTarget.focus();
  }

  syncBodyScrollLock() {
    const activeModal = document.querySelector(
      ".auth-modal.active, .register-modal.active, .terms-modal.active, .user-panel-modal.active, .neo-modal.active, .thank-you-modal.active, .receipt-modal.active, .admin-login-modal.active, .admin-data-modal.active, .customer-project-detail-modal.active, .admin-project-detail-modal:not([hidden])",
    );
    document.body.style.overflow = activeModal ? "hidden" : "";
  }

  handleAdminAuthFailure(error) {
    if (Number(error?.status) !== 401) return false;
    const authClass = window.authSystem?.constructor;
    authClass?.applyServerSession?.({ authenticated: false, principalType: null });
    this.projects = [];
    this.users = [];
    this.currentDetailId = null;
    this.currentDetailRequestSeq += 1;
    this.closeProjectDetail(false);
    this.closePanel();
    this.closeLoginModal(false);
    this.notification?.error("نشست مدیریت منقضی شده است؛ برای ادامه دوباره وارد شوید.");
    return true;
  }

'''
if s.count(anchor) != 1: raise SystemExit('admin method insertion anchor issue')
s = s.replace(anchor, methods + anchor, 1)
# Replace login success/validation block.
old = '''      const result = await apiRequest("adminLogin", { method: "POST", body: { username, password, totpCode } });
      window.authSystem?.constructor?.applyServerSession(result);
      const passwordInput = document.getElementById("adminPassword");
      const totpInput = document.getElementById("adminTotpCode");
      if (passwordInput) passwordInput.value = "";
      if (totpInput) totpInput.value = "";
      this.notification?.success("ورود مدیر تأیید شد.");
      await this.openPanel();
    } catch (error) {
      this.notification?.error(error?.message || "ورود امن مدیر تأیید نشد.");'''
new = '''      const result = await apiRequest("adminLogin", { method: "POST", body: { username, password, totpCode } });
      if (result?.authenticated !== true || result?.principalType !== "admin" || result?.admin?.role !== "admin") {
        throw new Error("پاسخ ورود مدیر، نشست معتبر و تأییدشده‌ای ارائه نکرد.");
      }
      const authClass = window.authSystem?.constructor;
      if (typeof authClass?.applyServerSession !== "function" || !authClass.applyServerSession(result)) {
        throw new Error("نشست مدیر در رابط قابل تأیید نیست.");
      }
      const passwordInput = document.getElementById("adminPassword");
      const totpInput = document.getElementById("adminTotpCode");
      if (passwordInput) passwordInput.value = "";
      if (totpInput) totpInput.value = "";
      this.panelReturnFocus = this.loginReturnFocus;
      this.closeLoginModal(false);
      this.notification?.success("ورود مدیر توسط سرور تأیید شد.");
      await this.openPanel();
    } catch (error) {
      this.notification?.error(error?.message || "ورود امن مدیر تأیید نشد.");'''
if s.count(old) != 1: raise SystemExit('admin login response validation match issue')
s = s.replace(old, new, 1)
# openPanel must validate modal exists to avoid a partial initialization crash.
old = '''    DOM.adminDataModal.classList.add("active");
    DOM.adminDataModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";'''
new = '''    if (!DOM.adminDataModal) {
      this.notification?.error("ساختار پنل مدیریت در صفحه در دسترس نیست.");
      return;
    }
    DOM.adminDataModal.classList.add("active");
    DOM.adminDataModal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";'''
if s.count(old) != 1: raise SystemExit('admin open panel guard match issue')
s = s.replace(old, new, 1)
# closePanel sync scroll and focus restore.
old = '''  closePanel() {
    this.closeProjectDetail(false);
    DOM.adminDataModal?.classList.remove("active");
    DOM.adminDataModal?.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
  }'''
new = '''  closePanel() {
    this.closeProjectDetail(false);
    DOM.adminDataModal?.classList.remove("active");
    DOM.adminDataModal?.setAttribute("aria-hidden", "true");
    this.syncBodyScrollLock();
    const target = this.panelReturnFocus;
    this.panelReturnFocus = null;
    if (target?.isConnected) target.focus();
  }'''
if s.count(old) != 1: raise SystemExit('admin close panel match issue')
s = s.replace(old, new, 1)
# Logout is only locally accepted after canonical server logout payload.
old = '''      await apiRequest("logout", { method: "POST", body: {} });
      window.authSystem?.constructor?.applyServerSession({ authenticated: false });
      window.location.reload();'''
new = '''      const result = await apiRequest("logout", { method: "POST", body: {} });
      if (result?.authenticated !== false || result?.principalType !== null) {
        throw new Error("پایان نشست مدیر توسط سرور تأیید نشد.");
      }
      window.authSystem?.constructor?.applyServerSession(result);
      this.projects = [];
      this.users = [];
      this.closePanel();
      this.closeLoginModal(false);
      window.location.reload();'''
if s.count(old) != 1: raise SystemExit('admin logout result validation match issue')
s = s.replace(old, new, 1)
# Detail open request seq prevents a slower prior request for the same project replacing the newest response.
old = '''    this.currentDetailId = id;
    if (trigger) this.detailReturnFocus = trigger;'''
new = '''    this.currentDetailId = id;
    const detailRequestSeq = ++this.currentDetailRequestSeq;
    if (trigger) this.detailReturnFocus = trigger;'''
if s.count(old) != 1: raise SystemExit('admin detail seq start match issue')
s = s.replace(old, new, 1)
old = '''      if (this.currentDetailId !== id || modal.hidden) return;'''
new = '''      if (this.currentDetailId !== id || detailRequestSeq !== this.currentDetailRequestSeq || modal.hidden) return;'''
if s.count(old) != 1: raise SystemExit('admin detail seq response match issue')
s = s.replace(old, new, 1)
# Every detail close invalidates outstanding detail responses.
old = '''    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    this.currentDetailId = null;'''
new = '''    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    this.currentDetailRequestSeq += 1;
    this.currentDetailId = null;'''
if s.count(old) != 1: raise SystemExit('admin detail close invalidation match issue')
s = s.replace(old, new, 1)
# Prevent keyboard/submit duplicates for the same message or internal note.
old = '''    if (!id || !text || text.length > 2000) {
      if (status) { status.textContent = "پیام باید بین ۱ تا ۲۰۰۰ نویسه باشد."; status.dataset.state = "error"; }
      input?.focus();
      return;
    }
    if (button) button.disabled = true;'''
new = '''    if (!id || !text || text.length > 2000) {
      if (status) { status.textContent = "پیام باید بین ۱ تا ۲۰۰۰ نویسه باشد."; status.dataset.state = "error"; }
      input?.focus();
      return;
    }
    if (this.replySendingId === id) return;
    this.replySendingId = id;
    if (button) button.disabled = true;'''
if s.count(old) != 1: raise SystemExit('admin reply duplicate prevention start match issue')
s = s.replace(old, new, 1)
old = '''    } finally {
      const currentForm = document.getElementById("adminProjectReplyForm");
      const currentButton = currentForm?.querySelector("#adminProjectReplySubmit");
      if (currentButton && this.currentDetailId === id && !document.getElementById("adminProjectDetailModal")?.hidden) currentButton.disabled = false;
    }
  }

  async addInternalNote() {'''
new = '''    } finally {
      if (this.replySendingId === id) this.replySendingId = null;
      const currentForm = document.getElementById("adminProjectReplyForm");
      const currentButton = currentForm?.querySelector("#adminProjectReplySubmit");
      if (currentButton && this.currentDetailId === id && !document.getElementById("adminProjectDetailModal")?.hidden) currentButton.disabled = false;
    }
  }

  async addInternalNote() {'''
if s.count(old) != 1: raise SystemExit('admin reply duplicate prevention end match issue')
s = s.replace(old, new, 1)
old = '''    if (!id || !text) {
      this.notification?.error("متن یادداشت داخلی را وارد کنید.");
      return;
    }
    if (button) button.disabled = true;
    try {'''
new = '''    if (!id || !text) {
      this.notification?.error("متن یادداشت داخلی را وارد کنید.");
      return;
    }
    if (this.noteSavingId === id) return;
    this.noteSavingId = id;
    if (button) button.disabled = true;
    try {'''
if s.count(old) != 1: raise SystemExit('admin note duplicate prevention start match issue')
s = s.replace(old, new, 1)
old = '''    } finally {
      if (button && !document.getElementById("adminProjectDetailModal")?.hidden) button.disabled = false;
    }
  }

  async loadUsers() {'''
new = '''    } finally {
      if (this.noteSavingId === id) this.noteSavingId = null;
      if (button && !document.getElementById("adminProjectDetailModal")?.hidden) button.disabled = false;
    }
  }

  async loadUsers() {'''
if s.count(old) != 1: raise SystemExit('admin note duplicate prevention end match issue')
s = s.replace(old, new, 1)
# Handle expired admin sessions in guarded admin API flows.
# Explicit catches use this pattern exactly where a user-facing status is updated.
s = s.replace('''    } catch (error) {
      if (seq !== this.statsRequestSeq) return;
      ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => this.setMetric(id, null));''',
'''    } catch (error) {
      if (seq !== this.statsRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => this.setMetric(id, null));''', 1)
s = s.replace('''    } catch (error) {
      if (seq !== this.projectRequestSeq) return;
      this.projects = [];''',
'''    } catch (error) {
      if (seq !== this.projectRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      this.projects = [];''', 1)
s = s.replace('''    } catch (error) {
      this.notification?.error(error?.message || "تغییر وضعیت ثبت نشد؛ مقدار سرور تغییر داده نشد.");''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "تغییر وضعیت ثبت نشد؛ مقدار سرور تغییر داده نشد.");''', 1)
s = s.replace('''    } catch (error) {
      this.notification?.error(error?.message || "بایگانی پروژه تأیید نشد.");''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "بایگانی پروژه تأیید نشد.");''', 1)
s = s.replace('''    } catch (error) {
      status.textContent = error?.message || "جزئیات پروژه دریافت نشدند.";''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      status.textContent = error?.message || "جزئیات پروژه دریافت نشدند.";''', 1)
s = s.replace('''    } catch (error) {
      if (status) { status.textContent = error?.message || "ثبت پاسخ تأیید نشد؛ متن برای تلاش دوباره حفظ شده است."; status.dataset.state = "error"; }''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      if (status) { status.textContent = error?.message || "ثبت پاسخ تأیید نشد؛ متن برای تلاش دوباره حفظ شده است."; status.dataset.state = "error"; }''', 1)
s = s.replace('''    } catch (error) {
      this.notification?.error(error?.message || "ذخیره یادداشت داخلی تأیید نشد.");''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "ذخیره یادداشت داخلی تأیید نشد.");''', 1)
s = s.replace('''    } catch (error) {
      if (seq !== this.userRequestSeq) return;
      this.users = [];''',
'''    } catch (error) {
      if (seq !== this.userRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      this.users = [];''', 1)
s = s.replace('''    } catch (error) {
      this.notification?.error(error?.message || "تغییر وضعیت حساب تأیید نشد.");''',
'''    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "تغییر وضعیت حساب تأیید نشد.");''', 1)
p.write_text(s, encoding='utf-8')

# 4) Rebuild the receipt view with HTML escaping, a locally bundled image export, and truthful receipt gating.
p = ROOT / 'js/neo.js'
s = p.read_text(encoding='utf-8')
marker = '// ============================================================\n// 🎉 مودال تشکر\n// ============================================================'
idx = s.find(marker)
if idx < 0: raise SystemExit('neo.js thank-you marker not found')
new_tail = r'''// Shared modal scroll-lock reconciliation for stacked MR NEON dialogs.
function syncModalScrollLock() {
  const activeModal = document.querySelector(
    ".auth-modal.active, .register-modal.active, .terms-modal.active, .user-panel-modal.active, .neo-modal.active, .thank-you-modal.active, .receipt-modal.active, .admin-login-modal.active, .admin-data-modal.active, .customer-project-detail-modal.active, .admin-project-detail-modal:not([hidden])",
  );
  document.body.style.overflow = activeModal ? "hidden" : "";
}

function escapeReceiptHTML(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);
}

async function copyTextWithFallback(value) {
  const text = String(value ?? "");
  if (!text) throw new Error("متنی برای کپی وجود ندارد.");
  if (navigator.clipboard?.writeText && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const field = document.createElement("textarea");
  field.value = text;
  field.setAttribute("readonly", "");
  field.style.position = "fixed";
  field.style.insetInlineStart = "-9999px";
  field.style.top = "0";
  document.body.appendChild(field);
  field.select();
  let copied = false;
  try {
    copied = document.execCommand("copy");
  } finally {
    field.remove();
  }
  if (!copied) throw new Error("دسترسی به کلیپ‌بورد در این مرورگر فراهم نیست.");
}

async function loadLocalHtml2Canvas() {
  if (typeof window.html2canvas === "function") return window.html2canvas;
  if (window.__mrNeonHtml2CanvasPromise) return window.__mrNeonHtml2CanvasPromise;
  window.__mrNeonHtml2CanvasPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-mrneon-html2canvas="true"]');
    const script = existing || document.createElement("script");
    const cleanup = () => {
      script.removeEventListener("load", onLoad);
      script.removeEventListener("error", onError);
    };
    const onLoad = () => {
      cleanup();
      if (typeof window.html2canvas === "function") resolve(window.html2canvas);
      else reject(new Error("ابزار ذخیرهٔ تصویر بارگذاری شد اما در دسترس نیست."));
    };
    const onError = () => {
      cleanup();
      reject(new Error("ابزار ذخیرهٔ تصویر محلی بارگذاری نشد."));
    };
    script.addEventListener("load", onLoad, { once: true });
    script.addEventListener("error", onError, { once: true });
    if (!existing) {
      script.src = new URL("assets/js/html2canvas.min.js", document.baseURI).href;
      script.async = true;
      script.dataset.mrneonHtml2canvas = "true";
      document.head.appendChild(script);
    } else if (typeof window.html2canvas === "function") {
      cleanup();
      resolve(window.html2canvas);
    }
  }).catch((error) => {
    window.__mrNeonHtml2CanvasPromise = null;
    throw error;
  });
  return window.__mrNeonHtml2CanvasPromise;
}

// ============================================================
// 🎉 مودال تشکر
// ============================================================
export class ThankYouModal {
  constructor() {
    this.modal = DOM.thankYouModal;
    this.returnFocus = null;
    this.init();
  }
  init() {
    document.getElementById("thankYouCopyBtn")?.addEventListener("click", async () => {
      const code = document.getElementById("thankYouTrackingCode")?.textContent || "";
      try {
        await copyTextWithFallback(code);
        window.notificationSystem?.success("کد پیگیری کپی شد.");
      } catch (error) {
        window.notificationSystem?.error(error?.message || "کپی کد پیگیری در دسترس نیست.");
      }
    });
    document.getElementById("goToPanelBtn")?.addEventListener("click", () => {
      this.close();
      window.setTimeout(() => window.userPanel?.openPanel(), 0);
    });
    document.getElementById("viewReceiptBtn")?.addEventListener("click", () => {
      this.close();
      if (window.lastSubmittedProject) window.receiptModal?.show(window.lastSubmittedProject);
      else window.notificationSystem?.error("رسید معتبر برای این درخواست در دسترس نیست.");
    });
    this.modal?.querySelector(".thank-you-backdrop")?.addEventListener("click", () => this.close());
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && this.modal?.classList.contains("active")) this.close();
    });
  }
  show(trackingCode, isLoggedIn) {
    const receipt = window.lastSubmittedProject;
    if (!receipt?.projectId || receipt?.status !== "submitted" || typeof receipt?.trackingCode !== "string"
        || !receipt.trackingCode || receipt.trackingCode !== String(trackingCode || "")) {
      window.notificationSystem?.error("تأیید ثبت درخواست از سرور برای نمایش رسید معتبر نیست.");
      return;
    }
    this.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const trackingNode = document.getElementById("thankYouTrackingCode");
    const panelButton = document.getElementById("goToPanelBtn");
    if (trackingNode) trackingNode.textContent = receipt.trackingCode;
    if (panelButton) panelButton.hidden = !isLoggedIn;
    this.modal?.classList.add("active");
    this.modal?.removeAttribute("hidden");
    this.modal?.setAttribute("aria-hidden", "false");
    syncModalScrollLock();
    window.setTimeout(() => document.getElementById("thankYouCopyBtn")?.focus(), 0);
  }
  close() {
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncModalScrollLock();
    if (this.returnFocus?.isConnected) this.returnFocus.focus();
    this.returnFocus = null;
  }
}

// ============================================================
// 🧾 رسید معتبر پروژه
// ============================================================
export class ReceiptModal {
  constructor() {
    this.modal = DOM.receiptModal;
    this.overlay = DOM.receiptOverlay;
    this.closeBtn = DOM.receiptClose;
    this.body = DOM.receiptBody;
    this.copyBtn = DOM.receiptCopyBtn;
    this.shareBtn = DOM.receiptShareBtn;
    this.downloadBtn = DOM.receiptDownloadBtn;
    this.printBtn = DOM.receiptPrintBtn;
    this.returnFocus = null;
    this.downloadInProgress = false;
    this.init();
  }

  init() {
    this.closeBtn?.addEventListener("click", () => this.close());
    this.overlay?.addEventListener("click", () => this.close());
    this.copyBtn?.addEventListener("click", async () => {
      const code = this.body?.querySelector(".receipt-code-val")?.textContent || "";
      try {
        await copyTextWithFallback(code);
        window.notificationSystem?.success("کد پیگیری کپی شد.");
      } catch (error) {
        window.notificationSystem?.error(error?.message || "کپی کد در دسترس نیست.");
      }
    });
    this.shareBtn?.addEventListener("click", async () => {
      const code = this.body?.querySelector(".receipt-code-val")?.textContent || "";
      const text = `درخواست پروژه در MR NEON ثبت شد.\nکد پیگیری: ${code}\n${window.location.origin}`;
      try {
        if (navigator.share) {
          await navigator.share({ title: "MR NEON — رسید ثبت پروژه", text, url: window.location.origin });
        } else {
          await copyTextWithFallback(text);
          window.notificationSystem?.success("متن اشتراک‌گذاری کپی شد.");
        }
      } catch (error) {
        if (error?.name !== "AbortError") window.notificationSystem?.error(error?.message || "اشتراک‌گذاری در دسترس نیست.");
      }
    });
    this.downloadBtn?.addEventListener("click", () => void this.downloadReceiptImage());
    this.printBtn?.addEventListener("click", () => window.print());
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && this.modal?.classList.contains("active")) this.close();
      if (event.key !== "Tab" || !this.modal?.classList.contains("active")) return;
      const focusable = [...this.modal.querySelectorAll('button:not([disabled]):not([hidden]), a[href], input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
        .filter((element) => element.getClientRects().length > 0);
      if (!focusable.length) { event.preventDefault(); return; }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
  }

  open() {
    this.returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    this.modal?.classList.add("active");
    this.modal?.removeAttribute("hidden");
    this.modal?.setAttribute("aria-hidden", "false");
    syncModalScrollLock();
    window.setTimeout(() => this.closeBtn?.focus(), 0);
  }

  close() {
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncModalScrollLock();
    if (this.returnFocus?.isConnected) this.returnFocus.focus();
    this.returnFocus = null;
  }

  async downloadReceiptImage() {
    if (this.downloadInProgress || !this.body?.querySelector(".receipt-code-val")) return;
    const sourceContainer = document.getElementById("receiptContainer");
    if (!sourceContainer) {
      window.notificationSystem?.error("محتوای رسید برای ذخیره در دسترس نیست.");
      return;
    }
    this.downloadInProgress = true;
    const button = this.downloadBtn;
    const originalText = button?.textContent || "📥 دانلود عکس";
    if (button) { button.disabled = true; button.setAttribute("aria-busy", "true"); button.textContent = "در حال آماده‌سازی تصویر…"; }
    let clone = null;
    try {
      const html2canvas = await loadLocalHtml2Canvas();
      clone = sourceContainer.cloneNode(true);
      clone.removeAttribute("id");
      clone.querySelectorAll("[id]").forEach((node) => node.removeAttribute("id"));
      clone.querySelector(".receipt-footer")?.remove();
      clone.querySelector(".receipt-close")?.remove();
      clone.style.position = "fixed";
      clone.style.top = "0";
      clone.style.left = "-10000px";
      clone.style.right = "auto";
      clone.style.width = `${Math.max(320, sourceContainer.clientWidth)}px`;
      clone.style.maxHeight = "none";
      clone.style.height = "auto";
      clone.style.overflow = "visible";
      clone.style.transform = "none";
      clone.style.opacity = "1";
      clone.style.zIndex = "-1";
      clone.querySelector(".receipt-body")?.style.setProperty("max-height", "none");
      clone.querySelector(".receipt-body")?.style.setProperty("overflow", "visible");
      document.body.appendChild(clone);
      const canvas = await html2canvas(clone, {
        backgroundColor: "#0f172a",
        scale: Math.min(Math.max(window.devicePixelRatio || 1, 1), 2),
        useCORS: false,
        logging: false,
        width: clone.scrollWidth,
        height: clone.scrollHeight,
        windowWidth: Math.max(document.documentElement.clientWidth, clone.scrollWidth),
        windowHeight: Math.max(document.documentElement.clientHeight, clone.scrollHeight),
      });
      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob((value) => value ? resolve(value) : reject(new Error("تصویر رسید تولید نشد.")), "image/png");
      });
      const url = URL.createObjectURL(blob);
      const code = this.body.querySelector(".receipt-code-val")?.textContent || "MR-NEON-receipt";
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `${code.replace(/[^A-Za-z0-9_-]/g, "_")}-receipt.png`;
      anchor.hidden = true;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      window.notificationSystem?.success("تصویر رسید آماده و ذخیره شد.");
    } catch (error) {
      window.notificationSystem?.error(error?.message || "ذخیرهٔ تصویر رسید انجام نشد.");
    } finally {
      clone?.remove();
      this.downloadInProgress = false;
      if (button) { button.disabled = false; button.removeAttribute("aria-busy"); button.textContent = originalText; }
    }
  }

  show(data) {
    if (!data || typeof data !== "object" || !data.projectId || data.status !== "submitted"
        || typeof data.trackingCode !== "string" || !data.trackingCode.trim()) {
      window.notificationSystem?.error("رسید فقط پس از تأیید ثبت درخواست توسط سرور قابل نمایش است.");
      return;
    }
    const safe = (value, fallback = "ثبت نشده") => value !== null && value !== undefined && String(value).trim() !== ""
      ? String(value).trim()
      : fallback;
    const featureMap = {
      payment: "درگاه پرداخت", membership: "سیستم عضویت", admin_panel: "پنل مدیریت", live_chat: "چت آنلاین",
      booking: "رزرو نوبت", pwa: "PWA", maps: "نقشه", gallery: "گالری پیشرفته", blog: "وبلاگ",
      multilingual: "چندزبانه", social: "شبکه‌های اجتماعی", seo: "سئو پیشرفته", comments: "نظرات",
      sms_auth: "احراز هویت پیامکی", mobile_app: "اپلیکیشن موبایل", push: "نوتیفیکیشن", video: "پخش ویدیو",
      cart: "سبد خرید", dashboard: "داشبورد آماری", ai: "هوش مصنوعی",
    };
    const featureValues = Array.isArray(data.features)
      ? data.features.map((feature) => featureMap[String(feature)] || String(feature))
      : [];
    const featureText = featureValues.length ? featureValues.join("، ") : "ثبت نشده";
    let displayDate = "تاریخ ثبت در پاسخ سرور موجود نیست";
    const serverDate = typeof data.persianDateTime === "string" && data.persianDateTime.trim()
      ? data.persianDateTime
      : (typeof data.persianDate === "string" && data.persianDate.trim() ? data.persianDate : null);
    if (serverDate) displayDate = serverDate;
    else if (typeof data.createdAt === "string" && data.createdAt.trim()) {
      const parsed = new Date(data.createdAt);
      if (!Number.isNaN(parsed.getTime())) displayDate = parsed.toLocaleDateString("fa-IR");
    }
    const rows = [
      { label: "👤 نام و نام خانوادگی", value: safe(data.fullName) },
      { label: "📱 شماره تماس", value: safe(data.phone) },
      { label: "📧 ایمیل / پیام‌رسان", value: safe(data.email) + (data.messenger ? ` (${safe(data.messenger)}: ${safe(data.messengerId)})` : "") },
      { label: "🎯 نوع پروژه", value: safe(data.projectTypeLabel || data.projectType || data.industry) },
      { label: "🎨 سبک طراحی", value: safe(data.style) },
      { label: "🔧 امکانات درخواستی", value: featureText },
      { label: "⏰ بازه زمانی", value: safe(data.timeline) },
      { label: "📝 یادداشت شما", value: safe(data.userNote, "ندارد") },
    ];
    const rowsHtml = rows.map((row) => `
      <div class="receipt-row">
        <span class="receipt-label">${escapeReceiptHTML(row.label)}</span>
        <span class="receipt-value">${escapeReceiptHTML(row.value)}</span>
      </div>`).join("");
    const support = `${safe(CONFIG.telegram)} | ${safe(CONFIG.email)}`;
    this.body.innerHTML = `
      <div class="receipt-print-header">
        <div class="receipt-logo-text">MR•NEON</div>
        <div class="receipt-title">رسید ثبت درخواست پروژه</div>
        <div class="receipt-code-box">
          <span class="receipt-code-label">کد پیگیری:</span>
          <span class="receipt-code-val">${escapeReceiptHTML(safe(data.trackingCode, "نامشخص"))}</span>
        </div>
        <div class="receipt-date">📅 تاریخ ثبت: ${escapeReceiptHTML(displayDate)}</div>
      </div>
      <div class="receipt-details-grid">${rowsHtml}</div>
      <div class="receipt-footer-note">
        <p>⚠️ این رسید تأیید ثبت درخواست است و به‌منزلهٔ تأیید نهایی قرارداد نیست.</p>
        <p>پشتیبانی: ${escapeReceiptHTML(support)}</p>
      </div>`;
    this.open();
  }
}
'''
s = s[:idx] + new_tail
p.write_text(s, encoding='utf-8')

# 5) Keep the receipt modal initially inaccessible while closed.
replace_file('components/neo-modal.html',
'''  id="receiptModal"
  role="dialog"''',
'''  id="receiptModal"
  hidden
  aria-hidden="true"
  role="dialog"''', 'receipt modal initial accessibility state')
replace_file('components/neo-modal.html',
'''  id="thankYouModal"
  role="dialog"''',
'''  id="thankYouModal"
  role="dialog"''', 'thank you modal state kept')

# Small state styling, no redesign or dependency added.
css = ROOT / 'css/components.css'
style = '''

/* Phase 05 integration: clear operational states and receipt export feedback. */
.admin-state[data-state="loading"],
.customer-state[data-state="loading"] { cursor: progress; }
.admin-state[data-state="error"],
.customer-state[data-state="error"],
.admin-project-reply-status[data-state="error"] { color: var(--error, #f87171); }
.admin-state[data-state="ready"],
.customer-state[data-state="success"],
.admin-project-reply-status[data-state="success"] { color: var(--success, #34d399); }
#receiptDownloadBtn[disabled] { opacity: .72; cursor: progress; }
#adminAccessBtn { border: 0; background: transparent; font: inherit; cursor: pointer; }
#adminAccessBtn:focus-visible { outline: 2px solid var(--neon, #06b6d4); outline-offset: 4px; border-radius: 4px; }
@media (prefers-reduced-motion: reduce) {
  .admin-login-modal .admin-modal-container,
  .receipt-container,
  .thank-you-container { transition-duration: 0.01ms !important; animation-duration: 0.01ms !important; }
}
'''
cs = css.read_text(encoding='utf-8')
if '/* Phase 05 integration: clear operational states' in cs: raise SystemExit('Phase05 CSS already present')
css.write_text(cs.rstrip() + style, encoding='utf-8')

print('Phase 05 implementation edits applied to local effective-source snapshot.')
