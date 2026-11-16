/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/auth.js
 * 🎯 Responsibility: Authentication, Registration, Forgot Password, User Panel, Terms Modal
 * 🔌 Dependencies: app.js (CONFIG, DOM, sanitization, and the shared on-demand Admin bootstrap)
 * 🔌 Dependants: app.js (imports and instantiates classes)
 * 🚫 Constraints: Do NOT embed Admin business rules here; use the shared bootstrap only when an existing Admin session opens the Admin workspace.
 * 📦 Exported: AuthSystem, RegistrationSystem, ForgotPasswordSystem, TermsModal, UserPanel
 * 🧠 AI NOTE: RegistrationSystem has 2-step flow with Telegram verification and Draft saving.
 */

import { CONFIG, DOM, sanitizeInput, ensureAdminPanel } from "./app.js";
import { apiRequest, apiFetch, getServerSession } from "./api-client.js";
import { inspectRegistrationPassword } from "./registration-validation.js";

const CUSTOMER_MESSAGE_IDEMPOTENCY_SAFE_RETRY_WINDOW_MS = 22 * 60 * 60 * 1000;

function safeRetryAfterSeconds(response) {
  const raw = response?.headers?.get?.("Retry-After");
  if (typeof raw !== "string" || !/^[0-9]{1,4}$/.test(raw.trim())) return null;
  const value = Number(raw.trim());
  return Number.isInteger(value) && value >= 1 && value <= 3600 ? value : null;
}

function createCustomerMessageIdempotencyKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  if (globalThis.crypto?.getRandomValues) {
    const bytes = new Uint8Array(32);
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  }
  throw new Error("مرورگر امکان ساخت کلید امن برای ارسال پیام را ندارد.");
}

function isAmbiguousMessageRequestError(error) {
  const code = typeof error?.code === "string" ? error.code : "";
  return !Number.isInteger(error?.status)
    || error.status === 0
    || error.status === 408
    || error.status >= 500
    || ["REQUEST_TIMEOUT", "NETWORK_ERROR", "INVALID_API_CONTENT_TYPE", "INVALID_API_JSON", "INVALID_API_ENVELOPE", "IDEMPOTENCY_IN_PROGRESS", "IDEMPOTENCY_RESULT_UNAVAILABLE", "IDEMPOTENCY_KEY_REUSED"].includes(code);
}

function syncSharedModalScrollLock() {
  const activeModal = document.querySelector(
    ".auth-modal.active, .register-modal.active, .terms-modal.active, .user-panel-modal.active, .customer-project-detail-modal.active, .neo-modal.active, .thank-you-modal.active, .receipt-modal.active, .admin-login-modal.active, .admin-data-modal.active, .admin-project-detail-modal:not([hidden]), .tour-overlay.active",
  );
  document.body.classList.toggle("modal-scroll-locked", Boolean(activeModal));
}

// ============================================================
// 🔐 سیستم احراز هویت (ورود)
// ============================================================
export class AuthSystem {
  constructor() {
    this.modal = DOM.authModal;
    this.form = DOM.authForm;
    this.notification = window.notificationSystem;
    this.init();
  }

  init() {
    if (!this.modal || !this.form) return;
    this.setupEventListeners();
  }

