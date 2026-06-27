/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/auth.js
 * 🎯 Responsibility: Authentication, Registration, Forgot Password, User Panel, Terms Modal
 * 🔌 Dependencies: app.js (CONFIG, DOM)
 * 🔌 Dependants: app.js (imports and instantiates classes)
 * 🚫 Constraints: Do NOT modify NEO or Admin logic here.
 * 📦 Exported: AuthSystem, RegistrationSystem, ForgotPasswordSystem, TermsModal, UserPanel
 * 🧠 AI NOTE: RegistrationSystem has 2-step flow with Telegram verification and Draft saving.
 */

import { CONFIG, DOM, sanitizeInput } from "./app.js";

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
    if (btnText) btnText.style.opacity = "0";
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      // ابتدا ورود کاربر عادی را امتحان کن
      await this.loginUser(username, password, remember);
      this.close();
    } catch (userErr) {
      // اگر ورود کاربر عادی ناموفق بود، ورود ادمین را امتحان کن
      try {
        if (!this.isDesktop())
          throw new Error("ورود ادمین فقط از دسکتاپ ممکن است");
        await this.loginAdmin(username, password, remember);
        this.close();
      } catch (adminErr) {
        this.notification?.error(adminErr.message || "اطلاعات ورود اشتباه است");
      }
    } finally {
      submitBtn.disabled = false;
      if (btnText) btnText.style.opacity = "1";
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  isDesktop() {
    return (
      !/android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile/i.test(
        navigator.userAgent,
      ) && window.innerWidth > 1024
    );
  }

  async loginAdmin(username, password, remember) {
    const resp = await fetch(CONFIG.apiEndpoint + "?action=adminLogin", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password }),
    });

    if (!resp.ok) {
      const err = await resp.json();
      throw new Error(err.error || "اطلاعات ادمین اشتباه است");
    }

    const data = await resp.json();
    const session = {
      type: "admin",
      username: data.data.username,
      token: data.data.token,
      loginTime: Date.now(),
      expiresAt: Date.now() + 86400000,
    };

    if (remember)
      localStorage.setItem("mrneon_admin_session", JSON.stringify(session));
    else
      sessionStorage.setItem("mrneon_admin_session", JSON.stringify(session));

    this.notification?.success("ورود موفق به پنل مدیریت");
    setTimeout(() => window.adminPanel?.openPanel(), 500);
  }

  async loginUser(username, password, remember) {
    const resp = await fetch(CONFIG.apiEndpoint + "?action=login", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-API-Key": CONFIG.apiKey,
      },
      body: JSON.stringify({ username, password }),
    });

    if (!resp.ok) {
      const err = await resp.json();
      throw new Error(err.error || "نام کاربری یا رمز عبور اشتباه");
    }

    const data = await resp.json();
    const session = {
      type: "user",
      userId: data.data.userId,
      username: data.data.username,
      fullName: data.data.fullName,
      token: data.data.token,
      phone: data.data.phone,
      email: data.data.email,
      loginTime: Date.now(),
      expiresAt: Date.now() + 86400000,
    };

    if (remember)
      localStorage.setItem("mrneon_user_session", JSON.stringify(session));
    else sessionStorage.setItem("mrneon_user_session", JSON.stringify(session));

    this.notification?.success(`خوش آمدید ${data.data.fullName} 🎉`);
    setTimeout(() => window.userPanel?.openPanel(), 500);
  }

  open() {
    const existingSession = AuthSystem.checkSession();
    if (existingSession) {
      if (existingSession.type === "admin") window.adminPanel?.openPanel();
      else window.userPanel?.openPanel();
      return;
    }

    this.clearFields();
    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    document.body.style.overflow = "hidden";
    setTimeout(() => document.getElementById("authUsername")?.focus(), 300);
  }

  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    document.body.style.overflow = "";
    this.clearFields();
  }

  clearFields() {
    document.getElementById("authUsername").value = "";
    document.getElementById("authPassword").value = "";
  }

  static checkSession() {
    const admin =
      localStorage.getItem("mrneon_admin_session") ||
      sessionStorage.getItem("mrneon_admin_session");
    if (admin) {
      const s = JSON.parse(admin);
      if (s.expiresAt > Date.now()) return { type: "admin", data: s };
      else AuthSystem.logout();
    }

    const user =
      localStorage.getItem("mrneon_user_session") ||
      sessionStorage.getItem("mrneon_user_session");
    if (user) {
      const s = JSON.parse(user);
      if (s.expiresAt > Date.now()) return { type: "user", data: s };
      else AuthSystem.logout();
    }

    return null;
  }

  static logout() {
    localStorage.removeItem("mrneon_admin_session");
    localStorage.removeItem("mrneon_user_session");
    sessionStorage.removeItem("mrneon_admin_session");
    sessionStorage.removeItem("mrneon_user_session");
    window.location.reload();
  }
}

