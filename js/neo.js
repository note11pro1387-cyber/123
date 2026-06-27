/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/neo.js
 * 🎯 Responsibility: NEO Assistant (9 steps), PreFlightCheck, ThankYou Modal, Receipt Modal
 * 🔌 Dependencies: app.js (CONFIG, DOM, generateTrackingCode, getStorage, setStorage), auth.js (AuthSystem)
 * 🔌 Dependants: app.js (imports and instantiates)
 * 🚫 Constraints: Do NOT change the 9-step flow or draft logic. Keep all NEO_STEPS data intact.
 * 📦 Exported: NEO_STEPS, NeoPreFlightCheck, NeoAssistantPro, ThankYouModal, ReceiptModal
 * 🧠 AI NOTE: NeoAssistantPro._submit() must show receipt BEFORE resetting state (fixes Issue #5)
 */

import {
  CONFIG,
  DOM,
  generateTrackingCode,
  getStorage,
  setStorage,
  sanitizeInput,
} from "./app.js";

import { AuthSystem } from "./auth.js";

// ============================================================
// 🤖 مراحل NEO
// ============================================================
export const NEO_STEPS = [
  {
    id: 0,
    title: "👋 خوش آمدید",
    subtitle:
      "من «نیو» هستم، دستیار هوشمند MR NEON. تو ۵ دقیقه پروژه‌ات رو تعریف کن!",
    type: "welcome",
  },
  {
    id: 1,
    title: "🎯 صنعت و هدف سایت شما",
    subtitle: "ابتدا صنعت، سپس هدف را انتخاب کنید",
    type: "industry-purpose",
    industries: [
      { value: "fashion", emoji: "👗", label: "مد و پوشاک" },
      { value: "tech", emoji: "💻", label: "فناوری" },
      { value: "food", emoji: "🍕", label: "رستوران و غذا" },
      { value: "health", emoji: "🏥", label: "پزشکی و سلامت" },
      { value: "education", emoji: "🎓", label: "آموزش" },
      { value: "realestate", emoji: "🏠", label: "املاک" },
      { value: "travel", emoji: "✈️", label: "گردشگری" },
      { value: "art", emoji: "🎨", label: "هنر و طراحی" },
      { value: "sport", emoji: "⚽", label: "ورزش" },
      { value: "other", emoji: "📦", label: "سایر" },
    ],
    purposesByIndustry: {
      fashion: [
        { value: "online_shop", label: "فروشگاه آنلاین" },
        { value: "catalog", label: "نمایش کالکشن" },
        { value: "booking", label: "رزرو مشاوره" },
      ],
      tech: [
        { value: "company_site", label: "معرفی شرکت" },
        { value: "saas", label: "فروش SaaS" },
        { value: "portfolio", label: "پورتفولیو" },
        { value: "blog", label: "وبلاگ" },
      ],
      food: [
        { value: "menu_order", label: "منوی آنلاین و سفارش" },
        { value: "reservation", label: "رزرو میز" },
      ],
      health: [
        { value: "clinic_site", label: "سایت کلینیک" },
        { value: "booking", label: "نوبت‌دهی آنلاین" },
      ],
      education: [
        { value: "lms", label: "آموزش آنلاین" },
        { value: "school_site", label: "سایت مدرسه" },
      ],
      realestate: [
        { value: "listing", label: "لیست املاک" },
        { value: "agency_site", label: "سایت مشاور املاک" },
      ],
      travel: [
        { value: "booking", label: "رزرو تور" },
        { value: "guide", label: "راهنمای سفر" },
      ],
      art: [
        { value: "portfolio", label: "نمایش آثار" },
        { value: "shop", label: "فروشگاه آثار" },
      ],
      sport: [
        { value: "club_site", label: "سایت باشگاه" },
        { value: "booking", label: "رزرو کلاس" },
      ],
      other: [
        { value: "landing", label: "صفحه فرود" },
        { value: "custom", label: "سفارشی" },
      ],
    },
  },
  {
    id: 2,
    title: "🎨 پالت رنگی مورد علاقه",
    subtitle: "چند پالت انتخاب کنید یا رنگ دلخواه وارد کنید",
    type: "colors",
    palettes: [
      { id: "neon", name: "نئون", colors: ["#06b6d4", "#0d9488", "#0f172a"] },
      { id: "sunset", name: "غروب", colors: ["#f59e0b", "#ef4444", "#7c2d12"] },
      {
        id: "ocean",
        name: "اقیانوس",
        colors: ["#0ea5e9", "#0284c7", "#0c4a6e"],
      },
      { id: "forest", name: "جنگل", colors: ["#10b981", "#059669", "#064e3b"] },
      {
        id: "royal",
        name: "سلطنتی",
        colors: ["#8b5cf6", "#6d28d9", "#4c1d95"],
      },
      {
        id: "minimal",
        name: "مینیمال",
        colors: ["#ffffff", "#1e293b", "#64748b"],
      },
      {
        id: "candy",
        name: "آبنباتی",
        colors: ["#ec4899", "#f472b6", "#fce7f3"],
      },
      { id: "earth", name: "زمینی", colors: ["#a8a29e", "#78716c", "#44403c"] },
      {
        id: "cyber",
        name: "سایبرپانک",
        colors: ["#f0abfc", "#06b6d4", "#0f172a"],
      },
      {
        id: "vintage",
        name: "رترو",
        colors: ["#fbbf24", "#dc2626", "#1e40af"],
      },
      {
        id: "pastel",
        name: "پاستلی",
        colors: ["#fbcfe8", "#c7d2fe", "#bbf7d0"],
      },
      {
        id: "monochrome",
        name: "مونوکروم",
        colors: ["#000000", "#6b7280", "#f3f4f6"],
      },
      { id: "luxury", name: "لوکس", colors: ["#d4af37", "#0f172a", "#ffffff"] },
      { id: "fresh", name: "تازه", colors: ["#22d3ee", "#84cc16", "#facc15"] },
      { id: "dark", name: "تاریک", colors: ["#0f172a", "#1e293b", "#475569"] },
      { id: "warm", name: "گرم", colors: ["#f97316", "#ef4444", "#fbbf24"] },
      { id: "cool", name: "خنک", colors: ["#3b82f6", "#8b5cf6", "#06b6d4"] },
      {
        id: "nature",
        name: "طبیعت",
        colors: ["#84cc16", "#65a30d", "#3f6212"],
      },
      {
        id: "corporate",
        name: "شرکتی",
        colors: ["#1e40af", "#3b82f6", "#dbeafe"],
      },
      {
        id: "creative",
        name: "خلاقانه",
        colors: ["#ec4899", "#8b5cf6", "#06b6d4"],
      },
    ],
    allowCustomColor: true,
  },
  {
    id: 3,
    title: "✨ سبک طراحی",
    subtitle: "چه استایلی دوست داری؟",
    type: "single",
    options: [
      { value: "modern", emoji: "🟢", label: "مدرن و مینیمال" },
      { value: "classic", emoji: "🟣", label: "کلاسیک و رسمی" },
      { value: "creative", emoji: "🔵", label: "خلاقانه و هنری" },
      { value: "luxury", emoji: "🟡", label: "لوکس و پریمیوم" },
      { value: "industrial", emoji: "⚫", label: "صنعتی" },
      { value: "cyberpunk", emoji: "🌆", label: "سایبرپانک" },
      { value: "neumorphism", emoji: "🔘", label: "نئومورفیسم" },
      { value: "glassmorphism", emoji: "💎", label: "گلس‌مورفیسم" },
      { value: "retro", emoji: "📼", label: "رترو" },
      { value: "brutalist", emoji: "🧱", label: "بروتالیست" },
      { value: "organic", emoji: "🌿", label: "ارگانیک" },
      { value: "3d", emoji: "🎮", label: "سه‌بعدی" },
      { value: "flat", emoji: "⬜", label: "فلت" },
      { value: "material", emoji: "📐", label: "متریال دیزاین" },
      { value: "dark", emoji: "🌙", label: "دارک مدرن" },
      { value: "light", emoji: "☀️", label: "لایت و تمیز" },
      { value: "gradient", emoji: "🌈", label: "گرادیانت" },
      { value: "monochrome", emoji: "⚪", label: "مونوکروم" },
    ],
  },
  {
    id: 4,
    title: "🔧 امکانات ویژه",
    subtitle: "هر ویژگی که نیاز داری انتخاب کن",
    type: "multi",
    options: [
      { value: "payment", emoji: "💳", label: "درگاه پرداخت" },
      { value: "membership", emoji: "👤", label: "سیستم عضویت" },
      { value: "admin_panel", emoji: "🎛️", label: "پنل مدیریت" },
      { value: "live_chat", emoji: "💬", label: "چت آنلاین" },
      { value: "booking", emoji: "📅", label: "رزرو نوبت" },
      { value: "pwa", emoji: "📱", label: "PWA" },
      { value: "maps", emoji: "🗺️", label: "نقشه و موقعیت‌یاب" },
      { value: "gallery", emoji: "🖼️", label: "گالری پیشرفته" },
      { value: "blog", emoji: "📝", label: "وبلاگ" },
      { value: "multilingual", emoji: "🌍", label: "چندزبانه" },
      { value: "social", emoji: "🔗", label: "اتصال شبکه‌های اجتماعی" },
      { value: "seo", emoji: "📈", label: "سئو پیشرفته" },
      { value: "comments", emoji: "💭", label: "نظرات کاربران" },
      { value: "sms_auth", emoji: "📲", label: "احراز هویت پیامکی" },
      { value: "mobile_app", emoji: "📱", label: "اپلیکیشن موبایل اختصاصی" },
      { value: "push", emoji: "🔔", label: "نوتیفیکیشن Push" },
      { value: "video", emoji: "🎥", label: "پخش ویدیو" },
      { value: "cart", emoji: "🛒", label: "سبد خرید پیشرفته" },
      { value: "dashboard", emoji: "📊", label: "داشبورد آماری" },
      { value: "ai", emoji: "🤖", label: "هوش مصنوعی" },
    ],
  },
  {
    id: 5,
    title: "📝 وضعیت محتوا",
    subtitle: "بگو محتوا آماده‌ست یا نیاز به کمک داری",
    type: "content",
    questions: [
      {
        id: "logo",
        label: "آیا لوگو دارید؟",
        options: [
          { value: "ready", label: "✅ بله، آماده است" },
          { value: "design_needed", label: "🎨 نیاز به طراحی" },
          { value: "undecided", label: "🤔 تصمیم نگرفته‌ام" },
        ],
      },
      {
        id: "text",
        label: "وضعیت متن‌ها",
        options: [
          { value: "ready", label: "✅ همه آماده" },
          { value: "partial", label: "📝 ناقص" },
          { value: "none", label: "❌ ندارم" },
          { value: "write_for_me", label: "✍️ شما بنویسید" },
        ],
      },
      {
        id: "images",
        label: "وضعیت تصاویر",
        options: [
          { value: "ready", label: "✅ آماده" },
          { value: "need_prep", label: "🖼️ باید تهیه شود" },
          { value: "design", label: "🎨 شما طراحی کنید" },
        ],
      },
    ],
  },
  {
    id: 6,
    title: "⏰ بازه زمانی",
    subtitle: "چقدر زود نیاز داری؟",
    type: "single",
    options: [
      { value: "urgent", emoji: "⚡", label: "فوری (۷۲ ساعت)" },
      { value: "normal", emoji: "📅", label: "عادی (۱-۲ هفته)" },
      { value: "flexible", emoji: "📆", label: "منعطف (۱ ماه)" },
      { value: "agreement", emoji: "🤝", label: "توافقی" },
    ],
  },
  {
    id: 7,
    title: "👤 اطلاعات تماس و یادداشت",
    subtitle: "برای پیگیری، اطلاعاتت رو وارد کن",
    type: "contact-info",
    fields: [
      {
        id: "fullName",
        label: "نام و نام خانوادگی",
        type: "text",
        required: true,
        prefill: true,
      },
      {
        id: "phone",
        label: "شماره تماس",
        type: "tel",
        required: true,
        prefill: true,
      },
      {
        id: "email",
        label: "ایمیل",
        type: "email",
        required: false,
        prefill: true,
      },
      {
        id: "messenger",
        label: "پیام‌رسان ترجیحی",
        type: "select",
        required: false,
        options: [
          "",
          "تلگرام",
          "واتساپ",
          "روبیکا",
          "بله",
          "ایتا",
          "اینستاگرام",
          "ایمیل",
        ],
      },
      {
        id: "messengerId",
        label: "آیدی پیام‌رسان",
        type: "text",
        required: false,
        conditional: "messenger",
      },
      {
        id: "userNote",
        label: "یادداشت برای ادمین (اختیاری)",
        type: "textarea",
        required: false,
      },
    ],
  },
  {
    id: 8,
    title: "🔍 بازبینی نهایی",
    subtitle: "همه اطلاعات را چک کن و در صورت نیاز ویرایش کن",
    type: "review",
  },
];

// ============================================================
// 🛂 Pre-flight Check برای NEO
// ============================================================
export class NeoPreFlightCheck {
  static async canOpenNEO() {
    const session = AuthSystem.checkSession();
    if (!session || session.type !== "user") {
      return {
        allowed: false,
        reason: "auth_required",
        message: "برای ثبت پروژه باید وارد حساب کاربری شوید",
      };
    }
    try {
      const resp = await fetch(
        CONFIG.apiEndpoint + "?action=getActiveProjectsCount",
        {
          headers: {
            Authorization: "Bearer " + session.data.token,
            "X-API-Key": CONFIG.apiKey,
          },
        },
      );
      if (resp.ok) {
        const data = await resp.json();
        if (data.count >= 3) {
          return {
            allowed: false,
            reason: "max_projects",
            message:
              "شما ۳ پروژه فعال دارید. لطفاً تا پایان پروژه‌های قبلی صبر کنید.",
          };
        }
      }
    } catch (e) {}
    return { allowed: true, userId: session.data.userId };
  }
  static loadDraft(userId) {
    try {
      return JSON.parse(sessionStorage.getItem(`neo_draft_${userId}`));
    } catch (e) {
      return null;
    }
  }
  static saveDraft(userId, data) {
    sessionStorage.setItem(
      `neo_draft_${userId}`,
      JSON.stringify({ ...data, timestamp: Date.now() }),
    );
  }
  static clearDraft(userId) {
    sessionStorage.removeItem(`neo_draft_${userId}`);
  }
}

// ============================================================
// 🤖 دستیار هوشمند NEO
// ============================================================
export class NeoAssistantPro {
  constructor() {
    this.step = 0;
    this.totalSteps = NEO_STEPS.length - 1;
    this.data = this._getEmptyData();
    this.elements = {
      modal: DOM.neoChatModal,
      backdrop: DOM.neoChatBackdrop,
      body: DOM.neoChatBody,
      progressRing: DOM.neoProgressRing,
      progressText: DOM.neoProgressText,
      stepInfo: DOM.neoStepInfo,
      prevBtn: DOM.neoPrevBtn,
      nextBtn: DOM.neoNextBtn,
      closeBtn: DOM.neoChatCloseBtn,
      floatBtn: DOM.neoChatBubble,
    };
    this.notification = window.notificationSystem;
    this.previousActiveElement = null;
    this.init();
  }
  _getEmptyData() {
    return {
      trackingCode: generateTrackingCode(),
      industry: "",
      purpose: "",
      colors: { palettes: [], customColors: [] },
      style: "",
      features: [],
      content: { logo: "", text: "", images: "" },
      timeline: "",
      fullName: "",
      phone: "",
      email: "",
      messenger: "",
      messengerId: "",
      userNote: "",
      status: "new",
      createdAt: null,
      userId: null,
    };
  }
  init() {
    if (!this.elements.floatBtn || !this.elements.modal) return;
    this.elements.floatBtn.addEventListener("click", () => this.open());
    this.elements.closeBtn.addEventListener("click", () => this.close());
    this.elements.backdrop.addEventListener("click", () => this.close());
    this.elements.prevBtn.addEventListener("click", () => this.prev());
    this.elements.nextBtn.addEventListener("click", () => this.next());
    document.addEventListener("keydown", (e) => {
      if (
        e.key === "Escape" &&
        this.elements.modal?.classList.contains("active")
      )
        this.close();
    });
  }
  async open() {
    const check = await NeoPreFlightCheck.canOpenNEO();
    if (!check.allowed) {
      this.notification?.error(check.message);
      if (check.reason === "auth_required")
        setTimeout(() => window.authSystem?.open(), 500);
      return;
    }
    this.userId = check.userId;
    this.previousActiveElement = document.activeElement;
    this.elements.modal?.classList.add("active");
    document.body.style.overflow = "hidden";
    const draft = NeoPreFlightCheck.loadDraft(this.userId);
    if (draft && !this._draftResumed) {
      this.step = draft.step;
      this.data = { ...this._getEmptyData(), ...draft.data };
      this.renderStep(this.step);
      this._draftResumed = true;
    } else {
      this.renderStep(this.step);
    }
    this._trapFocus();
  }
  close(reset = false) {
    this.elements.modal?.classList.remove("active");
    document.body.style.overflow = "";
    if (reset) {
      this.step = 0;
      this.data = this._getEmptyData();
      NeoPreFlightCheck.clearDraft(this.userId);
    }
    if (
      this.previousActiveElement &&
      typeof this.previousActiveElement.focus === "function"
    ) {
      setTimeout(() => this.previousActiveElement.focus(), 50);
    }
  }
  _trapFocus() {
    /* ... unchanged ... */
  }
  _removeTrap() {
    /* ... unchanged ... */
  }
  renderStep(sn) {
    const step = NEO_STEPS[sn];
    if (!step) return;
    if (step.id === 7 && this.userId) {
      const session = AuthSystem.checkSession();
      if (session && session.type === "user") {
        if (!this.data.fullName)
          this.data.fullName = session.data.fullName || "";
        if (!this.data.phone) this.data.phone = session.data.phone || "";
        if (!this.data.email) this.data.email = session.data.email || "";
        if (session.data.messenger && !this.data.messenger)
          this.data.messenger = session.data.messenger;
        if (session.data.messengerId && !this.data.messengerId)
          this.data.messengerId = session.data.messengerId;
      }
    }
    const percent = Math.round((sn / this.totalSteps) * 100);
    const circ = 2 * Math.PI * 15.9155;
    if (this.elements.progressRing) {
      this.elements.progressRing.style.strokeDasharray = `${circ}`;
      this.elements.progressRing.style.strokeDashoffset =
        circ * (1 - percent / 100);
    }
    if (this.elements.progressText)
      this.elements.progressText.textContent = `${percent}%`;
    if (this.elements.stepInfo)
      this.elements.stepInfo.innerHTML = `مرحله <span>${sn}</span> از ${this.totalSteps}`;
    if (this.elements.prevBtn) this.elements.prevBtn.disabled = sn === 0;
    const nextHtml =
      sn === 0
        ? '<span>شروع کنید</span> <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>'
        : sn === this.totalSteps
          ? "<span>✅ ثبت نهایی</span>"
          : '<span>ادامه</span> <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    if (this.elements.nextBtn) this.elements.nextBtn.innerHTML = nextHtml;
    this.elements.body.innerHTML = this._getStepHtml(step);
    this._attachEvents(step);
    this._removeTrap();
    this._trapFocus();
  }
  _getStepHtml(step) {
    const esc = (s) =>
      s.replace(
        /[&<>"']/g,
        (m) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
          })[m],
      );
    let h = `<div class="neo-step-content" onselectstart="return false" oncontextmenu="return false" style="user-select:none;-webkit-user-select:none;"><h3 class="neo-step-title">${esc(step.title)}</h3><p class="neo-step-subtitle">${esc(step.subtitle)}</p>`;
    switch (step.type) {
      case "welcome":
        h += `<div style="text-align:center;padding:40px 20px;"><div style="font-size:70px;">🚀</div><button class="chat-btn chat-btn-primary btn-lg neo-start-btn" style="padding:16px 40px;font-size:18px;">شروع پروژه جدید</button>${NeoPreFlightCheck.loadDraft(this.userId) ? '<p style="margin-top:16px;"><button id="neoContinuePrev" class="btn btn-link">ادامه درخواست قبلی</button></p>' : ""}</div>`;
        break;
      case "industry-purpose":
        h += `<div class="neo-options-grid" id="neoIndustries">${step.industries.map((ind) => `<div class="neo-option-card" data-value="${ind.value}" role="button" tabindex="0"><span class="neo-option-emoji">${ind.emoji}</span><span class="neo-option-label">${ind.label}</span></div>`).join("")}</div><div id="neoPurposes" style="display:none;"><div class="neo-options-grid"></div></div>`;
        break;
      case "colors":
        h += `<div class="palette-search"><input type="text" id="paletteSearchInput" placeholder="🔍 جستجوی پالت..."></div><div class="palette-grid" id="paletteGrid">${step.palettes.map((p) => `<div class="palette-card ${this.data.colors.palettes.includes(p.id) ? "selected" : ""}" data-palette-id="${p.id}"><div class="palette-colors">${p.colors.map((c) => `<div class="palette-color" style="background:${c};" title="${c}"></div>`).join("")}</div><div class="palette-name">${p.name}</div></div>`).join("")}</div>`;
        if (step.allowCustomColor)
          h += `<div class="custom-color-section"><label>🎨 رنگ دلخواه (hex یا نام):</label><div class="custom-color-input-group"><input type="text" id="customColorInput" placeholder="#FF5733"><button class="btn btn-sm" id="addCustomColorBtn">➕</button></div><div class="custom-colors-preview" id="customColorsPreview">${(this.data.colors.customColors || []).map((c) => `<span class="custom-color-badge">${c} <button data-remove-color="${c}">×</button></span>`).join("")}</div></div>`;
        break;
      case "single":
        h += `<div class="neo-options-grid">${step.options.map((o) => `<div class="neo-option-card ${this.data.style === o.value ? "selected" : ""}" data-value="${o.value}" role="button" tabindex="0"><span class="neo-option-emoji">${o.emoji}</span><span class="neo-option-label">${o.label}</span></div>`).join("")}</div>`;
        break;
      case "multi":
        h += `<div class="neo-checkbox-grid">${step.options.map((o) => `<label class="neo-checkbox-card ${this.data.features.includes(o.value) ? "checked" : ""}"><input type="checkbox" value="${o.value}" ${this.data.features.includes(o.value) ? "checked" : ""}><span class="neo-checkbox-label">${o.emoji} ${o.label}</span></label>`).join("")}</div>`;
        break;
      case "content":
        step.questions.forEach((q) => {
          h += `<div style="margin-bottom:24px;"><div style="color:var(--neon);font-weight:600;margin-bottom:12px;">${q.label}</div><div class="neo-options-grid">${q.options.map((o) => `<div class="neo-option-card ${this.data.content[q.id] === o.value ? "selected" : ""}" data-content-key="${q.id}" data-value="${o.value}" role="button" tabindex="0"><span class="neo-option-label">${o.label}</span></div>`).join("")}</div></div>`;
        });
        break;
      case "contact-info":
        h += step.fields
          .map((f) => {
            const val = this.data[f.id] || "";
            if (f.type === "select")
              return `<div class="neo-input-group" style="margin-bottom:16px;"><label style="color:rgba(255,255,255,0.7);display:block;margin-bottom:6px;">${f.label} ${f.required ? '<span style="color:#ef4444;">*</span>' : ""}</label><select class="neo-input-field" id="neo_${f.id}">${f.options.map((opt) => `<option value="${opt}" ${val === opt ? "selected" : ""}>${opt || "انتخاب کنید"}</option>`).join("")}</select></div>`;
            else if (f.type === "textarea")
              return `<div class="neo-input-group" style="margin-bottom:16px;"><label style="color:rgba(255,255,255,0.7);display:block;margin-bottom:6px;">${f.label}</label><textarea class="neo-input-field neo-textarea" id="neo_${f.id}" placeholder="یادداشت اختیاری...">${esc(val)}</textarea></div>`;
            else
              return `<div class="neo-input-group" style="margin-bottom:16px;"><label style="color:rgba(255,255,255,0.7);display:block;margin-bottom:6px;">${f.label} ${f.required ? '<span style="color:#ef4444;">*</span>' : ""}</label><input type="${f.type}" class="neo-input-field" id="neo_${f.id}" placeholder="${f.required ? "الزامی" : "اختیاری"}" value="${esc(val)}"></div>`;
          })
          .join("");
        break;
      case "review":
        h += this._getReviewHtml();
        break;
    }
    h += "</div>";
    return h;
  }
  _getReviewHtml() {
    const esc = (s) =>
      s
        ? String(s).replace(
            /[&<>"']/g,
            (m) =>
              ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;",
              })[m],
          )
        : "-";
    const industryMap = NEO_STEPS[1].industries.reduce((a, i) => {
      a[i.value] = i.label;
      return a;
    }, {});
    const purposeMap = {};
    Object.values(NEO_STEPS[1].purposesByIndustry)
      .flat()
      .forEach((p) => {
        purposeMap[p.value] = p.label;
      });
    const styleMap = NEO_STEPS[3].options.reduce((a, o) => {
      a[o.value] = o.label;
      return a;
    }, {});
    const featureMap = NEO_STEPS[4].options.reduce((a, o) => {
      a[o.value] = o.label;
      return a;
    }, {});
    const timeMap = {
      urgent: "فوری (۷۲h)",
      normal: "عادی",
      flexible: "منعطف",
      agreement: "توافقی",
    };
    const rows = [
      { label: "👤 نام", value: this.data.fullName || "-", step: 7 },
      { label: "📱 تلفن", value: this.data.phone || "-", step: 7 },
      {
        label: "🎯 صنعت",
        value: industryMap[this.data.industry] || "-",
        step: 1,
      },
      { label: "🎯 هدف", value: purposeMap[this.data.purpose] || "-", step: 1 },
      {
        label: "🎨 رنگ‌ها",
        value:
          this.data.colors.palettes.join(", ") +
          (this.data.colors.customColors.length
            ? " / سفارشی: " + this.data.colors.customColors.join(", ")
            : ""),
        step: 2,
      },
      { label: "✨ سبک", value: styleMap[this.data.style] || "-", step: 3 },
      {
        label: "🔧 امکانات",
        value:
          this.data.features.map((f) => featureMap[f] || f).join("، ") || "-",
        step: 4,
      },
      {
        label: "📝 لوگو",
        value:
          {
            ready: "✅ آماده",
            design_needed: "🎨 نیاز به طراحی",
            undecided: "🤔 نامشخص",
          }[this.data.content.logo] || "-",
        step: 5,
      },
      {
        label: "📝 متن",
        value:
          {
            ready: "✅ آماده",
            partial: "📝 ناقص",
            none: "❌ ندارم",
            write_for_me: "✍️ بنویسید",
          }[this.data.content.text] || "-",
        step: 5,
      },
      {
        label: "📝 تصاویر",
        value:
          {
            ready: "✅ آماده",
            need_prep: "🖼️ باید تهیه شود",
            design: "🎨 طراحی کنید",
          }[this.data.content.images] || "-",
        step: 5,
      },
      { label: "⏰ زمان", value: timeMap[this.data.timeline] || "-", step: 6 },
      {
        label: "📲 پیام‌رسان",
        value: `${this.data.messenger || "-"}: ${this.data.messengerId || ""}`,
        step: 7,
      },
      { label: "📝 یادداشت", value: this.data.userNote || "-", step: 7 },
    ];
    return `<div>${rows.map((r) => `<div class="neo-review-section"><div class="neo-review-label">${r.label}</div><div class="neo-review-value">${esc(r.value)}</div><span class="neo-review-edit" data-step="${r.step}">✏️ ویرایش</span></div>`).join("")}</div>`;
  }
  _attachEvents(step) {
    const body = this.elements.body;
    body
      .querySelector(".neo-start-btn")
      ?.addEventListener("click", () => this.next());
    body.querySelector("#neoContinuePrev")?.addEventListener("click", (e) => {
      e.preventDefault();
      const draft = NeoPreFlightCheck.loadDraft(this.userId);
      if (draft) {
        this.data = { ...this._getEmptyData(), ...draft.data };
        this.step = draft.step;
        this.renderStep(this.step);
      }
    });
    body.querySelectorAll(".neo-option-card").forEach((card) => {
      const handler = (e) => {
        if (e.target.tagName === "INPUT") return;
        const v = card.dataset.value;
        const grid = card.closest(".neo-options-grid");
        if (grid)
          grid
            .querySelectorAll(".neo-option-card")
            .forEach((c) => c.classList.remove("selected"));
        card.classList.add("selected");
        if (step.id === 3) this.data.style = v;
        else if (step.id === 6) this.data.timeline = v;
        else if (step.type === "content") {
          const key = card.dataset.contentKey;
          if (key) this.data.content[key] = v;
        } else if (step.type === "industry-purpose") {
          this.data.industry = v;
          const purposes = NEO_STEPS[1].purposesByIndustry[v] || [];
          const purposeDiv = document.getElementById("neoPurposes");
          if (purposeDiv) {
            purposeDiv.style.display = "block";
            const grid = purposeDiv.querySelector(".neo-options-grid");
            grid.innerHTML = purposes
              .map(
                (p) =>
                  `<div class="neo-option-card" data-purpose="${p.value}" role="button" tabindex="0"><span class="neo-option-label">${p.label}</span></div>`,
              )
              .join("");
            grid.querySelectorAll(".neo-option-card").forEach((c) =>
              c.addEventListener("click", (ev) => {
                ev.stopPropagation();
                grid
                  .querySelectorAll(".neo-option-card")
                  .forEach((x) => x.classList.remove("selected"));
                c.classList.add("selected");
                this.data.purpose = c.dataset.purpose;
              }),
            );
          }
        }
      };
      card.addEventListener("click", handler);
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          handler(e);
        }
      });
    });
    body.querySelectorAll(".palette-card").forEach((card) => {
      card.addEventListener("click", () => {
        const id = card.dataset.paletteId;
        if (this.data.colors.palettes.includes(id)) {
          this.data.colors.palettes = this.data.colors.palettes.filter(
            (x) => x !== id,
          );
          card.classList.remove("selected");
        } else {
          this.data.colors.palettes.push(id);
          card.classList.add("selected");
        }
      });
    });
    const searchInput = document.getElementById("paletteSearchInput");
    searchInput?.addEventListener("input", (e) => {
      const q = e.target.value.toLowerCase();
      document
        .querySelectorAll(".palette-card")
        .forEach(
          (c) =>
            (c.style.display = c.dataset.paletteId.includes(q) ? "" : "none"),
        );
    });
    document
      .getElementById("addCustomColorBtn")
      ?.addEventListener("click", () => {
        const input = document.getElementById("customColorInput");
        if (input.value.trim()) {
          this.data.colors.customColors = this.data.colors.customColors || [];
          this.data.colors.customColors.push(input.value.trim());
          input.value = "";
          this.renderStep(2);
        }
      });
    body.addEventListener("click", (e) => {
      if (e.target.dataset.removeColor) {
        this.data.colors.customColors = this.data.colors.customColors.filter(
          (c) => c !== e.target.dataset.removeColor,
        );
        this.renderStep(2);
      }
    });
    body.querySelectorAll(".neo-checkbox-card input").forEach((cb) => {
      cb.addEventListener("change", () => {
        const val = cb.value;
        if (cb.checked) {
          if (!this.data.features.includes(val)) this.data.features.push(val);
        } else {
          this.data.features = this.data.features.filter((f) => f !== val);
        }
        cb.closest(".neo-checkbox-card").classList.toggle(
          "checked",
          cb.checked,
        );
      });
    });
    step.fields?.forEach((f) => {
      const el = document.getElementById(`neo_${f.id}`);
      if (el) {
        el.addEventListener("input", (e) => {
          this.data[f.id] = e.target.value;
        });
        el.addEventListener("change", (e) => {
          this.data[f.id] = e.target.value;
        });
      }
    });
    body.querySelectorAll(".neo-review-edit").forEach((edit) => {
      edit.addEventListener("click", () => {
        const s = parseInt(edit.dataset.step);
        if (!isNaN(s) && s >= 0 && s <= 8) {
          this.step = s;
          this.renderStep(s);
        }
      });
    });
    if (step.id !== 0)
      NeoPreFlightCheck.saveDraft(this.userId, {
        data: this.data,
        step: this.step,
      });
  }
  next() {
    if (this.step === 0) {
      this.step = 1;
      this.renderStep(1);
      return;
    }
    if (this.step < this.totalSteps) {
      if (!this._validateStep(this.step)) return;
      this.step++;
      this.renderStep(this.step);
      const body = this.elements.body;
      if (body) {
        body.scrollTo({ top: 0, behavior: "smooth" });
      }
    } else {
      this._submit();
    }
  }
  prev() {
    if (this.step > 0) {
      this.step--;
      this.renderStep(this.step);
    }
  }
  _validateStep(sn) {
    const step = NEO_STEPS[sn];
    if (
      step.type === "industry-purpose" &&
      (!this.data.industry || !this.data.purpose)
    ) {
      this._shakeNext();
      this.notification?.warning("لطفاً صنعت و هدف را انتخاب کنید");
      return false;
    }
    if (
      step.type === "colors" &&
      this.data.colors.palettes.length === 0 &&
      (this.data.colors.customColors || []).length === 0
    ) {
      this._shakeNext();
      this.notification?.warning("حداقل یک پالت رنگی انتخاب کنید");
      return false;
    }
    if (step.id === 3 && !this.data.style) {
      this._shakeNext();
      this.notification?.warning("سبک طراحی را انتخاب کنید");
      return false;
    }
    if (step.id === 5) {
      if (
        !this.data.content.logo ||
        !this.data.content.text ||
        !this.data.content.images
      ) {
        this._shakeNext();
        this.notification?.warning("لطفاً وضعیت محتوا را کامل کنید");
        return false;
      }
    }
    if (step.id === 6 && !this.data.timeline) {
      this._shakeNext();
      this.notification?.warning("بازه زمانی را انتخاب کنید");
      return false;
    }
    if (step.id === 7 && (!this.data.fullName || !this.data.phone)) {
      this._shakeNext();
      this.notification?.warning("نام و شماره تماس الزامی است");
      return false;
    }
    return true;
  }
  _shakeNext() {
    this.elements.nextBtn.style.animation = "shake 0.5s";
    setTimeout(() => (this.elements.nextBtn.style.animation = ""), 500);
  }
  async _submit() {
    this.data.createdAt = new Date().toISOString();
    this.data.userId = this.userId;

    const nextBtn = this.elements.nextBtn;
    const originalBtnHtml = nextBtn.innerHTML;
    nextBtn.disabled = true;
    nextBtn.innerHTML =
      '<span class="loader-spinner"></span> در حال ثبت اطلاعات...';

    try {
      const session = AuthSystem.checkSession();
      const resp = await fetch(CONFIG.apiEndpoint + "?action=saveNeo", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + (session?.data?.token || ""),
        },
        body: JSON.stringify(this.data),
      });

      const result = await resp.json();
      if (!resp.ok) throw new Error(result.error || "خطا در ثبت");

      // ذخیره داده‌ها قبل از ریست state
      window.lastSubmittedProject = { ...this.data, ...result.data };
    } catch (e) {
      window.notificationSystem?.error(e.message || "خطا در ارتباط با سرور");
      // Fallback: ذخیره در localStorage
      const requests = getStorage("neo_requests") || [];
      requests.push({ ...this.data, id: Date.now() });
      setStorage("neo_requests", requests);
      window.lastSubmittedProject = this.data;
    } finally {
      nextBtn.disabled = false;
      nextBtn.innerHTML = originalBtnHtml;
    }

    // پاک کردن draft
    NeoPreFlightCheck.clearDraft(this.userId);

    // ⚡ نمایش رسید و مودال تشکر قبل از ریست state
    const isLoggedIn = AuthSystem.checkSession()?.type === "user";
    const trackingCode = window.lastSubmittedProject.trackingCode;

    // بستن مودال NEO (بدون ریست کامل)
    this.elements.modal?.classList.remove("active");
    document.body.style.overflow = "";

    // نمایش مودال تشکر بعد از ۳۰۰ms
    setTimeout(() => {
      window.thankYouModal?.show(trackingCode, isLoggedIn);
    }, 300);

    // ریست state بعد از نمایش
    this.step = 0;
    this.data = this._getEmptyData();
    this._draftResumed = false;
  }
}