  setupEventListeners() {
    this.form.addEventListener("submit", (e) => this.handleLogin(e));

    document
      .getElementById("showRegisterBtn")
      ?.addEventListener("click", () => {
        this.close();
        setTimeout(() => window.registrationSystem?.open(), 300);
      });

    document
      .getElementById("showForgotPasswordBtn")
      ?.addEventListener("click", () => {
        this.close();
        setTimeout(() => window.forgotPasswordSystem?.open(), 300);
      });

    this.modal
      .querySelector(".auth-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".auth-backdrop")
      ?.addEventListener("click", () => this.close());

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.modal.classList.contains("active"))
        this.close();
    });

    const pwdToggle = this.modal.querySelector(".password-toggle");
    const pwdInput = document.getElementById("authPassword");
    pwdToggle?.addEventListener("click", () => {
      pwdInput.type = pwdInput.type === "password" ? "text" : "password";
    });
  }

  async handleLogin(e) {
    e.preventDefault();
    const username = document.getElementById("authUsername").value.trim();
    const password = document.getElementById("authPassword").value;
    const remember = document.getElementById("rememberMe").checked;

    if (!username || !password) {
      this.notification?.error("نام کاربری و رمز عبور الزامی است");
      return;
    }

    const submitBtn = this.form.querySelector('button[type="submit"]');
    const btnText = submitBtn.querySelector(".btn-text");
    const btnLoader = submitBtn.querySelector(".btn-loader");
    submitBtn.disabled = true;
    if (btnText) btnText.closest("button")?.classList.add("is-submitting");
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      // User authentication never falls back to the independent Admin role.
      await this.loginUser(username, password, remember);
      this.close();
    } catch (err) {
      this.notification?.error(err.message || "ورود در حال حاضر امکان‌پذیر نیست");
    } finally {
      submitBtn.disabled = false;
      if (btnText) btnText.closest("button")?.classList.remove("is-submitting");
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  async loginUser(username, password, remember) {
    const data = await apiRequest("login", {
      method: "POST",
      body: { username, password },
    });
    AuthSystem.applyServerSession(data);
    if (remember) {
      this.notification?.info("ورود امن انجام شد؛ گزینهٔ ماندگاری نشست در این نسخه فعال نیست.");
    }
    this.notification?.success(`خوش آمدید ${data.user?.fullName || username} 🎉`);
    setTimeout(() => window.userPanel?.openPanel(), 500);
  }

  async open() {
    let existingSession = AuthSystem.checkSession();
    if (!AuthSystem.sessionChecked) {
      existingSession = await AuthSystem.restoreSession();
    }
    if (existingSession) {
      if (existingSession.type === "admin") {
        try {
          const adminPanel = await ensureAdminPanel();
          await adminPanel.openPanel();
        } catch {
          this.notification?.error("بارگذاری فضای مدیریت ناموفق بود؛ دوباره تلاش کنید.");
        }
      } else {
        window.userPanel?.openPanel();
      }
      return;
    }

    this.clearFields();
    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    syncSharedModalScrollLock();
    setTimeout(() => document.getElementById("authUsername")?.focus(), 300);
  }

  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    syncSharedModalScrollLock();
    this.clearFields();
  }

  clearFields() {
    document.getElementById("authUsername").value = "";
    document.getElementById("authPassword").value = "";
  }

  static currentSession = null;
  static sessionChecked = false;
  static sessionRestoreError = null;
  static sessionRestorePromise = null;
  static sessionRevision = 0;
  static legacyStorageCleaned = false;

  static checkSession() {
    if (!AuthSystem.legacyStorageCleaned) {
      try {
        for (const key of ["mrneon_admin_session", "mrneon_user_session"]) {
          localStorage.removeItem(key);
          sessionStorage.removeItem(key);
        }
      } catch (e) {
        // Storage may be unavailable in privacy-restricted browser contexts.
      }
      AuthSystem.legacyStorageCleaned = true;
    }
    return AuthSystem.currentSession;
  }

  static applyServerSession(payload) {
    AuthSystem.sessionRevision += 1;
    AuthSystem.sessionChecked = true;
    if (!payload || payload.authenticated !== true) {
      AuthSystem.currentSession = null;
      return null;
    }
    if (payload.principalType === "user" && payload.user && typeof payload.user === "object") {
      const user = payload.user;
      AuthSystem.currentSession = {
        type: "user",
        data: {
          userId: user.id,
          publicId: user.publicId || null,
          username: user.username,
          fullName: user.fullName,
          phone: user.phone,
          email: user.email || null,
        },
        expiresAt: Number.isInteger(payload.expiresAt) ? payload.expiresAt * 1000 : null,
      };
      return AuthSystem.currentSession;
    }
    if (payload.principalType === "admin" && payload.admin && typeof payload.admin === "object") {
      AuthSystem.currentSession = {
        type: "admin",
        data: { username: payload.admin.username, role: "admin" },
        expiresAt: Number.isInteger(payload.expiresAt) ? payload.expiresAt * 1000 : null,
      };
      return AuthSystem.currentSession;
    }
    AuthSystem.currentSession = null;
    return null;
  }

  static async restoreSession() {
    if (AuthSystem.sessionRestorePromise) return AuthSystem.sessionRestorePromise;
    const revisionAtStart = AuthSystem.sessionRevision;
    let restorePromise;
    restorePromise = (async () => {
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
  }

  static async logout() {
    try {
      const result = await apiRequest("logout", { method: "POST", body: {} });
      if (result?.authenticated !== false || result?.principalType !== null) {
        throw new Error("پایان نشست توسط سرور تأیید نشد.");
      }
      // Increment the shared session revision so an in-flight restore cannot resurrect
      // the authenticated session after a successful logout.
      AuthSystem.applyServerSession(result);
      window.location.reload();
      return true;
    } catch (error) {
      window.notificationSystem?.error(error?.message || "خروج امن توسط سرور تأیید نشد.");
      return false;
    }
  }

}

// Remove only legacy authentication entries from prior versions; keep NEO drafts/settings.
AuthSystem.checkSession();

// ============================================================
// 📝 سیستم ثبت‌نام (نسخه ۲.۰ - دو مرحله‌ای با Draft)
// ============================================================
export class RegistrationSystem {
  constructor() {
    this.modal = DOM.registerModal;
    this.form = DOM.registerForm;
    this.notification = window.notificationSystem;
    this.step = "info"; // info -> pending -> verify; verified registration does not auto-login
    this.pendingAttemptId = null;
    this.pendingChallengeId = null;
    this.pendingIdentifier = null;
    this.pendingTelegramLinkUrl = null;
    this.telegramLinked = false;
    this.draftKey = "registration_draft";
    this.init();
  }

  init() {
    if (!this.modal || !this.form) return;
    this.setupEvents();
  }

  setupEvents() {
    this.form.addEventListener("submit", (e) => this.handleSubmit(e));

    // دکمه بستن
    this.modal
      .querySelector(".register-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".register-backdrop")
      ?.addEventListener("click", () => this.close());

    // دکمه "ورود به حساب"
    document
      .getElementById("switchToLoginBtn")
      ?.addEventListener("click", (e) => {
        e.preventDefault();
        this.close();
        setTimeout(() => window.authSystem?.open(), 300);
      });

    // تغییر رمز عبور
    const pwdInput = this.form.querySelector("#regPassword");
    pwdInput?.addEventListener("input", () => this.updatePasswordStrength());
    this.form.querySelector("#regPasswordConfirm")?.addEventListener("input", () => this.validatePasswordMatch());

    const emailInput = this.form.querySelector("#regEmail");
    this.form.querySelectorAll('input[name="registrationChannel"]').forEach((radio) => {
      radio.addEventListener("change", () => {
        this.syncChannelRequirements();
        if (emailInput) this.clearValidation(emailInput);
        this.saveDraft();
      });
    });
    this.syncChannelRequirements();

    const toggleBtn = this.modal.querySelector(".password-toggle");
    toggleBtn?.addEventListener("click", () => {
      const inp = this.form.querySelector("#regPassword");
      if (inp) inp.type = inp.type === "password" ? "text" : "password";
    });

    // Escape closes the Terms dialog first; it must not also dismiss registration.
    document.addEventListener("keydown", (e) => {
      if (
        e.key === "Escape" &&
        this.modal.classList.contains("active") &&
        !DOM.termsModal?.classList.contains("active")
      ) {
        this.close();
      }
    });

    // Validation on blur
    [
      "regFullName",
      "regPhone",
      "regEmail",
      "regUsername",
      "regPassword",
      "regPasswordConfirm",
    ].forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener("blur", () => this.validateField(el));
        el.addEventListener("input", () => {
          this.clearValidation(el);
          this.saveDraft();
        });
      }
    });

    // Terms link
    DOM.termsLink?.addEventListener("click", (e) => {
      e.preventDefault();
      window.termsModal?.open();
    });

    // Reading or dismissing Terms is not consent. Only the user's explicit checkbox action counts.
    document.getElementById("termsAcceptBtn")?.addEventListener("click", () => {
      window.termsModal?.close();
    });
  }

  syncChannelRequirements() {
    const selectedChannel = this.form.querySelector('input[name="registrationChannel"]:checked')?.value || "";
    const emailInput = this.form.querySelector("#regEmail");
    const requirement = this.form.querySelector("#regEmailRequirement");
    const emailRequired = selectedChannel === "email";

    if (emailInput) {
      emailInput.required = emailRequired;
      emailInput.setAttribute("aria-required", emailRequired ? "true" : "false");
    }
    if (requirement) {
      requirement.textContent = emailRequired ? "اجباری" : "اختیاری";
      requirement.classList.toggle("optional-badge", !emailRequired);
      requirement.classList.toggle("required", emailRequired);
    }
  }

  saveDraft() {
    const draft = {
      fullName: document.getElementById("regFullName")?.value || "",
      phone: document.getElementById("regPhone")?.value || "",
      email: document.getElementById("regEmail")?.value || "",
      username: document.getElementById("regUsername")?.value || "",
      selectedChannel: this.form.querySelector('input[name="registrationChannel"]:checked')?.value || "",
      // رمز عبور ذخیره نمی‌شود (امنیتی)
      timestamp: Date.now(),
    };
    sessionStorage.setItem(this.draftKey, JSON.stringify(draft));
  }

  restoreDraft() {
    try {
      const raw = sessionStorage.getItem(this.draftKey);
      if (!raw) return;
      const draft = JSON.parse(raw);
      // فقط اگر draft کمتر از ۳۰ دقیقه قدمت داشته باشد
      if (Date.now() - draft.timestamp > 1800000) {
        sessionStorage.removeItem(this.draftKey);
        return;
      }
      if (draft.fullName)
        document.getElementById("regFullName").value = draft.fullName;
      if (draft.phone) document.getElementById("regPhone").value = draft.phone;
      if (draft.email) document.getElementById("regEmail").value = draft.email;
      if (draft.selectedChannel) {
        const channel = this.form.querySelector(`input[name="registrationChannel"][value="${draft.selectedChannel}"]`);
        if (channel) channel.checked = true;
      }
      if (draft.username)
        document.getElementById("regUsername").value = draft.username;
    } catch (e) {
      sessionStorage.removeItem(this.draftKey);
    }
  }

  clearDraft() {
    sessionStorage.removeItem(this.draftKey);
  }

  validateField(input) {
    const group = input.closest(".form-group");
    const msg = group?.querySelector(".validation-message");
    if (!msg) return true;

    let valid = true,
      txt = "";
    switch (input.name) {
      case "fullName":
        valid = input.value.trim().length >= 3;
        txt = valid ? "" : "حداقل ۳ کاراکتر";
        break;
      case "phone":
        // Client-side shape hint only; PhoneValidator owns canonical server validation.
        valid = /^[0-9۰-۹٠-٩ +().-]{7,32}$/.test(input.value.trim());
        txt = valid ? "" : "قالب شماره تلفن معتبر نیست";
        break;
      case "email": {
        const email = input.value.trim();
        const emailRequired = this.form.querySelector('input[name="registrationChannel"]:checked')?.value === "email";
        if (emailRequired && !email) {
          valid = false;
          txt = "برای انتخاب کانال Email، آدرس ایمیل الزامی است.";
        } else {
          valid = !email || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
          txt = valid ? "" : "ایمیل نامعتبر است.";
        }
        break;
      }
      case "username":
        valid = /^[a-zA-Z0-9_]{4,20}$/.test(input.value.trim());
        txt = valid ? "" : "۴-۲۰ کاراکتر انگلیسی/زیرخط";
        break;
      case "password": {
        const policy = inspectRegistrationPassword(input.value);
        valid = policy.valid;
        txt = valid ? "" : policy.errors[0];
        break;
      }
      case "passwordConfirm":
        valid = input.value === this.form.querySelector("#regPassword").value;
        txt = valid ? "" : "تکرار رمز یکسان نیست";
        break;
    }

    if (valid) {
      msg.textContent = "✓";
      msg.className = "validation-message valid";
      input.classList.remove("invalid");
      input.classList.add("valid");
    } else {
      msg.textContent = txt;
      msg.className = "validation-message invalid";
      input.classList.add("invalid");
      input.classList.remove("valid");
    }
    return valid;
  }

  clearValidation(input) {
    const group = input.closest(".form-group");
    const msg = group?.querySelector(".validation-message");
    if (msg) {
      msg.textContent = "";
      msg.className = "validation-message";
    }
    input.classList.remove("valid", "invalid");
  }

  updatePasswordStrength() {
    const input = this.form.querySelector("#regPassword");
    const policy = inspectRegistrationPassword(input?.value || "");
    const checks = {
      length: policy.characterCount >= 15 && (input?.value || "").length > 0,
      byteLimit: policy.withinByteLimit,
      unicode: policy.validUnicode,
      noControls: policy.hasNoControlCharacters,
    };

    this.form.querySelectorAll(".password-requirements .requirement").forEach((req) => {
      const icon = req.querySelector(".check-icon");
      const met = checks[req.dataset.check] === true;
      if (icon) icon.textContent = met ? "✓" : "✗";
      req.classList.toggle("met", met);
    });

    const score = Object.values(checks).filter(Boolean).length;
    const bar = this.modal.querySelector(".strength-bar");
    if (bar) {
      bar.classList.remove("strength-level-0", "strength-level-1", "strength-level-2", "strength-level-3", "strength-level-4");
      bar.classList.add(`strength-level-${score}`);
    }

    const hint = this.form.querySelector("#regPasswordPolicyHint");
    if (hint) {
      hint.textContent = input?.value
        ? `${policy.characterCount} نویسه · ${policy.byteLength} از ۱۰۲۴ بایت UTF-8${policy.valid ? " · شرایط محلی رعایت شده" : " · " + policy.errors[0]}`
        : "طول بر پایهٔ نویسه‌های Unicode و سقف بر پایهٔ بایت UTF-8 سنجیده می‌شود. فاصله‌های ابتدا و انتهای رمز حذف نمی‌شوند.";
    }
  }

  validatePasswordMatch() {
    const pw = document.getElementById("regPassword").value;
    const confirm = document.getElementById("regPasswordConfirm");
    const msg = confirm
      .closest(".form-group")
      ?.querySelector(".validation-message");

    if (confirm.value && confirm.value !== pw) {
      if (msg) {
        msg.textContent = "تکرار رمز یکسان نیست";
        msg.className = "validation-message invalid";
      }
      confirm.classList.add("invalid");
    } else {
      if (msg) {
        msg.textContent = "";
        msg.className = "validation-message";
      }
      confirm.classList.remove("invalid");
    }
  }

  async handleSubmit(e) {
    e.preventDefault();

    if (this.step === "pending") {
      this.notification?.info("برای ادامه، درخواست کد تأیید را از بخش وضعیت ثبت‌نام ارسال کنید.");
      return;
    }

    if (this.step === "verify") {
      await this.handleVerifyCode(e);
      return;
    }

    // مرحله ۱: ثبت اولیه. Required state follows the selected channel and mirrors RegistrationDTO.
    this.syncChannelRequirements();
    const selectedChannel = this.form.querySelector('input[name="registrationChannel"]:checked')?.value || "";
    const inputs = this.form.querySelectorAll("input[required]");
    let allValid = true;
    inputs.forEach((inp) => {
      if (!this.validateField(inp)) allValid = false;
    });

    if (!allValid) {
      this.notification?.error("لطفاً فیلدها را مطابق شرایط نمایش‌داده‌شده کامل کنید.");
      return;
    }


    if (!selectedChannel) {
      this.notification?.error("لطفاً دقیقاً یکی از کانال‌های Telegram یا Email را انتخاب کنید");
      return;
    }

    if (!document.getElementById("agreeTerms").checked) {
      this.notification?.error("لطفاً قوانین و مقررات را بپذیرید");
      return;
    }

    const data = {
      fullName: document.getElementById("regFullName").value.trim(),
      phone: document.getElementById("regPhone").value.trim(),
      email: document.getElementById("regEmail").value.trim() || null,
      username: document.getElementById("regUsername").value.trim(),
      password: document.getElementById("regPassword").value,
      selectedChannel,
      termsAccepted: document.getElementById("agreeTerms").checked === true,
    };

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.closest("button")?.classList.add("is-submitting");
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await apiFetch(CONFIG.apiEndpoint + "?action=register", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(data),
      });

      const res = await resp.json();
      if (!res.success) throw new Error(res.error || "خطا در ثبت موقت اطلاعات");
      if (!res.data || typeof res.data.attemptId !== "string" || !/^[a-f0-9]{32}$/.test(res.data.attemptId)
        || res.data.state !== "pending_verification" || res.data.verificationStatus !== "not_started"
        || res.data.selectedChannel !== selectedChannel) {
        throw new Error("پاسخ سرور قرارداد ثبت موقت ثبت‌نام یا کانال انتخاب‌شده را رعایت نکرد.");
      }

      this.pendingAttemptId = res.data.attemptId;
      this.pendingIdentifier = res.data.selectedChannel;
      this.pendingTelegramLinkUrl = res.data.selectedChannel === "telegram" && typeof res.data.telegramLinkUrl === "string"
        ? res.data.telegramLinkUrl
        : null;
      this.telegramLinked = res.data.selectedChannel !== "telegram" || res.data.telegramLinked === true;
      this.pendingChallengeId = null;
      this.step = "pending";
      this.clearDraft();
      this.form.querySelectorAll("input").forEach((input) => { input.disabled = true; });
      if (btnText) btnText.textContent = "اطلاعات موقت ثبت شد";
      this.showPendingStep();
      this.notification?.info(
        "درخواست ثبت‌نام به‌صورت موقت ذخیره شد. حساب هنوز فعال نیست و هیچ کدی تا پذیرش واقعی ارسال، موفق محسوب نمی‌شود.",
      );
    } catch (err) {
      this.notification?.error(err.message || "خطا در ارتباط با سرور");
    } finally {
      btn.disabled = this.step === "pending";
      if (btnText) btnText.closest("button")?.classList.remove("is-submitting");
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  showPendingStep() {
    const formGroups = this.form.querySelectorAll(".form-group, .checkbox-group");
    formGroups.forEach((group) => { group.hidden = true; });

    const verifySection = this.form.querySelector(".verify-section");
    if (verifySection) verifySection.hidden = true;

    let pendingSection = this.form.querySelector(".pending-verification-section");
    if (!pendingSection) {
      pendingSection = document.createElement("div");
      pendingSection.className = "pending-verification-section";
      pendingSection.innerHTML = `
        <div class="pending-verification-intro">
          <div class="pending-verification-icon" aria-hidden="true">✉️</div>
          <h3 class="pending-verification-title">ثبت موقت انجام شد</h3>
          <p class="pending-verification-copy">
            هنوز حسابی ایجاد یا تأیید نشده است. برای دریافت کد، درخواست زیر را ارسال کنید.
            موفقیت فقط پس از پذیرش واقعی سرویس ارسال گزارش می‌شود و اعتبار کد از همان لحظه دقیقاً ۱۲۰ ثانیه است.
          </p>
          <div id="telegramLinkPanel" class="pending-verification-telegram-panel" hidden>
            <p class="pending-verification-telegram-copy">برای اتصال امن، Bot را باز کنید و Start را بزنید. سپس وضعیت اتصال را بررسی کنید؛ Username به‌تنهایی اثبات مالکیت نیست.</p>
            <a id="telegramBotLink" class="btn btn-outline btn-sm pending-verification-telegram-link" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">باز کردن Bot تلگرام</a>
            <button type="button" class="btn btn-outline btn-sm pending-verification-check-link" id="checkTelegramLinkBtn">بررسی اتصال Telegram</button>
          </div>
          <button type="button" class="btn btn-primary pending-verification-primary" id="startVerificationBtn">
            درخواست کد تأیید
          </button>
          <button type="button" class="btn btn-outline btn-sm pending-verification-close" id="closePendingRegistrationBtn">
            بستن
          </button>
          <p id="pendingVerificationStatus" class="pending-verification-status" aria-live="polite">
            کدی ارسال نشده؛ این بخش تا پاسخ واقعی سرور در حالت انتظار می‌ماند.
          </p>
        </div>`;
      this.form.insertBefore(pendingSection, this.form.firstChild);
    }
    pendingSection.hidden = false;
    const registrationSubmit = this.form.querySelector('button[type="submit"]');
    if (registrationSubmit) registrationSubmit.hidden = true;
    const startButton = pendingSection.querySelector("#startVerificationBtn");
    const linkPanel = pendingSection.querySelector("#telegramLinkPanel");
    const link = pendingSection.querySelector("#telegramBotLink");
    const checkLinkButton = pendingSection.querySelector("#checkTelegramLinkBtn");
    if (this.pendingIdentifier === "telegram") {
      linkPanel.hidden = this.telegramLinked === true;
      if (this.telegramLinked === true) {
        const status = pendingSection.querySelector("#pendingVerificationStatus");
        if (status) status.textContent = "اتصال Telegram قبلی سمت سرور تأیید شده است. اکنون می‌توانید کد تأیید را درخواست کنید؛ حساب هنوز تأیید نشده است.";
        checkLinkButton.disabled = true;
      } else if (this.pendingTelegramLinkUrl && this.isTrustedTelegramLink(this.pendingTelegramLinkUrl)) {
        link.href = this.pendingTelegramLinkUrl;
        checkLinkButton.disabled = false;
      } else {
        link.hidden = true;
        checkLinkButton.disabled = true;
        const status = pendingSection.querySelector("#pendingVerificationStatus");
        if (status) status.textContent = "اتصال Telegram هنوز پیکربندی نشده است؛ Bot username، Secret و HTTPS عمومی باید توسط مدیر تنظیم شوند. هیچ کدی ارسال نشده است.";
      }
      startButton.disabled = this.telegramLinked !== true;
      checkLinkButton.addEventListener("click", () => this.checkTelegramLink());
    } else {
      linkPanel.hidden = true;
      startButton.disabled = false;
    }
    startButton.addEventListener("click", () => this.startVerification());
    pendingSection.querySelector("#closePendingRegistrationBtn")?.addEventListener("click", () => this.close());
  }

  isTrustedTelegramLink(value) {
    try {
      const url = new URL(value);
      return url.protocol === "https:" && url.hostname === "t.me" && !url.username && !url.password
        && /^\/[A-Za-z0-9_]{5,32}$/.test(url.pathname) && /bot$/i.test(url.pathname.slice(1))
        && /^[a-f0-9]{64}$/.test(url.searchParams.get("start") || "")
        && [...url.searchParams.keys()].length === 1;
    } catch {
      return false;
    }
  }

  async checkTelegramLink() {
    if (this.pendingIdentifier !== "telegram" || typeof this.pendingAttemptId !== "string"
      || !/^[a-f0-9]{32}$/.test(this.pendingAttemptId)) return;
    const panel = this.form.querySelector(".pending-verification-section");
    const status = panel?.querySelector("#pendingVerificationStatus");
    const button = panel?.querySelector("#checkTelegramLinkBtn");
    if (button) { button.disabled = true; button.textContent = "در حال بررسی…"; }
    try {
      const response = await apiFetch(CONFIG.apiEndpoint + "?action=telegramLinkStatus", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ attemptId: this.pendingAttemptId }),
      });
      const result = await response.json();
      if (!result.success || typeof result.data?.linked !== "boolean") throw new Error(result.error || "وضعیت اتصال قابل تأیید نیست.");
      this.telegramLinked = result.data.linked === true;
      const startButton = panel?.querySelector("#startVerificationBtn");
      if (startButton) startButton.disabled = !this.telegramLinked;
      if (status) status.textContent = this.telegramLinked
        ? "اتصال Telegram سمت سرور تأیید شد. اکنون می‌توانید کد را درخواست کنید؛ حساب هنوز تأیید نشده است."
        : "اتصال هنوز ثبت نشده است. در Telegram گزینهٔ Start را بزنید و دوباره بررسی کنید.";
    } catch (error) {
      if (status) status.textContent = error.message || "بررسی اتصال انجام نشد؛ هیچ موفقیتی فرض نشده است.";
      this.notification?.error(error.message || "بررسی اتصال Telegram ناموفق بود.");
    } finally {
      if (button) {
        button.disabled = this.telegramLinked === true;
        button.textContent = "بررسی اتصال Telegram";
      }
    }
  }

  async startVerification() {
    if (this.pendingIdentifier === "telegram" && this.telegramLinked !== true) {
      this.notification?.error("ابتدا اتصال امن Telegram را کامل و از سمت سرور بررسی کنید.");
      return;
    }
    if (typeof this.pendingAttemptId !== "string" || !/^[a-f0-9]{32}$/.test(this.pendingAttemptId)) {
      this.notification?.error("شناسهٔ درخواست ثبت‌نام در دسترس نیست؛ درخواست را دوباره ثبت کنید.");
      return;
    }
    const section = this.form.querySelector(".pending-verification-section");
    const button = section?.querySelector("#startVerificationBtn");
    const status = section?.querySelector("#pendingVerificationStatus");
    if (button) { button.disabled = true; button.textContent = "در حال بررسی درخواست…"; }
    if (status) status.textContent = "منتظر پاسخ واقعی سرور هستیم؛ ارسال موفق فرض نشده است.";
    try {
      const response = await apiFetch(CONFIG.apiEndpoint + "?action=sendVerificationCode", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ attemptId: this.pendingAttemptId }),
      });
      const result = await response.json();
      if (!result.success) {
        const retryAfter = safeRetryAfterSeconds(response);
        throw new Error(retryAfter
          ? `سرویس ارسال موقتاً محدود شده است؛ ${retryAfter} ثانیه دیگر دوباره تلاش کنید.`
          : (result.error || "درخواست ارسال کد پذیرفته نشد."));
      }
      const data = result.data;
      if (!data || data.state !== "active" || typeof data.challengeId !== "string"
        || !/^[a-f0-9]{32}$/.test(data.challengeId) || data.ttlSeconds !== 120
        || data.channel !== this.pendingIdentifier) {
        throw new Error("پاسخ سرور قرارداد Challenge فعال را رعایت نکرد.");
      }
      this.pendingChallengeId = data.challengeId;
      this.step = "verify";
      this.showVerifyStep(data);
      this.notification?.success("سرویس ارسال درخواست را پذیرفت؛ کد تا ۱۲۰ ثانیه پس از پذیرش معتبر است.");
    } catch (error) {
      if (status) status.textContent = error.message || "ارسال پذیرفته نشد؛ حساب همچنان تأییدنشده است.";
      this.notification?.error(error.message || "سرویس ارسال هنوز آماده نیست؛ ارسال موفق فرض نشده است.");
    } finally {
      if (button && this.step === "pending") { button.disabled = false; button.textContent = "تلاش دوباره برای درخواست کد"; }
    }
  }

  showVerifyStep(data = null) {
    const formGroups = this.form.querySelectorAll(".form-group, .checkbox-group");
    formGroups.forEach((group) => { group.hidden = true; });
    const pendingSection = this.form.querySelector(".pending-verification-section");
    if (pendingSection) pendingSection.hidden = true;

    let verifySection = this.form.querySelector(".verify-section");
    if (!verifySection) {
      verifySection = document.createElement("div");
      verifySection.className = "verify-section";
      verifySection.innerHTML = `
        <div class="verify-info">
          <div class="verify-icon" aria-hidden="true">🔐</div>
          <h3 class="verify-title">Challenge تأیید فعال است</h3>
          <p id="verifyDeliveryDescription" class="verify-description"></p>
        </div>
        <div class="form-group verify-code-group">
          <label for="verifyCode" class="verify-code-label">کد تأیید ۶ رقمی *</label>
          <input type="text" id="verifyCode" class="form-input verify-code-input" inputmode="numeric" autocomplete="one-time-code"
            placeholder="000000" maxlength="6" aria-describedby="verifyCodeHint">
          <div class="input-hint" id="verifyCodeHint">کد دریافتی را بدون فاصله وارد کنید. کد پس از پایان ۱۲۰ ثانیه قابل‌استفاده نیست.</div>
        </div>
        <div class="verify-actions-row">
          <button type="button" class="btn btn-outline btn-sm verify-action-button" id="resendCodeBtn">درخواست کد جدید</button>
          <button type="button" class="btn btn-outline btn-sm verify-action-button" id="closeVerifyBtn">بستن</button>
        </div>
        <p id="verifyExpiryStatus" class="verify-expiry-status" aria-live="polite"></p>`;
      this.form.insertBefore(verifySection, this.form.firstChild);
    }
    const description = verifySection.querySelector("#verifyDeliveryDescription");
    if (description) {
      description.textContent = this.pendingIdentifier === "email"
        ? "درخواست ارسال برای کانال Email پذیرفته شده است. این پیام به معنی تضمین رسیدن ایمیل نیست؛ نتیجهٔ نهایی فقط با اعتبارسنجی کد مشخص می‌شود."
        : "درخواست ارسال برای کانال Telegram پذیرفته شده است. فقط مقصد Telegram متصل و تأییدشده می‌تواند این Challenge را تکمیل کند.";
    }
    const expiry = verifySection.querySelector("#verifyExpiryStatus");
    if (expiry) expiry.textContent = data?.expiresAt ? `اعتبار Challenge تا ${data.expiresAt} (UTC) است؛ مدت مصوب ۱۲۰ ثانیه.` : "اعتبار کد ۱۲۰ ثانیه از زمان پذیرش ارسال است.";
    verifySection.hidden = false;
    const submit = this.form.querySelector('button[type="submit"]');
    if (submit) {
      submit.hidden = false;
      submit.disabled = false;
    }
    const btnText = submit?.querySelector(".btn-text");
    if (btnText) btnText.textContent = "تأیید کد و تکمیل ثبت‌نام";
    verifySection.querySelector("#resendCodeBtn")?.addEventListener("click", () => this.resendCode());
    verifySection.querySelector("#closeVerifyBtn")?.addEventListener("click", () => this.close());
    setTimeout(() => verifySection.querySelector("#verifyCode")?.focus(), 100);
  }

  backToInfo() {
    // A server-side attempt is immutable at this stage; do not imply that editing the old form edits it.
    this.close();
  }

  async handleVerifyCode(e) {
    e.preventDefault();
    const rawCode = document.getElementById("verifyCode").value.trim();
    const code = rawCode.replace(/[۰-۹٠-٩]/g, (digit) => {
      const codePoint = digit.charCodeAt(0);
      return String(codePoint >= 0x06F0 && codePoint <= 0x06F9 ? codePoint - 0x06F0 : codePoint - 0x0660);
    });

    if (!code || code.length !== 6 || !/^[0-9]{6}$/.test(code)) {
      this.notification?.error("کد ۶ رقمی معتبر وارد کنید");
      return;
    }

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.closest("button")?.classList.add("is-submitting");
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await apiFetch(
        CONFIG.apiEndpoint + "?action=verifyRegisterCode",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            attemptId: this.pendingAttemptId,
            challengeId: this.pendingChallengeId,
            code: code,
          }),
        },
      );

      const res = await resp.json();
      if (!res.success) throw new Error(res.error || "کد نامعتبر");

      if (!res.data || res.data.registered !== true || res.data.authenticated !== false || res.data.sessionEstablished !== false) {
        throw new Error("پاسخ سرور قرارداد تکمیل ثبت‌نام را رعایت نکرد.");
      }
      // Registration completion is not an authenticated session; only the existing login flow may create one.
      this.notification?.success("ثبت‌نام و تأیید کانال با موفقیت انجام شد. برای ورود، از فرم ورود استفاده کنید.");
      this.clearDraft();
      this.close();
      setTimeout(() => window.authSystem?.open(), 300);
    } catch (err) {
      const expiryStatus = this.form.querySelector("#verifyExpiryStatus");
      if (expiryStatus) expiryStatus.textContent = err.message || "تأیید کد انجام نشد؛ وضعیت حساب تغییر نکرده است.";
      this.notification?.error(err.message || "خطا در تایید کد");
    } finally {
      btn.disabled = false;
      if (btnText) btnText.closest("button")?.classList.remove("is-submitting");
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  async resendCode() {
    if (!this.pendingAttemptId) {
      this.notification?.error("شناسهٔ ثبت‌نام در دسترس نیست؛ درخواست را دوباره آغاز کنید.");
      return;
    }
    const button = this.form.querySelector("#resendCodeBtn");
    if (button) { button.disabled = true; button.textContent = "در حال درخواست کد جدید…"; }
    try {
      const resp = await apiFetch(CONFIG.apiEndpoint + "?action=resendVerificationCode", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ attemptId: this.pendingAttemptId }),
      });
      const result = await resp.json();
      if (!result.success) {
        const retryAfter = safeRetryAfterSeconds(resp);
        throw new Error(retryAfter
          ? `سرویس ارسال موقتاً محدود شده است؛ ${retryAfter} ثانیه دیگر برای درخواست کد جدید صبر کنید.`
          : (result.error || "درخواست کد جدید پذیرفته نشد."));
      }
      const data = result.data;
      if (!data || data.state !== "active" || typeof data.challengeId !== "string"
        || !/^[a-f0-9]{32}$/.test(data.challengeId) || data.ttlSeconds !== 120
        || data.channel !== this.pendingIdentifier) {
        throw new Error("پاسخ سرور قرارداد Challenge فعال را رعایت نکرد.");
      }
      this.pendingChallengeId = data.challengeId;
      const expiry = this.form.querySelector("#verifyExpiryStatus");
      if (expiry) expiry.textContent = data.expiresAt ? `کد جدید تا ${data.expiresAt} (UTC) معتبر است؛ مدت ۱۲۰ ثانیه.` : "Challenge جدید برای ۱۲۰ ثانیه فعال است.";
      this.notification?.success("سرویس درخواست ارسال کد جدید را پذیرفت؛ تأیید نهایی با کد جدید انجام می‌شود.");
    } catch (error) {
      const expiry = this.form.querySelector("#verifyExpiryStatus");
      if (expiry) expiry.textContent = error.message || "درخواست کد جدید پذیرفته نشد؛ کد قبلی ممکن است منقضی شده باشد.";
      this.notification?.error(error.message || "سرویس ارسال هنوز آماده نیست؛ موفقیت فرض نشده است.");
    } finally {
      if (button) { button.disabled = false; button.textContent = "درخواست کد جدید"; }
    }
  }

  open() {
    this.step = "info";
    this.pendingAttemptId = null;
    this.pendingChallengeId = null;
    this.pendingIdentifier = null;
    this.pendingTelegramLinkUrl = null;
    this.telegramLinked = false;
    this.form.querySelectorAll("input").forEach((input) => { input.disabled = false; });

    // Remove flow panels and restore the original registration form.
    this.form.querySelector(".verify-section")?.remove();
    this.form.querySelector(".pending-verification-section")?.remove();
    const registrationSubmit = this.form.querySelector('button[type="submit"]');
    if (registrationSubmit) registrationSubmit.hidden = false;

    // نمایش تمام form groups
    const formGroups = this.form.querySelectorAll(
      ".form-group, .checkbox-group",
    );
    formGroups.forEach((group) => { group.hidden = false; });

    // ریست متن دکمه
    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn?.querySelector(".btn-text");
    if (btnText) btnText.textContent = "ایجاد حساب کاربری";

    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    syncSharedModalScrollLock();
    this.form.reset();
    this.restoreDraft();
    this.syncChannelRequirements();
    this.updatePasswordStrength();
  }

  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    syncSharedModalScrollLock();
  }
}

