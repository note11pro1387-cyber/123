/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/app.js
 * 🎯 Responsibility: Main Orchestrator + Public UI Systems (Particles, Scroll, Portfolio, etc.)
 * 🔌 Dependencies: auth.js, neo.js; admin.js is imported on demand only when admin access is needed.
 * 🔌 Dependants: Browser (entry point via type="module")
 * 🚫 Constraints: Do NOT put auth or neo logic here. Only public UI systems.
 * 📦 Exported: CONFIG, AppState, DOM, PROJECTS, NotificationSystem, MRNeonApp, ...
 * 🧠 AI NOTE: This is the entry point. window.* assignments happen here.
 */

export const CONFIG = {
  email: "contact@mrneon.dev",
  telegram: "@MR_NEON_TEAM",
  telegramLink: "https://t.me/MR_NEON_TEAM",
  apiEndpoint: "api.php",
  particleCount: 25,
  scrollThreshold: 50,
  toastDuration: 4000,
  isTouch: "ontouchstart" in window || navigator.maxTouchPoints > 0,
  isReducedMotion: window.matchMedia("(prefers-reduced-motion: reduce)")
    .matches,
  shareText: `MR NEON - طراحی سایت حرفه‌ای در ۷۲ ساعت\n🚀 تحویل سریع، طراحی مدرن\n${window.location.origin}`,
};

export const DOM = (() => {
  const g = (s, all) =>
    all ? document.querySelectorAll(s) : document.querySelector(s);
  return {
    body: document.body,
    navbar: g("#navbar"),
    navMenu: g("#navMenu"),
    hamburger: g("#hamburger"),
    navLinks: g(".nav-link", true),
    sections: {
      hero: g("#hero"),
      stats: g("#stats"),
      services: g("#services"),
      portfolio: g("#portfolio"),
      team: g("#team"),
      tech: g("#tech"),
      contact: g("#contact"),
    },
    statCards: g(".stat-card", true),
    portfolioGrid: g("#portfolioGrid"),
    filterButtons: g(".filter-btn", true),
    viewMoreProjects: g("#viewMoreProjects"),
    projectModal: g("#projectModal"),
    modalClose: g("#modalClose"),
    modalBody: g("#modalBody"),
    copyButtons: g(".copy-btn", true),
    telegramBtn: g("#telegramBtn"),
    loadingScreen: g("#loadingScreen"),
    particlesContainer: g("#particles"),
    toastContainer: g("#toastContainer"),
    floatingContact: g("#floatingContact"),
    floatingContactBtn: g("#floatingContactBtn"),
    contactPanel: g("#contactPanel"),
    loginBtnDesktop: g("#loginBtnDesktop"),
    loginBtnMobile: g("#loginBtnMobile"),
    adminAccessBtn: g("#adminAccessBtn"),
    adminLoginModal: g("#adminLoginModal"),
    adminModalClose: g("#adminModalClose"),
    adminModalOverlay: g("#adminModalOverlay"),
    adminLoginForm: g("#adminLoginForm"),
    adminModalBody: g("#adminModalBody"),
    adminDataModal: g("#adminDataModal"),
    adminDataClose: g("#adminDataClose"),
    adminDataOverlay: g("#adminDataOverlay"),
    adminDataTbody: g("#adminDataTbody"),
    adminSearchInput: g("#adminSearchInput"),
    adminFilterStatus: g("#adminFilterStatus"),
    adminFilterType: g("#adminFilterType"),
    adminExportBtn: g("#adminExportBtn"),
    adminPagination: g("#adminPagination"),
    adminLogoutBtn: g("#adminLogoutBtn"),
    neoChatBubble: g("#neoChatBubble"),
    neoChatModal: g("#neoChatModal"),
    neoChatBackdrop: g("#neoChatBackdrop"),
    neoChatBody: g("#neoChatBody"),
    neoProgressRing: g("#neoProgressRing"),
    neoProgressText: g("#neoProgressText"),
    neoStepInfo: g("#neoStepInfo"),
    neoPrevBtn: g("#neoPrevBtn"),
    neoNextBtn: g("#neoNextBtn"),
    neoChatCloseBtn: g("#neoChatCloseBtn"),
    receiptModal: g("#receiptModal"),
    receiptOverlay: g("#receiptOverlay"),
    receiptBody: g("#receiptBody"),
    receiptClose: g("#receiptClose"),
    receiptDownloadBtn: g("#receiptDownloadBtn"),
    receiptPrintBtn: g("#receiptPrintBtn"),
    receiptCopyBtn: g("#receiptCopyBtn"),
    receiptShareBtn: g("#receiptShareBtn"),
    tourOverlay: g("#tourOverlay"),
    tourHighlight: g("#tourHighlight"),
    tourTooltip: g("#tourTooltip"),
    tourStepCount: g("#tourStepCount"),
    tourTooltipTitle: g("#tourTooltipTitle"),
    tourTooltipText: g("#tourTooltipText"),
    tourPrevBtn: g("#tourPrevBtn"),
    tourNextBtn: g("#tourNextBtn"),
    tourSkipBtn: g("#tourSkipBtn"),
    authModal: g("#authModal"),
    authForm: g("#authForm"),
    registerModal: g("#registerModal"),
    registerForm: g("#registerForm"),
    userPanelModal: g("#userPanelModal"),
    thankYouModal: g("#thankYouModal"),
    adminMetrics: g("#adminMetrics"),
    copyContactBtns: g(".copy-contact-btn", true),
    telegramContactBtn: g(".telegram-contact-btn"),
    openNeoFromContact: g("#openNeoFromContact"),
    shareSiteBtn: g("#shareSiteBtn"),
    floatingCopyBtns: g(".copy-item", true),
    termsModal: g("#termsModal"),
    termsLink: g("#termsLink"),
    forgotPasswordModal: g("#forgotPasswordModal"),
    forgotPasswordForm: g("#forgotPasswordForm"),
    floatingTelegramBtn: g("#floatingTelegramBtn"),
    userLogoutBtn: g("#userLogoutBtn"),
  };
})();

// Load the Admin workspace only when a public-page flow actually needs it.
// The promise is shared so concurrent entry paths create exactly one instance.
let adminPanelInitialization = null;
export function ensureAdminPanel() {
  if (window.adminPanel) return Promise.resolve(window.adminPanel);

  if (!adminPanelInitialization) {
    adminPanelInitialization = import("./admin.js")
      .then(({ AdminPanel }) => {
        if (typeof AdminPanel !== "function") {
          throw new TypeError("AdminPanel module does not expose its constructor.");
        }
        if (!window.adminPanel) window.adminPanel = new AdminPanel();
        return window.adminPanel;
      })
      .catch((error) => {
        adminPanelInitialization = null;
        throw error;
      });
  }

  return adminPanelInitialization;
}

// ============================================================
// 🧩 کلاس مدیریت وضعیت
// ============================================================
class AppState {
  constructor() {
    this.isMobileMenuOpen = false;
    this.isModalOpen = false;
    this.isFormSubmitting = false;
    this.currentFilter = "all";
    this.eventListeners = [];
  }
  addListener(element, event, handler, options) {
    if (!element) return;
    element.addEventListener(event, handler, options);
    this.eventListeners.push({ element, event, handler, options });
  }
  removeAllListeners() {
    this.eventListeners.forEach(({ element, event, handler, options }) => {
      element.removeEventListener(event, handler, options);
    });
    this.eventListeners = [];
  }
  destroy() {
    this.removeAllListeners();
  }
}
const state = new AppState();

const APP_PUBLIC_SCROLL_LOCK_SELECTOR =
  ".modal.active, .nav-menu.active, .nav-menu.is-closing, .tour-overlay.active";

function syncPublicScrollLock() {
  const shouldLock = Boolean(
    document.querySelector(APP_PUBLIC_SCROLL_LOCK_SELECTOR),
  );
  DOM.body?.classList.toggle("app-public-scroll-locked", shouldLock);
}

// Central, CSP-compatible image-failure handling for static and dynamic markup.
// The data attribute chooses one of two fixed behaviors; no arbitrary fallback URL
// can be supplied through markup or user-controlled data.
const IMAGE_FALLBACK_SRC = "assets/images/fallback.svg";
function applyManagedImageFallback(image) {
  const mode = image.dataset.imageFallback;
  if (mode !== "hide" && mode !== "replace") return false;
  if (image.dataset.imageFallbackHandled === "true") return false;

  image.dataset.imageFallbackHandled = "true";
  if (mode === "hide") {
    image.hidden = true;
    image.closest("button.gallery-thumb")?.setAttribute("hidden", "");
    return true;
  }

  image.alt = image.dataset.imageFallbackAlt || "تصویر در دسترس نیست";
  image.src = IMAGE_FALLBACK_SRC;
  return true;
}

function markImageLoadState(image, failed = false) {
  image.classList.remove("is-image-loading");
  image.classList.add("loaded");
  image.classList.toggle("error", failed);
}

function prepareManagedImage(image) {
  if (!(image instanceof HTMLImageElement)) return;

  if (image.complete && (image.currentSrc || image.getAttribute("src"))) {
    if (image.naturalWidth > 0) {
      markImageLoadState(image);
    } else {
      handleManagedImageError({ target: image });
    }
    return;
  }

  image.classList.add("is-image-loading");
}

function prepareManagedImages(root = document) {
  if (root instanceof HTMLImageElement) {
    prepareManagedImage(root);
    return;
  }
  root?.querySelectorAll?.("img").forEach(prepareManagedImage);
}

function handleManagedImageLoad(event) {
  const image = event.target;
  if (image instanceof HTMLImageElement) markImageLoadState(image);
}

function handleManagedImageError(event) {
  const image = event.target;
  if (!(image instanceof HTMLImageElement)) return;

  // A replacement fallback is another real request on the same element.
  // Keep its loading state until that request succeeds or fails.
  const fallbackApplied = applyManagedImageFallback(image);
  if (fallbackApplied && image.dataset.imageFallback === "replace") {
    image.classList.add("is-image-loading");
    image.classList.remove("loaded", "error");
    return;
  }

  markImageLoadState(image, true);
}

// Image load/error events do not bubble. Capture keeps one lifecycle handler
// for both initial and dynamically inserted images without duplicate requests.
state.addListener(document, "load", handleManagedImageLoad, true);
state.addListener(document, "error", handleManagedImageError, true);

function recoverAlreadyFailedManagedImages() {
  document.querySelectorAll("img[data-image-fallback]").forEach((image) => {
    if (image.complete && image.naturalWidth === 0) applyManagedImageFallback(image);
  });
}

