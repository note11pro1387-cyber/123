/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/app.js
 * 🎯 Responsibility: Main Orchestrator + Public UI Systems (Particles, Scroll, Portfolio, etc.)
 * 🔌 Dependencies: auth.js, neo.js, admin.js (imports classes)
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
  apiKey: "MR_13871390",
  adminCredentials: { username: "MR_NEON", password: "MR_13871390" },
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
    modalTitle: g("#modalTitle"),
    copyButtons: g(".copy-btn", true),
    telegramBtn: g("#telegramBtn"),
    loadingScreen: g("#loadingScreen"),
    particlesContainer: g("#particles"),
    marqueeTrack: g("#marqueeTrack"),
    toastContainer: g("#toastContainer"),
    floatingContact: g("#floatingContact"),
    floatingContactBtn: g("#floatingContactBtn"),
    contactPanel: g("#contactPanel"),
    loginBtnDesktop: g("#loginBtnDesktop"),
    loginBtnMobile: g("#loginBtnMobile"),
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

// ============================================================
// 📦 پروژه‌های نمونه
// ============================================================
export const PROJECTS = {
  1: {
    id: 1,
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
    images: [
      "assets/images/project-1-1.jpg",
      "assets/images/project-1-2.jpg",
      "assets/images/project-1-3.jpg",
    ],
  },
  2: {
    id: 2,
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
    images: [
      "assets/images/project-2-1.jpg",
      "assets/images/project-2-2.jpg",
      "assets/images/project-2-3.jpg",
    ],
  },
  3: {
    id: 3,
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
    images: [
      "assets/images/project-3-1.jpg",
      "assets/images/project-3-2.jpg",
      "assets/images/project-3-3.jpg",
    ],
  },
  4: {
    id: 4,
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
    images: [
      "assets/images/project-4-1.jpg",
      "assets/images/project-4-2.jpg",
      "assets/images/project-4-3.jpg",
    ],
  },
  5: {
    id: 5,
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
    images: [
      "assets/images/project-5-1.jpg",
      "assets/images/project-5-2.jpg",
      "assets/images/project-5-3.jpg",
    ],
  },
};

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
    t.className = `toast ${type}`;
    const icons = { success: "✓", error: "✕", warning: "⚠", info: "ℹ" };
    const titles = {
      success: "موفق",
      error: "خطا",
      warning: "توجه",
      info: "اطلاعات",
    };
    t.innerHTML = `<div class="toast-icon-wrap"><div class="toast-icon">${icons[type]}</div></div><div class="toast-content"><div class="toast-title">${titles[type]}</div><div class="toast-message">${sanitizeInput(msg)}</div></div><button class="toast-close" aria-label="بستن"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button><div class="toast-progress"></div>`;
    this.container.appendChild(t);
    this.toasts.push(t);
    requestAnimationFrame(() => {
      t.classList.add("show");
    });
    const cb = t.querySelector(".toast-close");
    let autoT;
    cb.addEventListener("click", () => this.remove(t));
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
    t.style.transform = "translateX(120%) scale(0.8)";
    t.style.opacity = "0";
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
    p.style.cssText = `position:absolute;width:${s}px;height:${s}px;background:linear-gradient(45deg,var(--neon),var(--primary-teal));border-radius:50%;left:${x}%;top:${y}%;opacity:${Math.random() * 0.4 + 0.1};filter:blur(1px);pointer-events:none;will-change:transform,opacity;`;
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
    this.escapeHandler = null;
  }
  init() {
    if (!this.grid) return;
    this.setupFilters();
    this.setupViewMore();
    this.setupProjectDetails();
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
    const cards = this.grid.querySelectorAll(".portfolio-card:not(.view-more)");
    cards.forEach((card) => {
      const cat = card.dataset.category;
      if (filter === "all" || cat === filter) {
        card.style.display = "block";
        requestAnimationFrame(() => {
          card.style.opacity = "1";
          card.style.transform = "scale(1)";
        });
      } else {
        card.style.opacity = "0";
        card.style.transform = "scale(0.8)";
        setTimeout(() => {
          card.style.display = "none";
        }, 300);
      }
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
    document.querySelectorAll(".view-details").forEach((btn) => {
      state.addListener(btn, "click", (e) => {
        e.stopPropagation();
        this.showProjectDetails(btn.dataset.project);
      });
    });
  }
  async showProjectDetails(pid) {
    const p = PROJECTS[pid];
    if (!p) return;
    state.isModalOpen = true;
    DOM.body.style.overflow = "hidden";
    const content = this.createModalContent(p);
    if (DOM.modalBody) {
      DOM.modalBody.innerHTML = content;
      if (DOM.modalTitle) DOM.modalTitle.textContent = p.title;
    }
    DOM.projectModal?.classList.add("active");
    const overlay = DOM.projectModal?.querySelector(".modal-overlay");
    const cont = DOM.projectModal?.querySelector(".modal-content");
    if (overlay) {
      overlay.style.transition = "opacity 0.3s ease";
      overlay.style.opacity = "0";
    }
    if (cont) {
      cont.style.transition = "all 0.3s ease";
      cont.style.opacity = "0";
      cont.style.transform = "translateY(50px) scale(0.95)";
    }
    requestAnimationFrame(() => {
      if (overlay) overlay.style.opacity = "1";
      if (cont) {
        cont.style.opacity = "1";
        cont.style.transform = "translateY(0) scale(1)";
      }
    });
    this.setupModalEvents();
    this.setupImageGallery();
  }
  createModalContent(p) {
    const esc = (s) => {
      const d = document.createElement("div");
      d.textContent = s;
      return d.innerHTML;
    };
    return `<div class="modal-header"><h3 class="modal-title">${esc(p.title)}</h3><span class="modal-category">${esc(p.categoryLabel)}</span></div><div class="modal-gallery"><div class="gallery-main"><img src="${p.images[0]}" alt="${esc(p.title)}" class="gallery-image active" onerror="this.onerror=null;this.src='assets/images/fallback.jpg'"></div><div class="gallery-thumbs">${p.images.map((img, i) => `<img src="${img}" alt="${esc(p.title)} - تصویر ${i + 1}" class="gallery-thumb${i === 0 ? " active" : ""}" data-index="${i}" onerror="this.onerror=null;this.style.display='none'">`).join("")}</div></div><div class="modal-details"><div class="detail-section"><h4 class="detail-title">توضیحات</h4><p class="detail-text">${esc(p.fullDescription)}</p></div><div class="detail-grid"><div class="detail-item"><h5 class="detail-label">تکنولوژی‌ها</h5><div class="tech-tags">${p.technologies.map((t) => `<span class="tech-tag">${esc(t)}</span>`).join("")}</div></div><div class="detail-item"><h5 class="detail-label">مدت زمان</h5><p class="detail-value">${esc(p.duration)}</p></div><div class="detail-item"><h5 class="detail-label">خدمات</h5><ul class="services-list">${p.services.map((s) => `<li>${esc(s)}</li>`).join("")}</ul></div></div><div class="modal-actions">${p.demoUrl ? `<a href="${p.demoUrl}" class="btn btn-primary" target="_blank" rel="noopener">مشاهده دمو</a>` : ""}<button class="btn btn-outline request-similar" data-project="${p.id}">درخواست پروژه مشابه</button></div></div>`;
  }
  setupModalEvents() {
    if (this.modalCloseHandler)
      DOM.modalClose?.removeEventListener("click", this.modalCloseHandler);
    this.modalCloseHandler = () => this.closeModal();
    state.addListener(DOM.modalClose, "click", this.modalCloseHandler);
    const overlay = DOM.projectModal?.querySelector(".modal-overlay");
    if (overlay) state.addListener(overlay, "click", () => this.closeModal());
    document.querySelectorAll(".request-similar").forEach((btn) => {
      state.addListener(btn, "click", () => {
        this.closeModal();
        setTimeout(() => {
          DOM.sections.contact?.scrollIntoView({ behavior: "smooth" });
        }, 400);
      });
    });
    if (this.escapeHandler)
      document.removeEventListener("keydown", this.escapeHandler);
    this.escapeHandler = (e) => {
      if (e.key === "Escape" && state.isModalOpen) this.closeModal();
    };
    document.addEventListener("keydown", this.escapeHandler);
  }
  closeModal() {
    state.isModalOpen = false;
    DOM.body.style.overflow = "";
    const overlay = DOM.projectModal?.querySelector(".modal-overlay");
    const cont = DOM.projectModal?.querySelector(".modal-content");
    if (overlay) overlay.style.opacity = "0";
    if (cont) {
      cont.style.transform = "translateY(50px) scale(0.95)";
      cont.style.opacity = "0";
    }
    setTimeout(() => {
      DOM.projectModal?.classList.remove("active");
      if (DOM.modalBody) DOM.modalBody.innerHTML = "";
    }, 300);
    if (this.escapeHandler) {
      document.removeEventListener("keydown", this.escapeHandler);
      this.escapeHandler = null;
    }
  }
  setupImageGallery() {
    document.querySelectorAll(".gallery-thumb").forEach((thumb) => {
      state.addListener(thumb, "click", () => {
        document
          .querySelectorAll(".gallery-thumb")
          .forEach((t) => t.classList.remove("active"));
        thumb.classList.add("active");
        const main = document.querySelector(".gallery-image");
        if (main) {
          main.style.opacity = "0";
          setTimeout(() => {
            main.src = thumb.src;
            main.alt = thumb.alt;
            main.style.opacity = "1";
          }, 200);
        }
      });
    });
  }
  addKeyboardSupport() {
    const cards = this.grid?.querySelectorAll(
      ".portfolio-card:not(.view-more)",
    );
    cards?.forEach((card) => {
      card.setAttribute("tabindex", "0");
      card.setAttribute("role", "button");
      state.addListener(card, "keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          const btn = card.querySelector(".view-details");
          if (btn) btn.click();
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
            btn.style.background = "rgba(16,185,129,0.2)";
            btn.style.borderColor = "#10b981";
            btn.style.color = "#10b981";
            setTimeout(() => {
              span.textContent = orig;
              btn.style.background = "";
              btn.style.borderColor = "";
              btn.style.color = "";
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
      ta.style.cssText = "position:fixed;left:-9999px;opacity:0;";
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
    DOM.body.style.overflow = "hidden";
    this.hamburger?.classList.add("active");
    this.hamburger?.setAttribute("aria-expanded", "true");
    this.navMenu?.classList.add("active");
    const items = this.navMenu?.querySelectorAll(".nav-item");
    if (items) {
      items.forEach((item, i) => {
        item.style.opacity = "0";
        item.style.transform = "translateX(20px)";
        setTimeout(
          () => {
            item.style.transition = "all 0.3s ease";
            item.style.opacity = "1";
            item.style.transform = "translateX(0)";
          },
          100 + i * 50,
        );
      });
    }
  }
  async closeMobileMenu() {
    state.isMobileMenuOpen = false;
    this.hamburger?.classList.remove("active");
    this.hamburger?.setAttribute("aria-expanded", "false");
    const items = this.navMenu?.querySelectorAll(".nav-item");
    if (items) {
      items.forEach((item, i) => {
        setTimeout(() => {
          item.style.opacity = "0";
          item.style.transform = "translateX(20px)";
        }, i * 30);
      });
    }
    setTimeout(() => {
      this.navMenu?.classList.remove("active");
      DOM.body.style.overflow = "";
      items?.forEach((item) => {
        item.style.transition = "";
        item.style.opacity = "";
        item.style.transform = "";
      });
    }, 300);
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
// 🏃 مارکویی تکنولوژی‌ها (بی‌نهایت)
// ============================================================
export class MarqueeSystem {
  constructor() {
    this.track = DOM.marqueeTrack;
    this.isPaused = false;
  }
  init() {
    if (!this.track || CONFIG.isReducedMotion) return;
    const originalContent = this.track.innerHTML;
    this.track.innerHTML = originalContent + originalContent;
    const singleWidth = this.track.scrollWidth / 2;
    const speed = singleWidth / 80;
    this.track.style.animation = `marquee-scroll ${speed}s linear infinite`;
    this.setupHoverEffect();
    this.setupVisibilityChange();
  }
  setupHoverEffect() {
    const items = this.track.querySelectorAll(".marquee-item");
    items.forEach((item) => {
      state.addListener(item, "mouseenter", () => {
        this.isPaused = true;
        this.track.style.animationPlayState = "paused";
      });
      state.addListener(item, "mouseleave", () => {
        this.isPaused = false;
        this.track.style.animationPlayState = "running";
      });
    });
  }
  setupVisibilityChange() {
    const h = () => {
      if (document.hidden) this.track.style.animationPlayState = "paused";
      else if (!this.isPaused) this.track.style.animationPlayState = "running";
    };
    document.addEventListener("visibilitychange", h);
  }
}

// ============================================================
// 🖼️ بارگذاری تنبل
// ============================================================
export class LazyLoadSystem {
  constructor() {
    this.images = document.querySelectorAll('img[loading="lazy"]');
    this.observer = null;
    this.init();
  }
  init() {
    if (!this.images.length) return;
    if ("IntersectionObserver" in window) {
      this.observer = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              this.loadImage(entry.target);
              this.observer.unobserve(entry.target);
            }
          });
        },
        { rootMargin: "100px 0px", threshold: 0.01 },
      );
      this.images.forEach((img) => {
        img.style.backgroundColor = "#f1f5f9";
        this.observer.observe(img);
      });
    } else {
      this.images.forEach((img) => this.loadImage(img));
    }
  }
  loadImage(img) {
    const src = img.getAttribute("src");
    if (!src || src.includes("data:image/svg+xml")) {
      img.classList.add("loaded");
      img.style.backgroundColor = "";
      return;
    }
    const temp = new Image();
    temp.onload = () => {
      img.classList.add("loaded");
      img.style.backgroundColor = "";
      img.style.transition = "opacity 0.3s ease";
      img.style.opacity = "0";
      requestAnimationFrame(() => {
        img.style.opacity = "1";
      });
    };
    temp.onerror = () => {
      img.onerror = null;
      img.src = "assets/images/fallback.jpg";
    };
    temp.src = src;
  }
  destroy() {
    if (this.observer) this.observer.disconnect();
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
      ta.style.cssText = "position:fixed;left:-9999px;opacity:0;";
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
        target: ".contact-cards-centered",
        title: "📞 تماس با ما",
        text: "از طریق این کارت‌ها با ما در ارتباط باشید.",
        position: "left",
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
    this.steps = [];
    this.active = false;
    this.seen = localStorage.getItem("mrneon_tour_seen_v7");
    this.overlay = DOM.tourOverlay;
    this.highlight = DOM.tourHighlight;
    this.tooltip = DOM.tourTooltip;
    this.stepCount = DOM.tourStepCount;
    this.tooltipTitle = DOM.tourTooltipTitle;
    this.tooltipText = DOM.tourTooltipText;
    this.prevBtn = DOM.tourPrevBtn;
    this.nextBtn = DOM.tourNextBtn;
    this.skipBtn = DOM.tourSkipBtn;
    this._keyHandler = this._handleKey.bind(this);
    this._backdropClick = this._handleBackdropClick.bind(this);
  }
  init() {
    if (!this.seen && !CONFIG.isReducedMotion) {
      setTimeout(() => this.start(), 1500);
    }
  }
  start() {
    if (this.active) return;
    this.active = true;
    this.current = 0;
    this.steps =
      window.innerWidth <= 768 ? this.mobileSteps : this.desktopSteps;
    this.overlay.classList.add("active");
    document.body.style.overflow = "hidden";
    this.nextBtn.addEventListener("click", () => this.next());
    this.prevBtn.addEventListener("click", () => this.prev());
    this.skipBtn.addEventListener("click", () => this.end());
    this.overlay.addEventListener("click", this._backdropClick);
    document.addEventListener("keydown", this._keyHandler);
    this._showStep(0);
  }
  _showStep(index) {
    if (!this.steps.length || index >= this.steps.length) {
      this.end();
      return;
    }
    const step = this.steps[index];
    const target = document.querySelector(step.target);
    if (!target) return;
    this.current = index;
    this.stepCount.textContent = `${index + 1} از ${this.steps.length}`;
    this.tooltipTitle.textContent = step.title;
    this.tooltipText.textContent = step.text;
    this.prevBtn.style.display = index === 0 ? "none" : "inline-flex";
    this.nextBtn.textContent =
      index === this.steps.length - 1 ? "تمام ✓" : "بعدی";
    target.scrollIntoView({ behavior: "smooth", block: "center" });
    setTimeout(() => {
      this._positionElements(target, step.position);
    }, 500);
  }
  _positionElements(target, position) {
    const rect = target.getBoundingClientRect();
    const highlight = this.highlight;
    const tooltip = this.tooltip;
    highlight.style.top = `${rect.top - 8}px`;
    highlight.style.left = `${rect.left - 8}px`;
    highlight.style.width = `${rect.width + 16}px`;
    highlight.style.height = `${rect.height + 16}px`;
    highlight.style.display = "block";
    tooltip.style.display = "block";
    void tooltip.offsetHeight;
    const tooltipRect = tooltip.getBoundingClientRect();
    const tipWidth = tooltipRect.width || 360;
    const tipHeight = tooltipRect.height || 200;
    const gap = 16;
    let top, left;
    switch (position) {
      case "bottom":
        top = rect.bottom + gap;
        left = rect.left + rect.width / 2 - tipWidth / 2;
        break;
      case "top":
        top = rect.top - tipHeight - gap;
        left = rect.left + rect.width / 2 - tipWidth / 2;
        break;
      case "left":
        top = rect.top + rect.height / 2 - tipHeight / 2;
        left = rect.left - tipWidth - gap;
        break;
      default:
        top = rect.bottom + gap;
        left = rect.left + rect.width / 2 - tipWidth / 2;
    }
    left = Math.max(10, Math.min(left, window.innerWidth - tipWidth - 10));
    top = Math.max(10, Math.min(top, window.innerHeight - tipHeight - 10));
    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
    tooltip.style.display = "block";
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
    this.active = false;
    this.overlay.classList.remove("active");
    document.body.style.overflow = "";
    this.highlight.style.display = "none";
    this.tooltip.style.display = "none";
    this.nextBtn.removeEventListener("click", this.next);
    this.prevBtn.removeEventListener("click", this.prev);
    this.skipBtn.removeEventListener("click", this.end);
    this.overlay.removeEventListener("click", this._backdropClick);
    document.removeEventListener("keydown", this._keyHandler);
    localStorage.setItem("mrneon_tour_seen_v7", "1");
  }
  _handleKey(e) {
    if (!this.active) return;
    if (e.key === "Escape") {
      e.preventDefault();
      this.end();
    } else if (e.key === "ArrowRight") {
      e.preventDefault();
      this.next();
    } else if (e.key === "ArrowLeft") {
      e.preventDefault();
      this.prev();
    }
    if (e.key === "Tab") e.preventDefault();
  }
  _handleBackdropClick(e) {
    if (e.target === this.overlay) e.stopPropagation();
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
import { AdminPanel } from "./admin.js";

export default class MRNeonApp {
  constructor() {
    this.systems = {};
  }
  async init() {
    try {
      await this.initializeSystems();
      this.setupEventListeners();
      await this.animateOnLoad();
    } catch (e) {
      this.emergencyReset();
    }
  }
  async initializeSystems() {
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
    await initSafe("marquee", MarqueeSystem);
    try {
      this.systems.lazyLoad = new LazyLoadSystem();
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

    // Admin panel
    window.adminPanel = new AdminPanel();

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

  async animateOnLoad() {
    const min = 800;
    const st = performance.now();
    const elapsed = performance.now() - st;
    const rem = Math.max(0, min - elapsed);
    await new Promise((r) => setTimeout(r, rem));
    if (DOM.loadingScreen) {
      DOM.loadingScreen.style.transition = "opacity 0.5s ease";
      DOM.loadingScreen.style.opacity = "0";
      await new Promise((r) => setTimeout(r, 500));
      DOM.loadingScreen.style.display = "none";
      DOM.loadingScreen.classList.add("hidden");
    }
    const hf = document.querySelector(".hero-features");
    if (hf) {
      const items = hf.querySelectorAll(".feature-item");
      items.forEach((item, i) => {
        setTimeout(() => {
          item.style.opacity = "0";
          item.style.transform = "translateY(20px)";
          item.style.transition = "all 0.4s ease";
          requestAnimationFrame(() => {
            item.style.opacity = "1";
            item.style.transform = "translateY(0)";
          });
        }, i * 100);
      });
    }
    setTimeout(() => {
      window.notificationSystem?.success("به MR NEON خوش آمدید! 🚀", 3000);
    }, 1000);
  }

  emergencyReset() {
    if (DOM.loadingScreen) {
      DOM.loadingScreen.style.display = "none";
      DOM.loadingScreen.classList.add("hidden");
    }
    DOM.body.style.overflow = "";
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