// ============================================================
// 🎉 مودال تشکر
// ============================================================
export class ThankYouModal {
  constructor() {
    this.modal = DOM.thankYouModal;
    this.init();
  }
  init() {
    document
      .getElementById("thankYouCopyBtn")
      ?.addEventListener("click", () => {
        const code = document.getElementById(
          "thankYouTrackingCode",
        ).textContent;
        navigator.clipboard
          .writeText(code)
          .then(() => window.notificationSystem?.success("کد کپی شد"));
      });
    document.getElementById("goToPanelBtn")?.addEventListener("click", () => {
      this.close();
      setTimeout(() => window.userPanel?.openPanel(), 300);
    });
    document.getElementById("viewReceiptBtn")?.addEventListener("click", () => {
      this.close();
      if (window.lastSubmittedProject)
        window.receiptModal?.show(window.lastSubmittedProject);
    });
  }
  show(trackingCode, isLoggedIn) {
    document.getElementById("thankYouTrackingCode").textContent = trackingCode;
    document.getElementById("goToPanelBtn").style.display = isLoggedIn
      ? "block"
      : "none";
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
// 🧾 رسید
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
    this._escHandler = null;

    this.featureMap = {
      payment: "درگاه پرداخت",
      membership: "سیستم عضویت",
      admin_panel: "پنل مدیریت",
      live_chat: "چت آنلاین",
      booking: "رزرو نوبت",
      pwa: "PWA",
      maps: "نقشه",
      gallery: "گالری پیشرفته",
      blog: "وبلاگ",
      multilingual: "چندزبانه",
      social: "شبکه‌های اجتماعی",
      seo: "سئو پیشرفته",
      comments: "نظرات",
      sms_auth: "احراز هویت پیامکی",
      mobile_app: "اپلیکیشن موبایل",
      push: "نوتیفیکیشن",
      video: "پخش ویدیو",
      cart: "سبد خرید",
      dashboard: "داشبورد آماری",
      ai: "هوش مصنوعی",
    };
    this.init();
  }