// ============================================================
// 📦 پروژه‌های نمونه
// ============================================================
export const PROJECTS = {
  1: {
    id: 1,
    imageWidth: 400,
    imageHeight: 280,
    title: "فروشگاه مد و زیبایی",
    category: "ecommerce",
    categoryLabel: "🛒 فروشگاهی",
    description: "طراحی کامل فروشگاه آنلاین",
    fullDescription: "فروشگاه آنلاین کامل برای برند مد و زیبایی",
    technologies: ["React", "Node.js", "MongoDB", "Redux", "Stripe API"],
    duration: "۴۵ روز",
    services: [
      "طراحی UI/UX",
      "توسعه فروشگاه",
      "اتصال درگاه پرداخت",
      "بهینه‌سازی سئو",
    ],
    demoUrl: "https://demo.mrneon.dev/fashion-store",
    images: ["assets/images/project-1.jpg"],
  },
  2: {
    id: 2,
    imageWidth: 800,
    imageHeight: 600,
    title: "پلتفرم آموزش آنلاین",
    category: "webapp",
    categoryLabel: "📱 وب‌اپ",
    description: "پلتفرم تعاملی آموزش",
    fullDescription: "پلتفرم آموزش آنلاین جامع",
    technologies: ["Vue.js", "Laravel", "MySQL", "WebRTC", "Socket.io"],
    duration: "۶۰ روز",
    services: [
      "تحلیل نیازها",
      "طراحی معماری",
      "توسعه فرانت‌اند و بک‌اند",
      "تست و راه‌اندازی",
    ],
    demoUrl: "https://demo.mrneon.dev/learning-platform",
    images: ["assets/images/project-2.jpg"],
  },
  3: {
    id: 3,
    imageWidth: 800,
    imageHeight: 600,
    title: "سایت شرکتی فناوری",
    category: "corporate",
    categoryLabel: "🏢 شرکتی",
    description: "طراحی مدرن شرکتی",
    fullDescription: "وب‌سایت شرکتی با طراحی مدرن",
    technologies: [
      "Next.js",
      "TypeScript",
      "Tailwind CSS",
      "GraphQL",
      "Contentful",
    ],
    duration: "۳۰ روز",
    services: [
      "طراحی UI/UX",
      "توسعه با Next.js",
      "بهینه‌سازی سئو",
      "پیاده‌سازی CMS",
    ],
    demoUrl: "https://demo.mrneon.dev/tech-company",
    images: ["assets/images/project-3.jpg"],
  },
  4: {
    id: 4,
    imageWidth: 400,
    imageHeight: 280,
    title: "وب‌اپ مدیریت پروژه",
    category: "webapp",
    categoryLabel: "🔧 وب‌اپ",
    description: "اپلیکیشن مدیریت پروژه",
    fullDescription: "سیستم مدیریت پروژه کامل",
    technologies: ["React", "Express.js", "PostgreSQL", "Socket.io", "JWT"],
    duration: "۷۵ روز",
    services: [
      "تحلیل فرآیندها",
      "طراحی دیتابیس",
      "توسعه API",
      "پیاده‌سازی Real-time",
    ],
    demoUrl: "https://demo.mrneon.dev/project-management",
    images: ["assets/images/project-4.jpg"],
  },
  5: {
    id: 5,
    imageWidth: 400,
    imageHeight: 280,
    title: "سایت رستوران لوکس",
    category: "ecommerce",
    categoryLabel: "🛒 فروشگاهی",
    description: "سایت رزرو آنلاین",
    fullDescription: "وب‌سایت رستوران لوکس با سیستم رزرو",
    technologies: ["WordPress", "WooCommerce", "PHP", "MySQL", "Stripe"],
    duration: "۲۵ روز",
    services: [
      "طراحی قالب اختصاصی",
      "پیاده‌سازی WooCommerce",
      "اتصال درگاه پرداخت",
      "بهینه‌سازی موبایل",
    ],
    demoUrl: "https://demo.mrneon.dev/restaurant",
    images: ["assets/images/project-5.jpg"],
  },
};

const PORTFOLIO_IMAGE_FALLBACK = "assets/images/fallback.svg";
const PORTFOLIO_IMAGE_PATH_PATTERN = /^assets\/images\/[a-z0-9][a-z0-9._-]{0,95}\.(?:avif|gif|jpe?g|png|svg|webp)$/i;

function trustedPortfolioImagePath(value) {
  if (typeof value !== "string" || value.length > 128 || /[\u0000-\u0020\\?#]/.test(value)) return null;
  if (!PORTFOLIO_IMAGE_PATH_PATTERN.test(value)) return null;
  const segments = value.split("/");
  if (segments.some((segment) => segment === "." || segment === "..")) return null;
  return value;
}

function trustedPortfolioDemoUrl(value) {
  if (typeof value !== "string" || value.length === 0 || value.length > 2048 || /[\u0000-\u0020\\]/.test(value)) return null;
  try {
    const url = new URL(value);
    if (url.protocol !== "https:" || url.hostname !== "demo.mrneon.dev"
      || url.port !== "" || url.username !== "" || url.password !== ""
      || url.origin !== "https://demo.mrneon.dev" || url.search !== "" || url.hash !== "") return null;
    return url.href;
  } catch {
    return null;
  }
}

// ============================================================
// 🔢 توابع کمکی
// ============================================================
export function generateTrackingCode() {
  const now = Date.now().toString(36).toUpperCase();
  const rand = Math.random().toString(36).substring(2, 8).toUpperCase();
  const extra = crypto?.randomUUID
    ? crypto.randomUUID().substring(0, 6).toUpperCase()
    : Math.random().toString(36).substring(2, 8).toUpperCase();
  return `MR-${now.substring(now.length - 4)}-${rand}-${extra}`;
}
export function getStorage(key) {
  try {
    return JSON.parse(localStorage.getItem(key));
  } catch (e) {
    return null;
  }
}
export function setStorage(key, val) {
  try {
    localStorage.setItem(key, JSON.stringify(val));
  } catch (e) {}
}
export function sanitizeInput(v) {
  if (typeof v !== "string") return "";
  const d = document.createElement("div");
  d.textContent = v;
  return d.innerHTML;
}

// ============================================================
// 🔔 نوتیفیکیشن
// ============================================================
export class NotificationSystem {
  constructor() {
    this.container = this.getContainer();
    this.toasts = [];
    this.maxToasts = 3;
  }
  getContainer() {
    let c = document.getElementById("toastContainer");
    if (!c) {
      c = document.createElement("div");
      c.id = "toastContainer";
      c.className = "toast-container";
      c.setAttribute("role", "region");
      c.setAttribute("aria-live", "polite");
      document.body.appendChild(c);
    }
    return c;
  }
  show(msg, type = "success", duration = 4000) {
    if (this.toasts.length >= this.maxToasts) this.remove(this.toasts[0]);
    const t = document.createElement("div");
    const toastTypes = {
      success: { icon: "✓", title: "موفق" },
      error: { icon: "✕", title: "خطا" },
      warning: { icon: "⚠", title: "توجه" },
      info: { icon: "ℹ", title: "اطلاعات" },
    };
    const safeType = Object.prototype.hasOwnProperty.call(toastTypes, type) ? type : "info";
    const toastMeta = toastTypes[safeType];
    t.className = `toast ${safeType}`;

    const iconWrap = document.createElement("div");
    iconWrap.className = "toast-icon-wrap";
    const icon = document.createElement("div");
    icon.className = "toast-icon";
    icon.textContent = toastMeta.icon;
    iconWrap.appendChild(icon);

    const content = document.createElement("div");
    content.className = "toast-content";
    const title = document.createElement("div");
    title.className = "toast-title";
    title.textContent = toastMeta.title;
    const message = document.createElement("div");
    message.className = "toast-message";
    message.textContent = typeof msg === "string" ? msg : "";
    content.append(title, message);

    const close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close";
    close.setAttribute("aria-label", "بستن");
    const closeIcon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    closeIcon.setAttribute("width", "16");
    closeIcon.setAttribute("height", "16");
    closeIcon.setAttribute("viewBox", "0 0 24 24");
    closeIcon.setAttribute("fill", "none");
    closeIcon.setAttribute("stroke", "currentColor");
    closeIcon.setAttribute("stroke-width", "2");
    closeIcon.setAttribute("aria-hidden", "true");
    const closePath = document.createElementNS("http://www.w3.org/2000/svg", "path");
    closePath.setAttribute("d", "M18 6L6 18M6 6l12 12");
    closeIcon.appendChild(closePath);
    close.appendChild(closeIcon);

    const progress = document.createElement("div");
    progress.className = "toast-progress";
    t.append(iconWrap, content, close, progress);
    this.container.appendChild(t);
    this.toasts.push(t);
    requestAnimationFrame(() => {
      t.classList.add("show");
    });
    let autoT;
    close.addEventListener("click", () => this.remove(t));
    if (duration > 0) autoT = setTimeout(() => this.remove(t), duration);
    const pause = () => {
      clearTimeout(autoT);
    };
    const resume = () => {
      autoT = setTimeout(() => this.remove(t), duration / 2);
    };
    t.addEventListener("mouseenter", pause);
    t.addEventListener("mouseleave", resume);
    return t;
  }
  remove(t) {
    if (!t || !t.parentNode) return;
    t.classList.remove("show");
    setTimeout(() => {
      if (t.parentNode) t.parentNode.removeChild(t);
      this.toasts = this.toasts.filter((x) => x !== t);
    }, 500);
  }
  success(m, d) {
    return this.show(m, "success", d);
  }
  error(m, d) {
    return this.show(m, "error", d);
  }
  warning(m, d) {
    return this.show(m, "warning", d);
  }
  info(m, d) {
    return this.show(m, "info", d);
  }
}
// window.notificationSystem will be assigned later in MRNeonApp

// ============================================================
// 🧰 سیستم انیمیشن
// ============================================================
export class AnimationSystem {
  static createCounterAnimation(el, target, duration = 2000) {
    if (!el) return Promise.resolve();
    return new Promise((r) => {
      const start = performance.now();
      const animate = (t) => {
        const p = Math.min((t - start) / duration, 1);
        const ease = 1 - Math.pow(1 - p, 3);
        el.textContent = Math.floor(target * ease).toLocaleString("en-US");
        if (p < 1) requestAnimationFrame(animate);
        else {
          el.textContent = target.toLocaleString("en-US");
          r();
        }
      };
      requestAnimationFrame(animate);
    });
  }
}

// ============================================================
// ✨ ذرات پس‌زمینه
// ============================================================
export class ParticleSystem {
  constructor(c) {
    this.container = c;
    this.particles = [];
    this.animationId = null;
    this.isActive = false;
  }
  createParticle() {
    const p = document.createElement("div");
    p.className = "particle";
    const s = Math.random() * 3 + 1,
      x = Math.random() * 100,
      y = Math.random() * 100;
    p.style.width = `${s}px`;
    p.style.height = `${s}px`;
    p.style.left = `${x}%`;
    p.style.top = `${y}%`;
    p.style.opacity = String(Math.random() * 0.4 + 0.1);
    return {
      element: p,
      x,
      y,
      speedX: (Math.random() - 0.5) * 0.15,
      speedY: (Math.random() - 0.5) * 0.15,
      size: s,
      phase: Math.random() * Math.PI * 2,
    };
  }
  init(count = 25) {
    if (CONFIG.isReducedMotion || CONFIG.isTouch || !this.container) return;
    const actual = window.innerWidth < 768 ? 0 : count;
    this.destroy();
    for (let i = 0; i < actual; i++) {
      const p = this.createParticle();
      this.container.appendChild(p.element);
      this.particles.push(p);
    }
    this.isActive = true;
    this.start();
  }
  start() {
    if (!this.isActive || this.animationId) return;
    let last = 0;
    const fps = 30,
      interval = 1000 / fps;
    const animate = (t) => {
      if (!this.isActive) return;
      if (t - last >= interval) {
        last = t - ((t - last) % interval);
        this.particles.forEach((p) => {
          p.x += p.speedX;
          p.y += p.speedY;
          if (p.x > 100) p.x = 0;
          if (p.x < 0) p.x = 100;
          if (p.y > 100) p.y = 0;
          if (p.y < 0) p.y = 100;
          p.element.style.left = `${p.x}%`;
          p.element.style.top = `${p.y}%`;
          p.element.style.opacity = Math.max(
            0.1,
            Math.min(0.5, 0.3 + Math.sin(t * 0.001 + p.phase) * 0.2),
          );
        });
      }
      this.animationId = requestAnimationFrame(animate);
    };
    this.animationId = requestAnimationFrame(animate);
  }
  stop() {
    this.isActive = false;
    if (this.animationId) {
      cancelAnimationFrame(this.animationId);
      this.animationId = null;
    }
  }
  destroy() {
    this.stop();
    this.particles.forEach((p) => {
      if (p.element && p.element.parentNode)
        p.element.parentNode.removeChild(p.element);
    });
    this.particles = [];
  }
}

// ============================================================
// 📊 شمارنده آمار
// ============================================================
export class StatsCounterSystem {
  constructor() {
    this.observer = null;
    this.hasAnimated = false;
    this.counters = [];
  }
  init() {
    if (!DOM.statCards.length) return;
    this.counters = Array.from(DOM.statCards)
      .map((card) => {
        const countEl = card.querySelector(".stat-number .count");
        const target = parseInt(card.dataset.count) || 0;
        const speed = parseInt(card.dataset.speed) || 2000;
        return { element: countEl, target, speed, card };
      })
      .filter((item) => item.element && item.target > 0);
    if (!this.counters.length) return;
    if ("IntersectionObserver" in window) {
      this.observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting && !this.hasAnimated) {
              this.animateCounters();
              this.hasAnimated = true;
              this.observer.disconnect();
            }
          });
        },
        { threshold: 0.3 },
      );
      const s = DOM.sections.stats;
      if (s) this.observer.observe(s);
    } else {
      this.animateCounters();
    }
  }
  async animateCounters() {
    const promises = this.counters.map(
      (c, i) =>
        new Promise((r) => {
          setTimeout(async () => {
            c.card.classList.add("animate-in");
            await AnimationSystem.createCounterAnimation(
              c.element,
              c.target,
              c.speed,
            );
            r();
          }, i * 150);
        }),
    );
    await Promise.all(promises);
  }
  destroy() {
    if (this.observer) this.observer.disconnect();
  }
}