// ============================================================
// 🔑 سیستم فراموشی رمز عبور
// ============================================================
export class ForgotPasswordSystem {
  constructor() {
    this.modal = DOM.forgotPasswordModal;
    this.form = DOM.forgotPasswordForm;
    this.notification = window.notificationSystem;
    this.step = "request";
    this.identifier = "";
    this.method = "";
    this.challengeRef = "";
    this.resetAuthorizationToken = ""; // Memory only; never expose in storage, URLs, attributes or logs.
    this.busy = false;
    this.flowGeneration = 0;
    this.activeRequestController = null;
    this.busyControlState = null;
    this.previousFocus = null;
    this.retryBlockedUntil = 0;
    this.retryTimer = null;
    this.init();
  }

  init() {
    if (!this.modal || !this.form) return;
    this.form.addEventListener("submit", (event) => this.handleSubmit(event));
    this.modal.querySelectorAll(".forgot-password-close, .auth-close, .auth-backdrop, .forgot-password-backdrop")
      .forEach((element) => element.addEventListener("click", () => this.close()));
    // Capture Escape before other modal listeners so only the top Recovery modal closes.
    document.addEventListener("keydown", (event) => this.handleModalKeydown(event), true);
    document.getElementById("fpBackToLoginBtn")?.addEventListener("click", (event) => {
      event.preventDefault();
      this.close();
      setTimeout(() => window.authSystem?.open(), 300);
    });
    document.getElementById("fpRestartFromCodeBtn")?.addEventListener("click", () => this.restartToRequest());
    document.getElementById("fpRestartFromResetBtn")?.addEventListener("click", () => this.restartToRequest());
    document.getElementById("fpMethod")?.addEventListener("change", () => {
      this.clearStatus();
      this.updateIdentifierHelp();
    });
    document.getElementById("fpIdentifier")?.addEventListener("input", () => this.clearStatus());
    document.getElementById("fpCode")?.addEventListener("input", (event) => {
      const input = event.currentTarget;
      input.value = this.normalizeDigits(input.value).replace(/[^0-9]/g, "").slice(0, 6);
    });
    this.updateIdentifierHelp();
    this.setStage("request", false);
    this.modal.setAttribute("aria-hidden", "true");
  }

  handleModalKeydown(event) {
    if (!this.modal?.classList.contains("active")) return;
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopImmediatePropagation();
      this.close();
      return;
    }
    if (event.key !== "Tab") return;
    const focusable = Array.from(this.modal.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((element) => !element.closest("[hidden]") && element.getClientRects().length > 0);
    if (!focusable.length) {
      event.preventDefault();
      this.modal.querySelector(".auth-container")?.setAttribute("tabindex", "-1");
      this.modal.querySelector(".auth-container")?.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && (document.activeElement === first || !this.modal.contains(document.activeElement))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  updateIdentifierHelp() {
    const email = document.getElementById("fpMethod")?.value === "email";
    const label = document.getElementById("fpIdentifierLabel");
    const hint = document.getElementById("fpIdentifierHint");
    const input = document.getElementById("fpIdentifier");
    if (label) label.textContent = email ? "ایمیل تأییدشدهٔ حساب" : "نام کاربری MR NEON";
    if (hint) hint.textContent = email
      ? "همان ایمیلی را وارد کنید که قبلاً در حساب تأیید کرده‌اید."
      : "نام کاربری حساب MR NEON را وارد کنید؛ نام کاربری تلگرام یا Chat ID کافی نیست.";
    if (input) {
      input.placeholder = email ? "name@example.com" : "نام کاربری حساب MR NEON";
      input.autocomplete = email ? "email" : "username";
      input.type = email ? "email" : "text";
      input.setAttribute("aria-describedby", "fpIdentifierHint fpStatus");
    }
  }

  setStage(stage, moveFocus = true) {
    const allowedStages = ["request", "verify", "reset"];
    this.step = allowedStages.includes(stage) ? stage : "request";
    const active = this.step;
    const sections = [
      ["request", document.getElementById("fpRequestSection")],
      ["verify", document.getElementById("fpCodeSection")],
      ["reset", document.getElementById("fpResetSection")],
    ];
    sections.forEach(([name, section]) => {
      if (!section) return;
      const isCurrent = name === active;
      section.hidden = !isCurrent;
      section.setAttribute("aria-hidden", isCurrent ? "false" : "true");
    });
    const identifierInput = document.getElementById("fpIdentifier");
    const codeInput = document.getElementById("fpCode");
    const newPassword = document.getElementById("fpNewPassword");
    const confirmPassword = document.getElementById("fpConfirmPassword");
    if (identifierInput) identifierInput.required = active === "request";
    if (codeInput) codeInput.required = active === "verify";
    if (newPassword) newPassword.required = active === "reset";
    if (confirmPassword) confirmPassword.required = active === "reset";
    const buttonText = this.form?.querySelector('button[type="submit"] .btn-text');
    if (buttonText) {
      buttonText.textContent = active === "request" ? "📤 ادامهٔ بازیابی"
        : active === "verify" ? "🔐 تأیید کد"
          : "🔑 بازنشانی رمز عبور";
    }
    if (moveFocus && this.modal?.classList.contains("active")) {
      const target = active === "request" ? identifierInput : active === "verify" ? codeInput : newPassword;
      setTimeout(() => {
        if (this.modal?.classList.contains("active") && target && !target.disabled) target.focus();
      }, 0);
    }
  }

  setStatus(message, kind = "info") {
    const status = document.getElementById("fpStatus");
    if (!status) return;
    status.textContent = message || "";
    status.dataset.kind = kind;
    status.setAttribute("role", kind === "error" ? "alert" : "status");
    status.setAttribute("aria-live", kind === "error" ? "assertive" : "polite");
    status.setAttribute("aria-atomic", "true");
  }

  clearStatus() {
    this.setStatus("");
  }

  notify(kind, message) {
    this.setStatus(message, kind === "error" ? "error" : kind === "success" ? "success" : "info");
    this.notification?.[kind]?.(message);
  }

  retryAfterSeconds(response) {
    const raw = response?.headers?.get?.("Retry-After");
    if (!raw) return null;
    if (/^[0-9]{1,6}$/.test(raw.trim())) return Math.min(3600, Math.max(1, Number(raw.trim())));
    const time = Date.parse(raw);
    if (Number.isNaN(time)) return null;
    return Math.min(3600, Math.max(1, Math.ceil((time - Date.now()) / 1000)));
  }

  recordRetryAfter(response) {
    if (Number(response?.status || 0) !== 429) return;
    const seconds = this.retryAfterSeconds(response);
    if (!seconds) return;
    this.retryBlockedUntil = Math.max(this.retryBlockedUntil, Date.now() + seconds * 1000);
    this.applyRetryCooldown();
  }

  applyRetryCooldown() {
    const button = this.form?.querySelector('button[type="submit"]');
    if (!button || this.busy) return;
    const remaining = this.retryBlockedUntil - Date.now();
    if (remaining > 0) {
      button.disabled = true;
      if (this.retryTimer) clearTimeout(this.retryTimer);
      this.retryTimer = setTimeout(() => {
        this.retryTimer = null;
        this.applyRetryCooldown();
      }, Math.min(remaining + 10, 3600010));
    } else {
      button.disabled = false;
      this.retryBlockedUntil = 0;
      if (this.retryTimer) clearTimeout(this.retryTimer);
      this.retryTimer = null;
    }
  }

  safeErrorMessage(response, stage, code = "") {
    const status = Number(response?.status || 0);
    if (status === 429) {
      const seconds = this.retryAfterSeconds(response);
      return seconds
        ? `تعداد درخواست‌ها از حد مجاز بیشتر شده است. لطفاً ${seconds} ثانیه بعد دوباره تلاش کنید.`
        : "تعداد درخواست‌ها از حد مجاز بیشتر شده است. لطفاً کمی بعد دوباره تلاش کنید.";
    }
    if (stage === "verify" && (code === "RECOVERY_CODE_INVALID_OR_EXPIRED" || status === 422)) {
      return "کد بازیابی معتبر نیست یا مهلتش پایان یافته است. برای جلوگیری از استفادهٔ مجدد از وضعیت قدیمی، بازیابی را از ابتدا آغاز کنید.";
    }
    if (stage === "reset" && code === "PASSWORD_POLICY_VIOLATION") {
      return "رمز جدید با سیاست امنیتی سرور سازگار نیست. حداقل طول و محدودیت نویسه‌ها را بررسی کنید.";
    }
    if (stage === "reset" && code === "PASSWORD_TOO_LONG_FOR_RUNTIME") {
      return "رمز واردشده برای الگوریتم امن فعال روی این سرور بیش از حد طولانی است؛ رمز کوتاه‌تری انتخاب کنید.";
    }
    if (stage === "reset" && code === "RESET_AUTHORIZATION_INVALID_OR_EXPIRED") {
      return "مجوز بازنشانی معتبر نیست یا مهلتش پایان یافته است. بازیابی را از ابتدا آغاز کنید.";
    }
    if (status === 0 || status >= 500 || code === "REQUEST_TIMEOUT" || code === "NETWORK_ERROR") {
      if (stage === "reset") {
        return "نتیجهٔ بازنشانی از سرور تأیید نشد. برای جلوگیری از تکرار ناخواسته، مجوز قبلی استفادهٔ دوباره نمی‌شود؛ ابتدا با رمز جدید وارد شوید و اگر موفق نشدید بازیابی را از ابتدا آغاز کنید.";
      }
      if (stage === "verify") {
        return "نتیجهٔ تأیید کد قابل تأیید نیست؛ برای جلوگیری از استفادهٔ مجدد از کد، بازیابی را از ابتدا آغاز کنید.";
      }
      return "درخواست بازیابی اکنون قابل تکمیل نیست. برای حفظ حریم خصوصی، وجود حساب یا وضعیت کانال نمایش داده نمی‌شود.";
    }
    if (stage === "start") {
      return "درخواست بازیابی اکنون قابل تکمیل نیست. برای حفظ حریم خصوصی، وجود حساب یا وضعیت کانال نمایش داده نمی‌شود.";
    }
    if (stage === "verify") {
      return "کد تأیید نشد. اگر پاسخ سرور نامشخص بوده است، بازیابی را از ابتدا آغاز کنید.";
    }
    return "بازنشانی رمز انجام نشد. در صورت انقضای مجوز، بازیابی را از ابتدا آغاز کنید.";
  }

  async handleSubmit(event) {
    event.preventDefault();
    if (this.busy || !this.modal?.classList.contains("active")) return;
    if (Date.now() < this.retryBlockedUntil) {
      const seconds = Math.max(1, Math.ceil((this.retryBlockedUntil - Date.now()) / 1000));
      this.notify("error", `لطفاً ${seconds} ثانیه دیگر صبر کنید و سپس دوباره تلاش کنید.`);
      this.applyRetryCooldown();
      return;
    }
    if (this.step === "request") await this.handleRequestCode();
    else if (this.step === "verify") await this.handleVerifyCode();
    else if (this.step === "reset") await this.handleResetPassword();
  }

  setBusyUi(isBusy, label = "") {
    if (isBusy) {
      this.busyControlState = new Map();
      this.form?.querySelectorAll("input, select, button").forEach((control) => {
        this.busyControlState.set(control, control.disabled);
        control.disabled = true;
      });
      const button = this.form?.querySelector('button[type="submit"]');
      const text = button?.querySelector(".btn-text");
      const loader = button?.querySelector(".btn-loader");
      if (text) text.textContent = label;
      if (loader) loader.removeAttribute("hidden");
      this.busy = true;
      return;
    }
    if (this.busyControlState) {
      this.busyControlState.forEach((wasDisabled, control) => {
        if (control?.isConnected) control.disabled = wasDisabled;
      });
    }
    this.busyControlState = null;
    const loader = this.form?.querySelector('button[type="submit"] .btn-loader');
    if (loader) loader.setAttribute("hidden", "");
    this.busy = false;
    this.setStage(this.step, false);
    const submitText = this.form?.querySelector('button[type="submit"] .btn-text');
    if (submitText) submitText.textContent = this.step === "request" ? "📤 ادامهٔ بازیابی"
      : this.step === "verify" ? "🔐 تأیید کد" : "🔑 بازنشانی رمز عبور";
  }

  async withBusy(label, action) {
    if (this.busy) return;
    const controller = new AbortController();
    const generation = this.flowGeneration;
    this.activeRequestController = controller;
    this.setBusyUi(true, label);
    try {
      await action({ signal: controller.signal, generation });
    } finally {
      if (this.activeRequestController === controller) this.activeRequestController = null;
      if (generation === this.flowGeneration) {
        this.setBusyUi(false);
        this.applyRetryCooldown();
      }
    }
  }

  isCurrent(generation) {
    return generation === this.flowGeneration && this.modal?.classList.contains("active");
  }

  async readResponse(response, generation) {
    if (!this.isCurrent(generation)) return null;
    try {
      const envelope = await response.json();
      if (!this.isCurrent(generation)) return null;
      if (!envelope || typeof envelope !== "object") return { response, envelope: null };
      this.lastRequestId = typeof envelope.meta?.requestId === "string" ? envelope.meta.requestId : null;
      return { response, envelope };
    } catch {
      if (!this.isCurrent(generation)) return null;
      return { response, envelope: null };
    }
  }

  async handleRequestCode() {
    const method = document.getElementById("fpMethod")?.value || "";
    const entered = document.getElementById("fpIdentifier")?.value || "";
    const identifier = entered.trim();
    if (!identifier || !["email", "telegram"].includes(method)) {
      this.notify("error", "روش بازیابی و شناسهٔ معتبر را وارد کنید.");
      return;
    }
    if (method === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier)) {
      this.notify("error", "ایمیل واردشده معتبر نیست.");
      return;
    }
    if (method === "telegram" && !/^[a-zA-Z0-9_@]{4,21}$/.test(identifier)) {
      this.notify("error", "نام کاربری حساب MR NEON معتبر نیست.");
      return;
    }

    this.clearStatus();
    await this.withBusy("در حال بررسی درخواست…", async ({ signal, generation }) => {
      let response;
      try {
        response = await apiFetch(CONFIG.apiEndpoint + "?action=forgotPassword", {
          method: "POST", signal, body: JSON.stringify({ identifier, method }),
        });
        const result = await this.readResponse(response, generation);
        if (!result) return;
        const envelope = result.envelope;
        if (response.status !== 202 || !response.ok || envelope?.success !== true
          || !/^[a-f0-9]{32}$/.test(envelope.data?.challengeRef || "") || envelope.data?.ttlSeconds !== 120
          || typeof envelope.data?.message !== "string" || envelope.data.message.trim() === "") {
          this.recordRetryAfter(response);
          this.notify("error", this.safeErrorMessage(response, "start", envelope?.code || ""));
          return;
        }
        this.identifier = identifier;
        this.method = method;
        this.challengeRef = envelope.data.challengeRef;
        this.resetAuthorizationToken = "";
        // Use fixed, disclosure-safe copy. Do not claim that a provider delivered or a recipient received anything.
        const generic = "اگر اطلاعات واردشده با حسابی مطابقت داشته باشد و کانال تأییدشده‌ای برای آن در دسترس باشد، دستورالعمل ادامه ارسال می‌شود. کد طبق پاسخ سرور ۱۲۰ ثانیه اعتبار دارد.";
        const info = document.getElementById("fpCodeInfo");
        if (info) info.textContent = generic;
        const code = document.getElementById("fpCode");
        if (code) code.value = "";
        this.setStage("verify");
        this.notify("info", generic);
      } catch {
        if (this.isCurrent(generation)) this.notify("error", this.safeErrorMessage(response, "start", "NETWORK_ERROR"));
      }
    });
  }

  normalizeDigits(value) {
    return String(value || "").replace(/[۰-۹٠-٩]/g, (digit) => {
      const code = digit.charCodeAt(0);
      if (code >= 0x06f0 && code <= 0x06f9) return String(code - 0x06f0);
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
      return digit;
    });
  }

  async handleVerifyCode() {
    const code = this.normalizeDigits(document.getElementById("fpCode")?.value || "").replace(/[^0-9]/g, "");
    if (!/^[0-9]{6}$/.test(code) || !this.challengeRef || !this.identifier || !["email", "telegram"].includes(this.method)) {
      this.notify("error", "کد ۶ رقمی معتبر وارد کنید یا بازیابی را از ابتدا آغاز کنید.");
      return;
    }
    const codeInput = document.getElementById("fpCode");
    if (codeInput) codeInput.value = code;
    this.clearStatus();
    await this.withBusy("در حال تأیید کد…", async ({ signal, generation }) => {
      let response;
      try {
        response = await apiFetch(CONFIG.apiEndpoint + "?action=verifyResetCode", {
          method: "POST", signal,
          body: JSON.stringify({ identifier: this.identifier, method: this.method, challengeRef: this.challengeRef, code }),
        });
        const result = await this.readResponse(response, generation);
        if (!result) return;
        const envelope = result.envelope;
        const token = envelope?.data?.resetAuthorizationToken;
        const validVerifyPayload = envelope?.success === true
          && /^[a-f0-9]{64}$/.test(token || "")
          && envelope.data?.passwordResetAllowed === true
          && Number.isInteger(envelope.data?.expiresInSeconds)
          && envelope.data.expiresInSeconds >= 60 && envelope.data.expiresInSeconds <= 900;
        if (!response.ok || !validVerifyPayload) {
          this.recordRetryAfter(response);
          const uncertain = response.status === 0 || response.status >= 500
            || (response.ok && !validVerifyPayload)
            || ["REQUEST_TIMEOUT", "NETWORK_ERROR", "INVALID_API_JSON", "INVALID_API_ENVELOPE"].includes(envelope?.code || "");
          if (uncertain || envelope?.code === "RECOVERY_CODE_INVALID_OR_EXPIRED" || response.status === 422) {
            this.clearRecoveryAttempt();
            this.setStage("request");
          }
          this.notify("error", this.safeErrorMessage(response, "verify", envelope?.code || ""));
          return;
        }
        // The reset-only capability is held only in page memory and never inserted into the DOM.
        this.resetAuthorizationToken = token;
        this.challengeRef = "";
        this.identifier = "";
        this.method = "";
        const input = document.getElementById("fpNewPassword");
        const confirm = document.getElementById("fpConfirmPassword");
        if (input) input.value = "";
        if (confirm) confirm.value = "";
        if (codeInput) codeInput.value = "";
        this.setStage("reset");
        this.notify("success", "کد تأیید شد. اکنون رمز جدید را تنظیم کنید.");
      } catch {
        if (!this.isCurrent(generation)) return;
        // A malformed/unknown verification result may have consumed the OTP. Do not blindly reuse it.
        this.clearRecoveryAttempt();
        this.setStage("request");
        this.notify("error", "نتیجهٔ تأیید کد قابل تأیید نیست. برای جلوگیری از استفادهٔ مجدد از کد، بازیابی را از ابتدا آغاز کنید.");
      }
    });
  }

  async handleResetPassword() {
    const password = document.getElementById("fpNewPassword")?.value || "";
    const confirmation = document.getElementById("fpConfirmPassword")?.value || "";
    const passwordPolicy = inspectRegistrationPassword(password);
    if (!passwordPolicy.valid) {
      this.notify("error", passwordPolicy.errors[0] || "رمز عبور با سیاست امنیتی جاری سازگار نیست.");
      return;
    }
    if (password !== confirmation) {
      this.notify("error", "رمز عبور و تکرار آن یکسان نیست.");
      return;
    }
    if (!/^[a-f0-9]{64}$/.test(this.resetAuthorizationToken)) {
      this.clearRecoveryAttempt();
      this.setStage("request");
      this.notify("error", "مجوز بازنشانی در دسترس نیست؛ بازیابی را دوباره آغاز کنید.");
      return;
    }

    this.clearStatus();
    await this.withBusy("در حال بازنشانی رمز…", async ({ signal, generation }) => {
      let response;
      try {
        response = await apiFetch(CONFIG.apiEndpoint + "?action=resetPassword", {
          method: "POST", signal,
          headers: { "X-Reset-Authorization": this.resetAuthorizationToken },
          body: JSON.stringify({ newPassword: password }),
        });
        const result = await this.readResponse(response, generation);
        if (!result) return;
        const envelope = result.envelope;
        if (!response.ok || envelope?.success !== true || envelope.data?.passwordReset !== true
          || envelope.data?.authenticated !== false || envelope.data?.sessionEstablished !== false) {
          const code = envelope?.code || "";
          this.recordRetryAfter(response);
          if (code === "RESET_AUTHORIZATION_INVALID_OR_EXPIRED") {
            this.clearRecoveryAttempt();
            this.setStage("request");
          } else if (response.status === 0 || response.status >= 500 || ["REQUEST_TIMEOUT", "NETWORK_ERROR", "INVALID_API_JSON", "INVALID_API_ENVELOPE"].includes(code)) {
            // The DB transaction may have committed even though its response was lost. Do not reuse a single-use capability.
            this.clearRecoveryAttempt();
            this.setStage("request");
          }
          this.notify("error", this.safeErrorMessage(response, "reset", code));
          return;
        }
        this.clearSensitiveFlow();
        this.close();
        this.notification?.success("رمز عبور بازنشانی شد. برای ادامه با رمز جدید وارد شوید.");
        setTimeout(() => window.authSystem?.open(), 500);
      } catch {
        if (!this.isCurrent(generation)) return;
        // Unknown outcome: clear the capability and require a fresh login/recovery decision.
        this.clearRecoveryAttempt();
        this.setStage("request");
        this.notify("error", this.safeErrorMessage(response, "reset", "REQUEST_TIMEOUT"));
      }
    });
  }

  clearSensitiveFlow() {
    this.resetAuthorizationToken = "";
    this.challengeRef = "";
    const code = document.getElementById("fpCode");
    if (code) code.value = "";
    const password = document.getElementById("fpNewPassword");
    if (password) password.value = "";
    const confirmation = document.getElementById("fpConfirmPassword");
    if (confirmation) confirmation.value = "";
  }

  clearRecoveryAttempt() {
    this.clearSensitiveFlow();
    this.identifier = "";
    this.method = "";
    const generic = document.getElementById("fpGenericInfo");
    if (generic) generic.textContent = "";
  }

  restartToRequest() {
    if (this.busy) return;
    this.flowGeneration += 1;
    this.clearRecoveryAttempt();
    this.step = "request";
    this.clearStatus();
    this.setStage("request");
    this.updateIdentifierHelp();
    document.getElementById("fpIdentifier")?.focus();
  }

  abortActiveRequest() {
    this.flowGeneration += 1;
    if (this.activeRequestController) this.activeRequestController.abort();
    this.activeRequestController = null;
    this.setBusyUi(false);
  }

  open() {
    this.abortActiveRequest();
    this.previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    this.clearRecoveryAttempt();
    this.step = "request";
    this.form?.reset();
    this.clearStatus();
    this.setStage("request", false);
    this.updateIdentifierHelp();
    this.modal?.classList.add("active");
    this.modal?.removeAttribute("hidden");
    this.modal?.setAttribute("aria-hidden", "false");
    syncSharedModalScrollLock();
    this.applyRetryCooldown();
    setTimeout(() => document.getElementById("fpIdentifier")?.focus(), 0);
  }

  close() {
    this.abortActiveRequest();
    this.clearRecoveryAttempt();
    this.identifier = "";
    this.method = "";
    this.step = "request";
    this.form?.reset();
    this.clearStatus();
    this.setStage("request", false);
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncSharedModalScrollLock();
    const target = this.previousFocus;
    this.previousFocus = null;
    if (target?.isConnected && target.getClientRects().length > 0) setTimeout(() => target.focus(), 0);
  }
}

// ============================================================
// 📜 مودال قوانین
// ============================================================
export class TermsModal {
  constructor() {
    this.modal = DOM.termsModal;
    this.init();
  }
  init() {
    this.modal
      .querySelector(".terms-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".terms-backdrop")
      ?.addEventListener("click", () => this.close());
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.modal.classList.contains("active"))
        this.close();
    });
  }
  open() {
    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    syncSharedModalScrollLock();
  }
  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    syncSharedModalScrollLock();
  }
}