  init() {
    this.closeBtn.addEventListener("click", () => this.close());
    this.overlay.addEventListener("click", () => this.close());

    this.copyBtn.addEventListener("click", () => {
      const code = this.body.querySelector(".receipt-code-val")?.textContent;
      if (code) {
        navigator.clipboard
          .writeText(code)
          .then(() => window.notificationSystem?.success("کد پیگیری کپی شد"))
          .catch(() => window.notificationSystem?.error("خطا در کپی"));
      }
    });

    this.shareBtn.addEventListener("click", async () => {
      const code =
        this.body.querySelector(".receipt-code-val")?.textContent || "";
      const text = `پروژه من در MR NEON ثبت شد! 🚀\nکد پیگیری: ${code}\nطراحی سایت حرفه‌ای در ۷۲ ساعت\n${window.location.origin}`;
      try {
        if (navigator.share) {
          await navigator.share({
            title: "MR NEON",
            text,
            url: window.location.origin,
          });
        } else {
          await navigator.clipboard.writeText(text);
          window.notificationSystem?.success("متن اشتراک‌گذاری کپی شد");
        }
      } catch (e) {
        if (e.name !== "AbortError") {
          await navigator.clipboard.writeText(text);
          window.notificationSystem?.info("متن در کلیپ‌بورد کپی شد");
        }
      }
    });

    this.printBtn.addEventListener("click", () => window.print());
  }