// ============================================================
// 🎞️ نمونه‌کارها
// ============================================================
export class PortfolioSystem {
  constructor() {
    this.grid = DOM.portfolioGrid;
    this.filters = DOM.filterButtons;
    this.viewMore = DOM.viewMoreProjects;
    this.modalCloseHandler = null;
    this.modalOverlayHandler = null;
    this.requestSimilarHandler = null;
    this.galleryThumbHandler = null;
    this.modalKeydownHandler = null;
    this.modalFocusInHandler = null;
    this.modalReturnFocus = null;
    this.modalReturnFocusOverride = null;
    this.modalInertSnapshot = null;
    this.modalFocusRedirecting = false;
    this.filterSequence = 0;
    this.modalCloseTimer = null;
    this.gallerySwitchSequence = 0;
  }
  init() {
    if (!this.grid) return;
    this.setupFilters();
    this.setupViewMore();
    this.setupProjectDetails();
    this.setupModalLifecycle();
    this.addKeyboardSupport();
  }
  setupFilters() {
    this.filters.forEach((btn) => {
      const h = () => {
        this.filterProjects(btn.dataset.filter);
        this.updateActiveFilter(btn);
      };
      state.addListener(btn, "click", h);
    });
  }
  filterProjects(filter) {
    this.currentFilter = filter;
    const sequence = ++this.filterSequence;
    const cards = this.grid.querySelectorAll(".portfolio-card:not(.view-more)");
    cards.forEach((card) => {
      const matches = filter === "all" || card.dataset.category === filter;
      card.dataset.filterSequence = String(sequence);
      card.inert = !matches;

      if (matches) {
        const wasHidden = card.hidden;
        card.hidden = false;
        card.classList.add("is-filter-visible");
        if (wasHidden || card.classList.contains("is-filtered-out")) {
          card.classList.add("is-filtered-out");
          requestAnimationFrame(() => {
            if (card.dataset.filterSequence === String(sequence)) {
              card.classList.remove("is-filtered-out");
            }
          });
        }
        return;
      }

      card.classList.add("is-filter-visible", "is-filtered-out");
      setTimeout(() => {
        if (
          card.dataset.filterSequence === String(sequence) &&
          card.classList.contains("is-filtered-out")
        ) {
          card.hidden = true;
        }
      }, CONFIG.isReducedMotion ? 0 : 300);
    });
  }
  updateActiveFilter(btn) {
    this.filters.forEach((b) => {
      b.classList.remove("active");
      b.setAttribute("aria-pressed", "false");
    });
    btn.classList.add("active");
    btn.setAttribute("aria-pressed", "true");
  }
  setupViewMore() {
    if (this.viewMore)
      state.addListener(this.viewMore, "click", () => {
        DOM.sections.contact?.scrollIntoView({ behavior: "smooth" });
      });
  }
  setupProjectDetails() {
    const cards = this.grid?.querySelectorAll(".portfolio-card:not(.view-more)");
    cards?.forEach((card) => {
      state.addListener(card, "click", () => {
        this.showProjectDetails(card.dataset.project, card);
      });
    });
  }
  setupModalLifecycle() {
    this.modalCloseHandler = () => this.closeModal();
    this.modalOverlayHandler = () => this.closeModal();
    this.requestSimilarHandler = (event) => {
      const button = event.target instanceof Element
        ? event.target.closest(".request-similar")
        : null;
      if (!button || !DOM.modalBody?.contains(button)) return;
      this.modalReturnFocusOverride = DOM.openNeoFromContact;
      this.closeModal();
      setTimeout(() => {
        DOM.sections.contact?.scrollIntoView({ behavior: CONFIG.isReducedMotion ? "auto" : "smooth" });
      }, CONFIG.isReducedMotion ? 0 : 400);
    };
    this.galleryThumbHandler = (event) => {
      const thumb = event.target instanceof Element
        ? event.target.closest(".gallery-thumb")
        : null;
      if (!thumb || !DOM.modalBody?.contains(thumb)) return;
      const image = thumb.querySelector("img");
      if (!image) return;
      DOM.modalBody.querySelectorAll(".gallery-thumb").forEach((item) => {
        item.classList.remove("active");
        item.setAttribute("aria-pressed", "false");
      });
      thumb.classList.add("active");
      thumb.setAttribute("aria-pressed", "true");
      const main = DOM.modalBody.querySelector(".gallery-image");
      if (!main) return;
      const sequence = ++this.gallerySwitchSequence;
      main.classList.add("is-switching");
      setTimeout(() => {
        if (sequence !== this.gallerySwitchSequence || !main.isConnected) return;
        main.src = image.currentSrc || image.src;
        main.alt = image.alt;
        requestAnimationFrame(() => {
          if (sequence === this.gallerySwitchSequence && main.isConnected) {
            main.classList.remove("is-switching");
          }
        });
      }, CONFIG.isReducedMotion ? 0 : 200);
    };

    state.addListener(DOM.modalClose, "click", this.modalCloseHandler);
    state.addListener(DOM.projectModal?.querySelector(".modal-overlay"), "click", this.modalOverlayHandler);
    state.addListener(DOM.modalBody, "click", this.requestSimilarHandler);
    state.addListener(DOM.modalBody, "click", this.galleryThumbHandler);
  }
  _getModalFocusableElements() {
    const modal = DOM.projectModal;
    if (!modal) return [];
    const selector = [
      "a[href]",
      "area[href]",
      "button:not([disabled])",
      "input:not([disabled]):not([type=hidden])",
      "select:not([disabled])",
      "textarea:not([disabled])",
      "[contenteditable=true]",
      "[tabindex]:not([tabindex='-1'])",
    ].join(",");
    return Array.from(modal.querySelectorAll(selector)).filter((element) => (
      !element.hidden
      && element.getAttribute("aria-hidden") !== "true"
      && !element.closest("[hidden], [inert], [aria-hidden='true']")
      && element.getAttribute("aria-disabled") !== "true"
      && element.getClientRects().length > 0
    ));
  }
  _setProjectModalBackgroundInert() {
    if (this.modalInertSnapshot || !DOM.projectModal) return;
    const background = [];
    let branch = DOM.projectModal;
    let parent = branch.parentElement;

    // Inert siblings at every ancestor level; the dialog itself may live inside <main>.
    while (parent && parent !== document.body) {
      Array.from(parent.children).forEach((element) => {
        if (element !== branch) background.push(element);
      });
      branch = parent;
      parent = branch.parentElement;
    }
    if (parent === document.body) {
      Array.from(parent.children).forEach((element) => {
        if (element !== branch) background.push(element);
      });
    }

    this.modalInertSnapshot = background.map((element) => ({
      element,
      inert: element.inert,
    }));
    this.modalInertSnapshot.forEach(({ element }) => { element.inert = true; });
  }
  _restoreProjectModalBackground() {
    if (!this.modalInertSnapshot) return;
    this.modalInertSnapshot.forEach(({ element, inert }) => {
      if (element.isConnected) element.inert = inert;
    });
    this.modalInertSnapshot = null;
  }
  _activateProjectModalFocus() {
    const modal = DOM.projectModal;
    if (!modal) return;
    this._deactivateProjectModalFocus();
    this.modalKeydownHandler = (event) => {
      if (!state.isModalOpen || !modal.classList.contains("active")) return;
      if (event.key === "Escape") {
        event.preventDefault();
        event.stopImmediatePropagation();
        this.closeModal();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = this._getModalFocusableElements();
      if (!focusable.length) {
        event.preventDefault();
        DOM.modalClose?.focus({ preventScroll: true });
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !modal.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !modal.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    };
    this.modalFocusInHandler = (event) => {
      if (!state.isModalOpen || modal.contains(event.target) || this.modalFocusRedirecting) return;
      this.modalFocusRedirecting = true;
      (DOM.modalClose || this._getModalFocusableElements()[0])?.focus({ preventScroll: true });
      this.modalFocusRedirecting = false;
    };
    document.addEventListener("keydown", this.modalKeydownHandler, true);
    document.addEventListener("focusin", this.modalFocusInHandler, true);
    DOM.modalClose?.focus({ preventScroll: true });
  }
  _deactivateProjectModalFocus() {
    if (this.modalKeydownHandler) {
      document.removeEventListener("keydown", this.modalKeydownHandler, true);
      this.modalKeydownHandler = null;
    }
    if (this.modalFocusInHandler) {
      document.removeEventListener("focusin", this.modalFocusInHandler, true);
      this.modalFocusInHandler = null;
    }
    this.modalFocusRedirecting = false;
  }
  _restoreProjectModalFocus() {
    this._restoreProjectModalBackground();
    const target = this.modalReturnFocusOverride || this.modalReturnFocus;
    this.modalReturnFocusOverride = null;
    this.modalReturnFocus = null;
    if (
      target instanceof HTMLElement
      && target.isConnected
      && !target.disabled
      && !target.closest("[hidden], [inert], [aria-hidden='true']")
      && target.getClientRects().length > 0
    ) {
      target.focus({ preventScroll: true });
      return;
    }
    DOM.filterButtons?.[0]?.focus({ preventScroll: true });
  }
  async showProjectDetails(pid, opener = document.activeElement) {
    const p = PROJECTS[pid];
    if (!p || !DOM.projectModal || !DOM.modalBody) return;
    if (!this.modalInertSnapshot) {
      this.modalReturnFocus = opener instanceof HTMLElement
        ? opener
        : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
      this.modalReturnFocusOverride = null;
      this._setProjectModalBackgroundInert();
    }
    state.isModalOpen = true;
    if (this.modalCloseTimer) {
      clearTimeout(this.modalCloseTimer);
      this.modalCloseTimer = null;
    }
    DOM.modalBody.innerHTML = this.createModalContent(p);
    prepareManagedImages(DOM.modalBody);
    const heading = DOM.modalBody.querySelector("#modalTitle");
    if (heading) heading.textContent = p.title;
    DOM.projectModal.classList.remove("is-closing");
    DOM.projectModal.classList.add("active");
    syncPublicScrollLock();
    this._activateProjectModalFocus();
  }
  createModalContent(p) {
    const esc = (s) => {
      const d = document.createElement("div");
      d.textContent = String(s ?? "");
      return d.innerHTML;
    };
    const imagePaths = Array.isArray(p.images)
      ? p.images.map(trustedPortfolioImagePath).filter(Boolean)
      : [];
    const galleryImages = imagePaths.length ? imagePaths : [PORTFOLIO_IMAGE_FALLBACK];
    const imagePath = galleryImages[0];
    const demoUrl = trustedPortfolioDemoUrl(p.demoUrl);
    const imageWidth = Number.isInteger(p.imageWidth) && p.imageWidth >= 1 && p.imageWidth <= 2400 ? p.imageWidth : 800;
    const imageHeight = Number.isInteger(p.imageHeight) && p.imageHeight >= 1 && p.imageHeight <= 2400 ? p.imageHeight : 600;
    const galleryThumbs = galleryImages.length > 1
      ? `<div class="gallery-thumbs">${galleryImages.map((img, i) => `<button type="button" class="gallery-thumb${i === 0 ? " active" : ""}" data-index="${i}" aria-label="نمایش تصویر ${i + 1} از ${esc(p.title)}" aria-pressed="${i === 0 ? "true" : "false"}"><img src="${esc(img)}" alt="${esc(p.title)} - تصویر ${i + 1}" loading="lazy" decoding="async" data-image-fallback="hide"></button>`).join("")}</div>`
      : "";

    return `<div class="modal-header"><h3 class="modal-title" id="modalTitle">${esc(p.title)}</h3><span class="modal-category">${esc(p.categoryLabel)}</span></div><div class="modal-gallery"><div class="gallery-main"><img src="${esc(imagePath)}" alt="${esc(p.title)}" class="gallery-image active" width="${imageWidth}" height="${imageHeight}" decoding="async" data-image-fallback="replace" data-image-fallback-alt="تصویر نمونه‌کار در دسترس نیست"></div>${galleryThumbs}</div><div class="modal-details"><div class="detail-section"><h4 class="detail-title">توضیحات</h4><p class="detail-text">${esc(p.fullDescription)}</p></div><div class="detail-grid"><div class="detail-item"><h5 class="detail-label">تکنولوژی‌ها</h5><div class="tech-tags">${(Array.isArray(p.technologies) ? p.technologies : []).map((t) => `<span class="tech-tag">${esc(t)}</span>`).join("")}</div></div><div class="detail-item"><h5 class="detail-label">مدت زمان</h5><p class="detail-value">${esc(p.duration)}</p></div><div class="detail-item"><h5 class="detail-label">خدمات</h5><ul class="services-list">${(Array.isArray(p.services) ? p.services : []).map((item) => `<li>${esc(item)}</li>`).join("")}</ul></div></div><div class="modal-actions">${demoUrl ? `<a href="${esc(demoUrl)}" class="btn btn-primary" target="_blank" rel="noopener noreferrer">مشاهده دمو</a>` : ""}<button type="button" class="btn btn-outline request-similar" data-project="${esc(p.id)}">درخواست پروژه مشابه</button></div></div>`;
  }
  closeModal() {
    if (!state.isModalOpen && !DOM.projectModal?.classList.contains("active")) return;
    state.isModalOpen = false;
    this._deactivateProjectModalFocus();
    DOM.projectModal?.classList.add("is-closing");
    syncPublicScrollLock();
    if (this.modalCloseTimer) clearTimeout(this.modalCloseTimer);
    this.modalCloseTimer = setTimeout(() => {
      if (DOM.projectModal?.classList.contains("is-closing")) {
        DOM.projectModal.classList.remove("active", "is-closing");
        if (DOM.modalBody) DOM.modalBody.innerHTML = "";
        syncPublicScrollLock();
        this._restoreProjectModalFocus();
      }
      this.modalCloseTimer = null;
    }, CONFIG.isReducedMotion ? 0 : 300);
  }
  addKeyboardSupport() {
    const cards = this.grid?.querySelectorAll(".portfolio-card:not(.view-more)");
    cards?.forEach((card) => {
      const title = card.querySelector(".portfolio-item-title")?.textContent?.trim();
      card.setAttribute("tabindex", "0");
      card.setAttribute("role", "button");
      card.setAttribute("aria-haspopup", "dialog");
      card.setAttribute("aria-controls", "projectModal");
      if (title) card.setAttribute("aria-label", `مشاهده جزئیات نمونه‌کار: ${title}`);
      state.addListener(card, "keydown", (event) => {
        if (event.target !== card || event.repeat) return;
        if (event.key === "Enter" || event.key === " " || event.key === "Spacebar") {
          event.preventDefault();
          card.click();
        }
      });
    });
  }
}

// ============================================================
// 📞 کارت‌های تماس
// ============================================================
export class ContactCardsSystem {
  constructor() {
    this.copyBtns = DOM.copyContactBtns;
    this.telegramBtn = DOM.telegramContactBtn;
    this.openNeoBtn = DOM.openNeoFromContact;
    this.notification = window.notificationSystem;
  }
  init() {
    this.setupCopyButtons();
    this.setupTelegramButton();
    this.setupNeoButton();
  }
  setupCopyButtons() {
    this.copyBtns.forEach((btn) => {
      state.addListener(btn, "click", async () => {
        const text = btn.dataset.text;
        const type = btn.dataset.type || "متن";
        if (!text) return;
        try {
          await this.copyToClipboard(text);
          this.notification?.success(`${type} کپی شد`);
          const span = btn.querySelector("span");
          if (span) {
            const orig = span.textContent;
            span.textContent = "✓ کپی شد";
            btn.classList.add("is-copied");
            setTimeout(() => {
              span.textContent = orig;
              btn.classList.remove("is-copied");
            }, 2000);
          }
        } catch {
          this.notification?.error("خطا در کپی");
        }
      });
    });
  }
  async copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext)
      await navigator.clipboard.writeText(text);
    else {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.className = "app-clipboard-fallback-textarea";
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
    }
  }
  setupTelegramButton() {
    if (!this.telegramBtn) return;
    const openTg = () => {
      const u = "MR_NEON_TEAM";
      const ua = navigator.userAgent.toLowerCase();
      const isMobile =
        /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile/.test(
          ua,
        );
      if (!isMobile && window.innerWidth > 1024) {
        window.open(`https://web.telegram.org/k/#@${u}`, "_blank");
        return;
      }
      if (/iphone|ipad|ipod/.test(ua)) {
        window.location.href = `tg://resolve?domain=${u}`;
        setTimeout(() => {
          if (document.visibilityState === "visible")
            window.open(`https://t.me/${u}`, "_blank");
        }, 2500);
      } else if (/android/.test(ua)) {
        window.location.href = `intent://resolve?domain=${u}#Intent;package=org.telegram.messenger;scheme=tg;end;`;
        setTimeout(() => {
          if (document.visibilityState === "visible") {
            window.location.href = `tg://resolve?domain=${u}`;
            setTimeout(() => {
              if (document.visibilityState === "visible")
                window.open(`https://t.me/${u}`, "_blank");
            }, 2000);
          }
        }, 1500);
      } else {
        window.location.href = `tg://resolve?domain=${u}`;
        setTimeout(() => {
          if (document.visibilityState === "visible")
            window.open(`https://t.me/${u}`, "_blank");
        }, 2000);
      }
    };
    state.addListener(this.telegramBtn, "click", (e) => {
      e.preventDefault();
      openTg();
    });
  }
  setupNeoButton() {
    if (this.openNeoBtn) {
      state.addListener(this.openNeoBtn, "click", () => {
        if (window.neoPro) window.neoPro.open();
      });
    }
  }
}