// ============================================================
// 👤 پنل کاربری
// ============================================================
function escapeCustomerHTML(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;",
  })[character]);
}

const CUSTOMER_PROJECT_STATUS_KEYS = new Set([
  "submitted", "under_review", "needs_information", "in_progress",
  "waiting_for_client", "completed", "rejected", "cancelled",
]);
const CUSTOMER_PUBLIC_EVENT_TYPES = new Set([
  "project.submitted", "project.status_changed", "project.milestone_changed",
]);
const CUSTOMER_MESSAGE_SENDER_TYPES = new Set(["user", "admin", "system"]);
const CUSTOMER_MESSAGE_SENDER_LABELS = new Set(["شما", "تیم MR NEON", "سیستم"]);
const CUSTOMER_MILESTONE_STATUSES = new Set(["pending", "active", "completed"]);
const CUSTOMER_BRIEF_KEYS = new Set([
  "industry", "purpose", "style", "timeline", "fullName", "phone", "email",
  "messenger", "messengerId", "userNote", "features", "colors", "content",
]);
const CUSTOMER_BRIEF_TEXT_LIMITS = Object.freeze({
  industry: 64, purpose: 64, style: 64, timeline: 64, fullName: 160,
  phone: 32, email: 254, messenger: 64, messengerId: 120, userNote: 2000,
});
const CUSTOMER_NOTIFICATION_CATEGORY_PATTERN = /^[a-z][a-z0-9_.-]{0,63}$/;

function isCustomerRecord(value) {
  if (!value || typeof value !== "object" || Array.isArray(value)) return false;
  const prototype = Object.getPrototypeOf(value);
  return prototype === Object.prototype || prototype === null;
}

function isCustomerBoundedText(value, maxCodePoints, { allowEmpty = true, multiline = false } = {}) {
  if (typeof value !== "string" || Array.from(value).length > maxCodePoints) return false;
  if (!allowEmpty && value.trim() === "") return false;
  const invalidControls = multiline
    ? /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/u
    : /[\u0000-\u001F\u007F]/u;
  return !invalidControls.test(value);
}

function isCustomerUtf8Text(value, maxBytes = 65535) {
  if (typeof value !== "string" || value.trim() === ""
    || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/u.test(value)) return false;
  let byteLength = 0;
  for (const character of value) {
    const point = character.codePointAt(0);
    byteLength += point <= 0x7F ? 1 : point <= 0x7FF ? 2 : point <= 0xFFFF ? 3 : 4;
    if (byteLength > maxBytes) return false;
  }
  return true;
}

function isCustomerSlug(value, maxLength = 64, allowEmpty = false) {
  return typeof value === "string"
    && value.length <= maxLength
    && (allowEmpty ? value === "" || /^[a-z0-9_]+$/.test(value) : /^[a-z0-9_]+$/.test(value));
}