// ============================================================
// 📝 سیستم ثبت‌نام (نسخه ۲.۰ - دو مرحله‌ای با Draft)
// ============================================================
export class RegistrationSystem {
  constructor() {
    this.modal = DOM.registerModal;
    this.form = DOM.registerForm;
    this.notification = window.notificationSystem;
    this.step = "info"; // 'info' یا 'verify'
    this.pendingUserId = null;
    this.pendingIdentifier = null;
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
    const pwdInput = document.getElementById("regPassword");
    pwdInput?.addEventListener("input", () => this.updatePasswordStrength());
    document
      .getElementById("regPasswordConfirm")
      ?.addEventListener("input", () => this.validatePasswordMatch());

    const toggleBtn = this.modal.querySelector(".password-toggle");
    toggleBtn?.addEventListener("click", () => {
      const inp = document.getElementById("regPassword");
      inp.type = inp.type === "password" ? "text" : "password";
    });

    // Escape key
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.modal.classList.contains("active"))
        this.close();
    });

    // Validation on blur
    [
      "regFullName",
      "regPhone",
      "regEmail",
      "regTelegram",
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

    document.getElementById("termsAcceptBtn")?.addEventListener("click", () => {
      window.termsModal?.close();
      document.getElementById("agreeTerms").checked = true;
    });
  }

  saveDraft() {
    const draft = {
      fullName: document.getElementById("regFullName")?.value || "",
      phone: document.getElementById("regPhone")?.value || "",
      email: document.getElementById("regEmail")?.value || "",
      telegramId: document.getElementById("regTelegram")?.value || "",
      username: document.getElementById("regUsername")?.value || "",
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
      if (draft.telegramId)
        document.getElementById("regTelegram").value = draft.telegramId;
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
        valid = /^09[0-9]{9}$/.test(input.value.trim());
        txt = valid ? "" : "شماره معتبر نیست (09xxxxxxxxx)";
        break;
      case "email":
        valid = !input.value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value);
        txt = valid ? "" : "ایمیل نامعتبر";
        break;
      case "telegramId":
        valid = /^[a-zA-Z0-9_]{5,32}$/.test(input.value.replace(/^@+/, ""));
        txt = valid ? "" : "آیدی نامعتبر (۵-۳۲ کاراکتر)";
        break;
      case "username":
        valid = /^[a-zA-Z0-9_]{4,20}$/.test(input.value.trim());
        txt = valid ? "" : "۴-۲۰ کاراکتر انگلیسی/زیرخط";
        break;
      case "password": {
        const pw = input.value;
        const hasLen = pw.length >= 8,
          hasUp = /[A-Z]/.test(pw),
          hasLow = /[a-z]/.test(pw),
          hasNum = /[0-9]/.test(pw);
        valid = hasLen && hasUp && hasLow && hasNum;
        txt = valid ? "" : "حداقل ۸ کاراکتر، شامل حروف و عدد";
        break;
      }
      case "passwordConfirm":
        valid = input.value === document.getElementById("regPassword").value;
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
    const pw = document.getElementById("regPassword").value;
    const checks = {
      length: pw.length >= 8,
      uppercase: /[A-Z]/.test(pw),
      lowercase: /[a-z]/.test(pw),
      number: /[0-9]/.test(pw),
    };

    document.querySelectorAll(".requirement").forEach((req) => {
      const check = req.dataset.check;
      const icon = req.querySelector(".check-icon");
      if (checks[check]) {
        icon.textContent = "✓";
        icon.style.color = "#10b981";
        req.classList.add("met");
      } else {
        icon.textContent = "✗";
        icon.style.color = "#ef4444";
        req.classList.remove("met");
      }
    });

    const score = Object.values(checks).filter(Boolean).length;
    const bar = this.modal.querySelector(".strength-bar");
    if (bar) {
      bar.style.width = (score / 4) * 100 + "%";
      bar.style.background =
        score <= 1
          ? "#ef4444"
          : score <= 2
            ? "#f59e0b"
            : score <= 3
              ? "#3b82f6"
              : "#10b981";
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

    // مرحله ۲: تایید کد
    if (this.step === "verify") {
      await this.handleVerifyCode(e);
      return;
    }

    // مرحله ۱: ثبت اولیه
    const inputs = this.form.querySelectorAll("input[required]");
    let allValid = true;
    inputs.forEach((inp) => {
      if (!this.validateField(inp)) allValid = false;
    });

    if (!allValid) {
      this.notification?.error("لطفاً فیلدها را درست پر کنید");
      return;
    }

    const telegram = document.getElementById("regTelegram").value.trim();
    if (!telegram) {
      this.notification?.error("آیدی تلگرام برای دریافت کد تایید الزامی است");
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
      telegramId: "@" + telegram.replace(/^@+/, ""),
      username: document.getElementById("regUsername").value.trim(),
      password: document.getElementById("regPassword").value,
    };

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.style.opacity = "0";
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=register", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify(data),
      });

      const res = await resp.json();
      if (!res.success) throw new Error(res.error || "خطا در ثبت‌نام");

      // ذخیره اطلاعات موقت
      this.pendingUserId = res.data.userId;
      this.pendingIdentifier = res.data.identifier;
      this.step = "verify";
      this.showVerifyStep();

      this.notification?.success(
        "✅ کد تایید به تلگرام شما ارسال شد. ربات را باز کنید.",
      );
    } catch (err) {
      this.notification?.error(err.message || "خطا در ارتباط با سرور");
    } finally {
      btn.disabled = false;
      if (btnText) btnText.style.opacity = "1";
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  showVerifyStep() {
    // مخفی کردن فرم اصلی
    const formGroups = this.form.querySelectorAll(
      ".form-group, .checkbox-group",
    );
    formGroups.forEach((g) => (g.style.display = "none"));

    // ایجاد بخش تایید
    let verifySection = this.form.querySelector(".verify-section");
    if (!verifySection) {
      verifySection = document.createElement("div");
      verifySection.className = "verify-section";
      verifySection.innerHTML = `
                <div class="verify-info" style="text-align: center; margin-bottom: 24px;">
                    <div style="font-size: 64px; margin-bottom: 16px;">🤖</div>
                    <h3 style="color: white; margin-bottom: 12px; font-size: 20px;">کد تایید ارسال شد</h3>
                    <p style="color: rgba(255,255,255,0.8); font-size: 14px; line-height: 1.8; margin-bottom: 16px;">
                        کد ۶ رقمی به تلگرام شما ارسال شد.<br>
                        <strong style="color: var(--neon);">ربات Notifier</strong> را باز کنید و دکمه <strong>Start</strong> را بزنید.
                    </p>
                    <a href="https://t.me/MR_NEON_Notifier_bot" target="_blank" 
                       class="btn btn-primary" style="display: inline-flex; margin-bottom: 20px; padding: 12px 24px;">
                        🤖 باز کردن ربات Notifier
                    </a>
                    <div style="background: rgba(6,182,212,0.1); border: 1px solid rgba(6,182,212,0.3); border-radius: 8px; padding: 12px; margin-bottom: 20px; font-size: 13px; color: rgba(255,255,255,0.7); text-align: right;">
                        💡 <strong>راهنما:</strong><br>
                        1️⃣ روی دکمه بالا کلیک کنید<br>
                        2️⃣ دکمه Start را در ربات بزنید<br>
                        3️⃣ کد ۶ رقمی را از ربات کپی کنید<br>
                        4️⃣ کد را در کادر زیر وارد کنید
                    </div>
                </div>
                <div class="form-group">
                    <label for="verifyCode" style="color: white; display: block; margin-bottom: 8px;">کد تایید ۶ رقمی *</label>
                    <input type="text" id="verifyCode" class="form-input"
                           placeholder="۱۲۳۴۵۶" maxlength="6"
                           style="text-align: center; letter-spacing: 8px; font-size: 24px; font-family: monospace; padding: 16px;">
                    <div class="input-hint">کد ۶ رقمی که از ربات دریافت کردید را وارد کنید</div>
                </div>
                <div style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-outline btn-sm" id="resendCodeBtn" style="flex: 1;">
                        🔄 ارسال مجدد کد
                    </button>
                    <button type="button" class="btn btn-outline btn-sm" id="backToInfoBtn" style="flex: 1;">
                        ↩️ بازگشت و ویرایش
                    </button>
                </div>
                <div style="margin-top: 16px; padding: 12px; background: rgba(245,158,11,0.1); border-radius: 8px; font-size: 12px; color: rgba(255,255,255,0.7);">
                    ⚠️ کد تا ۱۰ دقیقه معتبر است. اگر کد را دریافت نکردید، ربات را ریستارت کنید.
                </div>
            `;
      this.form.insertBefore(verifySection, this.form.firstChild);
    }
    verifySection.style.display = "block";

    // تغییر متن دکمه
    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    if (btnText) btnText.textContent = "✅ تایید و تکمیل ثبت‌نام";

    // Event listeners
    document
      .getElementById("resendCodeBtn")
      ?.addEventListener("click", () => this.resendCode());
    document
      .getElementById("backToInfoBtn")
      ?.addEventListener("click", () => this.backToInfo());

    // تمرکز روی فیلد کد
    setTimeout(() => document.getElementById("verifyCode")?.focus(), 300);
  }

  backToInfo() {
    this.step = "info";
    const verifySection = this.form.querySelector(".verify-section");
    if (verifySection) verifySection.style.display = "none";

    const formGroups = this.form.querySelectorAll(
      ".form-group, .checkbox-group",
    );
    formGroups.forEach((g) => (g.style.display = "block"));

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    if (btnText) btnText.textContent = "ایجاد حساب کاربری";
  }

  async handleVerifyCode(e) {
    e.preventDefault();
    const code = document.getElementById("verifyCode").value.trim();

    if (!code || code.length !== 6 || !/^\d{6}$/.test(code)) {
      this.notification?.error("کد ۶ رقمی معتبر وارد کنید");
      return;
    }

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.style.opacity = "0";
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await fetch(
        CONFIG.apiEndpoint + "?action=verifyRegisterCode",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-API-Key": CONFIG.apiKey,
          },
          body: JSON.stringify({
            userId: this.pendingUserId,
            code: code,
          }),
        },
      );

      const res = await resp.json();
      if (!res.success) throw new Error(res.error || "کد نامعتبر");

      // ایجاد session
      const session = {
        type: "user",
        userId: res.data.userId,
        username: res.data.username,
        fullName: res.data.fullName,
        token: res.data.token,
        phone: res.data.phone,
        email: res.data.email,
        loginTime: Date.now(),
        expiresAt: Date.now() + 86400000,
      };
      localStorage.setItem("mrneon_user_session", JSON.stringify(session));

      this.notification?.success(
        `🎉 ثبت‌نام با موفقیت انجام شد! خوش آمدید ${res.data.fullName}`,
      );

      this.clearDraft();
      this.close();
      setTimeout(() => window.userPanel?.openPanel(), 500);
    } catch (err) {
      this.notification?.error(err.message || "خطا در تایید کد");
    } finally {
      btn.disabled = false;
      if (btnText) btnText.style.opacity = "1";
      if (btnLoader) btnLoader.setAttribute("hidden", "");
    }
  }

  async resendCode() {
    try {
      this.notification?.info("در حال ارسال مجدد کد...");
      const resp = await fetch(
        CONFIG.apiEndpoint + "?action=resendVerificationCode",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-API-Key": CONFIG.apiKey,
          },
          body: JSON.stringify({ userId: this.pendingUserId }),
        },
      );

      const res = await resp.json();
      if (res.success) {
        this.notification?.success("✅ کد مجدداً ارسال شد");
      } else {
        this.notification?.error(res.error || "خطا در ارسال مجدد");
      }
    } catch (err) {
      this.notification?.error("خطا در ارتباط با سرور");
    }
  }

  open() {
    this.step = "info";
    this.pendingUserId = null;
    this.pendingIdentifier = null;

    // حذف verify section
    const verifySection = this.form.querySelector(".verify-section");
    if (verifySection) verifySection.remove();

    // نمایش تمام form groups
    const formGroups = this.form.querySelectorAll(
      ".form-group, .checkbox-group",
    );
    formGroups.forEach((g) => (g.style.display = "block"));

    // ریست متن دکمه
    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn?.querySelector(".btn-text");
    if (btnText) btnText.textContent = "ایجاد حساب کاربری";

    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    document.body.style.overflow = "hidden";
    this.form.reset();
    this.restoreDraft();
  }

  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    document.body.style.overflow = "";
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
    this.userId = null;
    this.init();
  }

  init() {
    if (!this.modal || !this.form) return;

    this.form.addEventListener("submit", (e) => this.handleSubmit(e));

    // دکمه بستن
    this.modal
      .querySelector(".forgot-password-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".auth-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".auth-backdrop")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".forgot-password-backdrop")
      ?.addEventListener("click", () => this.close());

    // Escape key
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.modal.classList.contains("active"))
        this.close();
    });

    // دکمه بازگشت به ورود
    const backBtn = document.getElementById("fpBackToLoginBtn");
    if (backBtn) {
      backBtn.addEventListener("click", (e) => {
        e.preventDefault();
        this.close();
        setTimeout(() => window.authSystem?.open(), 300);
      });
    }

    // تغییر روش
    document.getElementById("fpMethod")?.addEventListener("change", (e) => {
      const label = document.querySelector('label[for="fpIdentifier"]');
      if (label) {
        label.textContent =
          e.target.value === "email" ? "ایمیل" : "آیدی تلگرام";
      }
    });
  }

  async handleSubmit(e) {
    e.preventDefault();

    if (this.step === "request") {
      await this.handleRequestCode();
    } else if (this.step === "verify") {
      await this.handleResetPassword();
    }
  }

  async handleRequestCode() {
    const method = document.getElementById("fpMethod").value;
    const identifier = document.getElementById("fpIdentifier").value.trim();

    if (!identifier) {
      this.notification?.error("شناسه را وارد کنید");
      return;
    }

    if (method === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier)) {
      this.notification?.error("ایمیل نامعتبر است");
      return;
    }

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.textContent = "در حال ارسال...";
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=forgotPassword", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({
          identifier: "@" + identifier.replace(/^@+/, ""),
          method,
        }),
      });

      const data = await resp.json();

      if (data.success && data.data?.found) {
        this.identifier = data.data.identifier;
        this.userId = data.data.userId;
        this.method = data.data.method;
        this.step = "verify";

        document.getElementById("fpRequestSection").style.display = "none";
        document.getElementById("fpCodeSection").style.display = "block";

        const infoEl = document.getElementById("fpCodeInfo");
        if (infoEl) {
          infoEl.textContent = `کد بازیابی به ${this.method === "email" ? "ایمیل" : "تلگرام"} شما (${this.identifier}) ارسال شد.`;
        }

        if (btnText) btnText.textContent = "🔐 بازنشانی رمز عبور";
        this.notification?.success("✅ کد بازیابی ارسال شد");
      } else if (data.success && !data.data?.found) {
        this.notification?.info(
          "اگر حسابی با این شناسه وجود داشته باشد، کد ارسال می‌شود",
        );
      } else {
        this.notification?.error(data.error || "خطا در ارسال کد");
      }
    } catch (err) {
      this.notification?.error("خطا در ارتباط با سرور");
    } finally {
      btn.disabled = false;
      if (btnLoader) btnLoader.setAttribute("hidden", "");
      if (this.step === "request" && btnText)
        btnText.textContent = "📤 ارسال کد بازیابی";
    }
  }

  async handleResetPassword() {
    const code = document.getElementById("fpCode").value.trim();
    const newPassword = document.getElementById("fpNewPassword").value;
    const confirm =
      document.getElementById("fpConfirmPassword")?.value || newPassword;

    if (!code || code.length !== 6) {
      this.notification?.error("کد ۶ رقمی معتبر وارد کنید");
      return;
    }

    if (!/^(?=.*[A-Za-z])(?=.*\d).{8,}$/.test(newPassword)) {
      this.notification?.error(
        "رمز عبور باید حداقل ۸ کاراکتر شامل حروف و عدد باشد",
      );
      return;
    }

    if (newPassword !== confirm) {
      this.notification?.error("رمز عبور و تکرار آن یکسان نیست");
      return;
    }

    const btn = this.form.querySelector('button[type="submit"]');
    const btnText = btn.querySelector(".btn-text");
    const btnLoader = btn.querySelector(".btn-loader");

    btn.disabled = true;
    if (btnText) btnText.textContent = "در حال بازنشانی...";
    if (btnLoader) btnLoader.removeAttribute("hidden");

    try {
      const resp = await fetch(
        CONFIG.apiEndpoint + "?action=verifyPasswordResetCode",
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-API-Key": CONFIG.apiKey,
          },
          body: JSON.stringify({
            identifier: this.identifier,
            code,
            newPassword,
            userId: this.userId,
          }),
        },
      );

      const data = await resp.json();

      if (data.success) {
        this.notification?.success(
          "✅ رمز عبور با موفقیت بازنشانی شد. حالا می‌توانید وارد شوید.",
        );
        this.close();
        setTimeout(() => window.authSystem?.open(), 500);
      } else {
        this.notification?.error(data.error || "کد نامعتبر یا منقضی شده");
      }
    } catch (err) {
      this.notification?.error("خطا در ارتباط با سرور");
    } finally {
      btn.disabled = false;
      if (btnLoader) btnLoader.setAttribute("hidden", "");
      if (btnText) btnText.textContent = "🔐 بازنشانی رمز عبور";
    }
  }

  open() {
    this.step = "request";
    this.identifier = "";
    this.method = "";
    this.userId = null;

    this.form.reset();
    document.getElementById("fpRequestSection").style.display = "block";
    document.getElementById("fpCodeSection").style.display = "none";

    const btnText = this.form.querySelector('button[type="submit"] .btn-text');
    if (btnText) btnText.textContent = "📤 ارسال کد بازیابی";

    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    document.body.style.overflow = "hidden";

    setTimeout(() => document.getElementById("fpIdentifier")?.focus(), 300);
  }

  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    document.body.style.overflow = "";
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
    document.body.style.overflow = "hidden";
  }
  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    document.body.style.overflow = "";
  }
}