// ============================================================
// 🖱️ اسکرول و نویگیشن
// ============================================================
export class ScrollSystem {
  constructor() {
    this.navbar = DOM.navbar;
    this.navLinks = DOM.navLinks;
    this.sections = DOM.sections;
    this.observers = [];
    this.rafId = null;
  }
  init() {
    this.setupScrollEvents();
    this.setupNavLinks();
    this.setupScrollAnimations();
  }
  setupScrollEvents() {
    const h = () => {
      const sp = window.scrollY;
      this.handleNavbarScroll(sp);
      this.handleSectionActivation();
    };
    window.addEventListener("scroll", h, { passive: true });
    state.addListener(window, "scroll", h, { passive: true });
  }
  handleNavbarScroll(sp) {
    if (!this.navbar) return;
    if (sp > CONFIG.scrollThreshold) this.navbar.classList.add("scrolled");
    else this.navbar.classList.remove("scrolled");
  }
  handleSectionActivation() {
    if (this.rafId) cancelAnimationFrame(this.rafId);
    this.rafId = requestAnimationFrame(() => {
      const sp = window.scrollY + 100;
      Object.values(this.sections).forEach((sec) => {
        if (!sec) return;
        const top = sec.offsetTop;
        const h = sec.offsetHeight;
        if (sp >= top && sp < top + h) this.updateActiveNavLink(sec.id);
      });
    });
  }
  updateActiveNavLink(sid) {
    this.navLinks.forEach((link) => {
      link.classList.remove("active");
      if (link.getAttribute("href") === `#${sid}`) link.classList.add("active");
    });
  }
  setupNavLinks() {
    this.navLinks.forEach((link) => {
      state.addListener(link, "click", (e) => {
        e.preventDefault();
        const tid = link.getAttribute("href");
        if (!tid || tid === "#") return;
        const el = document.querySelector(tid);
        if (!el) return;
        if (state.isMobileMenuOpen) mobileMenuInstance?.closeMobileMenu();
        const nh = this.navbar?.offsetHeight || 80;
        window.scrollTo({
          top: el.offsetTop - nh,
          behavior: CONFIG.isReducedMotion ? "auto" : "smooth",
        });
        this.updateActiveNavLink(tid.substring(1));
      });
    });
  }
  setupScrollAnimations() {
    const obs = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) entry.target.classList.add("animate-in");
        });
      },
      { threshold: 0.1, rootMargin: "0px 0px -50px 0px" },
    );
    document
      .querySelectorAll(
        ".service-card, .team-card, .stat-card, .portfolio-card",
      )
      .forEach((el) => {
        obs.observe(el);
        this.observers.push(obs);
      });
  }
  destroy() {
    this.observers.forEach((o) => o.disconnect());
    if (this.rafId) cancelAnimationFrame(this.rafId);
  }
}

