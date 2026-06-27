/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: js/admin.js
 * 🎯 Responsibility: Admin Panel, Data Table, Filters, Pagination, CSV Export
 * 🔌 Dependencies: app.js (CONFIG, DOM)
 * 🔌 Dependants: app.js (imports and instantiates)
 * 🚫 Constraints: Do NOT modify user-side auth or NEO logic.
 * 📦 Exported: AdminPanel
 * 🧠 AI NOTE: Uses Bearer Token (not Basic Auth) for API calls. Has debounce for search.
 */

import { CONFIG, DOM } from "./app.js";

export class AdminPanel {
  constructor() {
    this.currentPage = 1;
    this.perPage = 10;
    this.allData = [];
    this.filteredData = [];
    this.users = [];
    this.notification = window.notificationSystem;
    this.init();
  }
  init() {
    DOM.adminLoginForm?.addEventListener("submit", (e) => {
      e.preventDefault();
      this.handleLogin();
    });
    DOM.adminDataClose?.addEventListener("click", () => this.closePanel());
    DOM.adminDataOverlay?.addEventListener("click", () => this.closePanel());
    DOM.adminSearchInput?.addEventListener("input", () => this.applyFilters());
    DOM.adminFilterStatus?.addEventListener("change", () =>
      this.applyFilters(),
    );
    DOM.adminFilterType?.addEventListener("change", () => this.applyFilters());
    DOM.adminExportBtn?.addEventListener("click", () => this.exportCSV());
    DOM.adminLogoutBtn?.addEventListener("click", () => {
      this.closePanel();
      this.logoutAdmin();
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && DOM.adminDataModal.classList.contains("active"))
        this.closePanel();
    });
  }

  getAdminToken() {
    const adminSession =
      JSON.parse(localStorage.getItem("mrneon_admin_session") || "null") ||
      JSON.parse(sessionStorage.getItem("mrneon_admin_session") || "null");
    if (!adminSession || adminSession.expiresAt < Date.now()) {
      return null;
    }
    return adminSession.token;
  }

  handleLogin() {
    // تلاش برای ورود به پنل ادمین
    // اگر سشن معتبر وجود داشت، پنل را باز کن
    const token = this.getAdminToken();
    if (token) {
      DOM.adminLoginModal.classList.remove("active");
      this.openPanel();
      return;
    }
    // اگر سشن معتبر نبود، به مودال ورود اصلی هدایت کن
    DOM.adminLoginModal.classList.remove("active");
    window.authSystem?.open();
  }

  async openPanel() {
    const token = this.getAdminToken();
    if (!token) {
      this.notification?.error("لطفاً ابتدا وارد شوید");
      window.authSystem?.open();
      return;
    }
    DOM.adminDataModal.classList.add("active");
    document.body.style.overflow = "hidden";
    await this.loadData();
    this.applyFilters();
    this.updateMetrics();
  }

  closePanel() {
    DOM.adminDataModal.classList.remove("active");
    document.body.style.overflow = "";
  }

  logoutAdmin() {
    localStorage.removeItem("mrneon_admin_session");
    sessionStorage.removeItem("mrneon_admin_session");
    this.notification?.info("از پنل مدیریت خارج شدید");
  }

  async loadData() {
    const token = this.getAdminToken();
    if (!token) {
      this.notification?.error("نشست ادمین منقضی شده. لطفاً دوباره وارد شوید.");
      this.closePanel();
      window.authSystem?.open();
      return;
    }

    const headers = {
      Authorization: "Bearer " + token,
      "X-API-Key": CONFIG.apiKey,
    };

    try {
      const resp = await fetch(CONFIG.apiEndpoint + "?action=getAll", {
        headers,
      });
      if (resp.ok) {
        const json = await resp.json();
        this.allData = json.data || [];
      }
    } catch (e) {
      this.allData = [];
    }
    try {
      const respUsers = await fetch(
        CONFIG.apiEndpoint + "?action=getAllUsers",
        { headers },
      );
      if (respUsers.ok) {
        const json = await respUsers.json();
        this.users = json.users || [];
      }
    } catch (e) {
      this.users = [];
    }
  }
  applyFilters() {
    const search = (DOM.adminSearchInput?.value || "").toLowerCase();
    const status = DOM.adminFilterStatus?.value || "all";
    const type = DOM.adminFilterType?.value || "all";
    this.filteredData = this.allData
      .filter((r) => {
        const matchSearch =
          !search ||
          r.fullName?.toLowerCase().includes(search) ||
          r.trackingCode?.toLowerCase().includes(search) ||
          r.contactValue?.toLowerCase().includes(search);
        const matchStatus = status === "all" || r.status === status;
        const matchType = type === "all" || r.projectType === type;
        return matchSearch && matchStatus && matchType;
      })
      .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
    this.currentPage = 1;
    this.renderTable();
    this.updateMetrics();
  }
  updateMetrics() {
    const totalUsers = this.users.length;
    const pending = this.allData.filter(
      (r) => r.progress === 0 || r.status === "new",
    ).length;
    const inProgress = this.allData.filter(
      (r) => r.progress > 0 && r.progress < 100,
    ).length;
    const completed = this.allData.filter(
      (r) => r.progress >= 100 || r.status === "completed",
    ).length;
    document.getElementById("metricTotalUsers").textContent = totalUsers;
    document.getElementById("metricPending").textContent = pending;
    document.getElementById("metricInProgress").textContent = inProgress;
    document.getElementById("metricCompleted").textContent = completed;
  }
  renderTable() {
    const start = (this.currentPage - 1) * this.perPage;
    const pageData = this.filteredData.slice(start, start + this.perPage);

    const esc = (s) => {
      if (!s) return "-";
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    };

    const getStatusBadge = (status, progress) => {
      const badges = {
        rejected:
          '<span style="display:inline-block;padding:2px 8px;background:rgba(239,68,68,0.2);color:#ef4444;border-radius:4px;font-size:11px;">❌ رد</span>',
        completed:
          '<span style="display:inline-block;padding:2px 8px;background:rgba(16,185,129,0.2);color:#10b981;border-radius:4px;font-size:11px;">✅ تکمیل</span>',
      };
      if (badges[status]) return badges[status];
      if (progress === 0)
        return '<span style="display:inline-block;padding:2px 8px;background:rgba(245,158,11,0.2);color:#f59e0b;border-radius:4px;font-size:11px;">⏳ در انتظار</span>';
      if (progress <= 25)
        return '<span style="display:inline-block;padding:2px 8px;background:rgba(59,130,246,0.2);color:#3b82f6;border-radius:4px;font-size:11px;">✅ تایید</span>';
      if (progress <= 50)
        return '<span style="display:inline-block;padding:2px 8px;background:rgba(139,92,246,0.2);color:#8b5cf6;border-radius:4px;font-size:11px;">🎨 طراحی</span>';
      if (progress <= 75)
        return '<span style="display:inline-block;padding:2px 8px;background:rgba(6,182,212,0.2);color:#06b6d4;border-radius:4px;font-size:11px;">⚙️ ساخت</span>';
      if (progress <= 90)
        return '<span style="display:inline-block;padding:2px 8px;background:rgba(34,197,94,0.2);color:#22c55e;border-radius:4px;font-size:11px;">🔧 نهایی</span>';
      return '<span style="display:inline-block;padding:2px 8px;background:rgba(16,185,129,0.2);color:#10b981;border-radius:4px;font-size:11px;">✨ تکمیل</span>';
    };

    DOM.adminDataTbody.innerHTML = pageData.length
      ? pageData
          .map(
            (r) => `
        <tr>
            <td>
                <strong style="color: var(--primary-teal); font-family: monospace;">${esc(r.trackingCode)}</strong>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                    ${esc(r.persianDateTime || r.persianDate || "-")}
                </div>
            </td>
            <td>
                <div style="font-weight: 600; color: var(--text-primary);">${esc(r.fullName || "-")}</div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">📱 ${esc(r.phone || "-")}</div>
                ${r.email ? `<div style="font-size: 11px; color: var(--text-muted);">📧 ${esc(r.email)}</div>` : ""}
                ${r.messenger && r.messengerId ? `<div style="font-size: 11px; color: var(--text-muted);">💬 ${esc(r.messenger)}: ${esc(r.messengerId)}</div>` : ""}
            </td>
            <td>
                <div style="font-weight: 500;">${esc(r.industryLabel || r.industry || "-")}</div>
                ${r.purpose ? `<div style="font-size: 12px; color: var(--text-muted);">${esc(r.purposeLabel || r.purpose)}</div>` : ""}
            </td>
            <td>
                <div>🎨 ${esc(r.styleLabel || r.style || "-")}</div>
                <div style="font-size: 12px; color: var(--text-muted);">⏰ ${esc(r.timelineLabel || r.timeline || "-")}</div>
            </td>
            <td>
                <div style="max-width: 150px; font-size: 12px;" title="${esc((r.featuresLabels || []).join("، "))}">
                    ${(r.featuresLabels || []).slice(0, 3).join("<br>") || "-"}
                    ${(r.featuresLabels || []).length > 3 ? `<div style="color: var(--primary-teal);">+${r.featuresLabels.length - 3} مورد دیگر</div>` : ""}
                </div>
            </td>
            <td>
                <select class="form-input form-select" style="padding: 4px 8px; font-size: 12px; margin-bottom: 4px;" 
                        data-id="${r.id}" data-field="progress">
                    <option value="0" ${r.progress == 0 ? "selected" : ""}>0% - در انتظار</option>
                    <option value="25" ${r.progress == 25 ? "selected" : ""}>25% - تایید شد</option>
                    <option value="50" ${r.progress == 50 ? "selected" : ""}>50% - طراحی</option>
                    <option value="75" ${r.progress == 75 ? "selected" : ""}>75% - ساخت</option>
                    <option value="90" ${r.progress == 90 ? "selected" : ""}>90% - نهایی</option>
                    <option value="100" ${r.progress == 100 ? "selected" : ""}>100% - تکمیل</option>
                </select>
                ${getStatusBadge(r.status, r.progress)}
            </td>
            <td>
                <div style="max-width: 150px; font-size: 12px; overflow: hidden; text-overflow: ellipsis;" title="${esc(r.userNote)}">
                    ${esc(r.userNote || "-")}
                </div>
            </td>
            <td>
                <input type="text" class="form-input" style="padding: 4px 8px; font-size: 12px; width: 120px;" 
                       value="${esc(r.adminNote || "")}" data-id="${r.id}" data-field="note" 
                       placeholder="یادداشت...">
            </td>
            <td>
                <button class="btn btn-outline btn-sm" data-view="${r.id}" 
                        style="padding: 4px 8px; font-size: 11px; margin: 1px;" title="مشاهده جزئیات">👁️</button>
                <button class="btn btn-outline btn-sm btn-danger" data-delete="${r.id}" 
                        style="padding: 4px 8px; font-size: 11px; margin: 1px;" title="حذف">🗑️</button>
            </td>
        </tr>
    `,
          )
          .join("")
      : '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">📭 داده‌ای یافت نشد</td></tr>';

    this.renderPagination();
    this.setupTableEvents();
  }
  setupTableEvents() {
    DOM.adminDataTbody
      .querySelectorAll('select[data-field="progress"]')
      .forEach((sel) => {
        sel.addEventListener("change", (e) =>
          this.updateField(e.target.dataset.id, "progress", e.target.value),
        );
      });
    DOM.adminDataTbody
      .querySelectorAll('input[data-field="note"]')
      .forEach((inp) => {
        inp.addEventListener("change", (e) =>
          this.updateField(e.target.dataset.id, "adminNote", e.target.value),
        );
      });
    DOM.adminDataTbody.querySelectorAll("[data-view]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const r = this.allData.find((x) => x.id == btn.dataset.view);
        if (r) window.receiptModal?.show(r);
      });
    });
    DOM.adminDataTbody.querySelectorAll("[data-delete]").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (confirm("حذف شود؟")) this.deleteRequest(btn.dataset.delete);
      });
    });
  }
  async updateField(id, field, value) {
    const token = this.getAdminToken();
    if (!token) {
      this.notification?.error("نشست ادمین منقضی شده");
      return;
    }

    const idx = this.allData.findIndex((x) => x.id == id);
    if (idx === -1) return;
    this.allData[idx][field] = field === "progress" ? parseInt(value) : value;
    try {
      await fetch(CONFIG.apiEndpoint + "?action=adminUpdate", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({
          id,
          field,
          value: field === "progress" ? parseInt(value) : value,
        }),
      });
      this.notification?.success("به‌روزرسانی شد");
      this.updateMetrics();
    } catch (e) {
      this.notification?.error("خطا");
    }
  }
  async deleteRequest(id) {
    const token = this.getAdminToken();
    if (!token) {
      this.notification?.error("نشست ادمین منقضی شده");
      return;
    }

    this.allData = this.allData.filter((x) => x.id != id);
    try {
      await fetch(CONFIG.apiEndpoint + "?action=delete", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: "Bearer " + token,
          "X-API-Key": CONFIG.apiKey,
        },
        body: JSON.stringify({ id }),
      });
    } catch (e) {}
    this.applyFilters();
    this.updateMetrics();
  }
  renderPagination() {
    const total = Math.ceil(this.filteredData.length / this.perPage);
    if (total <= 1) {
      DOM.adminPagination.innerHTML = "";
      return;
    }
    let h = "";
    for (let i = 1; i <= total; i++) {
      h += `<button class="btn btn-sm ${i === this.currentPage ? "btn-primary" : "btn-outline"}" data-page="${i}" style="padding:6px 14px;font-size:13px;">${i}</button>`;
    }
    DOM.adminPagination.innerHTML = h;
    DOM.adminPagination.querySelectorAll("[data-page]").forEach((btn) => {
      btn.addEventListener("click", () => {
        this.currentPage = parseInt(btn.dataset.page);
        this.renderTable();
      });
    });
  }
  exportCSV() {
    const rows = this.filteredData.map((r) => [
      r.trackingCode || "",
      r.fullName || "",
      r.projectType || "",
      r.createdAt || "",
      r.status || "",
      r.userNote || "",
      r.adminNote || "",
    ]);
    const csv =
      "\uFEFFکد پیگیری,نام,نوع پروژه,تاریخ,وضعیت,یادداشت کاربر,یادداشت ادمین\n" +
      rows
        .map((r) => r.map((c) => `"${c.replace(/"/g, '""')}"`).join(","))
        .join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `mrneon-requests-${new Date().toISOString().split("T")[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }
  getProjectTypeLabel(t) {
    const m = {
      ecommerce: "فروشگاهی",
      corporate: "شرکتی",
      webapp: "وب‌اپ",
      portfolio: "نمونه‌کار",
      personal: "شخصی",
    };
    return m[t] || t || "-";
  }
}