  open() {
    this.modal.classList.add("active");
    document.body.style.overflow = "hidden";
    this._escHandler = (e) => {
      if (e.key === "Escape") this.close();
    };
    document.addEventListener("keydown", this._escHandler);
  }

  close() {
    this.modal.classList.remove("active");
    document.body.style.overflow = "";
    if (this._escHandler) {
      document.removeEventListener("keydown", this._escHandler);
      this._escHandler = null;
    }
  }

  show(data) {
    if (!data) return;
    const safe = (val, fallback = "ثبت نشده") =>
      val && String(val).trim() !== "" ? String(val).trim() : fallback;

    let featuresText = "ثبت نشده";
    const featureMap = {
      payment: "درگاه پرداخت",
      membership: "سیستم عضویت",
      admin_panel: "پنل مدیریت",
      live_chat: "چت آنلاین",
      booking: "رزرو نوبت",
      pwa: "PWA",
      maps: "نقشه",
      gallery: "گالری",
      blog: "وبلاگ",
      multilingual: "چندزبانه",
      seo: "سئو",
      ai: "AI",
      cart: "سبد خرید",
      dashboard: "داشبورد",
      video: "ویدیو",
    };

    if (Array.isArray(data.features) && data.features.length > 0) {
      featuresText = data.features.map((f) => featureMap[f] || f).join("، ");
    }

    // استفاده از persianDateTime که از API می‌آید
    const displayDate =
      data.persianDateTime ||
      data.persianDate ||
      (data.createdAt
        ? new Date(data.createdAt).toLocaleDateString("fa-IR")
        : new Date().toLocaleDateString("fa-IR"));

    const rows = [
      { l: "👤 نام و نام خانوادگی", v: safe(data.fullName) },
      { l: "📱 شماره تماس", v: safe(data.phone) },
      {
        l: "📧 ایمیل / پیام‌رسان",
        v:
          safe(data.email) +
          (data.messenger
            ? ` (${data.messenger}: ${safe(data.messengerId)})`
            : ""),
      },
      { l: "🎯 نوع پروژه", v: safe(data.projectTypeLabel || data.projectType) },
      { l: "🎨 سبک طراحی", v: safe(data.style) },
      { l: "🔧 امکانات درخواستی", v: featuresText },
      { l: "⏰ بازه زمانی", v: safe(data.timeline) },
      { l: "📝 یادداشت شما", v: safe(data.userNote, "ندارد") },
    ];

    this.body.innerHTML = `
        <div class="receipt-print-header">
            <div class="receipt-logo-text">MR•NEON</div>
            <div class="receipt-title">رسید ثبت درخواست پروژه</div>
            <div class="receipt-code-box">
                <span class="receipt-code-label">کد پیگیری:</span>
                <span class="receipt-code-val">${safe(data.trackingCode, "نامشخص")}</span>
            </div>
            <div class="receipt-date">📅 تاریخ ثبت: ${displayDate}</div>
        </div>
        <div class="receipt-details-grid">
            ${rows
              .map(
                (r) => `
                        <div class="receipt-row">
                            <span class="receipt-label">${r.l}</span>
                            <span class="receipt-value">${r.v}</span>
                        </div>
                    `,
              )
              .join("")}
        </div>
        <div class="receipt-footer-note">
            <p>⚠️ این رسید به منزله تایید نهایی قرارداد نیست. کارشناسان ما به زودی با شما تماس خواهند گرفت.</p>
            <p>پشتیبانی: ${CONFIG.telegram} | ${CONFIG.email}</p>
        </div>
    `;
    this.open();
  }
}