// ============================================================
// 📱 منوی موبایل
// ============================================================
let mobileMenuInstance = null;
export class MobileMenuSystem {
  constructor() {
    this.hamburger = DOM.hamburger;
    this.navMenu = DOM.navMenu;
    this.navLinks = DOM.navLinks;
    this.outsideHandler = null;
    this.closeTimer = null;
  }
  init() {
    if (!this.hamburger) return;
    this.setupHamburger();
    this.setupMenuLinks();
    this.setupOutsideClick();
    mobileMenuInstance = this;
  }
  setupHamburger() {
    state.addListener(this.hamburger, "click", () => this.toggleMobileMenu());
  }
  async toggleMobileMenu() {
    if (!state.isMobileMenuOpen) await this.openMobileMenu();
    else await this.closeMobileMenu();
  }
  async openMobileMenu() {
    state.isMobileMenuOpen = true;
    if (this.closeTimer) {
      clearTimeout(this.closeTimer);
      this.closeTimer = null;
    }
    this.hamburger?.classList.add("active");
    this.hamburger?.setAttribute("aria-expanded", "true");
    this.navMenu?.classList.remove("is-closing");
    this.navMenu?.classList.add("active");
    syncPublicScrollLock();
  }
  async closeMobileMenu() {
    if (!state.isMobileMenuOpen && !this.navMenu?.classList.contains("active")) return;
    state.isMobileMenuOpen = false;
    this.hamburger?.classList.remove("active");
    this.hamburger?.setAttribute("aria-expanded", "false");
    this.navMenu?.classList.remove("active");
    this.navMenu?.classList.add("is-closing");
    syncPublicScrollLock();
    if (this.closeTimer) clearTimeout(this.closeTimer);
    this.closeTimer = setTimeout(() => {
      this.navMenu?.classList.remove("is-closing");
      syncPublicScrollLock();
      this.closeTimer = null;
    }, CONFIG.isReducedMotion ? 0 : 300);
  }
  setupMenuLinks() {
    this.navLinks.forEach((link) => {
      state.addListener(link, "click", () => {
        if (state.isMobileMenuOpen) this.closeMobileMenu();
      });
    });
  }
  setupOutsideClick() {
    this.outsideHandler = (e) => {
      if (
        state.isMobileMenuOpen &&
        this.navMenu &&
        !this.navMenu.contains(e.target) &&
        this.hamburger &&
        !this.hamburger.contains(e.target)
      )
        this.closeMobileMenu();
    };
    document.addEventListener("click", this.outsideHandler);
  }
}

// ============================================================
// 🔗 Floating Contact
// ============================================================
export class FloatingContactSystem {
  constructor() {
    this.panel = DOM.contactPanel;
    this.btn = DOM.floatingContactBtn;
    this.notification = window.notificationSystem;
    this.init();
  }
  init() {
    if (!this.btn) return;
    let open = false;
    const toggle = () => {
      open = !open;
      DOM.floatingContact.classList.toggle("active", open);
      this.btn.setAttribute("aria-expanded", open);
      this.panel?.setAttribute("aria-hidden", !open);
    };
    const close = () => {
      if (open) {
        open = false;
        DOM.floatingContact.classList.remove("active");
        this.btn.setAttribute("aria-expanded", "false");
        this.panel?.setAttribute("aria-hidden", "true");
      }
    };
    state.addListener(this.btn, "click", (e) => {
      e.stopPropagation();
      toggle();
    });
    state.addListener(document, "click", (e) => {
      if (open && !DOM.floatingContact.contains(e.target)) close();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && open) close();
    });
    document.querySelectorAll(".copy-item").forEach((btn) => {
      state.addListener(btn, "click", async () => {
        const text = btn.dataset.copy;
        if (!text) return;
        await this.copyToClipboard(text);
        this.notification?.success(`"${text}" کپی شد`);
      });
    });
    DOM.shareSiteBtn?.addEventListener("click", async () => {
      const text = `🚀 وب‌سایت حرفه‌ای خود را تنها در ۷۲ ساعت با تیم MR NEON راه‌اندازی کنید! همین حالا شروع کنید: ${window.location.origin}`;
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
        } else {
          window.notificationSystem?.warning(
            "لطفاً یکی از پلتفرم‌ها را برای ارسال انتخاب کنید",
          );
          const contactSection = document.querySelector("#floatingContact");
          if (contactSection) {
            contactSection.scrollIntoView({
              behavior: "smooth",
              block: "center",
            });
          }
          const shareBtn = document.querySelector("#shareSiteBtn");
          if (shareBtn) {
            shareBtn.classList.add("share-highlight");
            setTimeout(
              () => shareBtn.classList.remove("share-highlight"),
              3000,
            );
          }
        }
      }
    });
    DOM.floatingTelegramBtn?.addEventListener("click", (e) => {
      e.preventDefault();
      const u = "MR_NEON_TEAM";
      const ua = navigator.userAgent.toLowerCase();
      const isM =
        /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini|mobile/.test(
          ua,
        );
      if (!isM && window.innerWidth > 1024) {
        window.open(`https://web.telegram.org/k/#@${u}`, "_blank");
        return;
      }
      if (/iphone|ipad|ipod/.test(ua)) {
        window.location.href = `tg://resolve?domain=${u}`;
        setTimeout(() => {
          if (document.visibilityState === "visible")
            window.open(`https://t.me/${u}`, "_blank");
        }, 2500);
      } else if (/android/.test(ua)) {
        window.location.href = `intent://resolve?domain=${u}#Intent;package=org.telegram.messenger;scheme=tg;end;`;
        setTimeout(() => {
          if (document.visibilityState === "visible") {
            window.location.href = `tg://resolve?domain=${u}`;
            setTimeout(() => {
              if (document.visibilityState === "visible")
                window.open(`https://t.me/${u}`, "_blank");
            }, 2000);
          }
        }, 1500);
      } else {
        window.location.href = `tg://resolve?domain=${u}`;
        setTimeout(() => {
          if (document.visibilityState === "visible")
            window.open(`https://t.me/${u}`, "_blank");
        }, 2000);
      }
    });
  }
  async copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext)
      await navigator.clipboard.writeText(text);
    else {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.className = "app-clipboard-fallback-textarea";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
    }
  }
}