function isCustomerUtcTimestamp(value) {
  if (typeof value !== "string"
    || !/^[1-9]\d{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\dZ$/.test(value)) return false;
  const parsed = new Date(value);
  return Number.isFinite(parsed.getTime()) && parsed.toISOString() === `${value.slice(0, -1)}.000Z`;
}

function isCustomerNullableUtcTimestamp(value) {
  return value === null || isCustomerUtcTimestamp(value);
}

function isCustomerStringList(value, maximumItems, maximumLength, { allowEmptyItems = false } = {}) {
  return Array.isArray(value) && value.length <= maximumItems
    && value.every((item) => isCustomerBoundedText(item, maximumLength, { allowEmpty: allowEmptyItems }));
}

function isCustomerListPagination(value, expectedPage, expectedPerPage) {
  return isCustomerRecord(value)
    && Number.isSafeInteger(expectedPage) && expectedPage >= 1 && expectedPage <= 1000000
    && value.page === expectedPage
    && value.perPage === expectedPerPage
    && Number.isSafeInteger(value.total) && value.total >= 0
    && typeof value.hasMore === "boolean"
    && value.hasMore === (expectedPage * expectedPerPage < value.total);
}

function isCustomerHistoryPagination(value, expectedPage = null) {
  return isCustomerListPagination(value, expectedPage ?? value?.page, 50);
}

function isCustomerProjectBrief(value) {
  if (!isCustomerRecord(value)) return false;
  for (const key of Object.keys(value)) {
    if (!CUSTOMER_BRIEF_KEYS.has(key)) return false;
  }
  for (const [key, maximum] of Object.entries(CUSTOMER_BRIEF_TEXT_LIMITS)) {
    if (Object.prototype.hasOwnProperty.call(value, key)
      && !isCustomerBoundedText(value[key], maximum, { allowEmpty: true, multiline: key === "userNote" })) return false;
  }
  if (Object.prototype.hasOwnProperty.call(value, "features")
    && (!isCustomerStringList(value.features, 20, 64) || !value.features.every((item) => isCustomerSlug(item)))) return false;
  if (Object.prototype.hasOwnProperty.call(value, "colors")) {
    const colors = value.colors;
    if (!isCustomerRecord(colors) || Object.keys(colors).some((key) => !["palettes", "customColors"].includes(key))) return false;
    if (Object.prototype.hasOwnProperty.call(colors, "palettes")
      && (!isCustomerStringList(colors.palettes, 20, 64) || !colors.palettes.every((item) => isCustomerSlug(item)))) return false;
    if (Object.prototype.hasOwnProperty.call(colors, "customColors")
      && (!Array.isArray(colors.customColors) || colors.customColors.length > 4
        || !colors.customColors.every((item) => typeof item === "string" && /^#[A-Fa-f0-9]{6}$/.test(item)))) return false;
  }
  if (Object.prototype.hasOwnProperty.call(value, "content")) {
    const content = value.content;
    if (!isCustomerRecord(content) || Object.keys(content).length !== 3
      || !["logo", "text", "images"].every((key) => Object.prototype.hasOwnProperty.call(content, key))) return false;
    if (!Object.values(content).every((item) => isCustomerSlug(item, 64, true))) return false;
  }
  return true;
}

function isCustomerProjectSummary(project, expectedPublicId = null) {
  if (!isCustomerRecord(project)
    || typeof project.id !== "string" || !/^[a-f0-9]{32}$/.test(project.id)
    || typeof project.publicId !== "string" || !/^[a-f0-9]{32}$/.test(project.publicId)
    || project.id !== project.publicId
    || (expectedPublicId !== null && project.publicId !== expectedPublicId)
    || typeof project.trackingCode !== "string" || !/^MN-[A-HJ-NP-Z2-9]{12}$/.test(project.trackingCode)
    || !isCustomerBoundedText(project.title, 180, { allowEmpty: false })
    || !isCustomerSlug(project.projectType)
    || !isCustomerSlug(project.industry) || !isCustomerSlug(project.purpose)
    || !isCustomerSlug(project.style) || !isCustomerSlug(project.timeline)
    || typeof project.status !== "string" || !CUSTOMER_PROJECT_STATUS_KEYS.has(project.status)
    || !isCustomerBoundedText(project.statusLabel, 100, { allowEmpty: false })
    || !Number.isSafeInteger(project.progress) || project.progress < 0 || project.progress > 100
    || !isCustomerUtcTimestamp(project.createdAt)
    || !isCustomerUtcTimestamp(project.submittedAt)
    || !isCustomerUtcTimestamp(project.updatedAt)
    || typeof project.archived !== "boolean") return false;
  for (const key of ["industryLabel", "purposeLabel", "styleLabel", "timelineLabel"]) {
    if (!isCustomerBoundedText(project[key], 180, { allowEmpty: false })) return false;
  }
  if (!isCustomerStringList(project.paletteLabels, 20, 120)
    || !isCustomerStringList(project.featuresLabels, 20, 120)
    || !isCustomerBoundedText(project.messengerLabel, 120)) return false;
  const contentLabels = project.contentLabels;
  if (!isCustomerRecord(contentLabels)
    || !["logo", "text", "images"].every((key) => isCustomerBoundedText(contentLabels[key], 120))
    || Object.keys(contentLabels).some((key) => !["logo", "text", "images"].includes(key))) return false;
  return true;
}

function isCustomerTimelineItem(item) {
  return isCustomerRecord(item)
    && typeof item.itemKey === "string" && /^[a-f0-9]{64}$/.test(item.itemKey)
    && typeof item.type === "string" && CUSTOMER_PUBLIC_EVENT_TYPES.has(item.type)
    && isCustomerBoundedText(item.label, 180, { allowEmpty: false })
    && (item.fromStatus === null || (typeof item.fromStatus === "string" && CUSTOMER_PROJECT_STATUS_KEYS.has(item.fromStatus)))
    && (item.toStatus === null || (typeof item.toStatus === "string" && CUSTOMER_PROJECT_STATUS_KEYS.has(item.toStatus)))
    && (item.message === null || isCustomerBoundedText(item.message, 1000, { multiline: true }))
    && isCustomerUtcTimestamp(item.createdAt);
}

function isCustomerMilestone(item) {
  return isCustomerRecord(item)
    && typeof item.code === "string" && /^[a-z][a-z0-9_-]{0,31}$/.test(item.code)
    && isCustomerBoundedText(item.name, 120, { allowEmpty: false })
    && typeof item.status === "string" && CUSTOMER_MILESTONE_STATUSES.has(item.status)
    && isCustomerBoundedText(item.statusLabel, 60, { allowEmpty: false })
    && Number.isSafeInteger(item.weight) && item.weight >= 0 && item.weight <= 100
    && isCustomerNullableUtcTimestamp(item.startedAt)
    && isCustomerNullableUtcTimestamp(item.completedAt);
}

function isCustomerProjectMessage(item) {
  const expectedLabel = item?.senderType === "user" ? "شما"
    : item?.senderType === "admin" ? "تیم MR NEON"
      : item?.senderType === "system" ? "سیستم" : null;
  return isCustomerRecord(item)
    && typeof item.itemKey === "string" && /^[a-f0-9]{64}$/.test(item.itemKey)
    && typeof item.senderType === "string" && CUSTOMER_MESSAGE_SENDER_TYPES.has(item.senderType)
    && item.senderLabel === expectedLabel && CUSTOMER_MESSAGE_SENDER_LABELS.has(item.senderLabel)
    && isCustomerUtf8Text(item.body)
    && isCustomerUtcTimestamp(item.createdAt)
    && typeof item.read === "boolean";
}

function isCustomerProjectDetail(project, expectedPublicId = null, expectedHistoryPages = { timeline: 1, messages: 1 }) {
  const reasonStatuses = new Set(["needs_information", "rejected", "cancelled"]);
  return isCustomerProjectSummary(project, expectedPublicId)
    && Object.prototype.hasOwnProperty.call(project, "statusReason")
    && (project.statusReason === null || (reasonStatuses.has(project.status)
      && isCustomerBoundedText(project.statusReason, 500, { allowEmpty: false, multiline: true })))
    && isCustomerProjectBrief(project.brief)
    && Array.isArray(project.timeline) && project.timeline.length <= 50 && project.timeline.every(isCustomerTimelineItem)
    && Array.isArray(project.milestones) && project.milestones.length <= 100 && project.milestones.every(isCustomerMilestone)
    && Array.isArray(project.messages) && project.messages.length <= 50 && project.messages.every(isCustomerProjectMessage)
    && isCustomerHistoryPagination(project.timelinePagination, expectedHistoryPages.timeline)
    && isCustomerHistoryPagination(project.messagesPagination, expectedHistoryPages.messages);
}

function isCustomerNotificationItem(item) {
  return isCustomerRecord(item)
    && typeof item.id === "string" && /^[a-f0-9]{32}$/.test(item.id)
    && isCustomerBoundedText(item.title, 180, { allowEmpty: false })
    && isCustomerBoundedText(item.message, 1000, { multiline: true })
    && isCustomerUtcTimestamp(item.timestamp)
    && typeof item.read === "boolean"
    && typeof item.category === "string" && CUSTOMER_NOTIFICATION_CATEGORY_PATTERN.test(item.category);
}

function mergeOlderCustomerHistory(olderItems, currentItems, keyOf) {
  const merged = [];
  const seen = new Set();
  for (const item of [...olderItems, ...currentItems]) {
    const key = keyOf(item);
    if (seen.has(key)) continue;
    seen.add(key);
    merged.push(item);
  }
  return merged;
}

function customerTimelineItemKey(item) {
  return item.itemKey;
}

function customerMessageItemKey(item) {
  return item.itemKey;
}

export class UserPanel {
  constructor() {
    this.modal = DOM.userPanelModal;
    this.detailModal = document.getElementById("customerProjectDetailModal");
    this.projects = [];
    this.projectStats = null;
    this.projectPage = 1;
    this.hasMoreProjects = false;
    this.projectsLoading = false;
    this.projectsLoadState = "idle";
    this.notifications = [];
    this.notificationsUnreadCount = 0;
    this.notificationsLoadState = "idle";
    this.notificationsLoaded = false;
    this.notificationsOwnerId = null;
    this.notificationsLoading = false;
    this.notificationMarkingId = null;
    this.notificationMarkingToken = null;
    this.currentProject = null;
    this.currentProjectRequest = 0;
    this.messageSending = false;
    this.pendingMessageRequests = new Map();
    this.timelineLoadingMore = false;
    this.messagesLoadingMore = false;
    this.lastFocusedDetailTrigger = null;
    this.lastFocusedPanelTrigger = null;
    this.notification = window.notificationSystem;
    this.init();
  }

  init() {
    if (!this.modal) return;
    this.setupTabs();
    this.setupEvents();
    this.setupProjectDetailDialog();
  }

  setupTabs() {
    const tablist = this.modal.querySelector(".user-panel-tabs");
    const tabs = [...this.modal.querySelectorAll(".tab-btn[data-tab]")];
    const contents = [...this.modal.querySelectorAll(".tab-content")];
    if (!tablist || tabs.length === 0) return;

    const activate = (button, { focus = false } = {}) => {
      const targetId = button?.getAttribute("aria-controls");
      const target = targetId ? document.getElementById(targetId) : null;
      if (!button || !target || !this.modal.contains(target)) return;

      tabs.forEach((item) => {
        const active = item === button;
        item.classList.toggle("active", active);
        item.setAttribute("aria-selected", active ? "true" : "false");
        item.tabIndex = active ? 0 : -1;
      });
      contents.forEach((panel) => {
        const active = panel === target;
        panel.classList.toggle("active", active);
        panel.hidden = !active;
      });
      if (focus) button.focus();
    };

    tabs.forEach((button) => {
      button.addEventListener("click", () => activate(button));
      button.addEventListener("keydown", (event) => {
        const index = tabs.indexOf(button);
        const direction = getComputedStyle(tablist).direction;
        let nextIndex = null;
        if (event.key === "Home") nextIndex = 0;
        else if (event.key === "End") nextIndex = tabs.length - 1;
        else if (event.key === "ArrowRight") nextIndex = (index + (direction === "rtl" ? -1 : 1) + tabs.length) % tabs.length;
        else if (event.key === "ArrowLeft") nextIndex = (index + (direction === "rtl" ? 1 : -1) + tabs.length) % tabs.length;
        if (nextIndex === null) return;
        event.preventDefault();
        activate(tabs[nextIndex], { focus: true });
      });
    });

    const selected = tabs.find((tab) => tab.getAttribute("aria-selected") === "true") || tabs[0];
    activate(selected);
  }

  setupEvents() {
    this.modal.querySelector(".panel-close")?.addEventListener("click", () => this.close());
    this.modal.querySelector(".user-panel-backdrop")?.addEventListener("click", () => this.close());
    DOM.userLogoutBtn?.addEventListener("click", async () => {
      const button = DOM.userLogoutBtn;
      if (button?.disabled) return;
      if (button) {
        button.disabled = true;
        button.setAttribute("aria-busy", "true");
      }
      try {
        const loggedOut = await AuthSystem.logout();
        if (loggedOut) this.close();
      } finally {
        if (button) {
          button.disabled = false;
          button.removeAttribute("aria-busy");
        }
      }
    });

    document.getElementById("newProjectBtn")?.addEventListener("click", async (event) => {
      const button = event.currentTarget;
      if (!(button instanceof HTMLButtonElement) || button.getAttribute("aria-disabled") === "true") return;
      const originalLabel = button.textContent;
      const status = document.getElementById("newProjectStatus");
      const setStatus = (message, state) => {
        if (!status) return;
        status.textContent = message;
        status.dataset.state = state;
        status.hidden = !message;
      };
      button.setAttribute("aria-disabled", "true");
      button.setAttribute("aria-busy", "true");
      button.textContent = "در حال بررسی دسترسی…";
      setStatus("در حال بررسی نشست و امکان شروع ثبت پروژه…", "loading");
      try {
        const check = await window.NeoPreFlightCheck?.canOpenNEO();
        if (check?.allowed) {
          setStatus("دسترسی تأیید شد؛ فرم NEO در حال آماده‌سازی است.", "success");
          this.close();
          setTimeout(() => window.neoPro?.open(), 250);
        } else {
          const message = check?.message || "امکان شروع درخواست در حال حاضر فراهم نیست.";
          setStatus(message, "error");
          this.notification?.error(message);
        }
      } catch (error) {
        const message = "بررسی امکان ثبت پروژه انجام نشد؛ اتصال را بررسی و دوباره تلاش کنید.";
        setStatus(message, "error");
        this.notification?.error(message);
      } finally {
        button.textContent = originalLabel;
        button.removeAttribute("aria-busy");
        button.removeAttribute("aria-disabled");
      }
    });

    document.getElementById("loadMoreProjectsBtn")?.addEventListener("click", () => {
      void this.loadProjects({ append: true });
    });
    document.getElementById("userProjectsRetryBtn")?.addEventListener("click", () => {
      void this.loadProjects({ append: false });
    });
    const notificationsRetryButton = document.getElementById("userNotificationsRetryBtn");
    notificationsRetryButton?.addEventListener("click", () => {
      if (notificationsRetryButton.getAttribute("aria-disabled") === "true"
        || this.notificationsLoading || this.notificationMarkingId) return;
      void this.loadNotifications();
    });

    document.addEventListener("keydown", (event) => {
      if (!this.modal?.classList.contains("active")) return;
      if (this.detailModal?.classList.contains("active")) return;

      if (event.key === "Escape") {
        event.preventDefault();
        this.close();
        return;
      }
      if (event.key !== "Tab") return;

      const focusable = [...this.modal.querySelectorAll(
        'button:not([disabled]):not([hidden]), a[href], input:not([disabled]):not([hidden]), select:not([disabled]):not([hidden]), textarea:not([disabled]):not([hidden]), [tabindex]:not([tabindex="-1"])',
      )].filter((element) => element.getClientRects().length > 0 && !element.closest('[aria-hidden="true"]'));
      const container = this.modal.querySelector(".user-panel-container");
      if (focusable.length === 0) {
        event.preventDefault();
        container?.focus();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (!this.modal.contains(document.activeElement)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      } else if (event.shiftKey && (document.activeElement === first || !focusable.includes(document.activeElement))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !focusable.includes(document.activeElement))) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  setupProjectDetailDialog() {
    if (!this.detailModal) return;
    this.detailModal.querySelector("[data-detail-close]")?.addEventListener("click", () => this.closeProjectDetails());
    this.detailModal.querySelector("[data-detail-backdrop]")?.addEventListener("click", (event) => {
      if (event.target === event.currentTarget) this.closeProjectDetails();
    });
    this.detailModal.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        this.closeProjectDetails();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = [...this.detailModal.querySelectorAll(
        'button:not([disabled]):not([hidden]), textarea:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])',
      )].filter((element) => element.getClientRects().length > 0);
      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    document.getElementById("customerProjectMessageForm")?.addEventListener("submit", (event) => {
      void this.submitProjectMessage(event);
    });
    document.getElementById("customerProjectTimelineMoreBtn")?.addEventListener("click", () => {
      void this.loadOlderProjectHistory("timeline");
    });
    document.getElementById("customerProjectMessagesMoreBtn")?.addEventListener("click", () => {
      void this.loadOlderProjectHistory("messages");
    });
  }

  async openPanel() {
    // Session and profile data are refreshed from the server each time the panel is opened.
    const session = await AuthSystem.restoreSession();
    if (!session) {
      this.notification?.error(
        AuthSystem.sessionRestoreError?.message || "نشست معتبر پیدا نشد؛ برای ادامه وارد حساب شوید.",
      );
      return;
    }
    if (session.type !== "user") {
      this.notification?.error("برای مشاهده پروژه‌های مشتری باید با حساب مشتری وارد شوید.");
      return;
    }
    const notificationOwnerId = this.getCustomerNotificationOwnerId(session);
    if (!notificationOwnerId || this.notificationsOwnerId !== notificationOwnerId) {
      this.resetCustomerNotifications(notificationOwnerId);
    }
    const activeElement = document.activeElement;
    this.lastFocusedPanelTrigger = activeElement instanceof HTMLElement && activeElement !== document.body && !this.modal.contains(activeElement)
      ? activeElement
      : null;
    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    this.modal.setAttribute("aria-hidden", "false");
    syncSharedModalScrollLock();
    this.loadUserData(session);
    window.setTimeout(() => {
      if (!this.modal?.classList.contains("active")) return;
      const selectedTab = this.modal.querySelector('.tab-btn[aria-selected="true"]');
      (selectedTab || this.modal.querySelector(".panel-close") || this.modal.querySelector(".user-panel-container"))?.focus();
    }, 0);
    void this.loadProjects({ append: false });
    void this.loadNotifications();
  }

  loadUserData(session = AuthSystem.checkSession()) {
    if (!session || session.type !== "user") return;
    const data = session.data || {};
    const name = typeof data.fullName === "string" && data.fullName.trim() ? data.fullName : data.username || "کاربر";
    const username = typeof data.username === "string" ? data.username : "";
    document.getElementById("userPanelName").textContent = name;
    document.getElementById("userPanelUsername").textContent = username ? "@" + username : "";
    const setValue = (id, value) => {
      const input = document.getElementById(id);
      if (input) input.value = typeof value === "string" && value.trim() ? value : "ثبت نشده";
    };
    setValue("userAccountFullName", data.fullName);
    setValue("currentUsername", username);
    setValue("userAccountPhone", data.phone);
    setValue("userAccountEmail", data.email);
  }

  getCustomerNotificationOwnerId(session = AuthSystem.checkSession()) {
    const rawId = session?.type === "user"
      ? (session.data?.userId ?? session.data?.publicId)
      : null;
    if (typeof rawId !== "string" && typeof rawId !== "number") return null;
    const ownerId = String(rawId).trim();
    return ownerId && ownerId.length <= 128 ? ownerId : null;
  }

  resetCustomerNotifications(ownerId = null) {
    this.notifications = [];
    this.notificationsUnreadCount = 0;
    this.notificationsLoaded = false;
    this.notificationsOwnerId = ownerId;
    this.notificationsLoadState = "idle";
    this.notificationMarkingId = null;
    this.notificationMarkingToken = null;

    const badge = document.getElementById("notifBadge");
    if (badge) {
      badge.textContent = "0";
      badge.hidden = true;
    }
    const container = document.getElementById("userNotificationsList");
    if (container) {
      container.replaceChildren();
      container.setAttribute("aria-busy", "false");
      container.inert = false;
    }
    const status = document.getElementById("userNotificationsStatus");
    if (status) {
      status.textContent = "";
      status.hidden = true;
      status.removeAttribute("data-state");
    }
    const actionStatus = document.getElementById("userNotificationActionStatus");
    if (actionStatus) {
      actionStatus.textContent = "";
      actionStatus.hidden = true;
      actionStatus.removeAttribute("data-state");
    }
    const retry = document.getElementById("userNotificationsRetryBtn");
    if (retry) {
      retry.hidden = true;
      retry.removeAttribute("aria-disabled");
    }
  }

  handleCustomerRequestError(error) {
    if (Number(error?.status) !== 401) return false;
    // Use the shared revision path; a late get-session response must not revive this session.
    AuthSystem.applyServerSession({ authenticated: false, principalType: null });
    this.projects = [];
    this.projectStats = null;
    this.projectPage = 1;
    this.hasMoreProjects = false;
    this.projectsLoadState = "error";
    this.resetCustomerNotifications(null);
    this.notificationsLoadState = "error";
    this.currentProject = null;
    this.currentProjectRequest += 1;
    this.closeProjectDetails();
    this.close();
    this.setProjectStatus("نشست شما منقضی شده است؛ برای ادامه دوباره وارد حساب شوید.", "error");
    this.renderProjectsState();
    this.updateStats();
    this.renderNotifications();
    this.notification?.error("نشست شما منقضی شده است؛ دوباره وارد حساب شوید.");
    return true;
  }

  async loadProjects({ append = false } = {}) {
    if (this.projectsLoading) return;
    const session = AuthSystem.checkSession();
    if (!session || session.type !== "user") {
      this.projectsLoadState = "error";
      this.setProjectStatus("برای دریافت پروژه‌ها نشست معتبر لازم است.", "error");
      this.renderProjectsState();
      return;
    }
    const requestSessionRevision = AuthSystem.sessionRevision;
    this.projectsLoading = true;
    if (!append) {
      // Do not present a prior panel/session snapshot as if it were freshly confirmed.
      this.projects = [];
      this.projectStats = null;
      this.projectPage = 1;
      this.hasMoreProjects = false;
      this.updateStats();
    }
    this.projectsLoadState = "loading";
    this.setProjectStatus(append ? "در حال دریافت پروژه‌های بیشتر…" : "در حال دریافت پروژه‌ها از سرور…", "loading");
    this.renderProjectsState();
    const loadMore = document.getElementById("loadMoreProjectsBtn");
    if (loadMore) loadMore.disabled = true;
    try {
      const page = append ? this.projectPage + 1 : 1;
      const data = await apiRequest("getProjects", { method: "GET", query: { page, perPage: 20 } });
      if (requestSessionRevision !== AuthSystem.sessionRevision || AuthSystem.checkSession()?.type !== "user") return;
      const incoming = data?.projects;
      const requestedPage = append ? this.projectPage + 1 : 1;
      const validStats = isCustomerRecord(data?.stats)
        && Number.isSafeInteger(data.stats.total) && data.stats.total >= 0
        && Number.isSafeInteger(data.stats.active) && data.stats.active >= 0 && data.stats.active <= data.stats.total
        && Number.isSafeInteger(data.stats.completed) && data.stats.completed >= 0 && data.stats.completed <= data.stats.total;
      if (!Array.isArray(incoming) || incoming.length > 20
        || !incoming.every((project) => isCustomerProjectSummary(project))
        || !isCustomerListPagination(data?.pagination, requestedPage, 20)
        || !validStats) {
        throw new Error("ساختار پاسخ فهرست پروژه‌ها معتبر نیست؛ دادهٔ ناسازگار نمایش داده نمی‌شود.");
      }
      const previous = append ? this.projects : [];
      const merged = [...previous];
      const known = new Set(merged.map((project) => String(project.publicId || project.id)));
      for (const project of incoming) {
        const id = String(project?.publicId || project?.id || "");
        if (id && !known.has(id)) {
          merged.push(project);
          known.add(id);
        }
      }
      this.projects = merged;
      this.projectPage = data.pagination.page;
      this.hasMoreProjects = data.pagination.hasMore;
      this.projectStats = {
        total: data.stats.total,
        active: data.stats.active,
        completed: data.stats.completed,
      };
      this.projectsLoadState = this.projects.length === 0 ? "empty" : "success";
      this.renderProjects();
      this.updateStats();
      this.setProjectStatus(
        this.projects.length === 0 ? "هنوز پروژه‌ای ثبت نشده است." : `نمایش ${this.projects.length} از ${this.projectStats.total} پروژه حساب شما.`,
        this.projectsLoadState,
      );
    } catch (error) {
      if (requestSessionRevision !== AuthSystem.sessionRevision) return;
      if (this.handleCustomerRequestError(error)) return;
      this.projectsLoadState = "error";
      this.setProjectStatus(error?.message || "پروژه‌ها دریافت نشدند؛ دوباره تلاش کنید.", "error");
      this.renderProjectsState();
      this.notification?.error(error?.message || "بارگذاری پروژه‌ها ناموفق بود.");
    } finally {
      this.projectsLoading = false;
      if (loadMore) {
        loadMore.disabled = false;
        loadMore.hidden = !this.hasMoreProjects;
      }
      if (requestSessionRevision !== AuthSystem.sessionRevision
          && this.modal?.classList.contains("active")
          && AuthSystem.checkSession()?.type === "user") {
        queueMicrotask(() => void this.loadProjects({ append: false }));
      }
    }
  }

  setProjectStatus(message, state) {
    const status = document.getElementById("userProjectsStatus");
    if (!status) return;
    status.dataset.state = state;
    status.textContent = message;
    status.setAttribute("aria-busy", state === "loading" ? "true" : "false");
    const retry = document.getElementById("userProjectsRetryBtn");
    if (retry) retry.hidden = state !== "error";
  }

  createProjectListSkeletonCard() {
    const card = document.createElement("article");
    card.className = "project-card customer-project-card customer-project-card-skeleton";
    card.setAttribute("aria-hidden", "true");

    const header = document.createElement("div");
    header.className = "project-header customer-project-skeleton-header";

    const info = document.createElement("div");
    info.className = "project-info customer-project-skeleton-info";
    for (const className of ["customer-skeleton-line customer-skeleton-title", "customer-skeleton-line customer-skeleton-meta", "customer-skeleton-line customer-skeleton-meta-short"]) {
      const line = document.createElement("span");
      line.className = className;
      info.append(line);
    }

    const status = document.createElement("span");
    status.className = "customer-skeleton-block customer-skeleton-status";
    header.append(info, status);

    const progress = document.createElement("div");
    progress.className = "project-progress customer-project-skeleton-progress";
    const circle = document.createElement("span");
    circle.className = "customer-skeleton-block customer-skeleton-circle";
    const details = document.createElement("div");
    details.className = "customer-project-skeleton-details";
    for (const className of ["customer-skeleton-line customer-skeleton-detail-wide", "customer-skeleton-line customer-skeleton-detail-medium", "customer-skeleton-line customer-skeleton-detail-short"]) {
      const line = document.createElement("span");
      line.className = className;
      details.append(line);
    }
    progress.append(circle, details);

    const actions = document.createElement("div");
    actions.className = "project-actions customer-project-skeleton-actions";
    for (let index = 0; index < 2; index += 1) {
      const button = document.createElement("span");
      button.className = "customer-skeleton-block customer-skeleton-button";
      actions.append(button);
    }

    card.append(header, progress, actions);
    return card;
  }

  renderProjectsState() {
    const container = document.getElementById("userProjectsList");
    if (!container) return;

    const isLoading = this.projectsLoadState === "loading";
    container.setAttribute("aria-busy", isLoading ? "true" : "false");

    if (this.projects.length === 0 && isLoading) {
      // The live status above remains the accessible source of truth; skeletons
      // are decorative and never contain guessed project names or progress.
      const skeletons = document.createDocumentFragment();
      for (let index = 0; index < 3; index += 1) {
        skeletons.append(this.createProjectListSkeletonCard());
      }
      container.replaceChildren(skeletons);
      return;
    }

    if (this.projects.length === 0 && ["error", "idle"].includes(this.projectsLoadState)) {
      container.replaceChildren();
    }
  }

  renderProjects() {
    const container = document.getElementById("userProjectsList");
    if (!container) return;
    container.removeAttribute("aria-busy");
    if (this.projects.length === 0) {
      container.innerHTML = this.projectsLoadState === "empty"
        ? '<div class="empty-state"><div class="empty-icon" aria-hidden="true">📭</div><h3>هنوز پروژه‌ای ثبت نکرده‌اید</h3><p>با NEO اولین درخواست خود را ثبت کنید.</p></div>'
        : "";
      this.setupProjectActions();
      return;
    }
    container.innerHTML = this.projects.map((project) => this.createProjectCard(project)).join("");
    this.setupProjectActions();
  }

  createProjectCard(project) {
    const id = String(project.publicId || project.id || "");
    const progress = Math.max(0, Math.min(100, Number(project.progress) || 0));
    const status = String(project.status || "submitted");
    const statusLabel = this.getStatusLabel(status);
    const statusClass = this.getStatusClass(status);
    const industry = project.industryLabel || "نامشخص";
    const style = project.styleLabel || "نامشخص";
    const timeline = project.timelineLabel || "نامشخص";
    const tracking = project.trackingCode || "کد پیگیری در دسترس نیست";
    return `
      <article class="project-card customer-project-card" data-project-id="${escapeCustomerHTML(id)}">
        <div class="project-header">
          <div class="project-info">
            <h3>${escapeCustomerHTML(project.title || industry)}</h3>
            <p class="customer-project-meta">${escapeCustomerHTML(industry)} · ${escapeCustomerHTML(style)} · زمان‌بندی ${escapeCustomerHTML(timeline)}</p>
            <button type="button" class="project-code customer-copy-code" data-action="copy-code" aria-label="کپی کد پیگیری ${escapeCustomerHTML(tracking)}">${escapeCustomerHTML(tracking)} <span aria-hidden="true">⧉</span></button>
          </div>
          <span class="project-status ${statusClass}">${escapeCustomerHTML(statusLabel)}</span>
        </div>
        <div class="project-progress">
          <div class="progress-circle-user" role="img" aria-label="پیشرفت ${progress} درصد">
            <svg viewBox="0 0 36 36" aria-hidden="true" focusable="false">
              <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="3"/>
              <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#06b6d4" stroke-width="3" stroke-dasharray="${progress}, 100" stroke-linecap="round" transform="rotate(-90 18 18)"/>
            </svg>
            <span class="progress-text-user">${progress}%</span>
          </div>
          <div class="progress-details">
            <div class="progress-stage">${escapeCustomerHTML(statusLabel)}</div>
            <div class="progress-date">ثبت: ${escapeCustomerHTML(this.formatDate(project.createdAt))}</div>
            <div class="progress-date">آخرین به‌روزرسانی: ${escapeCustomerHTML(this.formatDate(project.updatedAt))}</div>
          </div>
        </div>
        <div class="project-actions customer-project-actions">
          <button type="button" class="btn btn-outline btn-sm" data-action="view">مشاهده جزئیات</button>
          <button type="button" class="btn btn-primary btn-sm" data-action="message">پیام به پشتیبانی</button>
        </div>
      </article>`;
  }

  setupProjectActions() {
    const container = document.getElementById("userProjectsList");
    if (!container) return;
    container.querySelectorAll(".project-card [data-action]").forEach((button) => {
      button.addEventListener("click", () => {
        const card = button.closest(".project-card");
        const projectId = card?.dataset.projectId;
        if (!projectId) return;
        if (button.dataset.action === "copy-code") {
          const project = this.projects.find((item) => String(item.publicId || item.id) === projectId);
          if (project?.trackingCode) void this.copyTrackingCode(project.trackingCode);
          return;
        }
        if (button.dataset.action === "view") void this.showProjectDetails(projectId, false);
        if (button.dataset.action === "message") void this.showProjectDetails(projectId, true);
      });
    });
  }

  async copyTrackingCode(code) {
    try {
      if (!navigator.clipboard?.writeText) throw new Error("clipboard-unavailable");
      await navigator.clipboard.writeText(String(code));
      this.notification?.success("کد پیگیری کپی شد.");
    } catch {
      this.notification?.error("کپی خودکار در دسترس نیست؛ کد پیگیری را از روی کارت انتخاب کنید.");
    }
  }

  async showProjectDetails(publicId, focusMessage = false) {
    if (!this.detailModal || !/^[a-f0-9]{32}$/.test(String(publicId))) {
      this.notification?.error("شناسهٔ پروژه معتبر نیست.");
      return;
    }
    this.lastFocusedDetailTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    this.currentProject = null;
    this.timelineLoadingMore = false;
    this.messagesLoadingMore = false;
    const detailSkeleton = document.getElementById("customerProjectDetailSkeleton");
    if (detailSkeleton) detailSkeleton.hidden = false;
    this.setProjectHistorySkeleton("timeline", false);
    this.setProjectHistorySkeleton("messages", false);
    for (const id of ["customerProjectTimelineStatus", "customerProjectMessagesStatus"]) {
      const historyStatus = document.getElementById(id);
      if (historyStatus) {
        historyStatus.hidden = true;
        historyStatus.textContent = "";
        historyStatus.dataset.state = "idle";
        historyStatus.setAttribute("aria-busy", "false");
      }
    }
    const requestId = ++this.currentProjectRequest;
    const requestSessionRevision = AuthSystem.sessionRevision;
    const status = document.getElementById("customerProjectDetailStatus");
    const content = document.getElementById("customerProjectDetailContent");
    const retry = document.getElementById("customerProjectDetailRetryBtn");
    if (status) {
      status.hidden = false;
      status.dataset.state = "loading";
      status.textContent = "در حال دریافت جزئیات معتبر پروژه از سرور…";
      status.removeAttribute("aria-busy");
    }
    if (content) {
      content.hidden = true;
      content.setAttribute("aria-busy", "true");
    }
    if (retry) retry.hidden = true;
    this.detailModal.classList.add("active");
    this.detailModal.removeAttribute("hidden");
    syncSharedModalScrollLock();
    document.getElementById("customerProjectDetailClose")?.focus();
    const retryButton = document.getElementById("customerProjectDetailRetryBtn");
    if (retryButton) {
      retryButton.onclick = () => void this.showProjectDetails(publicId, focusMessage);
    }
    try {
      const result = await apiRequest("getProjectById", { method: "GET", query: { projectId: publicId } });
      if (requestId !== this.currentProjectRequest || !this.detailModal.classList.contains("active")) return;
      if (requestSessionRevision !== AuthSystem.sessionRevision || AuthSystem.checkSession()?.type !== "user") {
        this.closeProjectDetails();
        return;
      }
      const project = result?.project;
      if (!isCustomerProjectDetail(project, publicId)) {
        throw new Error("ساختار جزئیات و صفحه‌بندی پروژه در پاسخ سرور معتبر نیست؛ دادهٔ ناسازگار نمایش داده نمی‌شود.");
      }
      this.currentProject = project;
      const projectMessages = project.messages;
      if (projectMessages.some((message) => message.senderType !== "user" && message.read !== true)) {
        try {
          const receipt = await apiRequest("markCustomerMessagesRead", { method: "POST", body: { projectId: result.project.publicId || result.project.id } });
          if (receipt?.projectId === publicId && receipt?.read === true) {
            result.project.messages = projectMessages.map((message) => message.senderType === "user" ? message : { ...message, read: true });
          }
        } catch (receiptError) {
          if (requestId !== this.currentProjectRequest || !this.detailModal.classList.contains("active")) return;
          if (this.handleCustomerRequestError(receiptError)) return;
          if (requestSessionRevision !== AuthSystem.sessionRevision || AuthSystem.checkSession()?.type !== "user") {
            this.closeProjectDetails();
            return;
          }
          // Preserve the server-returned read state if the acknowledgement did not succeed.
        }
      }
      if (requestId !== this.currentProjectRequest || !this.detailModal.classList.contains("active")) return;
      this.renderProjectDetails(result.project);
      if (detailSkeleton) detailSkeleton.hidden = true;
      if (content) {
        content.hidden = false;
        content.setAttribute("aria-busy", "false");
      }
      if (status) {
        status.hidden = true;
        status.textContent = "";
        status.removeAttribute("aria-busy");
      }
      if (focusMessage) setTimeout(() => document.getElementById("customerProjectMessageInput")?.focus(), 0);
    } catch (error) {
      // Ignore an older request before interpreting its error; a stale 401 must not
      // close a newer project's dialog or invalidate a newer customer session.
      if (requestId !== this.currentProjectRequest || !this.detailModal.classList.contains("active")) return;
      if (this.handleCustomerRequestError(error)) return;
      if (detailSkeleton) detailSkeleton.hidden = true;
      if (status) {
        status.hidden = false;
        status.dataset.state = "error";
        status.textContent = error?.message || "جزئیات پروژه دریافت نشد؛ ارتباط را بررسی و دوباره تلاش کنید.";
        status.removeAttribute("aria-busy");
      }
      if (retry) retry.hidden = false;
      if (content) content.setAttribute("aria-busy", "false");
    }
  }

  renderProjectDetails(project) {
    const summary = document.getElementById("customerProjectDetailSummary");
    const timeline = document.getElementById("customerProjectTimeline");
    const messages = document.getElementById("customerProjectMessages");
    const milestones = document.getElementById("customerProjectMilestones");
    const briefContainer = document.getElementById("customerProjectBrief");
    if (!summary || !timeline || !messages || !milestones || !briefContainer) return;
    const progress = Math.max(0, Math.min(100, Number(project.progress) || 0));
    const status = this.getStatusLabel(String(project.status || "submitted"));
    summary.innerHTML = `
      <div class="customer-detail-heading">
        <div><p class="customer-detail-eyebrow">${escapeCustomerHTML(project.projectType || "پروژه")}</p><h3>${escapeCustomerHTML(project.title || "پروژهٔ شما")}</h3></div>
        <span class="project-status ${this.getStatusClass(String(project.status || "submitted"))}">${escapeCustomerHTML(status)}</span>
      </div>
      <div class="customer-detail-codes"><span>کد پیگیری</span><strong dir="ltr">${escapeCustomerHTML(project.trackingCode || "—")}</strong><button type="button" class="btn btn-outline btn-sm" data-copy-detail-code>کپی کد</button></div>
      <div class="customer-detail-progress"><div class="customer-detail-progress-label"><span>پیشرفت ثبت‌شده در سرور</span><strong>${progress}%</strong></div><progress class="customer-detail-progress-bar" max="100" value="${progress}" aria-label="پیشرفت پروژه">${progress}%</progress></div>
      <dl class="customer-detail-facts">
        <div><dt>ثبت درخواست</dt><dd>${escapeCustomerHTML(this.formatDate(project.submittedAt || project.createdAt, true))}</dd></div>
        <div><dt>آخرین به‌روزرسانی</dt><dd>${escapeCustomerHTML(this.formatDate(project.updatedAt, true))}</dd></div>
        <div><dt>وضعیت</dt><dd>${escapeCustomerHTML(status)}</dd></div>
      </dl>`;
    if (["needs_information", "rejected", "cancelled"].includes(project.status)
      && typeof project.statusReason === "string" && project.statusReason.trim() !== "") {
      const reason = document.createElement("p");
      reason.className = "customer-status-reason";
      const label = document.createElement("strong");
      label.textContent = project.status === "needs_information" ? "اطلاعات موردنیاز: " : "دلیل تغییر وضعیت: ";
      const value = document.createElement("span");
      value.textContent = project.statusReason;
      reason.append(label, value);
      summary.append(reason);
    }
    summary.querySelector("[data-copy-detail-code]")?.addEventListener("click", () => {
      if (project.trackingCode) void this.copyTrackingCode(project.trackingCode);
    });

    const brief = project.brief && typeof project.brief === "object" ? project.brief : {};
    const paletteLabels = Array.isArray(project.paletteLabels) ? project.paletteLabels : [];
    const featureLabels = Array.isArray(project.featuresLabels) ? project.featuresLabels : [];
    const contentLabels = project.contentLabels && typeof project.contentLabels === "object" ? project.contentLabels : {};
    const contentSummary = [["لوگو", contentLabels.logo], ["متن", contentLabels.text], ["تصاویر", contentLabels.images]]
      .filter(([, value]) => typeof value === "string" && value.trim() !== "")
      .map(([label, value]) => `${label}: ${value}`)
      .join(" · ");
    const fields = [
      ["نوع فعالیت", project.industryLabel || "نامشخص"],
      ["هدف پروژه", project.purposeLabel || "نامشخص"],
      ["سبک طراحی", project.styleLabel || "نامشخص"],
      ["زمان‌بندی", project.timelineLabel || "نامشخص"],
      ["پالت‌های انتخابی", paletteLabels.join("، ")],
      ["رنگ‌های دلخواه", Array.isArray(brief.colors?.customColors) ? brief.colors.customColors.join("، ") : ""],
      ["امکانات درخواستی", featureLabels.join("، ")],
      ["وضعیت محتوا", contentSummary],
      ["نام ثبت‌شده", brief.fullName],
      ["شماره تماس", brief.phone],
      ["ایمیل", brief.email],
      ["راه ارتباطی", [project.messengerLabel || "", brief.messengerId].filter(Boolean).join(" — ")],
      ["توضیحات شما", brief.userNote],
    ].filter(([label, value]) => typeof value === "string" && value.trim() !== "");
    briefContainer.innerHTML = fields.length
      ? fields.map(([label, value]) => `<div class="customer-brief-row"><dt>${escapeCustomerHTML(label)}</dt><dd>${escapeCustomerHTML(value)}</dd></div>`).join("")
      : '<p class="customer-empty-inline">جزئیات تکمیلی برای این پروژه ثبت نشده است.</p>';

    const milestoneItems = Array.isArray(project.milestones) ? project.milestones : [];
    milestones.innerHTML = milestoneItems.length
      ? milestoneItems.map((item) => `
        <li class="customer-milestone customer-milestone-${escapeCustomerHTML(item.status)}">
          <span class="customer-milestone-marker" aria-hidden="true"></span>
          <div><strong>${escapeCustomerHTML(item.name || "مرحله")}</strong><span>${escapeCustomerHTML(item.statusLabel || "نامشخص")}</span></div>
          <span class="customer-milestone-weight">${Math.max(0, Math.min(100, Number(item.weight) || 0))}%</span>
        </li>`).join("")
      : '<li class="customer-empty-inline">مراحل پروژه هنوز در دسترس نیستند.</li>';

    const timelineItems = Array.isArray(project.timeline) ? project.timeline : [];
    timeline.innerHTML = timelineItems.length
      ? timelineItems.map((event) => `
        <li class="customer-timeline-entry"><span class="customer-timeline-dot" aria-hidden="true"></span><div><p>${escapeCustomerHTML(event.label || "رویداد پروژه")}</p>${typeof event.message === "string" && event.message ? `<p class="customer-timeline-message">${escapeCustomerHTML(event.message)}</p>` : ""}<time datetime="${escapeCustomerHTML(event.createdAt || "")}">${escapeCustomerHTML(this.formatDate(event.createdAt, true))}</time></div></li>`).join("")
      : '<li class="customer-empty-inline">هنوز رویداد عمومی برای این پروژه ثبت نشده است.</li>';

    const messageItems = Array.isArray(project.messages) ? project.messages : [];
    messages.innerHTML = messageItems.length
      ? messageItems.map((message) => `
        <article class="customer-message ${message.senderType === "user" ? "customer-message-own" : "customer-message-team"}">
          <header><strong>${escapeCustomerHTML(message.senderLabel || "پیام")}</strong><time datetime="${escapeCustomerHTML(message.createdAt || "")}">${escapeCustomerHTML(this.formatDate(message.createdAt, true))}</time></header>
          <p>${escapeCustomerHTML(message.body || "")}</p>
          <small class="customer-message-state" data-state="${message.read ? "read" : "unread"}">${message.senderType === "user" ? (message.read ? "خوانده‌شده توسط مدیریت" : "ثبت‌شده؛ در انتظار مشاهده") : (message.read ? "خوانده‌شده توسط شما" : "پیام جدید")}</small>
        </article>`).join("")
      : '<p class="customer-empty-inline">هنوز پیامی برای این پروژه ثبت نشده است. برای ارتباط با تیم می‌توانید پیام بفرستید.</p>';
    this.updateProjectHistoryControls();
  }

  createCustomerTimelineSkeletonItem() {
    const item = document.createElement("li");
    item.className = "customer-timeline-entry customer-history-skeleton customer-history-skeleton--timeline";
    item.setAttribute("aria-hidden", "true");
    item.dataset.customerHistorySkeleton = "true";

    const dot = document.createElement("span");
    dot.className = "customer-skeleton-block customer-history-skeleton__dot";
    const content = document.createElement("div");
    content.className = "customer-history-skeleton__content";
    for (const className of [
      "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--title",
      "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--body",
      "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--meta",
    ]) {
      const line = document.createElement("span");
      line.className = className;
      content.append(line);
    }
    item.append(dot, content);
    return item;
  }

  createCustomerMessageSkeletonItem(index = 0) {
    const item = document.createElement("article");
    item.className = `customer-message customer-history-skeleton customer-history-skeleton--message${index % 2 ? " customer-history-skeleton--own" : ""}`;
    item.setAttribute("aria-hidden", "true");
    item.dataset.customerHistorySkeleton = "true";

    const header = document.createElement("div");
    header.className = "customer-history-skeleton__message-header";
    const sender = document.createElement("span");
    sender.className = "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--sender";
    const date = document.createElement("span");
    date.className = "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--date";
    header.append(sender, date);
    const body = document.createElement("span");
    body.className = "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--message";
    const meta = document.createElement("span");
    meta.className = "customer-skeleton-line customer-history-skeleton__line customer-history-skeleton__line--message-meta";
    item.append(header, body, meta);
    return item;
  }

  setProjectHistorySkeleton(type, visible) {
    const isTimeline = type === "timeline";
    if (!isTimeline && type !== "messages") return;
    const container = document.getElementById(isTimeline ? "customerProjectTimeline" : "customerProjectMessages");
    if (!container) return;

    container.querySelectorAll("[data-customer-history-skeleton]").forEach((item) => item.remove());
    container.setAttribute("aria-busy", visible ? "true" : "false");
    if (!visible) return;

    const placeholders = document.createDocumentFragment();
    const count = isTimeline ? 3 : 2;
    for (let index = 0; index < count; index += 1) {
      placeholders.append(isTimeline
        ? this.createCustomerTimelineSkeletonItem()
        : this.createCustomerMessageSkeletonItem(index));
    }
    container.prepend(placeholders);
  }

  updateProjectHistoryControls() {
    const project = this.currentProject;
    const controls = [
      {
        type: "timeline",
        key: "timelinePagination",
        buttonId: "customerProjectTimelineMoreBtn",
        statusId: "customerProjectTimelineStatus",
        loading: this.timelineLoadingMore,
        idleLabel: "نمایش رویدادهای قدیمی‌تر",
        loadingLabel: "در حال دریافت رویدادهای قدیمی‌تر…",
        retryLabel: "تلاش دوباره برای دریافت رویدادهای قدیمی‌تر",
      },
      {
        type: "messages",
        key: "messagesPagination",
        buttonId: "customerProjectMessagesMoreBtn",
        statusId: "customerProjectMessagesStatus",
        loading: this.messagesLoadingMore,
        idleLabel: "نمایش پیام‌های قدیمی‌تر",
        loadingLabel: "در حال دریافت پیام‌های قدیمی‌تر…",
        retryLabel: "تلاش دوباره برای دریافت پیام‌های قدیمی‌تر",
      },
    ];
    for (const item of controls) {
      const button = document.getElementById(item.buttonId);
      const status = document.getElementById(item.statusId);
      const pagination = project?.[item.key];
      if (button) {
        button.hidden = !pagination?.hasMore;
        button.disabled = item.loading;
        button.setAttribute("aria-busy", item.loading ? "true" : "false");
        button.textContent = item.loading
          ? item.loadingLabel
          : (status?.dataset.state === "error" ? item.retryLabel : item.idleLabel);
      }
      if (!pagination?.hasMore && status?.dataset.state === "idle") status.hidden = true;
    }
  }

  async loadOlderProjectHistory(type) {
    const isTimeline = type === "timeline";
    if (!isTimeline && type !== "messages") return;
    const project = this.currentProject;
    if (!project || !this.detailModal?.classList.contains("active")) return;
    const loadingProperty = isTimeline ? "timelineLoadingMore" : "messagesLoadingMore";
    if (this[loadingProperty]) return;

    const collection = isTimeline ? "timeline" : "messages";
    const paginationKey = isTimeline ? "timelinePagination" : "messagesPagination";
    const pagination = project[paginationKey];
    if (!isCustomerHistoryPagination(pagination) || !pagination.hasMore) return;

    const publicId = String(project.publicId || project.id || "");
    if (!/^[a-f0-9]{32}$/.test(publicId)) return;
    const requestId = this.currentProjectRequest;
    const sessionRevision = AuthSystem.sessionRevision;
    const targetPage = pagination.page + 1;
    const otherPaginationKey = isTimeline ? "messagesPagination" : "timelinePagination";
    const otherPage = project[otherPaginationKey]?.page;
    const statusId = isTimeline ? "customerProjectTimelineStatus" : "customerProjectMessagesStatus";
    const status = document.getElementById(statusId);
    this[loadingProperty] = true;
    this.setProjectHistorySkeleton(type, true);
    if (status) {
      status.hidden = false;
      status.dataset.state = "loading";
      status.textContent = isTimeline ? "در حال دریافت رویدادهای قدیمی‌تر از سرور…" : "در حال دریافت پیام‌های قدیمی‌تر از سرور…";
      status.removeAttribute("aria-busy");
    }
    this.updateProjectHistoryControls();

    try {
      const query = {
        projectId: publicId,
        timelinePage: isTimeline ? targetPage : project.timelinePagination.page,
        messagesPage: isTimeline ? project.messagesPagination.page : targetPage,
      };
      const result = await apiRequest("getProjectById", { method: "GET", query });
      if (requestId !== this.currentProjectRequest || !this.detailModal?.classList.contains("active")
        || String(this.currentProject?.publicId || this.currentProject?.id || "") !== publicId) return;
      if (sessionRevision !== AuthSystem.sessionRevision || AuthSystem.checkSession()?.type !== "user") {
        this.closeProjectDetails();
        return;
      }
      const incoming = result?.project;
      const requestedPagination = incoming?.[paginationKey];
      const validCollection = isTimeline
        ? Array.isArray(incoming?.timeline) && incoming.timeline.length <= 50 && incoming.timeline.every(isCustomerTimelineItem)
        : Array.isArray(incoming?.messages) && incoming.messages.length <= 50 && incoming.messages.every(isCustomerProjectMessage);
      const expectedHistoryPages = isTimeline
        ? { timeline: targetPage, messages: otherPage }
        : { timeline: otherPage, messages: targetPage };
      if (!isCustomerProjectDetail(incoming, publicId, expectedHistoryPages)
        || !validCollection
        || !isCustomerHistoryPagination(requestedPagination, targetPage)
        || !isCustomerHistoryPagination(incoming[otherPaginationKey], otherPage)) {
        throw new Error("ساختار صفحهٔ قدیمی‌تر تاریخچه در پاسخ سرور معتبر نیست؛ هیچ بخشی از تاریخچه جایگزین نشد.");
      }
      const existing = Array.isArray(this.currentProject[collection]) ? this.currentProject[collection] : [];
      const older = incoming[collection];
      const keyOf = isTimeline ? customerTimelineItemKey : customerMessageItemKey;
      const merged = mergeOlderCustomerHistory(older, existing, keyOf);
      this.currentProject[collection] = merged;
      this.currentProject[paginationKey] = requestedPagination;
      this.renderProjectDetails(this.currentProject);
      // Rendering both collections must not erase the other collection's in-flight placeholders.
      if (this.timelineLoadingMore) this.setProjectHistorySkeleton("timeline", true);
      if (this.messagesLoadingMore) this.setProjectHistorySkeleton("messages", true);
      if (status) {
        status.hidden = false;
        status.dataset.state = "success";
        status.textContent = isTimeline
          ? `${Math.max(0, merged.length - existing.length)} رویداد قدیمی‌تر دریافت شد.`
          : `${Math.max(0, merged.length - existing.length)} پیام قدیمی‌تر دریافت شد.`;
        status.setAttribute("aria-busy", "false");
      }
    } catch (error) {
      // A response for a closed/switched project has no authority over current UI state.
      if (requestId !== this.currentProjectRequest || !this.detailModal?.classList.contains("active")
        || String(this.currentProject?.publicId || this.currentProject?.id || "") !== publicId) return;
      if (this.handleCustomerRequestError(error)) return;
      if (status) {
        status.hidden = false;
        status.dataset.state = "error";
        status.textContent = error?.message || (isTimeline ? "دریافت رویدادهای قدیمی‌تر ناموفق بود." : "دریافت پیام‌های قدیمی‌تر ناموفق بود.");
        status.setAttribute("aria-busy", "false");
      }
    } finally {
      if (requestId === this.currentProjectRequest
        && String(this.currentProject?.publicId || this.currentProject?.id || "") === publicId) {
        this[loadingProperty] = false;
        this.setProjectHistorySkeleton(type, false);
        this.updateProjectHistoryControls();
      }
    }
  }

  async submitProjectMessage(event) {
    event.preventDefault();
    if (this.messageSending || !this.currentProject) return;
    const input = document.getElementById("customerProjectMessageInput");
    const submit = document.getElementById("customerProjectMessageSubmit");
    const status = document.getElementById("customerProjectMessageStatus");
    const text = String(input?.value || "").trim();
    if (!text || text.length > 2000) {
      if (status) {
        status.dataset.state = "error";
        status.textContent = "پیام باید بین ۱ تا ۲۰۰۰ نویسه باشد.";
      }
      input?.focus();
      return;
    }

    const projectId = String(this.currentProject.publicId || this.currentProject.id || "");
    let pendingRequest = this.pendingMessageRequests.get(projectId) || null;
    if (pendingRequest?.uncertain && pendingRequest.text !== text) {
      if (status) {
        status.dataset.state = "error";
        status.textContent = "نتیجهٔ تلاش قبلی مشخص نیست و ممکن است پیام ثبت شده باشد. برای جلوگیری از ثبت تکراری، ابتدا همان متن قبلی را با همان کلید دوباره ارسال کنید.";
        status.setAttribute("aria-busy", "false");
      }
      if (input) input.value = pendingRequest.text;
      input?.focus();
      return;
    }
    if (pendingRequest?.uncertain) {
      const createdAtMs = Number(pendingRequest.createdAtMs) || 0;
      const retryWindowExpired = createdAtMs <= 0 || Date.now() - createdAtMs >= CUSTOMER_MESSAGE_IDEMPOTENCY_SAFE_RETRY_WINDOW_MS;
      if (retryWindowExpired) {
        const confirmed = window.confirm(
          "بیش از ۲۲ ساعت از تلاش نامشخص گذشته یا زمان آن معلوم نیست. ابتدا تاریخچهٔ همین پروژه را تازه‌سازی و بررسی کنید که پیام قبلی ثبت نشده باشد. فقط اگر این بررسی را انجام داده‌اید و پیام قبلی را پیدا نکردید، تأیید کنید تا با کلید تازه ارسال شود. ادامه می‌تواند پیام تکراری بسازد."
        );
        if (!confirmed) {
          if (status) {
            status.dataset.state = "error";
            status.textContent = "برای جلوگیری از ارسال تکراری، تلاش قدیمی نگه داشته شد. ابتدا تاریخچهٔ پروژه را بررسی کنید.";
            status.setAttribute("aria-busy", "false");
          }
          return;
        }
        try {
          pendingRequest = {
            projectId,
            text,
            key: createCustomerMessageIdempotencyKey(),
            createdAtMs: Date.now(),
            uncertain: false,
          };
          this.pendingMessageRequests.set(projectId, pendingRequest);
        } catch (keyError) {
          if (status) {
            status.dataset.state = "error";
            status.textContent = keyError?.message || "کلید امن ارسال پیام ساخته نشد.";
            status.setAttribute("aria-busy", "false");
          }
          return;
        }
      }
    }
    if (!pendingRequest || pendingRequest.text !== text) {
      try {
        pendingRequest = {
          projectId,
          text,
          key: createCustomerMessageIdempotencyKey(),
          createdAtMs: Date.now(),
          uncertain: false,
        };
      } catch (keyError) {
        if (status) {
          status.dataset.state = "error";
          status.textContent = keyError?.message || "کلید امن ارسال پیام ساخته نشد.";
        }
        return;
      }
      this.pendingMessageRequests.set(projectId, pendingRequest);
    }

    this.messageSending = true;
    if (submit) submit.disabled = true;
    if (status) {
      status.dataset.state = "loading";
      status.textContent = pendingRequest.uncertain
        ? "در حال بازیابی نتیجهٔ تلاش قبلی با همان کلید امن…"
        : "در حال ارسال پیام به سرور…";
      status.setAttribute("aria-busy", "true");
    }
    const requestSessionRevision = AuthSystem.sessionRevision;
    try {
      const result = await apiRequest("sendMessage", {
        method: "POST",
        headers: { "Idempotency-Key": pendingRequest.key },
        body: { projectId, text: pendingRequest.text },
      });
      if (result?.sent !== true || result?.deliveryStatus !== "stored" || typeof result.createdAt !== "string") {
        const error = new Error("ثبت پیام توسط سرور تأیید نشد.");
        error.code = "INVALID_MESSAGE_RESPONSE";
        throw error;
      }
      // A canonical success (including a replay) resolves this key and permits a new message.
      this.pendingMessageRequests.delete(projectId);
      if (!this.detailModal?.classList.contains("active")
        || String(this.currentProject?.publicId || this.currentProject?.id || "") !== projectId
        || requestSessionRevision !== AuthSystem.sessionRevision
        || AuthSystem.checkSession()?.type !== "user") return;
      if (input) input.value = "";
      if (status) {
        status.dataset.state = "success";
        status.textContent = result.message || "پیام در سرور ثبت شد؛ تحویل یا مشاهدهٔ آن توسط مدیریت هنوز تأیید نشده است.";
        status.setAttribute("aria-busy", "false");
      }
      this.notification?.success("پیام در سرور ثبت شد؛ وضعیت تحویل جداگانه تأیید نشده است.");
      await this.showProjectDetails(projectId, false);
    } catch (error) {
      if (isAmbiguousMessageRequestError(error)) pendingRequest.uncertain = true;
      this.pendingMessageRequests.set(projectId, pendingRequest);
      if (!this.detailModal?.classList.contains("active")
        || String(this.currentProject?.publicId || this.currentProject?.id || "") !== projectId) return;
      if (this.handleCustomerRequestError(error)) return;
      if (requestSessionRevision !== AuthSystem.sessionRevision || AuthSystem.checkSession()?.type !== "user") {
        this.closeProjectDetails();
        return;
      }
      if (status) {
        status.dataset.state = "error";
        status.textContent = pendingRequest.uncertain
          ? "نتیجهٔ درخواست مشخص نیست و ممکن است پیام ثبت شده باشد. متن را تغییر ندهید؛ با همان متن دوباره ارسال کنید تا سرور نتیجهٔ قبلی را بازیابی کند."
          : (error?.message || "ارسال پیام تأیید نشد. متن پیام حفظ شده است.");
        status.setAttribute("aria-busy", "false");
      }
      this.notification?.error(pendingRequest.uncertain
        ? "نتیجهٔ ارسال نامشخص است؛ برای جلوگیری از پیام تکراری، با همان متن دوباره تلاش کنید."
        : (error?.message || "ارسال پیام انجام نشد؛ متن برای تلاش دوباره حفظ شده است."));
    } finally {
      this.messageSending = false;
      if (submit) submit.disabled = false;
    }
  }

  closeProjectDetails() {
    if (!this.detailModal) return;
    this.currentProjectRequest += 1;
    this.currentProject = null;
    this.timelineLoadingMore = false;
    this.messagesLoadingMore = false;
    const detailSkeleton = document.getElementById("customerProjectDetailSkeleton");
    if (detailSkeleton) detailSkeleton.hidden = true;
    this.setProjectHistorySkeleton("timeline", false);
    this.setProjectHistorySkeleton("messages", false);
    document.getElementById("customerProjectDetailContent")?.setAttribute("aria-busy", "false");
    this.detailModal.classList.remove("active");
    this.detailModal.setAttribute("hidden", "");
    syncSharedModalScrollLock();
    if (this.lastFocusedDetailTrigger?.isConnected) this.lastFocusedDetailTrigger.focus();
    else this.modal?.querySelector(".panel-close")?.focus();
  }

  async loadNotifications() {
    const container = document.getElementById("userNotificationsList");
    if (!container || this.notificationsLoading || this.notificationMarkingId) return;
    const session = AuthSystem.checkSession();
    if (!session || session.type !== "user") return;

    const ownerId = this.getCustomerNotificationOwnerId(session);
    if (!ownerId || this.notificationsOwnerId !== ownerId) {
      this.resetCustomerNotifications(ownerId);
    }
    const hasValidSnapshot = Boolean(
      ownerId && this.notificationsLoaded && this.notificationsOwnerId === ownerId,
    );
    const requestSessionRevision = AuthSystem.sessionRevision;
    this.notificationsLoading = true;
    this.notificationsLoadState = hasValidSnapshot
      ? (this.notifications.length ? "success" : "empty")
      : "loading";

    const status = document.getElementById("userNotificationsStatus");
    if (status) {
      status.textContent = hasValidSnapshot
        ? "در حال به‌روزرسانی اعلان‌ها؛ آخرین نتیجهٔ معتبر تا دریافت پاسخ جدید حفظ می‌شود."
        : "در حال دریافت اعلان‌ها از سرور…";
      status.dataset.state = "loading";
      status.hidden = false;
    }
    const actionStatus = document.getElementById("userNotificationActionStatus");
    if (actionStatus) {
      actionStatus.textContent = "";
      actionStatus.hidden = true;
      actionStatus.removeAttribute("data-state");
    }
    container.setAttribute("aria-busy", "true");
    container.inert = true;

    const retry = document.getElementById("userNotificationsRetryBtn");
    if (retry && !retry.hidden) retry.setAttribute("aria-disabled", "true");

    // Skeletons are shown only before the first validated response for this account.
    // A previously validated empty result is also a snapshot and must not be replaced.
    if (!hasValidSnapshot) {
      const skeletons = document.createDocumentFragment();
      for (let index = 0; index < 3; index += 1) {
        skeletons.append(this.createNotificationSkeletonItem());
      }
      container.replaceChildren(skeletons);
    }

    try {
      const data = await apiRequest("getNotifications", { method: "GET", query: { page: 1, perPage: 25 } });
      const currentSession = AuthSystem.checkSession();
      if (requestSessionRevision !== AuthSystem.sessionRevision
        || currentSession?.type !== "user"
        || (ownerId && this.getCustomerNotificationOwnerId(currentSession) !== ownerId)) return;
      if (!Array.isArray(data?.notifications) || data.notifications.length > 25
        || !data.notifications.every(isCustomerNotificationItem)
        || !isCustomerListPagination(data?.pagination, 1, 25)
        || !Number.isSafeInteger(data?.unreadCount) || data.unreadCount < 0) {
        throw new Error("ساختار پاسخ اعلان‌ها معتبر نیست؛ دادهٔ ناقص یا خارج از قرارداد نمایش داده نمی‌شود.");
      }
      this.notifications = data.notifications;
      this.notificationsUnreadCount = data.unreadCount;
      this.notificationsLoaded = true;
      this.notificationsOwnerId = ownerId;
      this.notificationsLoadState = this.notifications.length ? "success" : "empty";
      this.renderNotifications();
    } catch (error) {
      if (requestSessionRevision !== AuthSystem.sessionRevision) return;
      if (this.handleCustomerRequestError(error)) return;

      const keepSnapshot = Boolean(
        ownerId && this.notificationsLoaded && this.notificationsOwnerId === ownerId,
      );
      this.notificationsLoadState = keepSnapshot
        ? (this.notifications.length ? "success" : "empty")
        : "error";
      container.setAttribute("aria-busy", "false");
      container.inert = false;
      if (!keepSnapshot) container.replaceChildren();

      if (status) {
        status.textContent = keepSnapshot
          ? "به‌روزرسانی اعلان‌ها انجام نشد. آخرین اعلان‌های معتبر و شمارندهٔ همان پاسخ همچنان نمایش داده می‌شوند؛ برای دریافت وضعیت تازه دوباره تلاش کنید."
          : (error?.message || "اعلان‌ها دریافت نشدند؛ دوباره تلاش کنید.");
        status.dataset.state = "error";
        status.hidden = false;
      }
      if (retry) {
        retry.hidden = false;
        retry.removeAttribute("aria-disabled");
      }
    } finally {
      this.notificationsLoading = false;
      container.inert = false;
      const retryButton = document.getElementById("userNotificationsRetryBtn");
      if (retryButton && !retryButton.hidden && requestSessionRevision === AuthSystem.sessionRevision) {
        retryButton.setAttribute("aria-disabled", "false");
      }
      if (requestSessionRevision !== AuthSystem.sessionRevision
          && this.modal?.classList.contains("active")
          && AuthSystem.checkSession()?.type === "user") {
        queueMicrotask(() => void this.loadNotifications());
      }
    }
  }

  createNotificationSkeletonItem() {
    const item = document.createElement("div");
    item.className = "customer-notification-skeleton";
    item.setAttribute("aria-hidden", "true");

    const icon = document.createElement("span");
    icon.className = "customer-notification-skeleton__icon";

    const content = document.createElement("span");
    content.className = "customer-notification-skeleton__content";
    for (const className of [
      "customer-notification-skeleton__line customer-notification-skeleton__line--title",
      "customer-notification-skeleton__line customer-notification-skeleton__line--message",
      "customer-notification-skeleton__line customer-notification-skeleton__line--date",
    ]) {
      const line = document.createElement("span");
      line.className = className;
      content.append(line);
    }

    const state = document.createElement("span");
    state.className = "customer-notification-skeleton__state";
    item.append(icon, content, state);
    return item;
  }

  renderNotifications() {
    const container = document.getElementById("userNotificationsList");
    const badge = document.getElementById("notifBadge");
    const unread = Number.isInteger(this.notificationsUnreadCount)
      ? this.notificationsUnreadCount
      : this.notifications.filter((notification) => notification.read !== true).length;
    if (badge) {
      badge.textContent = String(unread);
      badge.hidden = unread === 0;
    }
    if (!container) return;
    container.setAttribute("aria-busy", "false");
    container.inert = false;
    const status = document.getElementById("userNotificationsStatus");
    if (status) {
      status.hidden = true;
      status.textContent = "";
      status.removeAttribute("data-state");
    }
    const retry = document.getElementById("userNotificationsRetryBtn");
    const restoreRetryFocus = Boolean(retry && document.activeElement === retry);
    if (retry) {
      retry.hidden = true;
      retry.removeAttribute("aria-disabled");
    }
    const restoreFocusAfterRefresh = () => {
      if (!restoreRetryFocus) return;
      const target = container.querySelector(".notification-item")
        || document.getElementById("userNotificationsTabButton");
      target?.focus();
    };
    if (this.notificationsLoadState === "error") {
      container.innerHTML = '<div class="customer-state" role="alert"><p>اعلان‌ها در نشست فعلی قابل‌تأیید نیستند. برای مشاهدهٔ داده‌های تازه دوباره وارد حساب شوید.</p></div>';
      restoreFocusAfterRefresh();
      return;
    }
    if (this.notifications.length === 0) {
      if (this.notificationsLoadState === "empty") {
        container.innerHTML = '<div class="empty-state"><div class="empty-icon" aria-hidden="true">🔕</div><h3>اعلانی وجود ندارد</h3><p>اعلان‌های جدید فقط پس از ثبت واقعی در سرور نمایش داده می‌شوند.</p></div>';
      } else {
        container.replaceChildren();
      }
      restoreFocusAfterRefresh();
      return;
    }
    container.innerHTML = this.notifications.map((notification) => `
      <button type="button" class="notification-item ${notification.read ? "" : "unread"}" data-notif-id="${escapeCustomerHTML(notification.id)}" aria-label="${escapeCustomerHTML(notification.title || "اعلان")}${notification.read ? "، خوانده‌شده" : "، خوانده‌نشده"}">
        <span class="notif-icon" aria-hidden="true">🔔</span>
        <span class="notif-content"><span class="notif-title">${escapeCustomerHTML(notification.title || "اعلان")}</span><span class="notif-text">${escapeCustomerHTML(notification.message || "")}</span><time class="notif-date" datetime="${escapeCustomerHTML(notification.timestamp || "")}">${escapeCustomerHTML(this.formatDate(notification.timestamp, true))}</time></span>
        <span class="notification-read-state">${notification.read ? "خوانده‌شده" : "جدید"}</span>
      </button>`).join("");
    container.querySelectorAll(".notification-item[data-notif-id]").forEach((item) => {
      item.addEventListener("click", () => {
        if (this.notificationMarkingId || this.notificationsLoading
          || item.getAttribute("aria-busy") === "true" || item.getAttribute("aria-disabled") === "true"
          || item.classList.contains("unread") === false) return;
        void this.markNotifRead(item.dataset.notifId, item);
      });
    });
    restoreFocusAfterRefresh();
  }

  async markNotifRead(notifId, button = null) {
    if (!/^[a-f0-9]{32}$/.test(String(notifId || ""))
      || this.notificationMarkingId || this.notificationsLoading) return false;

    const notification = this.notifications.find((item) => item.id === notifId);
    const session = AuthSystem.checkSession();
    const ownerId = this.getCustomerNotificationOwnerId(session);
    if (!notification || notification.read === true || session?.type !== "user"
      || !ownerId || ownerId !== this.notificationsOwnerId) return false;

    const requestSessionRevision = AuthSystem.sessionRevision;
    const container = document.getElementById("userNotificationsList");
    if (!container) return false;

    const actionStatus = document.getElementById("userNotificationActionStatus");
    const retry = document.getElementById("userNotificationsRetryBtn");
    const notificationsTab = document.getElementById("notificationsTab");
    const actionButton = button?.dataset.notifId === notifId
      ? button
      : Array.from(container.querySelectorAll(".notification-item[data-notif-id]"))
        .find((item) => item.dataset.notifId === notifId) || null;
    const originalLabel = actionButton?.getAttribute("aria-label") ?? null;
    const notificationIndex = this.notifications.findIndex((item) => item.id === notifId);
    const focusedControl = container.contains(document.activeElement) ? document.activeElement : null;
    const focusedNotificationId = focusedControl?.getAttribute("data-notif-id") || "";
    const restoreListFocus = Boolean(focusedControl && notificationsTab && !notificationsTab.hidden
      && this.modal?.classList.contains("active"));
    const actionToken = {};

    this.notificationMarkingId = notifId;
    this.notificationMarkingToken = actionToken;
    if (actionStatus) {
      actionStatus.textContent = "در حال ثبت وضعیت خوانده‌شدن اعلان در سرور…";
      actionStatus.dataset.state = "loading";
      actionStatus.hidden = false;
    }
    container.setAttribute("aria-busy", "true");
    container.querySelectorAll(".notification-item[data-notif-id]").forEach((control) => {
      control.setAttribute("aria-disabled", "true");
      if (control.dataset.notifId === notifId) {
        control.setAttribute("aria-busy", "true");
        control.setAttribute("aria-label", `${notification.title}، در حال ثبت وضعیت خوانده‌شدن`);
      }
    });
    if (retry && !retry.hidden) retry.setAttribute("aria-disabled", "true");

    try {
      const result = await apiRequest("markNotificationRead", { method: "POST", body: { id: notifId } });
      const currentSession = AuthSystem.checkSession();
      if (this.notificationMarkingToken !== actionToken
        || requestSessionRevision !== AuthSystem.sessionRevision
        || currentSession?.type !== "user"
        || this.getCustomerNotificationOwnerId(currentSession) !== ownerId
        || this.notificationsOwnerId !== ownerId) return false;
      if (result?.id !== notifId || result?.read !== true
        || !Number.isSafeInteger(result?.unreadCount) || result.unreadCount < 0) {
        throw new Error("تغییر وضعیت اعلان توسط سرور تأیید نشد.");
      }

      this.notifications = this.notifications.map((item) => item.id === notifId ? { ...item, read: true } : item);
      this.notificationsUnreadCount = result.unreadCount;
      this.notificationMarkingId = null;
      this.notificationMarkingToken = null;
      this.renderNotifications();
      if (actionStatus) {
        actionStatus.textContent = "سرور خوانده‌شدن اعلان را تأیید کرد.";
        actionStatus.dataset.state = "success";
        actionStatus.hidden = false;
      }

      if (restoreListFocus && container.contains(document.activeElement)
        && notificationsTab && !notificationsTab.hidden) {
        const actions = Array.from(container.querySelectorAll(".notification-item[data-notif-id]"));
        const sameNotificationAction = focusedNotificationId && focusedNotificationId !== notifId
          ? actions.find((item) => item.dataset.notifId === focusedNotificationId)
          : null;
        const followingUnread = this.notifications.slice(Math.max(0, notificationIndex + 1)).find((item) => !item.read)
          || this.notifications.slice(0, Math.max(0, notificationIndex)).reverse().find((item) => !item.read);
        const nextAction = sameNotificationAction || (followingUnread
          ? actions.find((item) => item.dataset.notifId === followingUnread.id)
          : actions.find((item) => item.dataset.notifId === notifId));
        (nextAction || document.getElementById("userNotificationsTabButton"))?.focus({ preventScroll: true });
      }
      return true;
    } catch (error) {
      if (requestSessionRevision !== AuthSystem.sessionRevision) return false;
      if (this.handleCustomerRequestError(error)) return false;
      if (actionStatus) {
        actionStatus.textContent = error?.message || "وضعیت اعلان تغییر نکرد؛ دوباره تلاش کنید.";
        actionStatus.dataset.state = "error";
        actionStatus.hidden = false;
      }
      this.notification?.error(error?.message || "وضعیت اعلان تغییر نکرد؛ دوباره تلاش کنید.");
      return false;
    } finally {
      if (this.notificationMarkingToken === actionToken) {
        this.notificationMarkingId = null;
        this.notificationMarkingToken = null;
        const latestSession = AuthSystem.checkSession();
        const latestOwnerId = this.getCustomerNotificationOwnerId(latestSession);
        if (latestSession?.type !== "user" || latestOwnerId !== ownerId || this.notificationsOwnerId !== ownerId) {
          this.resetCustomerNotifications(latestOwnerId);
        } else {
          container.setAttribute("aria-busy", "false");
          container.querySelectorAll(".notification-item[data-notif-id]").forEach((control) => {
            control.removeAttribute("aria-disabled");
            control.removeAttribute("aria-busy");
          });
          if (actionButton?.isConnected && originalLabel !== null) {
            actionButton.setAttribute("aria-label", originalLabel);
          }
          retry?.removeAttribute("aria-disabled");
          if (requestSessionRevision !== AuthSystem.sessionRevision) {
            if (actionStatus) {
              actionStatus.textContent = "نشست تغییر کرد؛ نتیجهٔ این اقدام از نشست قبلی اعمال نشد و وضعیت اعلان‌ها باید دوباره دریافت شود.";
              actionStatus.dataset.state = "error";
              actionStatus.hidden = false;
            }
            if (this.modal?.classList.contains("active")) queueMicrotask(() => void this.loadNotifications());
          }
        }
      }
    }
  }

  updateStats() {
    const active = document.getElementById("activeProjectsCount");
    const completed = document.getElementById("completedProjectsCount");
    if (!this.projectStats) {
      if (active) active.textContent = "—";
      if (completed) completed.textContent = "—";
      return;
    }
    if (active) active.textContent = String(this.projectStats.active);
    if (completed) completed.textContent = String(this.projectStats.completed);
  }

  getStatusLabel(status) {
    return ({
      submitted: "ثبت‌شده", under_review: "در حال بررسی", needs_information: "نیازمند اطلاعات",
      in_progress: "در حال انجام", waiting_for_client: "منتظر پاسخ شما", completed: "تکمیل‌شده",
      rejected: "ردشده", cancelled: "لغوشده",
    })[status] || "وضعیت نامشخص";
  }

  getStatusClass(status) {
    return ({
      submitted: "status-pending", under_review: "status-active", needs_information: "status-pending",
      in_progress: "status-active", waiting_for_client: "status-pending", completed: "status-completed",
      rejected: "status-rejected", cancelled: "status-rejected",
    })[status] || "status-pending";
  }

  formatDate(value, withTime = false) {
    if (typeof value !== "string" || value === "") return "—";
    let dateValue = value;
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(dateValue)) dateValue = dateValue.replace(" ", "T") + "Z";
    const date = new Date(dateValue);
    if (!Number.isFinite(date.getTime())) return "—";
    return date.toLocaleString("fa-IR", withTime
      ? { year: "numeric", month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" }
      : { year: "numeric", month: "short", day: "numeric" });
  }

  close() {
    if (this.detailModal?.classList.contains("active")) this.closeProjectDetails();
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncSharedModalScrollLock();
    if (this.lastFocusedPanelTrigger?.isConnected) this.lastFocusedPanelTrigger.focus();
    this.lastFocusedPanelTrigger = null;
  }
}