// ============================================================
// 👤 پنل کاربری
// ============================================================
export class UserPanel {
  constructor() {
    this.modal = DOM.userPanelModal;
    this.projects = [];
    this.notifications = [];
    this.notification = window.notificationSystem;
    this.init();
  }
  init() {
    if (!this.modal) return;
    this.setupTabs();
    this.setupEvents();
  }
  setupTabs() {
    const tabs = this.modal.querySelectorAll(".tab-btn");
    const contents = this.modal.querySelectorAll(".tab-content");
    tabs.forEach((btn) => {
      btn.addEventListener("click", () => {
        tabs.forEach((b) => b.classList.remove("active"));
        contents.forEach((c) => c.classList.remove("active"));
        btn.classList.add("active");
        const target = document.getElementById(btn.dataset.tab + "Tab");
        if (target) target.classList.add("active");
      });
    });
  }
  setupEvents() {
    this.modal
      .querySelector(".panel-close")
      ?.addEventListener("click", () => this.close());
    this.modal
      .querySelector(".user-panel-backdrop")
      ?.addEventListener("click", () => this.close());
    DOM.userLogoutBtn?.addEventListener("click", () => {
      this.close();
      AuthSystem.logout();
    });
    document
      .getElementById("newProjectBtn")
      ?.addEventListener("click", async () => {
        // NeoPreFlightCheck از neo.js import شده و از طریق window.neoPreFlightCheck در دسترس است
        // اما چون neo.js در app.js بعد از auth import می‌شود، از window استفاده می‌کنیم
        const check = await window.NeoPreFlightCheck?.canOpenNEO();
        if (check?.allowed) {
          this.close();
          setTimeout(() => window.neoPro?.open(), 300);
        } else this.notification?.error(check?.message || "خطا");
      });
    document
      .getElementById("changeUsernameBtn")
      ?.addEventListener("click", () => this.changeUsername());
    document
      .getElementById("changePasswordBtn")
      ?.addEventListener("click", () => this.changePassword());
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && this.modal.classList.contains("active"))
        this.close();
    });
  }
  async openPanel() {
    const session = AuthSystem.checkSession();
    if (!session || session.type !== "user") {
      this.notification?.error("لطفاً وارد شوید");
      return;
    }
    this.modal.classList.add("active");
    this.modal.removeAttribute("hidden");
    document.body.style.overflow = "hidden";
    await this.loadUserData();
    await this.loadProjects();
    await this.loadNotifications();
  }
  async loadUserData() {
    const session = AuthSystem.checkSession();
    if (!session) return;
    document.getElementById("userPanelName").textContent =
      session.data.fullName;
    document.getElementById("userPanelUsername").textContent =
      "@" + session.data.username;
    document.getElementById("currentUsername").value = session.data.username;
  }
  async loadProjects() {
    const session = AuthSystem.checkSession();
    if (!session) return;
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=getProjects", {
        headers: {
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
      });
      if (resp.ok) {
        const data = await resp.json();
        this.projects = data.projects || [];
        this.renderProjects();
        this.updateStats();
      }
    } catch (e) {
      this.notification?.error("خطا در بارگذاری پروژه‌ها");
    }
  }
  renderProjects() {
    const container = document.getElementById("userProjectsList");
    if (!container) return;
    if (this.projects.length === 0) {
      container.innerHTML = `<div class="empty-state"><div class="empty-icon">📭</div><h3>هنوز پروژه‌ای ثبت نکرده‌اید</h3><p>اولین پروژه خود را ثبت کنید!</p></div>`;
      return;
    }
    container.innerHTML = this.projects
      .map((p) => this.createProjectCard(p))
      .join("");
    this.setupProjectActions();
  }
  createProjectCard(project) {
    const progress = project.progress || 0;
    const statusLabel = this.getStatusLabel(project.status, progress);
    const statusClass = this.getStatusClass(project.status, progress);

    const industryLabels = {
      fashion: "👗 مد و پوشاک",
      tech: "💻 فناوری",
      food: "🍕 رستوران",
      health: "🏥 پزشکی",
      education: "🎓 آموزش",
      realestate: "🏠 املاک",
      travel: "✈️ گردشگری",
      art: "🎨 هنر",
      sport: "⚽ ورزش",
      other: "📦 سایر",
    };

    const styleLabels = {
      modern: "مدرن",
      classic: "کلاسیک",
      creative: "خلاقانه",
      luxury: "لوکس",
      cyberpunk: "سایبرپانک",
      minimal: "مینیمال",
      dark: "دارک",
    };

    const timelineLabels = {
      urgent: "⚡ فوری",
      normal: "📅 عادی",
      flexible: "📆 منعطف",
      agreement: "🤝 توافقی",
    };

    const timeline = (project.activityLog || [])
      .slice(-5)
      .reverse()
      .map(
        (log) => `
            <div class="timeline-item">
                <div class="timeline-icon" style="background: ${this.getTimelineColor(log.type)};">
                    ${this.getTimelineIcon(log.type)}
                </div>
                <div class="timeline-content">
                    <div class="timeline-date">${log.persianDateTime || this.formatDate(log.timestamp)}</div>
                    <div class="timeline-text">${log.message}</div>
                    ${log.note ? `<div class="timeline-note">📝 ${log.note}</div>` : ""}
                </div>
            </div>
        `,
      )
      .join("");

    const industry =
      industryLabels[project.industry] || project.industry || "نامشخص";
    const style = styleLabels[project.style] || project.style || "-";
    const timelineLabel =
      timelineLabels[project.timeline] || project.timeline || "-";

    return `
        <div class="project-card" data-project-id="${project.id}">
            <div class="project-header">
                <div class="project-info">
                    <h3 style="display: flex; align-items: center; gap: 8px;">
                        <span>${industry}</span>
                        <span class="project-code" style="font-size: 11px; color: var(--neon);">${project.trackingCode}</span>
                    </h3>
                    <div style="font-size: 13px; color: rgba(255,255,255,0.6); margin-top: 4px;">
                        🎨 ${style} | ⏰ ${timelineLabel}
                    </div>
                </div>
                <div class="project-status ${statusClass}">${statusLabel}</div>
            </div>
            
            <div class="project-progress">
                <div class="progress-circle-user" data-progress="${progress}">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9155" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="3"/>
                        <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#06b6d4" stroke-width="3" 
                                stroke-dasharray="${progress}, 100" stroke-linecap="round" transform="rotate(-90 18 18)"/>
                    </svg>
                    <div class="progress-text-user">${progress}%</div>
                </div>
                <div class="progress-details">
                    <div class="progress-stage">${statusLabel}</div>
                    <div class="progress-date">ثبت: ${project.persianDateTime || this.formatDate(project.createdAt)}</div>
                </div>
            </div>
            
            ${
              project.adminNote
                ? `
                <div class="admin-note">
                    <div class="note-label">📝 یادداشت ادمین:</div>
                    <div class="note-text">${project.adminNote}</div>
                </div>
            `
                : ""
            }
            
            ${
              project.userNote
                ? `
                <div class="user-note-section">
                    <div class="note-label">📝 یادداشت شما:</div>
                    <div class="note-text">${project.userNote}</div>
                    <button class="btn btn-sm btn-outline edit-user-note" data-id="${project.id}">✏️ ویرایش</button>
                </div>
            `
                : `
                <button class="btn btn-sm btn-outline edit-user-note" data-id="${project.id}">📝 افزودن یادداشت</button>
            `
            }
            
            ${
              timeline
                ? `
                <details class="project-timeline">
                    <summary>📊 تاریخچه فعالیت‌ها (${(project.activityLog || []).length} رویداد)</summary>
                    <div class="timeline-container">${timeline}</div>
                </details>
            `
                : ""
            }
            
            <div class="project-actions">
                <button class="btn btn-outline btn-sm" data-action="view">👁️ مشاهده</button>
                <button class="btn btn-outline btn-sm" data-action="edit">✏️ ویرایش</button>
                <button class="btn btn-outline btn-sm" data-action="message">💬 پیام</button>
                <button class="btn btn-outline btn-sm btn-danger" data-action="delete">🗑️ حذف</button>
            </div>
        </div>
    `;
  }
  setupProjectActions() {
    document.querySelectorAll(".project-card [data-action]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        const card = btn.closest(".project-card");
        const projectId = card.dataset.projectId;
        const action = btn.dataset.action;
        this.handleProjectAction(projectId, action);
      });
    });
    document.querySelectorAll(".edit-user-note").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        const id = btn.dataset.id;
        this.editUserNote(id);
      });
    });
  }
  async handleProjectAction(projectId, action) {
    const project = this.projects.find((p) => p.id === projectId);
    if (!project) return;
    switch (action) {
      case "edit":
        this.close();
        setTimeout(() => window.neoPro?.loadProjectForEdit(project), 300);
        break;
      case "view":
        this.showProjectDetails(project);
        break;
      case "message":
        this.sendMessageToAdmin(projectId);
        break;
      case "delete":
        if (confirm("آیا از حذف این پروژه مطمئن هستید؟"))
          await this.deleteProject(projectId);
        break;
    }
  }
  async sendMessageToAdmin(projectId) {
    const message = prompt("پیام خود را برای ادمین بنویسید:");
    if (!message || !message.trim()) return;
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=sendMessage", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({ projectId, text: message.trim() }),
      });
      const data = await resp.json();
      if (data.success) {
        this.notification?.success("پیام با موفقیت ارسال شد");
        await this.loadProjects();
      } else {
        this.notification?.error(data.error || "خطا در ارسال پیام");
      }
    } catch (e) {
      this.notification?.error("خطا در ارتباط با سرور");
    }
  }
  async deleteProject(projectId) {
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=deleteProject", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({ projectId }),
      });
      if (resp.ok) {
        this.notification?.success("پروژه حذف شد");
        await this.loadProjects();
      }
    } catch (e) {
      this.notification?.error("خطا در حذف");
    }
  }
  showProjectDetails(project) {
    alert(
      `جزئیات پروژه: ${project.trackingCode}\nنوع: ${project.projectType}\nوضعیت: ${project.progress}%`,
    );
  }
  async editUserNote(projectId) {
    const project = this.projects.find((p) => p.id === projectId);
    if (!project) return;
    const newNote = prompt(
      "یادداشت خود را وارد کنید: ",
      project.userNote || "",
    );
    if (newNote === null) return;
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=updateProject", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({ projectId, userNote: newNote }),
      });
      if (resp.ok) {
        this.notification?.success("یادداشت بروز شد");
        await this.loadProjects();
      }
    } catch (e) {
      this.notification?.error("خطا");
    }
  }
  updateStats() {
    const active = this.projects.filter(
      (p) => p.status !== "completed" && p.status !== "rejected",
    ).length;
    const completed = this.projects.filter(
      (p) => p.status === "completed",
    ).length;
    document.getElementById("activeProjectsCount").textContent = active;
    document.getElementById("completedProjectsCount").textContent = completed;
  }
  async loadNotifications() {
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(
        CONFIG.apiEndpoint + "?action=getNotifications",
        {
          headers: {
            Authorization: "Bearer " + session.data.token,
            "X-API-Key": CONFIG.apiKey,
          },
        },
      );
      if (resp.ok) {
        const data = await resp.json();
        this.notifications = data.notifications || [];
        this.renderNotifications();
      }
    } catch (e) {}
  }
  renderNotifications() {
    const container = document.getElementById("userNotificationsList");
    const badge = document.getElementById("notifBadge");
    const unread = this.notifications.filter((n) => !n.read).length;
    if (badge) {
      badge.textContent = unread;
      badge.hidden = unread === 0;
    }
    if (!container) return;
    if (this.notifications.length === 0) {
      container.innerHTML = `<div class="empty-state"><div class="empty-icon">🔕</div><h3>اعلانی وجود ندارد</h3></div>`;
      return;
    }
    container.innerHTML = this.notifications
      .map(
        (n) =>
          `<div class="notification-item ${n.read ? "" : "unread"}" data-notif-id="${n.id}"><div class="notif-icon">🔔</div><div class="notif-content"><div class="notif-title">${n.title}</div><div class="notif-text">${n.message}</div><div class="notif-date">${this.formatDate(n.timestamp)}</div></div></div>`,
      )
      .join("");
    document.querySelectorAll(".notification-item").forEach((item) => {
      item.addEventListener("click", () => {
        const id = item.dataset.notifId;
        this.markNotifRead(id);
        item.classList.remove("unread");
      });
    });
  }
  async markNotifRead(notifId) {
    const session = AuthSystem.checkSession();
    await fetch(CONFIG.apiEndpoint + "?action=markNotificationRead", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: "Bearer " + session.data.token,
        "X-API-Key": CONFIG.apiKey,
      },
      body: JSON.stringify({ id: notifId }),
    });
  }
  async changeUsername() {
    const newUsername = document.getElementById("newUsername").value.trim();
    if (!/^[a-zA-Z0-9_]{4,20}$/.test(newUsername)) {
      this.notification?.error("نام کاربری نامعتبر");
      return;
    }
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=changeUsername", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({ newUsername }),
      });
      const data = await resp.json();
      if (data.success) {
        this.notification?.success("نام کاربری تغییر کرد");
        session.data.username = newUsername;
        localStorage.setItem("mrneon_user_session", JSON.stringify(session));
        this.loadUserData();
      } else this.notification?.error(data.error);
    } catch (e) {
      this.notification?.error("خطا");
    }
  }
  async changePassword() {
    const current = document.getElementById("currentPassword").value;
    const newPass = document.getElementById("newPassword").value;
    const confirm = document.getElementById("confirmNewPassword").value;
    if (newPass !== confirm) {
      this.notification?.error("رمز جدید و تکرار یکسان نیست");
      return;
    }
    if (!/^(?=.*[A-Za-z])(?=.*\d).{8,}$/.test(newPass)) {
      this.notification?.error("رمز جدید ضعیف است");
      return;
    }
    const session = AuthSystem.checkSession();
    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=changePassword", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + session.data.token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({
          currentPassword: current,
          newPassword: newPass,
        }),
      });
      const data = await resp.json();
      if (data.success) {
        this.notification?.success("رمز عبور تغییر کرد");
        document.getElementById("userSettingsForm").reset();
      } else this.notification?.error(data.error);
    } catch (e) {
      this.notification?.error("خطا");
    }
  }
  close() {
    this.modal.classList.remove("active");
    this.modal.setAttribute("hidden", "");
    document.body.style.overflow = "";
  }
  getStatusLabel(status, progress) {
    if (status === "rejected") return "❌ رد شده";
    if (status === "completed" || progress >= 100) return "✅ تکمیل شده";
    if (progress === 0) return "⏳ در انتظار بررسی";
    if (progress <= 25) return "✅ تایید شد";
    if (progress <= 50) return "🎨 در حال طراحی";
    if (progress <= 75) return "⚙️ در حال ساخت";
    if (progress <= 90) return "🔧 نهایی‌سازی";
    return "✨ در حال تکمیل";
  }
  getStatusClass(status, progress) {
    if (status === "rejected") return "status-rejected";
    if (status === "completed" || progress >= 100) return "status-completed";
    if (progress === 0) return "status-pending";
    return "status-active";
  }
  getProjectTypeLabel(type) {
    const m = {
      ecommerce: "فروشگاهی",
      corporate: "شرکتی",
      webapp: "وب‌اپ",
      portfolio: "نمونه‌کار",
      personal: "شخصی",
    };
    return m[type] || type || "نامشخص";
  }
  formatDate(ts) {
    return new Date(ts).toLocaleDateString("fa-IR");
  }
  getTimelineIcon(t) {
    return (
      {
        created: "📝",
        approved: "✓",
        in_progress: "⚙",
        completed: "✓",
        edited: "✏",
        admin_note: "📝",
      }[t] || "•"
    );
  }
  getTimelineColor(t) {
    return (
      {
        created: "#64748b",
        approved: "#3b82f6",
        in_progress: "#06b6d4",
        completed: "#10b981",
        edited: "#f59e0b",
        admin_note: "#8b5cf6",
      }[t] || "#64748b"
    );
  }
}