// ============================================================
// 🗺️ تور راهنما
// ============================================================
export class TourGuideV7 {
  constructor() {
    this.desktopSteps = [
      {
        target: "#hero",
        title: "👋 به MR NEON خوش آمدید!",
        text: "ما تیم برنامه‌نویسی وب هستیم که سایت شما را در کمتر از ۷۲ ساعت تحویل می‌دهیم.",
        position: "bottom",
      },
      {
        target: "#loginBtnDesktop",
        title: "🔐 ورود و ثبت‌نام",
        text: "برای مدیریت پروژه‌ها و ثبت درخواست، وارد حساب خود شوید.",
        position: "bottom",
      },
      {
        target: "#neoChatBubble",
        title: "🤖 دستیار هوشمند NEO",
        text: "برای ثبت پروژه جدید، روی این دکمه کلیک کنید.",
        position: "top",
      },
      {
        centered: true,
        title: "📞 راه‌های ارتباطی",
        text: "کارت‌های ایمیل و تلگرام در پایین صفحه، و دکمهٔ شناور پیام در گوشهٔ صفحه، همیشه برای تماس با ما در دسترس شما هستند.",
      },
    ];
    this.mobileSteps = [
      {
        target: "#hero",
        title: "👋 خوش آمدید!",
        text: "ما تیم برنامه‌نویسی وب هستیم.",
        position: "bottom",
      },
      {
        target: "#loginBtnMobile",
        title: "🔐 ورود",
        text: "برای ثبت پروژه وارد شوید.",
        position: "bottom",
      },
      {
        target: "#neoChatBubble",
        title: "🤖 ربات نئو",
        text: "ثبت پروژه در ۵ دقیقه.",
        position: "top",
      },
    ];
    this.current = 0;
    this.currentIsCentered = false;
    this.steps = [];
    this.active = false;
    this.positionSequence = 0;
    this.previousFocus = null;
    this.startTimer = null;
    this.warnedTooltipOverflow = false;

    try {
      this.seen = localStorage.getItem("mrneon_tour_seen_v7");
    } catch {
      this.seen = "unavailable";
    }

    this._nextHandler = () => this.next();
    this._prevHandler = () => this.prev();
    this._skipHandler = () => this.end();
    this._resizeHandler = () => this._handleResize();
    this.overlay = DOM.tourOverlay;
    this.highlight = DOM.tourHighlight;
    this.tooltip = DOM.tourTooltip;
    this.stepCount = DOM.tourStepCount;
    this.tooltipTitle = DOM.tourTooltipTitle;
    this.tooltipText = DOM.tourTooltipText;
    this.prevBtn = DOM.tourPrevBtn;
    this.nextBtn = DOM.tourNextBtn;
    this.skipBtn = DOM.tourSkipBtn;

    if (this.highlight) this.highlight.hidden = true;
    if (this.tooltip) this.tooltip.hidden = true;

    this._keyHandler = this._handleKey.bind(this);
    this._backdropClick = this._handleBackdropClick.bind(this);
  }

  init() {
    if (!this.seen && !CONFIG.isReducedMotion) {
      this.startTimer = window.setTimeout(() => {
        this.startTimer = null;
        this.start();
      }, 1500);
    }
  }

  start() {
    if (this.active) return;
    if (this.startTimer !== null) {
      window.clearTimeout(this.startTimer);
      this.startTimer = null;
    }

    const requiredElements = [
      this.overlay,
      this.highlight,
      this.tooltip,
      this.stepCount,
      this.tooltipTitle,
      this.tooltipText,
      this.prevBtn,
      this.nextBtn,
      this.skipBtn,
    ];
    if (requiredElements.some((element) => !element)) {
      console.warn("Tour: required DOM elements are missing; the tour was not started.");
      return;
    }

    this.previousFocus =
      document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;
    this.active = true;
    this.current = 0;
    this.currentIsCentered = false;
    this.steps =
      window.innerWidth <= 768 ? this.mobileSteps : this.desktopSteps;

    this.overlay.classList.add("active");
    syncPublicScrollLock();
    this.nextBtn.addEventListener("click", this._nextHandler);
    this.prevBtn.addEventListener("click", this._prevHandler);
    this.skipBtn.addEventListener("click", this._skipHandler);
    this.overlay.addEventListener("click", this._backdropClick);
    document.addEventListener("keydown", this._keyHandler);
    window.addEventListener("resize", this._resizeHandler);

    this._showStep(0);
  }

  _showStep(index) {
    if (
      !Array.isArray(this.steps) ||
      this.steps.length === 0 ||
      !Number.isInteger(index) ||
      index < 0 ||
      index >= this.steps.length
    ) {
      this.end();
      return;
    }

    const step = this.steps[index];
    if (!step || typeof step !== "object") {
      console.warn(`Tour: step ${index + 1} is invalid. Ending tour.`);
      this.end();
      return;
    }

    // Informational steps intentionally have no target and never scroll.
    if (step.centered === true) {
      this._showCenteredStep(index, step);
      return;
    }

    // Reject malformed steps before calling querySelector.
    if (typeof step.target !== "string" || step.target.trim() === "") {
      console.warn(
        `Tour: step ${index + 1} has no valid target or centered mode. Ending tour.`,
      );
      this.end();
      return;
    }

    let target;
    try {
      target = document.querySelector(step.target);
    } catch (error) {
      console.warn(`Tour: step ${index + 1} has an invalid target selector.`, error);
      this.end();
      return;
    }

    if (!target) {
      // Preserve the existing missing-target behavior; this remains a known
      // configuration issue rather than changing navigation semantics here.
      console.warn(`Tour: target not found: ${step.target}`);
      return;
    }

    this.current = index;
    this.currentIsCentered = false;
    this.stepCount.textContent = `${index + 1} از ${this.steps.length}`;
    this.tooltipTitle.textContent = step.title ?? "";
    this.tooltipText.textContent = step.text ?? "";
    this.prevBtn.hidden = index === 0;
    this.nextBtn.textContent =
      index === this.steps.length - 1 ? "تمام ✓" : "بعدی";

    target.scrollIntoView({
      behavior: CONFIG.isReducedMotion ? "auto" : "smooth",
      block: "center",
    });

    const positionSequence = ++this.positionSequence;
    window.setTimeout(
      () => {
        if (this.active && positionSequence === this.positionSequence) {
          this._positionElements(target, step.position);
          if (index === 0) this._focusNextButton();
        }
      },
      CONFIG.isReducedMotion ? 0 : 500,
    );
  }

  _showCenteredStep(index, step) {
    // Invalidate pending target positioning before switching visual modes.
    this.positionSequence += 1;
    this.current = index;
    this.currentIsCentered = true;
    this.stepCount.textContent = `${index + 1} از ${this.steps.length}`;
    this.tooltipTitle.textContent = step.title ?? "";
    this.tooltipText.textContent = step.text ?? "";
    this.prevBtn.hidden = index === 0;
    this.nextBtn.textContent =
      index === this.steps.length - 1 ? "تمام ✓" : "بعدی";

    // The highlight's shadow normally dims the page. With no target, apply
    // the same dimming directly to the overlay instead.
    this.overlay.style.background = "rgba(15, 23, 42, 0.85)";
    this.highlight.hidden = true;
    this.highlight.style.top = "";
    this.highlight.style.left = "";
    this.highlight.style.width = "";
    this.highlight.style.height = "";

    this.tooltip.hidden = false;
    this.tooltip.scrollTop = 0;
    this._centerTooltip();
    this._focusNextButton();
  }

  _constrainTooltip(resetScroll = false) {
    if (!this.tooltip) return null;

    const viewportWidth = Math.max(
      1,
      window.innerWidth || document.documentElement.clientWidth || 1,
    );
    const viewportHeight = Math.max(
      1,
      window.innerHeight || document.documentElement.clientHeight || 1,
    );
    const margin = Math.min(
      10,
      Math.max(2, Math.floor(Math.min(viewportWidth, viewportHeight) * 0.025)),
    );

    // Inline constraints keep the tooltip within even very small viewports;
    // the existing stylesheet still controls its normal visual appearance.
    // Respect the stylesheet's design cap (360px desktop / 280px mobile)
    // while reducing it further when the viewport is narrower.
    this.tooltip.style.maxWidth = "";
    const styledMaxWidth = Number.parseFloat(getComputedStyle(this.tooltip).maxWidth);
    const viewportMaxWidth = Math.max(1, viewportWidth - margin * 2);
    const effectiveMaxWidth = Number.isFinite(styledMaxWidth)
      ? Math.min(styledMaxWidth, viewportMaxWidth)
      : viewportMaxWidth;
    this.tooltip.style.maxWidth = `${effectiveMaxWidth}px`;
    // Reset auto sizing and measure from a known in-viewport origin. Absolute
    // auto-width elements can otherwise change width after their left offset
    // is updated (shrink-to-fit), invalidating the centering calculation.
    this.tooltip.style.width = "";
    this.tooltip.style.left = `${margin}px`;
    this.tooltip.style.maxHeight = `${Math.max(1, viewportHeight - margin * 2)}px`;
    this.tooltip.style.overflowY = "auto";
    this.tooltip.style.overscrollBehavior = "contain";
    if (resetScroll) this.tooltip.scrollTop = 0;

    void this.tooltip.offsetHeight;
    const rect = this.tooltip.getBoundingClientRect();
    if (rect.width > 0) this.tooltip.style.width = `${rect.width}px`;
    if (
      !this.warnedTooltipOverflow &&
      this.tooltip.scrollHeight > this.tooltip.clientHeight + 1
    ) {
      this.warnedTooltipOverflow = true;
      console.warn(
        "Tour: tooltip content exceeds the viewport; internal scrolling has been enabled.",
      );
    }

    return { rect, margin, viewportWidth, viewportHeight };
  }

  _centerTooltip() {
    if (!this.tooltip) return;
    const geometry = this._constrainTooltip();
    if (!geometry) return;

    const { rect, margin, viewportWidth, viewportHeight } = geometry;
    const width = rect.width || Math.min(360, viewportWidth - margin * 2);
    const height = rect.height || Math.min(200, viewportHeight - margin * 2);
    const maxLeft = Math.max(margin, viewportWidth - width - margin);
    const maxTop = Math.max(margin, viewportHeight - height - margin);
    const left = Math.max(
      margin,
      Math.min((viewportWidth - width) / 2, maxLeft),
    );
    const top = Math.max(
      margin,
      Math.min((viewportHeight - height) / 2, maxTop),
    );

    this.tooltip.style.left = `${left}px`;
    this.tooltip.style.top = `${top}px`;
    this._ensureElementVisible(document.activeElement);
  }

  _positionElements(target, position, resetTooltipScroll = true) {
    if (!target || !this.highlight || !this.tooltip) return;

    // Returning from centered mode restores the highlight's cutout dimming.
    this.overlay.style.background = "";
    const rect = target.getBoundingClientRect();
    const highlight = this.highlight;
    const tooltip = this.tooltip;

    highlight.hidden = false;
    tooltip.hidden = false;
    highlight.style.top = `${rect.top - 8}px`;
    highlight.style.left = `${rect.left - 8}px`;
    highlight.style.width = `${rect.width + 16}px`;
    highlight.style.height = `${rect.height + 16}px`;

    const geometry = this._constrainTooltip(resetTooltipScroll);
    if (!geometry) return;
    const { rect: tooltipRect, margin, viewportWidth, viewportHeight } = geometry;
    const tipWidth = tooltipRect.width || Math.min(360, viewportWidth - margin * 2);
    const tipHeight = tooltipRect.height || Math.min(200, viewportHeight - margin * 2);
    const gap = 16;
    let top;
    let left;

    switch (position) {
      case "top":
        top = rect.top - tipHeight - gap;
        left = rect.left + rect.width / 2 - tipWidth / 2;
        break;
      case "left":
        top = rect.top + rect.height / 2 - tipHeight / 2;
        left = rect.left - tipWidth - gap;
        break;
      case "bottom":
      default:
        top = rect.bottom + gap;
        left = rect.left + rect.width / 2 - tipWidth / 2;
        break;
    }

    const maxLeft = Math.max(margin, viewportWidth - tipWidth - margin);
    const maxTop = Math.max(margin, viewportHeight - tipHeight - margin);
    left = Math.max(margin, Math.min(left, maxLeft));
    top = Math.max(margin, Math.min(top, maxTop));
    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
    this._ensureElementVisible(document.activeElement);
  }

  _ensureElementVisible(element) {
    if (!element || !this.tooltip || this.tooltip.hidden || !this.tooltip.contains(element)) return;

    // Keep a focused action visible inside a scrollable tooltip without
    // scrolling the underlying page.
    const tooltipRect = this.tooltip.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    const contentTop = tooltipRect.top + this.tooltip.clientTop;
    const contentBottom = contentTop + this.tooltip.clientHeight;
    if (elementRect.top < contentTop) {
      this.tooltip.scrollTop -= contentTop - elementRect.top;
    } else if (elementRect.bottom > contentBottom) {
      this.tooltip.scrollTop += elementRect.bottom - contentBottom;
    }
  }

  _focusElement(element) {
    if (!element || typeof element.focus !== "function") return;

    try {
      element.focus({ preventScroll: true });
    } catch {
      element.focus();
    }
    this._ensureElementVisible(element);
  }

  _focusNextButton() {
    if (!this.nextBtn || !this.tooltip || this.tooltip.hidden) return;
    this._focusElement(this.nextBtn);
  }

  _handleResize() {
    if (!this.active) return;

    if (this.currentIsCentered) {
      this._centerTooltip();
      return;
    }

    const step = this.steps[this.current];
    if (typeof step?.target !== "string") return;

    let target;
    try {
      target = document.querySelector(step.target);
    } catch {
      return;
    }
    if (target) this._positionElements(target, step.position, false);
  }

  next() {
    if (this.current < this.steps.length - 1) {
      this._showStep(this.current + 1);
    } else {
      this.end();
    }
  }

  prev() {
    if (this.current > 0) {
      this._showStep(this.current - 1);
    }
  }

  end() {
    const wasActive = this.active;
    const focusTarget = this.previousFocus;
    this.active = false;
    this.currentIsCentered = false;
    this.positionSequence += 1;
    if (this.startTimer !== null) {
      window.clearTimeout(this.startTimer);
      this.startTimer = null;
    }

    this.overlay?.classList.remove("active");
    if (this.overlay) this.overlay.style.background = "";

    if (this.highlight) {
      this.highlight.hidden = true;
      this.highlight.style.top = "";
      this.highlight.style.left = "";
      this.highlight.style.width = "";
      this.highlight.style.height = "";
    }

    if (this.tooltip) {
      this.tooltip.hidden = true;
      this.tooltip.style.top = "";
      this.tooltip.style.left = "";
      this.tooltip.style.width = "";
      this.tooltip.style.maxWidth = "";
      this.tooltip.style.maxHeight = "";
      this.tooltip.style.overflowY = "";
      this.tooltip.style.overscrollBehavior = "";
      this.tooltip.scrollTop = 0;
    }

    syncPublicScrollLock();
    this.nextBtn?.removeEventListener("click", this._nextHandler);
    this.prevBtn?.removeEventListener("click", this._prevHandler);
    this.skipBtn?.removeEventListener("click", this._skipHandler);
    this.overlay?.removeEventListener("click", this._backdropClick);
    document.removeEventListener("keydown", this._keyHandler);
    window.removeEventListener("resize", this._resizeHandler);

    try {
      localStorage.setItem("mrneon_tour_seen_v7", "1");
    } catch {
      // Tour dismissal remains effective for this page when storage is blocked.
    }

    this.previousFocus = null;
    if (wasActive && focusTarget?.isConnected && typeof focusTarget.focus === "function") {
      try {
        this._focusElement(focusTarget);
      } catch {
        focusTarget.focus();
      }
    }
  }

  _handleKey(event) {
    if (!this.active) return;

    if (event.key === "Escape") {
      event.preventDefault();
      this.end();
      return;
    }

    if (event.key === "Tab") {
      // The first targeted tooltip is hidden until its initial target
      // positioning completes. Do not trap Tab on a hidden child.
      if (this.tooltip?.hidden) return;

      // Keep focus inside the tour while still allowing keyboard users to
      // reach Previous, Next, and Skip instead of trapping them on one button.
      const focusable = [this.prevBtn, this.nextBtn, this.skipBtn].filter(
        (button) => button && !button.hidden && !button.disabled,
      );
      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }

      const currentIndex = focusable.indexOf(document.activeElement);
      if (event.shiftKey && currentIndex <= 0) {
        event.preventDefault();
        this._focusElement(focusable[focusable.length - 1]);
      } else if (!event.shiftKey && (currentIndex === -1 || currentIndex === focusable.length - 1)) {
        event.preventDefault();
        this._focusElement(focusable[0]);
      }
      return;
    }

    if (!this.tooltip?.contains(document.activeElement)) return;

    if (event.key === "ArrowRight") {
      event.preventDefault();
      this.next();
    } else if (event.key === "ArrowLeft") {
      event.preventDefault();
      this.prev();
    }
  }

  _handleBackdropClick(event) {
    if (event.target === this.overlay) event.stopPropagation();
  }
}

// ============================================================
// 🌀 Curved Tech Loop — Vanilla SVG textPath + requestAnimationFrame
// ============================================================
export class CurvedTechLoopSystem {
  constructor() {
    this.container = document.getElementById("techCurvedLoop");
    this.svg = this.container?.querySelector(".tech-curved-loop__svg") ?? null;
    this.textPath = document.getElementById("techCurvedLoopTextPath");
    this.path = document.getElementById("techCurvedLoopPath");

    this.rafId = null;
    this.observer = null;
    this.onePeriod = 0;
    this.duration = 80000;
    this.startTime = 0;
    this.elapsed = 0;
    this.isVisible = false;
    this.isPageVisible = document.visibilityState !== "hidden";
    this.isDragging = false;
    this.pointerId = null;
    this.lastPointerX = 0;
    this.destroyed = false;

    this._tick = this._tick.bind(this);
    this._onVisibilityChange = this._onVisibilityChange.bind(this);
    this._onPointerDown = this._onPointerDown.bind(this);
    this._onPointerMove = this._onPointerMove.bind(this);
    this._onPointerUp = this._onPointerUp.bind(this);
    this._onKeyDown = this._onKeyDown.bind(this);
  }

  init() {
    if (!this.container || !this.svg || !this.textPath || !this.path) return;

    // The static SVG remains visible when reduced motion is requested.
    if (CONFIG.isReducedMotion) return;

    const prepare = () => {
      if (this.destroyed || !this.container.isConnected) return;

      try {
        const totalTextLength = this.textPath.getComputedTextLength();
        const pathLength = this.path.getTotalLength();

        // The visible text contains two identical copies. The measured half is
        // therefore the exact distance needed for a seamless repeat.
        this.onePeriod = totalTextLength / 2;
        if (
          !Number.isFinite(this.onePeriod) ||
          this.onePeriod <= 0 ||
          !Number.isFinite(pathLength) ||
          pathLength < totalTextLength
        ) {
          console.warn("CurvedTechLoop: SVG path is too short for its repeated text.");
          return;
        }

        this.container.classList.add("is-animated");
        this.container.tabIndex = 0;
        this.container.setAttribute("aria-keyshortcuts", "ArrowLeft ArrowRight");
        this.container.setAttribute(
          "aria-description",
          "برای جابه‌جایی دستی، متن را بکشید یا از کلیدهای جهت‌دار چپ و راست استفاده کنید.",
        );

        this._bindInteractions();
        this._observeVisibility();
        document.addEventListener("visibilitychange", this._onVisibilityChange);
      } catch (error) {
        this.destroy();
        console.error("CurvedTechLoop initialization failed:", error);
      }
    };

    if (document.fonts?.ready && typeof document.fonts.ready.then === "function") {
      document.fonts.ready.then(prepare, prepare);
    } else {
      prepare();
    }
  }

  _bindInteractions() {
    this.container.addEventListener("pointerdown", this._onPointerDown);
    this.container.addEventListener("pointermove", this._onPointerMove);
    this.container.addEventListener("pointerup", this._onPointerUp);
    this.container.addEventListener("pointercancel", this._onPointerUp);
    this.container.addEventListener("lostpointercapture", this._onPointerUp);
    this.container.addEventListener("keydown", this._onKeyDown);
  }

  _observeVisibility() {
    if ("IntersectionObserver" in window) {
      this.observer = new IntersectionObserver(
        (entries) => {
          for (const entry of entries) {
            this.isVisible = entry.isIntersecting;
            this._syncPlayback();
          }
        },
        { threshold: 0.05 },
      );
      this.observer.observe(this.container);
      return;
    }

    this.isVisible = true;
    this._syncPlayback();
  }

  _canAnimate() {
    return this.isVisible && this.isPageVisible && !this.isDragging && !this.destroyed;
  }

  _syncPlayback() {
    if (this._canAnimate()) this._play();
    else this._pause();
  }

  _play() {
    if (this.rafId !== null || !this._canAnimate()) return;
    this.startTime = performance.now() - this.elapsed;
    this.rafId = requestAnimationFrame(this._tick);
  }

  _pause() {
    if (this.rafId === null) return;

    this.elapsed = this._normalizeTime(performance.now() - this.startTime);
    cancelAnimationFrame(this.rafId);
    this.rafId = null;
  }

  _normalizeTime(value) {
    return ((value % this.duration) + this.duration) % this.duration;
  }

  _renderAtElapsed() {
    if (!this.onePeriod) return;
    const progress = this.elapsed / this.duration;
    const offset = -progress * this.onePeriod;
    this.textPath.setAttribute("startOffset", offset.toFixed(2));
  }

  _tick(now) {
    this.rafId = null;
    if (!this._canAnimate()) return;

    this.elapsed = this._normalizeTime(now - this.startTime);
    this._renderAtElapsed();
    this.rafId = requestAnimationFrame(this._tick);
  }

  _onVisibilityChange() {
    this.isPageVisible = document.visibilityState !== "hidden";
    this._syncPlayback();
  }

  _onPointerDown(event) {
    if (!event.isPrimary || event.button !== 0 || !this.onePeriod) return;

    this._pause();
    this.isDragging = true;
    this.pointerId = event.pointerId;
    this.lastPointerX = event.clientX;

    try {
      this.container.setPointerCapture(event.pointerId);
    } catch {
      // Pointer capture is an enhancement; normal pointer events still work.
    }
  }

  _onPointerMove(event) {
    if (!this.isDragging || event.pointerId !== this.pointerId) return;

    const bounds = this.svg.getBoundingClientRect();
    if (bounds.width <= 0) return;

    const deltaX = event.clientX - this.lastPointerX;
    this.lastPointerX = event.clientX;

    // Convert CSS pixels to SVG viewBox units, then map the drag to loop time.
    const deltaInSvgUnits = (deltaX / bounds.width) * 1200;
    this.elapsed = this._normalizeTime(
      this.elapsed - (deltaInSvgUnits / this.onePeriod) * this.duration,
    );
    this._renderAtElapsed();
  }

  _onPointerUp(event) {
    if (!this.isDragging || event.pointerId !== this.pointerId) return;

    this.isDragging = false;
    this.pointerId = null;
    this.startTime = performance.now() - this.elapsed;
    this._syncPlayback();
  }

  _onKeyDown(event) {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    if (!this.onePeriod || CONFIG.isReducedMotion) return;

    event.preventDefault();
    this._pause();
    const direction = event.key === "ArrowLeft" ? 1 : -1;
    this.elapsed = this._normalizeTime(this.elapsed + direction * this.duration * 0.025);
    this.startTime = performance.now() - this.elapsed;
    this._renderAtElapsed();
    this._syncPlayback();
  }

  destroy() {
    this.destroyed = true;
    this._pause();

    if (this.observer) {
      this.observer.disconnect();
      this.observer = null;
    }

    document.removeEventListener("visibilitychange", this._onVisibilityChange);

    if (this.container) {
      if (this.pointerId !== null) {
        try {
          if (this.container.hasPointerCapture(this.pointerId)) {
            this.container.releasePointerCapture(this.pointerId);
          }
        } catch {
          // The browser may already have released the pointer capture.
        }
      }
      this.isDragging = false;
      this.pointerId = null;
      this.container.classList.remove("is-animated");
      this.container.removeAttribute("tabindex");
      this.container.removeAttribute("aria-keyshortcuts");
      this.container.removeAttribute("aria-description");
      this.container.removeEventListener("pointerdown", this._onPointerDown);
      this.container.removeEventListener("pointermove", this._onPointerMove);
      this.container.removeEventListener("pointerup", this._onPointerUp);
      this.container.removeEventListener("pointercancel", this._onPointerUp);
      this.container.removeEventListener("lostpointercapture", this._onPointerUp);
      this.container.removeEventListener("keydown", this._onKeyDown);
    }
  }
}

// ============================================================
// 🏁 برنامه اصلی
// ============================================================
// Import classes from other modules (circular-safe because CONFIG, DOM already exported)
import {
  AuthSystem,
  RegistrationSystem,
  ForgotPasswordSystem,
  TermsModal,
  UserPanel,
} from "./auth.js";
import {
  NEO_STEPS,
  NeoPreFlightCheck,
  NeoAssistantPro,
  ThankYouModal,
  ReceiptModal,
} from "./neo.js";
import { configureApiEndpoint } from "./api-client.js";

export default class MRNeonApp {
  constructor() {
    this.systems = {};
  }
  async init() {
    try {
      this.updateCurrentYear();
      recoverAlreadyFailedManagedImages();
      await this.initializeSystems();
      this.setupEventListeners();
      this.finishInitialLoad();
    } catch (e) {
      this.emergencyReset();
      return;
    }
    this.animatePublicContent();
  }
  updateCurrentYear() {
    const yearElement = document.getElementById("currentYear");
    if (!yearElement) return;
    yearElement.textContent = new Date().toLocaleDateString("fa-IR", {
      year: "numeric",
    });
  }

  async initializeSystems() {
    configureApiEndpoint(CONFIG.apiEndpoint);
    const initSafe = async (name, ClassRef, ...args) => {
      try {
        const instance = new ClassRef(...args);
        instance.init();
        this.systems[name] = instance;
      } catch (e) {
        console.error(`Failed init ${name}:`, e);
      }
    };

    // Public UI systems
    window.notificationSystem = new NotificationSystem();
    window.receiptModal = new ReceiptModal();

    await initSafe("scroll", ScrollSystem);
    await initSafe("mobileMenu", MobileMenuSystem);
    await initSafe("portfolio", PortfolioSystem);
    await initSafe("stats", StatsCounterSystem);
    await initSafe("curvedTechLoop", CurvedTechLoopSystem);
    try {
      // Native `loading="lazy"` schedules requests; this only syncs real image states.
      prepareManagedImages(document);
    } catch (e) {}
    if (!CONFIG.isReducedMotion && !CONFIG.isTouch && DOM.particlesContainer) {
      try {
        const ps = new ParticleSystem(DOM.particlesContainer);
        ps.init();
        this.systems.particle = ps;
      } catch (e) {}
    }

    // Auth & User systems
    window.authSystem = new AuthSystem();
    window.registrationSystem = new RegistrationSystem();
    window.forgotPasswordSystem = new ForgotPasswordSystem();
    window.termsModal = new TermsModal();
    window.userPanel = new UserPanel();

    // AdminPanel is initialized on demand by the public Admin entry action or an Admin session flow.
    // Restore server-issued HttpOnly-cookie session in memory; no auth token is read from Browser Storage.
    void AuthSystem.restoreSession();

    // NEO
    window.neoPro = new NeoAssistantPro();
    window.thankYouModal = new ThankYouModal();

    // Tour (optional)
    try {
      this.systems.tour = new TourGuideV7();
      this.systems.tour.init();
    } catch (e) {}

    // Contact cards
    try {
      this.systems.contactCards = new ContactCardsSystem();
      this.systems.contactCards.init();
    } catch (e) {}

    // Floating contact
    try {
      this.systems.floatingContact = new FloatingContactSystem();
    } catch (e) {}

    // Login buttons
    [DOM.loginBtnDesktop, DOM.loginBtnMobile].forEach((btn) => {
      btn?.addEventListener("click", () => window.authSystem?.open());
    });
  }

  setupEventListeners() {
    let rt;
    let adminAccessPending = false;
    state.addListener(DOM.adminAccessBtn, "click", async (event) => {
      event.preventDefault();
      if (adminAccessPending) return;

      const trigger = event.currentTarget;
      const hadAriaBusy = trigger.hasAttribute("aria-busy");
      const previousAriaBusy = trigger.getAttribute("aria-busy");
      adminAccessPending = true;
      trigger.setAttribute("aria-busy", "true");

      try {
        const adminPanel = await ensureAdminPanel();
        adminPanel.openLoginModal(trigger);
      } catch {
        window.notificationSystem?.error("بارگذاری بخش مدیریت ناموفق بود؛ دوباره تلاش کنید.");
      } finally {
        if (hadAriaBusy) trigger.setAttribute("aria-busy", previousAriaBusy ?? "");
        else trigger.removeAttribute("aria-busy");
        adminAccessPending = false;
      }
    });

    const rh = () => {
      clearTimeout(rt);
      rt = setTimeout(() => this.handleResize(), 250);
    };
    state.addListener(window, "resize", rh);
    state.addListener(window, "orientationchange", () =>
      setTimeout(() => this.handleResize(), 100),
    );
  }

  handleResize() {
    if (window.innerWidth > 992 && state.isMobileMenuOpen)
      this.systems.mobileMenu?.closeMobileMenu();
  }

  finishInitialLoad() {
    const screen = DOM.loadingScreen;
    if (!screen) return;
    screen.dataset.state = "success";
    screen.setAttribute("aria-busy", "false");
    screen.setAttribute("aria-hidden", "true");
    screen.classList.add("hidden");
  }

  animatePublicContent() {
    const hf = document.querySelector(".hero-features");
    if (hf && !CONFIG.isReducedMotion) {
      const items = hf.querySelectorAll(".feature-item");
      hf.classList.add("is-revealing");
      items.forEach((item, i) => {
        setTimeout(() => item.classList.add("is-visible"), i * 100);
      });
    }
    setTimeout(() => {
      window.notificationSystem?.success("به MR NEON خوش آمدید! 🚀", 3000);
    }, 1000);
  }

  emergencyReset() {
    const screen = DOM.loadingScreen;
    if (screen) {
      screen.classList.remove("hidden");
      screen.dataset.state = "error";
      screen.setAttribute("aria-busy", "false");
      screen.setAttribute("aria-hidden", "false");
      screen.querySelector(".neon-loader")?.setAttribute("hidden", "");
      screen.querySelector("#loadingStatus")?.setAttribute("hidden", "");
      const error = screen.querySelector("#loadingError");
      if (error) error.hidden = false;
      const retry = screen.querySelector("#loadingRetryBtn");
      if (retry && retry.dataset.reloadBound !== "true") {
        retry.dataset.reloadBound = "true";
        retry.addEventListener("click", () => window.location.reload(), { once: true });
      }
      retry?.focus({ preventScroll: true });
    }
    syncPublicScrollLock();
  }

  destroy() {
    Object.values(this.systems).forEach((s) => {
      if (s && typeof s.destroy === "function") s.destroy();
    });
    state.destroy();
  }
}

let app;
document.addEventListener("DOMContentLoaded", () => {
  app = new MRNeonApp();
  app.init();
  window.MRNeonApp = app;
});
window.addEventListener("unload", () => {
  if (app) app.destroy();
});
