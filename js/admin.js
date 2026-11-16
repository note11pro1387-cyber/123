/** MR NEON Admin Panel — DB-backed operations, no browser-held admin credentials. */
import { apiRequest, downloadApiFile, getServerSession } from "./api-client.js";

const byId = (id) => document.getElementById(id);
const ADMIN_DOM = {
  adminLoginForm: byId("adminLoginForm"),
  adminLoginModal: byId("adminLoginModal"),
  adminModalClose: byId("adminModalClose"),
  adminModalOverlay: byId("adminModalOverlay"),
  adminDataModal: byId("adminDataModal"),
  adminDataClose: byId("adminDataClose"),
  adminDataOverlay: byId("adminDataOverlay"),
  adminDataTbody: byId("adminDataTbody"),
  adminSearchInput: byId("adminSearchInput"),
  adminFilterStatus: byId("adminFilterStatus"),
  adminFilterType: byId("adminFilterType"),
  adminProjectSort: byId("adminProjectSort"),
  adminProjectSortDirection: byId("adminProjectSortDirection"),
  adminProjectOwnerFilter: byId("adminProjectOwnerFilter"),
  adminProjectDateFilter: byId("adminProjectDateFilter"),
  adminExportBtn: byId("adminExportBtn"),
  adminPagination: byId("adminPagination"),
  adminLogoutBtn: byId("adminLogoutBtn"),
  adminUserStatusReasonModal: byId("adminUserStatusReasonModal"),
  adminUserStatusReasonForm: byId("adminUserStatusReasonForm"),
  adminUserStatusReasonInput: byId("adminUserStatusReasonInput"),
  adminUserStatusReasonTitle: byId("adminUserStatusReasonTitle"),
  adminUserStatusReasonImpact: byId("adminUserStatusReasonImpact"),
  adminUserStatusReasonStatus: byId("adminUserStatusReasonStatus"),
  adminUserStatusReasonSubmit: byId("adminUserStatusReasonSubmit"),
};

const PROJECT_STATUSES = [
  ["submitted", "ثبت‌شده"],
  ["under_review", "در حال بررسی"],
  ["needs_information", "نیازمند اطلاعات"],
  ["in_progress", "در حال انجام"],
  ["waiting_for_client", "در انتظار مشتری"],
  ["completed", "تکمیل‌شده"],
  ["rejected", "ردشده"],
  ["cancelled", "لغوشده"],
];

const ACCOUNT_STATUSES = {
  active: "فعال",
  suspended: "تعلیق‌شده",
  pending: "در انتظار تأیید",
  closed: "بسته‌شده",
};

const PROJECT_TRANSITIONS = {
  submitted: ["under_review", "needs_information", "rejected", "cancelled"],
  under_review: ["needs_information", "in_progress", "waiting_for_client", "rejected", "cancelled"],
  needs_information: ["under_review", "in_progress", "waiting_for_client", "rejected", "cancelled"],
  in_progress: ["needs_information", "waiting_for_client", "completed", "rejected", "cancelled"],
  waiting_for_client: ["needs_information", "in_progress", "completed", "rejected", "cancelled"],
  completed: [], rejected: [], cancelled: [],
};

const html = (value) => String(value ?? "")
  .replace(/&/g, "&amp;")
  .replace(/</g, "&lt;")
  .replace(/>/g, "&gt;")
  .replace(/"/g, "&quot;")
  .replace(/'/g, "&#039;");

const fmtDate = (value) => {
  if (typeof value !== "string" || !value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat("fa-IR", { dateStyle: "medium", timeStyle: "short" }).format(date);
};

const fmtNumber = (value) => new Intl.NumberFormat("fa-IR").format(Number(value) || 0);
const newIdempotencyKey = () => {
  const bytes = new Uint8Array(24);
  if (!globalThis.crypto?.getRandomValues) throw new Error("ایجاد کلید امن برای ثبت پاسخ ممکن نشد.");
  globalThis.crypto.getRandomValues(bytes);
  return Array.from(bytes, (value) => value.toString(16).padStart(2, "0")).join("");
};
const sha256Hex = async (value) => {
  if (!globalThis.crypto?.subtle || typeof globalThis.TextEncoder !== "function") return null;
  const digest = await globalThis.crypto.subtle.digest("SHA-256", new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
};
// Keep exactly one pending reply operation per project. A changed message overwrites
// the prior pending fingerprint/key, so reverting to older text cannot resurrect its key.
const adminReplyPendingStorageKey = (projectId) => `mrneon.admin.reply.pending.v2.${projectId}`;

// Decorative table placeholders. All labels and shape names are static source values;
// no project/user data is fabricated or interpolated into these rows.
const ADMIN_TABLE_SKELETONS = Object.freeze({
  projects: Object.freeze([
    ["کد پیگیری", ["wide", "short"]],
    ["مشتری", ["wide", "medium"]],
    ["نوع پروژه", ["medium", "short"]],
    ["درخواست", ["medium", "short"]],
    ["وضعیت / پیشرفت", ["pill", "wide"]],
    ["توضیح مشتری", ["wide", "medium"]],
    ["یادداشت داخلی", ["wide", "short"]],
    ["عملیات", ["action", "action-short"]],
  ]),
  users: Object.freeze([
    ["کاربر", ["wide", "medium"]],
    ["اطلاعات تماس", ["wide", "medium"]],
    ["وضعیت حساب", ["pill"]],
    ["پروژه‌ها", ["medium"]],
    ["تاریخ / آخرین ورود", ["wide", "medium"]],
    ["عملیات", ["action", "action-short"]],
  ]),
  audit: Object.freeze([
    ["زمان (UTC)", ["wide", "short"]],
    ["عامل", ["medium"]],
    ["رویداد", ["wide", "medium"]],
    ["نوع موضوع", ["medium"]],
  ]),
  messages: Object.freeze([
    ["زمان", ["wide", "short"]],
    ["مشتری", ["wide", "medium"]],
    ["پروژه", ["wide", "medium"]],
    ["فرستنده", ["medium"]],
    ["متن پیام", ["wide", "wide", "medium"]],
    ["خواندن", ["pill"]],
    ["عملیات", ["action"]],
  ]),
});

const renderAdminTableSkeleton = (kind, rowCount = 3) => {
  const columns = ADMIN_TABLE_SKELETONS[kind];
  if (!columns || !Number.isSafeInteger(rowCount) || rowCount < 1 || rowCount > 5) return "";

  const cells = columns.map(([label, shapes]) => {
    const placeholders = shapes.map((shape) => {
      if (shape === "pill") return '<span class="admin-skeleton-shape admin-skeleton-pill"></span>';
      if (shape === "action" || shape === "action-short") {
        return `<span class="admin-skeleton-shape admin-skeleton-action${shape === "action-short" ? " admin-skeleton-action--short" : ""}"></span>`;
      }
      const allowedSize = ["wide", "medium", "short"].includes(shape) ? shape : "medium";
      return `<span class="admin-skeleton-shape admin-skeleton-line admin-skeleton-line--${allowedSize}"></span>`;
    }).join("");
    return `<td data-label="${html(label)}"><span class="admin-skeleton-stack">${placeholders}</span></td>`;
  }).join("");

  return Array.from({ length: rowCount }, () => `<tr class="admin-skeleton-row" aria-hidden="true">${cells}</tr>`).join("");
};

const createAdminUserDossierSkeleton = () => {
  const root = document.createElement("div");
  root.className = "admin-user-dossier-skeleton";
  root.setAttribute("aria-hidden", "true");
  root.dataset.adminUserDossierSkeleton = "true";

  const sections = [
    { layout: "facts", count: 6, lines: 2 },
    { layout: "rows", count: 2, lines: 3 },
    { layout: "rows", count: 2, lines: 3 },
    { layout: "rows", count: 2, lines: 3 },
    { layout: "rows", count: 2, lines: 3 },
    { layout: "rows", count: 2, lines: 2 },
  ];

  const makeShape = (modifier = "") => {
    const shape = document.createElement("span");
    shape.className = `admin-skeleton-shape admin-user-dossier-skeleton__shape${modifier ? ` ${modifier}` : ""}`;
    return shape;
  };

  for (const definition of sections) {
    const section = document.createElement("section");
    section.className = "admin-user-dossier-skeleton__section";
    section.append(makeShape("admin-user-dossier-skeleton__heading"));

    const content = document.createElement("div");
    content.className = definition.layout === "facts"
      ? "admin-user-dossier-skeleton__facts"
      : "admin-user-dossier-skeleton__rows";

    for (let index = 0; index < definition.count; index += 1) {
      const item = document.createElement("div");
      item.className = definition.layout === "facts"
        ? "admin-user-dossier-skeleton__fact"
        : "admin-user-dossier-skeleton__row";
      item.append(makeShape("admin-user-dossier-skeleton__label"));
      item.append(makeShape(index % 2 === 0
        ? "admin-user-dossier-skeleton__value"
        : "admin-user-dossier-skeleton__value admin-user-dossier-skeleton__value--short"));
      if (definition.lines > 2) item.append(makeShape("admin-user-dossier-skeleton__meta"));
      content.append(item);
    }

    section.append(content);
    root.append(section);
  }

  return root;
};

const PROJECT_STATUS_KEYS = new Set(PROJECT_STATUSES.map(([status]) => status));
const ACCOUNT_STATUS_KEYS = new Set(Object.keys(ACCOUNT_STATUSES));
const isPlainRecord = (value) => value !== null && typeof value === "object" && !Array.isArray(value);
const isPositiveSafeInteger = (value) => Number.isSafeInteger(value) && value > 0;
const isCanonicalPublicId = (value) => typeof value === "string" && /^[a-f0-9]{32}$/.test(value);
const isAdminPagination = (value) => isPlainRecord(value)
  && Number.isSafeInteger(value.page) && value.page >= 1
  && Number.isSafeInteger(value.perPage) && value.perPage >= 1
  && Number.isSafeInteger(value.total) && value.total >= 0
  && Number.isSafeInteger(value.pages) && value.pages >= 0
  && typeof value.hasMore === "boolean";
const isBoundedText = (value, maxCodePoints) => typeof value === "string"
  && Array.from(value).length <= maxCodePoints;
const isNullableUtcTimestamp = (value) => value === null
  || (typeof value === "string"
    && /^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d\.\d{6}Z$/.test(value)
    && Number.isFinite(Date.parse(value)));
const isAdminFixedPage = (value, expectedPage, expectedPerPage = 25) => isPlainRecord(value)
  && Number.isSafeInteger(expectedPage) && expectedPage >= 1 && expectedPage <= 1000000
  && value.page === expectedPage
  && value.perPage === expectedPerPage
  && Number.isSafeInteger(value.total) && value.total >= 0
  && Number.isSafeInteger(value.pages) && value.pages === Math.ceil(value.total / expectedPerPage)
  && typeof value.hasMore === "boolean"
  && value.hasMore === (expectedPage * expectedPerPage < value.total);
const ADMIN_MESSAGE_SENDER_KEYS = new Set(["user", "admin", "system"]);
const ADMIN_AUDIT_ACTOR_KEYS = new Set(["user", "admin", "system", "anonymous"]);
const ADMIN_AUDIT_SUBJECT_KEYS = new Set(["user", "project", "registration_attempt", "other", "none"]);
const isAdminMessageListItem = (item) => isPlainRecord(item)
  && isPositiveSafeInteger(item.projectId)
  && isBoundedText(item.trackingCode, 32)
  && isBoundedText(item.projectTitle, 180)
  && typeof item.projectStatus === "string" && PROJECT_STATUS_KEYS.has(item.projectStatus)
  && typeof item.projectArchived === "boolean"
  && isBoundedText(item.ownerName, 160)
  && isBoundedText(item.ownerUsername, 32)
  && typeof item.senderType === "string" && ADMIN_MESSAGE_SENDER_KEYS.has(item.senderType)
  && isBoundedText(item.body, 1200)
  && typeof item.bodyTruncated === "boolean"
  && isNullableUtcTimestamp(item.createdAt)
  && typeof item.read === "boolean";
const isAdminAuditEvent = (event) => isPlainRecord(event)
  && typeof event.actorType === "string" && ADMIN_AUDIT_ACTOR_KEYS.has(event.actorType)
  && typeof event.action === "string" && /^[a-z][a-z0-9_.-]{0,95}$/.test(event.action)
  && typeof event.subjectType === "string" && ADMIN_AUDIT_SUBJECT_KEYS.has(event.subjectType)
  && isNullableUtcTimestamp(event.createdAt);
const isAdminNotificationItem = (item) => isPlainRecord(item)
  && typeof item.id === "string" && /^[a-f0-9]{32}$/.test(item.id)
  && isBoundedText(item.category, 64)
  && isBoundedText(item.title, 180)
  && isBoundedText(item.message, 1000)
  && isNullableUtcTimestamp(item.timestamp)
  && typeof item.read === "boolean";
const isAdminProjectListItem = (project) => isPlainRecord(project)
  && isPositiveSafeInteger(project.id)
  && isCanonicalPublicId(project.publicId)
  && typeof project.status === "string" && PROJECT_STATUS_KEYS.has(project.status);
const isAdminUserListItem = (user) => isPlainRecord(user)
  && isPositiveSafeInteger(user.id)
  && isCanonicalPublicId(user.publicId)
  && typeof user.accountStatus === "string" && ACCOUNT_STATUS_KEYS.has(user.accountStatus);
const isRequiredUtcTimestamp = (value) => typeof value === "string" && isNullableUtcTimestamp(value);
const ADMIN_TEXT_ENCODER = new TextEncoder();
const isBoundedUtf8Text = (value, maxBytes, maxCodePoints = maxBytes) => typeof value === "string"
  && Array.from(value).length <= maxCodePoints
  && ADMIN_TEXT_ENCODER.encode(value).length <= maxBytes;
const isNullableBoundedText = (value, maxBytes, maxCodePoints = maxBytes) => value === null
  || isBoundedUtf8Text(value, maxBytes, maxCodePoints);
const isBoundedStringList = (value, maxItems, maxText = 120) => Array.isArray(value)
  && value.length <= maxItems
  && value.every((item) => isBoundedText(item, maxText));
const ADMIN_MILESTONE_STATES = new Set(["pending", "active", "completed"]);
const ADMIN_MESSAGE_LABELS = { user: "مشتری", admin: "مدیریت", system: "سامانه" };
const isAdminProjectDetail = (data, expectedId) => {
  if (!isPlainRecord(data) || !isPlainRecord(data.project) || !isPositiveSafeInteger(expectedId)) return false;
  const project = data.project;
  if (!isAdminProjectListItem(project) || project.id !== expectedId
    || !isBoundedText(project.trackingCode, 32) || !/^[A-Z0-9-]{1,32}$/.test(project.trackingCode)
    || !isBoundedText(project.title, 180) || !isBoundedText(project.projectType, 64)
    || !isBoundedText(project.statusLabel, 120)
    || !Number.isSafeInteger(project.progress) || project.progress < 0 || project.progress > 100
    || !isRequiredUtcTimestamp(project.createdAt) || !isRequiredUtcTimestamp(project.submittedAt)
    || !isRequiredUtcTimestamp(project.updatedAt) || !isNullableUtcTimestamp(project.archivedAt)
    || !isBoundedText(project.fullName, 160) || !isBoundedText(project.phone, 20)
    || !isBoundedText(project.email, 254) || !ACCOUNT_STATUS_KEYS.has(project.ownerAccountStatus)
    || ![project.industry, project.industryLabel, project.purpose, project.purposeLabel,
      project.style, project.styleLabel, project.timeline, project.timelineLabel,
      project.messenger, project.messengerLabel].every((item) => isBoundedText(item, 120))
    || !isBoundedText(project.messengerId, 120)
    || !isBoundedText(project.userNote, 2000)
    || !isBoundedUtf8Text(project.adminNote, 60000, 60000)
    || !(project.statusReason === null || isBoundedText(project.statusReason, 500))
    || typeof project.briefAvailable !== "boolean"
    || data.briefAvailable !== project.briefAvailable
    || !isBoundedStringList(project.paletteLabels, 32, 120)
    || !isBoundedStringList(project.featuresLabels, 64, 120)
    || !isPlainRecord(project.contentLabels)
    || !["logo", "text", "images"].every((key) => isBoundedText(project.contentLabels[key], 120))) return false;

  if (!isPlainRecord(data.owner)
    || !isBoundedText(data.owner.fullName, 160)
    || !isBoundedText(data.owner.username, 32)
    || !isBoundedText(data.owner.phone, 20)
    || !isBoundedText(data.owner.email, 254)
    || !ACCOUNT_STATUS_KEYS.has(data.owner.accountStatus)) return false;

  const briefIsEmpty = Array.isArray(data.brief) && data.brief.length === 0;
  if (!(isPlainRecord(data.brief) || briefIsEmpty)) return false;
  if (isPlainRecord(data.brief) && Object.prototype.hasOwnProperty.call(data.brief, "colors")) {
    const colors = data.brief.colors;
    if (!isPlainRecord(colors)) return false;
    if (Object.prototype.hasOwnProperty.call(colors, "customColors")) {
      if (!Array.isArray(colors.customColors) || colors.customColors.length > 4
        || !colors.customColors.every((color) => typeof color === "string" && /^#[0-9A-Fa-f]{6}$/.test(color))) return false;
    }
  }

  return Array.isArray(data.lifecycleHistory) && data.lifecycleHistory.length <= 100
    && data.lifecycleHistory.every((event) => isPlainRecord(event)
      && typeof event.type === "string" && /^[a-z][a-z0-9_.-]{0,63}$/.test(event.type)
      && ["user", "admin", "system"].includes(event.actorType)
      && (event.fromStatus === null || PROJECT_STATUS_KEYS.has(event.fromStatus))
      && (event.toStatus === null || PROJECT_STATUS_KEYS.has(event.toStatus))
      && isNullableBoundedText(event.fromStatusLabel, 120)
      && isNullableBoundedText(event.toStatusLabel, 120)
      && isRequiredUtcTimestamp(event.createdAt))
    && Array.isArray(data.customerMessages) && data.customerMessages.length <= 200
    && data.customerMessages.every((message) => isPlainRecord(message)
      && ADMIN_MESSAGE_SENDER_KEYS.has(message.senderType)
      && message.senderLabel === ADMIN_MESSAGE_LABELS[message.senderType]
      && isBoundedUtf8Text(message.body, 65535, 65535)
      && isRequiredUtcTimestamp(message.createdAt)
      && typeof message.read === "boolean")
    && Array.isArray(data.internalNotes) && data.internalNotes.length <= 100
    && data.internalNotes.every((note) => isPlainRecord(note)
      && isPositiveSafeInteger(note.id)
      && isBoundedText(note.adminId, 128)
      && isBoundedUtf8Text(note.body, 65535, 65535)
      && isRequiredUtcTimestamp(note.createdAt)
      && isRequiredUtcTimestamp(note.updatedAt))
    && Array.isArray(data.milestones) && data.milestones.length <= 100
    && data.milestones.every((milestone) => isPlainRecord(milestone)
      && isBoundedText(milestone.code, 32) && /^[a-z0-9_-]{1,32}$/.test(milestone.code)
      && isBoundedText(milestone.name, 120)
      && ADMIN_MILESTONE_STATES.has(milestone.status)
      && Number.isSafeInteger(milestone.weight) && milestone.weight >= 0 && milestone.weight <= 100
      && isNullableUtcTimestamp(milestone.startedAt)
      && isNullableUtcTimestamp(milestone.completedAt));
};
const isAdminUserDetail = (data, expectedPublicId) => {
  if (!isPlainRecord(data) || !isPlainRecord(data.user) || !isCanonicalPublicId(expectedPublicId)) return false;
  const user = data.user;
  if (user.publicId !== expectedPublicId
    || !isBoundedText(user.fullName, 160) || !isBoundedText(user.username, 32)
    || !isBoundedText(user.phone, 20) || !ACCOUNT_STATUS_KEYS.has(user.accountStatus)
    || !isRequiredUtcTimestamp(user.createdAt) || !isRequiredUtcTimestamp(user.updatedAt)
    || !isNullableUtcTimestamp(user.lastLoginAt)
    || !Number.isSafeInteger(user.projectCount) || user.projectCount < 0) return false;

  return Array.isArray(data.channels) && data.channels.every((channel) => isPlainRecord(channel)
      && ["email", "telegram", "unknown"].includes(channel.type)
      && isBoundedText(channel.value, 255)
      && typeof channel.verified === "boolean"
      && isNullableUtcTimestamp(channel.verifiedAt)
      && (!channel.verified || channel.verifiedAt !== null)
      && typeof channel.primary === "boolean")
    && Array.isArray(data.projects) && data.projects.length <= 50
    && data.projects.every((project) => isPlainRecord(project)
      && isCanonicalPublicId(project.publicId)
      && isBoundedText(project.trackingCode, 32) && /^[A-Z0-9-]{1,32}$/.test(project.trackingCode)
      && isBoundedText(project.title, 180) && isBoundedText(project.projectType, 64)
      && PROJECT_STATUS_KEYS.has(project.status) && isBoundedText(project.statusLabel, 120)
      && Number.isSafeInteger(project.progress) && project.progress >= 0 && project.progress <= 100
      && isRequiredUtcTimestamp(project.createdAt) && isRequiredUtcTimestamp(project.updatedAt)
      && typeof project.archived === "boolean")
    && isAdminFixedPage(data.projectsPagination, 1, 50)
    && data.projectsPagination.total >= data.projects.length
    && Array.isArray(data.messages) && data.messages.length <= 30
    && data.messages.every((message) => isPlainRecord(message)
      && isCanonicalPublicId(message.projectPublicId)
      && isBoundedText(message.trackingCode, 32)
      && isBoundedText(message.projectTitle, 180)
      && ADMIN_MESSAGE_SENDER_KEYS.has(message.senderType)
      && isBoundedUtf8Text(message.body, 4800, 1200)
      && isRequiredUtcTimestamp(message.createdAt)
      && typeof message.read === "boolean")
    && Array.isArray(data.sessions) && data.sessions.length <= 20
    && data.sessions.every((session) => isPlainRecord(session)
      && isRequiredUtcTimestamp(session.createdAt)
      && isRequiredUtcTimestamp(session.expiresAt)
      && isRequiredUtcTimestamp(session.lastActivityAt)
      && typeof session.revoked === "boolean"
      && isNullableUtcTimestamp(session.revokedAt)
      && session.revoked === (session.revokedAt !== null)
      && isNullableBoundedText(session.revokeReason, 64))
    && Array.isArray(data.securityEvents) && data.securityEvents.length <= 30
    && data.securityEvents.every((event) => isPlainRecord(event)
      && ["user", "admin", "system", "anonymous"].includes(event.actorType)
      && typeof event.action === "string" && /^[a-z][a-z0-9_.-]{0,95}$/.test(event.action)
      && isRequiredUtcTimestamp(event.createdAt));
};

export class AdminPanel {
  constructor() {
    this.perPage = 10;
    this.projectPage = 1;
    this.userPage = 1;
    this.projects = [];
    this.users = [];
    this.projectPagination = { page: 1, perPage: this.perPage, total: 0, pages: 0 };
    this.userPagination = { page: 1, perPage: this.perPage, total: 0, pages: 0 };
    this.adminNotifications = [];
    this.adminNotificationsLoaded = false;
    this.adminNotificationLoading = false;
    this.adminNotificationSessionGeneration = 0;
    this.adminMessagePage = 1;
    this.adminMessageRequestSeq = 0;
    this.adminMessageSearchTimer = null;
    this.adminMessages = [];
    this.adminMessagesLoaded = false;
    this.adminMessageLastQuery = null;
    this.adminMessagePagination = { page: 1, perPage: 25, total: 0, pages: 0 };
    this.adminMessageUnreadCount = 0;
    this.adminAuditPage = 1;
    this.adminAuditRequestSeq = 0;
    this.adminAuditSearchTimer = null;
    this.adminAuditEvents = [];
    this.adminAuditLoaded = false;
    this.adminAuditLastQuery = null;
    this.adminAuditPagination = { page: 1, perPage: 25, total: 0, pages: 0 };
    this.adminSettingsRequestSeq = 0;
    this.adminSettingsSnapshot = null;
    this.adminNotificationPage = 1;
    this.adminNotificationHasMore = false;
    this.adminNotificationUnreadCount = 0;
    this.adminNotificationRequestSeq = 0;
    this.adminNotificationRetryAppend = false;
    this.adminNotificationMarkingIds = new Set();
    this.projectRequestSeq = 0;
    this.userRequestSeq = 0;
    this.statsRequestSeq = 0;
    this.statsLoaded = false;
    this.searchTimer = null;
    this.userSearchTimer = null;
    this.currentDetailId = null;
    this.currentDetailRequestSeq = 0;
    this.currentUserDetailId = null;
    this.userDetailRequestSeq = 0;
    this.userDetailReturnFocus = null;
    this.userDetailBackgroundState = [];
    this.detailReturnFocus = null;
    this.detailBackgroundState = [];
    this.loginReturnFocus = null;
    this.loginFocusTimer = null;
    this.pendingUserStatusChange = null;
    this.userStatusReturnFocus = null;
    this.userStatusBackgroundState = null;
    this.panelReturnFocus = null;
    this.replySendingId = null;
    this.replyPendingOperations = new Map();
    this.noteSavingId = null;
    this.adminSessionExpiredHandled = false;
    this.localAdminSession = null;
    this.localSessionChecked = false;
    this.notification = window.notificationSystem;
    this.init();
  }

  init() {
    ADMIN_DOM.adminLoginForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      void this.handleLogin();
    });
    ADMIN_DOM.adminModalClose?.addEventListener("click", () => this.closeLoginModal());
    ADMIN_DOM.adminModalOverlay?.addEventListener("click", () => this.closeLoginModal());
    // The public shell owns the lazy Admin entry click; standalone Admin pages use admin-entry.js.
    ADMIN_DOM.adminDataClose?.addEventListener("click", () => {
      if (this.isStandaloneWorkspace()) window.location.assign("index.php");
      else this.closePanel();
    });
    ADMIN_DOM.adminDataOverlay?.addEventListener("click", () => this.closePanel());
    ADMIN_DOM.adminLogoutBtn?.addEventListener("click", () => void this.logoutAdmin(ADMIN_DOM.adminLogoutBtn));
    byId("adminMessagesLogoutBtn")?.addEventListener("click", () => void this.logoutAdmin(byId("adminMessagesLogoutBtn")));
    byId("adminAuditLogoutBtn")?.addEventListener("click", () => void this.logoutAdmin(byId("adminAuditLogoutBtn")));
    byId("adminSettingsLogoutBtn")?.addEventListener("click", () => void this.logoutAdmin(byId("adminSettingsLogoutBtn")));
    byId("adminSettingsRefreshBtn")?.addEventListener("click", () => void this.loadAdminSettings());
    byId("adminStatsRetryBtn")?.addEventListener("click", (event) => {
      if (event.currentTarget.getAttribute("aria-disabled") === "true") return;
      void this.loadStats();
    });
    byId("adminSettingsRetryBtn")?.addEventListener("click", () => void this.loadAdminSettings());
    byId("adminAuditRefreshBtn")?.addEventListener("click", () => void this.loadAdminAudit());
    byId("adminAuditRetryBtn")?.addEventListener("click", (event) => {
      if (event.currentTarget.getAttribute("aria-disabled") === "true") return;
      void this.loadAdminAudit();
    });
    byId("adminAuditSearch")?.addEventListener("input", () => {
      if (this.adminAuditSearchTimer !== null) window.clearTimeout(this.adminAuditSearchTimer);
      this.adminAuditSearchTimer = window.setTimeout(() => {
        this.adminAuditSearchTimer = null;
        this.adminAuditPage = 1;
        void this.loadAdminAudit();
      }, 300);
    });
    byId("adminAuditActor")?.addEventListener("change", () => {
      this.adminAuditPage = 1;
      void this.loadAdminAudit();
    });
    byId("adminAuditSubject")?.addEventListener("change", () => {
      this.adminAuditPage = 1;
      void this.loadAdminAudit();
    });
    byId("adminAuditPagination")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-audit-page]");
      if (!button || button.disabled) return;
      const page = Number(button.dataset.auditPage);
      const pages = Number(this.adminAuditPagination.pages);
      if (!Number.isSafeInteger(page) || page < 1 || page > pages || page === this.adminAuditPage) return;
      this.adminAuditPage = page;
      void this.loadAdminAudit();
    });
    ADMIN_DOM.adminExportBtn?.addEventListener("click", () => this.exportCSV());
    ADMIN_DOM.adminSearchInput?.addEventListener("input", () => {
      clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.projectPage = 1;
        void this.loadProjects();
      }, 250);
    });
    ADMIN_DOM.adminProjectOwnerFilter?.addEventListener("input", () => {
      clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.projectPage = 1;
        void this.loadProjects();
      }, 250);
    });
    ADMIN_DOM.adminProjectDateFilter?.addEventListener("change", () => {
      this.projectPage = 1;
      void this.loadProjects();
    });
    ADMIN_DOM.adminFilterStatus?.addEventListener("change", () => {
      this.projectPage = 1;
      void this.loadProjects();
    });
    ADMIN_DOM.adminFilterType?.addEventListener("change", () => {
      this.projectPage = 1;
      void this.loadProjects();
    });
    ADMIN_DOM.adminProjectSort?.addEventListener("change", () => {
      this.projectPage = 1;
      void this.loadProjects();
    });
    ADMIN_DOM.adminProjectSortDirection?.addEventListener("change", () => {
      this.projectPage = 1;
      void this.loadProjects();
    });
    document.getElementById("adminUserSearchInput")?.addEventListener("input", () => {
      clearTimeout(this.userSearchTimer);
      this.userSearchTimer = setTimeout(() => {
        this.userPage = 1;
        void this.loadUsers();
      }, 250);
    });
    document.getElementById("adminUserFilterStatus")?.addEventListener("change", () => {
      this.userPage = 1;
      void this.loadUsers();
    });
    document.getElementById("adminUserDateFilter")?.addEventListener("change", () => {
      this.userPage = 1;
      void this.loadUsers();
    });
    document.getElementById("adminUserSort")?.addEventListener("change", () => {
      this.userPage = 1;
      void this.loadUsers();
    });
    document.getElementById("adminUserSortDirection")?.addEventListener("change", () => {
      this.userPage = 1;
      void this.loadUsers();
    });
    document.querySelectorAll("[data-admin-tab]").forEach((button) => {
      button.addEventListener("click", () => void this.setTab(button.dataset.adminTab));
      button.addEventListener("keydown", (event) => this.handleTabKeydown(event, button));
    });
    ADMIN_DOM.adminDataTbody?.addEventListener("change", (event) => {
      const select = event.target.closest("select[data-project-status]");
      if (select) void this.updateProjectStatus(select);
    });
    ADMIN_DOM.adminDataTbody?.addEventListener("click", (event) => {
      const view = event.target.closest("[data-project-view]");
      if (view) void this.openProjectDetail(Number(view.dataset.projectView), view);
      const archive = event.target.closest("[data-project-archive]");
      if (archive) void this.archiveProject(Number(archive.dataset.projectArchive));
    });
    document.getElementById("adminUsersTbody")?.addEventListener("click", (event) => {
      const action = event.target.closest("[data-user-status]");
      if (action) this.updateUserStatus(Number(action.dataset.userId), action.dataset.userStatus, action);
    });
    document.getElementById("adminUserDetailClose")?.addEventListener("click", () => this.closeUserDetail());
    document.getElementById("adminUserDetailRetryBtn")?.addEventListener("click", (event) => {
      const retryButton = event.currentTarget;
      if (retryButton?.getAttribute("aria-disabled") === "true") return;
      const publicId = this.currentUserDetailId;
      if (publicId) void this.openUserDetail(publicId, null);
    });
    ADMIN_DOM.adminUserStatusReasonForm?.addEventListener("submit", (event) => void this.submitUserStatusReason(event));
    document.getElementById("adminUserStatusReasonClose")?.addEventListener("click", () => {
      if (!ADMIN_DOM.adminUserStatusReasonSubmit?.disabled) this.closeUserStatusReasonModal();
    });
    document.getElementById("adminUserStatusReasonCancel")?.addEventListener("click", () => {
      if (!ADMIN_DOM.adminUserStatusReasonSubmit?.disabled) this.closeUserStatusReasonModal();
    });
    document.getElementById("adminUserStatusReasonBackdrop")?.addEventListener("click", () => {
      if (!ADMIN_DOM.adminUserStatusReasonSubmit?.disabled) this.closeUserStatusReasonModal();
    });
    byId("adminMessagesRefreshBtn")?.addEventListener("click", () => void this.loadAdminMessages());
    byId("adminMessagesRetryBtn")?.addEventListener("click", (event) => {
      if (event.currentTarget?.getAttribute("aria-disabled") === "true") return;
      void this.loadAdminMessages();
    });
    byId("adminMessagesSearch")?.addEventListener("input", () => {
      if (this.adminMessageSearchTimer !== null) window.clearTimeout(this.adminMessageSearchTimer);
      this.adminMessageSearchTimer = window.setTimeout(() => {
        this.adminMessageSearchTimer = null;
        this.adminMessagePage = 1;
        void this.loadAdminMessages();
      }, 300);
    });
    byId("adminMessagesSender")?.addEventListener("change", () => {
      this.adminMessagePage = 1;
      void this.loadAdminMessages();
    });
    byId("adminMessagesRead")?.addEventListener("change", () => {
      this.adminMessagePage = 1;
      void this.loadAdminMessages();
    });
    byId("adminMessagesTbody")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-message-project]");
      if (!button) return;
      const projectId = Number(button.dataset.messageProject);
      if (Number.isSafeInteger(projectId) && projectId > 0) void this.openProjectDetail(projectId, button);
    });
    byId("adminMessagesPagination")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-messages-page]");
      if (!button || button.disabled) return;
      const page = Number(button.dataset.messagesPage);
      const pages = Number(this.adminMessagePagination.pages);
      if (!Number.isSafeInteger(page) || page < 1 || page > pages || page === this.adminMessagePage) return;
      this.adminMessagePage = page;
      void this.loadAdminMessages();
    });
    document.getElementById("adminNotificationsRetryBtn")?.addEventListener("click", (event) => {
      if (this.adminNotificationMarkingIds.size > 0
        || event.currentTarget?.getAttribute("aria-disabled") === "true") return;
      void this.loadAdminNotifications({ append: this.adminNotificationRetryAppend });
    });
    document.getElementById("loadMoreAdminNotificationsBtn")?.addEventListener("click", (event) => {
      if (!this.adminNotificationHasMore || this.adminNotificationMarkingIds.size > 0
        || event.currentTarget?.getAttribute("aria-disabled") === "true") return;
      void this.loadAdminNotifications({ append: true });
    });
    document.getElementById("adminNotificationsList")?.addEventListener("click", (event) => {
      const button = event.target.closest("[data-admin-notification-read]");
      if (!button || button.getAttribute("aria-disabled") === "true") return;
      void this.markAdminNotificationRead(button.dataset.notificationId, button);
    });
    document.getElementById("adminDetailClose")?.addEventListener("click", () => this.closeProjectDetail());
    document.getElementById("adminProjectDetailRetryBtn")?.addEventListener("click", () => {
      const id = this.currentDetailId;
      if (!Number.isInteger(id) || id < 1) return;
      const retryButton = document.getElementById("adminProjectDetailRetryBtn");
      void this.openProjectDetail(id, retryButton);
    });
    document.querySelector("[data-admin-detail-backdrop]")?.addEventListener("click", () => this.closeProjectDetail());
    document.getElementById("adminInternalNoteForm")?.addEventListener("submit", (event) => {
      event.preventDefault();
      void this.addInternalNote();
    });
    document.getElementById("adminProjectDetailBody")?.addEventListener("submit", (event) => {
      const form = event.target.closest("#adminProjectReplyForm");
      if (!form) return;
      event.preventDefault();
      void this.sendProjectReply(form);
    });
    document.addEventListener("keydown", (event) => this.handleDocumentKeydown(event));
  }

  isStandaloneWorkspace() {
    return ["admin-workspace", "admin-user-detail", "admin-messages", "admin-audit", "admin-settings"].includes(document.body?.dataset?.page || "");
  }

  isUserDetailRoute() {
    return document.body?.dataset?.page === "admin-user-detail";
  }

  isAdminMessagesRoute() {
    return document.body?.dataset?.page === "admin-messages";
  }

  isAdminAuditRoute() {
    return document.body?.dataset?.page === "admin-audit";
  }

  isAdminSettingsRoute() {
    return document.body?.dataset?.page === "admin-settings";
  }

  handleTabKeydown(event, currentButton) {
    const keys = ["ArrowLeft", "ArrowRight", "Home", "End"];
    if (!keys.includes(event.key)) return;
    const tablist = currentButton.closest('[role="tablist"]');
    const buttons = [...(tablist?.querySelectorAll('[role="tab"]') || [])].filter((button) => !button.disabled && !button.hidden);
    if (buttons.length < 2) return;
    const index = buttons.indexOf(currentButton);
    const rtl = getComputedStyle(tablist).direction === "rtl";
    let targetIndex = index;
    if (event.key === "Home") targetIndex = 0;
    else if (event.key === "End") targetIndex = buttons.length - 1;
    else if (event.key === "ArrowRight") targetIndex = (index + (rtl ? -1 : 1) + buttons.length) % buttons.length;
    else if (event.key === "ArrowLeft") targetIndex = (index + (rtl ? 1 : -1) + buttons.length) % buttons.length;
    event.preventDefault();
    const target = buttons[targetIndex];
    void this.setTab(target.dataset.adminTab);
    target.focus();
  }

  getFocusableElements(container) {
    if (!container) return [];
    return [...container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
      .filter((element) => element.getClientRects().length > 0
        && !element.closest('[hidden], [inert], [aria-hidden="true"]')
        && element.getAttribute("aria-disabled") !== "true");
  }

  trapDialogFocus(event, dialog) {
    if (event.key !== "Tab" || !dialog) return;
    const focusable = this.getFocusableElements(dialog);
    if (!focusable.length) {
      event.preventDefault();
      dialog.focus?.();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || !focusable.includes(active))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || !focusable.includes(active))) {
      event.preventDefault();
      first.focus();
    }
  }

  handleDocumentKeydown(event) {
    const userStatusModal = ADMIN_DOM.adminUserStatusReasonModal;
    const userStatusOpen = Boolean(userStatusModal && !userStatusModal.hidden);
    const userDetailModal = document.getElementById("adminUserDetailModal");
    const userDetailOpen = Boolean(userDetailModal && !userDetailModal.hidden);
    const detailModal = document.getElementById("adminProjectDetailModal");
    const detailOpen = Boolean(detailModal && !detailModal.hidden);
    const loginOpen = Boolean(ADMIN_DOM.adminLoginModal?.classList.contains("active"));
    if (event.key === "Escape") {
      if (userStatusOpen) {
        event.preventDefault();
        if (!ADMIN_DOM.adminUserStatusReasonSubmit?.disabled) this.closeUserStatusReasonModal();
      } else if (userDetailOpen) {
        event.preventDefault();
        this.closeUserDetail();
      } else if (detailOpen) {
        event.preventDefault();
        this.closeProjectDetail();
      } else if (loginOpen) {
        event.preventDefault();
        this.closeLoginModal();
      } else if (!this.isStandaloneWorkspace() && ADMIN_DOM.adminDataModal?.classList.contains("active")) {
        event.preventDefault();
        this.closePanel();
      }
      return;
    }
    if (event.key !== "Tab") return;
    if (userStatusOpen) {
      this.trapDialogFocus(event, userStatusModal.querySelector('[role="dialog"]'));
      return;
    }
    if (userDetailOpen) {
      if (!this.isUserDetailRoute()) this.trapDialogFocus(event, userDetailModal);
      return;
    }
    if (detailOpen) {
      this.trapDialogFocus(event, detailModal.querySelector('[role="dialog"]'));
      return;
    }
    if (loginOpen) {
      this.trapDialogFocus(event, ADMIN_DOM.adminLoginModal);
      return;
    }
    if (!this.isStandaloneWorkspace() && ADMIN_DOM.adminDataModal?.classList.contains("active")) {
      this.trapDialogFocus(event, ADMIN_DOM.adminDataModal);
    }
  }

  applyServerSession(payload) {
    const authClass = window.authSystem?.constructor;
    if (typeof authClass?.applyServerSession === "function") {
      const applied = authClass.applyServerSession(payload);
      this.localAdminSession = applied?.type === "admin" ? applied : null;
      this.localSessionChecked = true;
      return applied;
    }
    if (payload?.authenticated === true && payload?.principalType === "admin" && payload?.admin?.role === "admin") {
      this.localAdminSession = {
        type: "admin",
        data: { username: payload.admin.username, role: "admin" },
        expiresAt: Number.isInteger(payload.expiresAt) ? payload.expiresAt * 1000 : null,
      };
    } else {
      this.localAdminSession = null;
    }
    this.localSessionChecked = true;
    return this.localAdminSession;
  }

  async restoreServerSession() {
    const authClass = window.authSystem?.constructor;
    if (typeof authClass?.restoreSession === "function") {
      if (!authClass.sessionChecked) await authClass.restoreSession();
      return this.getAdminSession();
    }
    const payload = await getServerSession();
    this.applyServerSession(payload);
    return this.getAdminSession();
  }

  async bootStandaloneWorkspace() {
    try {
      const session = await this.restoreServerSession();
      if (session) {
        await this.openPanel();
      } else {
        this.openLoginModal(null);
      }
    } catch (error) {
      this.notification?.error(error?.message || "بررسی نشست مدیریت ناموفق بود؛ برای ادامه دوباره وارد شوید.");
      this.openLoginModal(null);
    }
  }

  openLoginModal(trigger = null) {
    if (this.getAdminSession()) {
      this.panelReturnFocus = trigger instanceof HTMLElement ? trigger : null;
      void this.openPanel();
      return;
    }
    this.loginReturnFocus = trigger instanceof HTMLElement ? trigger : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    ADMIN_DOM.adminLoginModal?.removeAttribute("hidden");
    ADMIN_DOM.adminLoginModal?.classList.add("active");
    ADMIN_DOM.adminLoginModal?.setAttribute("aria-hidden", "false");
    this.syncBodyScrollLock();
    if (this.loginFocusTimer !== null) window.clearTimeout(this.loginFocusTimer);
    this.loginFocusTimer = window.setTimeout(() => {
      this.loginFocusTimer = null;
      if (ADMIN_DOM.adminLoginModal?.classList.contains("active")) {
        document.getElementById("adminUsername")?.focus();
      }
    }, 0);
  }

  closeLoginModal(restoreFocus = true, returnToPublic = true) {
    const returnToPublicRoute = returnToPublic && this.isStandaloneWorkspace() && !this.getAdminSession();
    if (this.loginFocusTimer !== null) {
      window.clearTimeout(this.loginFocusTimer);
      this.loginFocusTimer = null;
    }
    ADMIN_DOM.adminLoginModal?.classList.remove("active");
    ADMIN_DOM.adminLoginModal?.setAttribute("aria-hidden", "true");
    ADMIN_DOM.adminLoginModal?.setAttribute("hidden", "");
    const restoreTarget = this.loginReturnFocus;
    this.loginReturnFocus = null;
    const passwordInput = document.getElementById("adminPassword");
    const totpInput = document.getElementById("adminTotpCode");
    if (passwordInput) passwordInput.value = "";
    if (totpInput) totpInput.value = "";
    this.syncBodyScrollLock();
    if (restoreFocus && restoreTarget?.isConnected) restoreTarget.focus();
    if (returnToPublicRoute) window.location.assign("index.php");
  }

  syncBodyScrollLock() {
    const blockingAdminDialog = document.querySelector(
      ".admin-login-modal.active, .admin-project-detail-modal:not([hidden]), .admin-user-status-modal:not([hidden])",
    );
    document.body?.classList.toggle("admin-scroll-locked", Boolean(blockingAdminDialog));
  }

  handleAdminAuthFailure(error) {
    if (Number(error?.status) !== 401) return false;
    if (this.adminSessionExpiredHandled) return true;
    this.adminSessionExpiredHandled = true;
    this.projectRequestSeq += 1;
    this.userRequestSeq += 1;
    this.statsRequestSeq += 1;
    this.resetAdminMessages();
    this.resetAdminNotifications("نشست مدیریت منقضی شده است؛ برای مشاهدهٔ اعلان‌ها دوباره وارد شوید.");
    this.resetAdminAudit();
    this.applyServerSession({ authenticated: false, principalType: null });
    this.projects = [];
    this.users = [];
    this.resetAdminStats();
    this.resetAdminSettingsSummary();
    this.currentDetailId = null;
    this.currentDetailRequestSeq += 1;
    this.closeUserStatusReasonModal(false);
    this.closeUserDetail(false, false);
    this.closeProjectDetail(false);
    this.closePanel();
    this.closeLoginModal(false, false);
    this.notification?.error("نشست مدیریت منقضی شده است؛ برای ادامه دوباره وارد شوید.");
    if (this.isStandaloneWorkspace()) this.openLoginModal(null);
    return true;
  }

  getAdminSession() {
    const authClass = window.authSystem?.constructor;
    if (typeof authClass?.checkSession === "function") {
      const session = authClass.checkSession();
      return session?.type === "admin" ? session : null;
    }
    return this.localAdminSession?.type === "admin" ? this.localAdminSession : null;
  }

  async handleLogin() {
    const username = document.getElementById("adminUsername")?.value?.trim() || "";
    const password = document.getElementById("adminPassword")?.value || "";
    const totpCode = document.getElementById("adminTotpCode")?.value?.trim() || "";
    if (!username || !password || !/^[0-9]{6}$/.test(totpCode)) {
      this.notification?.error("نام کاربری، رمز عبور و کد تأیید شش‌رقمی الزامی است.");
      return;
    }
    const submit = ADMIN_DOM.adminLoginForm?.querySelector('button[type="submit"]');
    if (submit) submit.disabled = true;
    try {
      const result = await apiRequest("adminLogin", { method: "POST", body: { username, password, totpCode } });
      if (result?.authenticated !== true || result?.principalType !== "admin" || result?.admin?.role !== "admin") {
        throw new Error("پاسخ ورود مدیر، نشست معتبر و تأییدشده‌ای ارائه نکرد.");
      }
      if (!this.applyServerSession(result)) {
        throw new Error("نشست مدیر در رابط قابل تأیید نیست.");
      }
      this.adminSessionExpiredHandled = false;
      this.resetAdminNotifications();
      this.resetAdminAudit();
      const passwordInput = document.getElementById("adminPassword");
      const totpInput = document.getElementById("adminTotpCode");
      if (passwordInput) passwordInput.value = "";
      if (totpInput) totpInput.value = "";
      this.panelReturnFocus = this.loginReturnFocus;
      this.closeLoginModal(false);
      this.notification?.success("ورود مدیر توسط سرور تأیید شد.");
      await this.openPanel();
    } catch (error) {
      this.notification?.error(error?.message || "ورود امن مدیر تأیید نشد.");
    } finally {
      const passwordInput = document.getElementById("adminPassword");
      const totpInput = document.getElementById("adminTotpCode");
      if (passwordInput) passwordInput.value = "";
      if (totpInput) totpInput.value = "";
      if (submit) submit.disabled = false;
    }
  }

  async openPanel() {
    let session = this.getAdminSession();
    if (!session) {
      try {
        session = await this.restoreServerSession();
      } catch (error) {
        if (this.isStandaloneWorkspace()) {
          this.notification?.error(error?.message || "بررسی نشست مدیریت ناموفق بود.");
          this.openLoginModal(null);
        } else {
          this.notification?.error("برای دسترسی به پنل باید احراز هویت مدیر انجام شود.");
        }
        return;
      }
    }
    if (!session) {
      if (this.isStandaloneWorkspace()) this.openLoginModal(null);
      else this.notification?.error("برای دسترسی به پنل باید احراز هویت مدیر انجام شود.");
      return;
    }
    if (!this.isStandaloneWorkspace()) {
      window.location.assign("admin.php");
      return;
    }
    if (this.isUserDetailRoute()) {
      const publicId = new URL(window.location.href).searchParams.get("id") || "";
      if (!/^[a-f0-9]{32}$/.test(publicId)) {
        this.notification?.error("شناسهٔ پرونده معتبر نیست؛ به فضای کاری مدیریت بازگردانده می‌شوید.");
        window.location.replace("admin.php");
        return;
      }
      await this.openUserDetail(publicId, null);
      return;
    }
    if (this.isAdminMessagesRoute()) {
      const title = byId("adminMessagesTitle");
      if (title) title.focus();
      await this.loadAdminMessages();
      return;
    }
    if (this.isAdminAuditRoute()) {
      const title = byId("adminAuditTitle");
      if (title) title.focus();
      await this.loadAdminAudit();
      return;
    }
    if (this.isAdminSettingsRoute()) {
      const title = byId("adminSettingsTitle");
      if (title) title.focus();
      await this.loadAdminSettings();
      return;
    }
    if (!ADMIN_DOM.adminDataModal) {
      this.notification?.error("ساختار فضای کاری مدیریت در این صفحه در دسترس نیست.");
      return;
    }
    ADMIN_DOM.adminDataModal.hidden = false;
    ADMIN_DOM.adminDataModal.classList.add("active");
    ADMIN_DOM.adminDataModal.setAttribute("aria-hidden", "false");
    ADMIN_DOM.adminDataModal.setAttribute("role", "main");
    ADMIN_DOM.adminDataModal.removeAttribute("aria-modal");
    this.syncBodyScrollLock();
    await this.setTab("projects");
    document.getElementById("adminDataTitle")?.focus();
    await Promise.all([this.loadStats(), this.loadProjects()]);
  }

  closePanel() {
    this.projectRequestSeq += 1;
    this.userRequestSeq += 1;
    this.statsRequestSeq += 1;
    this.cancelAdminNotificationRequest();
    const metrics = byId("adminMetrics");
    const statsStatus = byId("adminStatsStatus");
    const statsRetry = byId("adminStatsRetryBtn");
    if (metrics) metrics.setAttribute("aria-busy", "false");
    if (statsStatus?.dataset.state === "loading") {
      if (this.statsLoaded) {
        statsStatus.textContent = "آخرین آمار معتبر حفظ شده است؛ با بازشدن فضای کاری به‌روزرسانی می‌شود.";
        statsStatus.dataset.state = "ready";
      } else {
        this.setStatsSkeleton(false);
        ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => this.setMetric(id, null));
        statsStatus.textContent = "برای دریافت آمار، فضای کاری مدیریت را باز کنید.";
        statsStatus.dataset.state = "idle";
      }
      if (statsRetry) {
        statsRetry.hidden = true;
        statsRetry.disabled = false;
        statsRetry.removeAttribute("aria-disabled");
        statsRetry.textContent = "تلاش دوباره برای دریافت آمار";
      }
    }
    this.closeProjectDetail(false);
    ADMIN_DOM.adminDataModal?.classList.remove("active");
    if (ADMIN_DOM.adminDataModal) ADMIN_DOM.adminDataModal.hidden = true;
    ADMIN_DOM.adminDataModal?.setAttribute("aria-hidden", "true");
    if (this.isStandaloneWorkspace() && ADMIN_DOM.adminDataModal) {
      ADMIN_DOM.adminDataModal.setAttribute("role", "main");
      ADMIN_DOM.adminDataModal.removeAttribute("aria-modal");
    }
    this.syncBodyScrollLock();
    const target = this.panelReturnFocus;
    this.panelReturnFocus = null;
    if (target?.isConnected) target.focus();
  }

  async logoutAdmin(button = ADMIN_DOM.adminLogoutBtn) {
    if (button) button.disabled = true;
    try {
      const result = await apiRequest("logout", { method: "POST", body: {} });
      if (result?.authenticated !== false || result?.principalType !== null) {
        throw new Error("پایان نشست مدیر توسط سرور تأیید نشد.");
      }
      this.applyServerSession(result);
      this.resetAdminMessages();
      this.resetAdminNotifications();
      this.resetAdminAudit();
      this.projects = [];
      this.users = [];
      this.resetAdminStats();
      this.resetAdminSettingsSummary();
      this.closePanel();
      this.closeLoginModal(false, false);
      if (this.isStandaloneWorkspace()) window.location.assign("index.php");
      else window.location.reload();
    } catch (error) {
      this.notification?.error(error?.message || "ابطال امن نشست مدیر تأیید نشد.");
    } finally {
      if (button) button.disabled = false;
    }
  }

  async setTab(tab) {
    if (!["projects", "users", "notifications"].includes(tab)) tab = "projects";
    const views = {
      projects: document.getElementById("adminProjectsView"),
      users: document.getElementById("adminUsersView"),
      notifications: document.getElementById("adminNotificationsView"),
    };
    document.querySelectorAll("[data-admin-tab]").forEach((button) => {
      const active = button.dataset.adminTab === tab;
      button.classList.toggle("active", active);
      button.setAttribute("aria-selected", active ? "true" : "false");
      button.setAttribute("tabindex", active ? "0" : "-1");
    });
    for (const [key, view] of Object.entries(views)) {
      if (!view) continue;
      const active = key === tab;
      view.hidden = !active;
      view.setAttribute("aria-hidden", active ? "false" : "true");
    }
    if (tab === "users") await this.loadUsers();
    if (tab === "notifications") await this.loadAdminNotifications({ append: false });
  }

  resetAdminAudit() {
    this.adminAuditRequestSeq += 1;
    if (this.adminAuditSearchTimer !== null) window.clearTimeout(this.adminAuditSearchTimer);
    this.adminAuditSearchTimer = null;
    this.adminAuditPage = 1;
    this.adminAuditEvents = [];
    this.adminAuditLoaded = false;
    this.adminAuditLastQuery = null;
    this.adminAuditPagination = { page: 1, perPage: 25, total: 0, pages: 0 };

    const tableWrap = byId("adminAuditTableWrap");
    const tbody = byId("adminAuditTbody");
    const pagination = byId("adminAuditPagination");
    const status = byId("adminAuditStatus");
    const retry = byId("adminAuditRetryBtn");
    const refresh = byId("adminAuditRefreshBtn");
    if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
    if (pagination) {
      pagination.removeAttribute("inert");
      pagination.replaceChildren();
    }
    if (tbody) tbody.innerHTML = '<tr><td colspan="4" class="admin-state-cell">برای مشاهدهٔ گزارش‌های ممیزی، نشست مدیریت معتبر لازم است.</td></tr>';
    const search = byId("adminAuditSearch");
    const actor = byId("adminAuditActor");
    const subject = byId("adminAuditSubject");
    if (search) search.value = "";
    if (actor) actor.value = "all";
    if (subject) subject.value = "all";
    if (status) {
      status.textContent = "برای مشاهدهٔ گزارش‌های ممیزی، نشست مدیریت معتبر لازم است.";
      status.dataset.state = "idle";
    }
    if (retry) {
      retry.hidden = true;
      retry.removeAttribute("aria-disabled");
      retry.textContent = "تلاش دوباره";
    }
    if (refresh) refresh.disabled = false;
  }

  async loadAdminAudit() {
    if (this.adminAuditSearchTimer !== null) {
      window.clearTimeout(this.adminAuditSearchTimer);
      this.adminAuditSearchTimer = null;
    }
    const sequence = ++this.adminAuditRequestSeq;
    const status = byId("adminAuditStatus");
    const tbody = byId("adminAuditTbody");
    const pagination = byId("adminAuditPagination");
    const tableWrap = byId("adminAuditTableWrap");
    const retry = byId("adminAuditRetryBtn");
    const refresh = byId("adminAuditRefreshBtn");
    if (!status || !tbody || !pagination || !this.isAdminAuditRoute()) {
      if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
      if (pagination) pagination.removeAttribute("inert");
      return;
    }

    const query = {
      page: this.adminAuditPage,
      search: String(byId("adminAuditSearch")?.value || "").trim(),
      actor: String(byId("adminAuditActor")?.value || "all"),
      subject: String(byId("adminAuditSubject")?.value || "all"),
    };
    const previousQuery = this.adminAuditLastQuery;
    const hasPreviousSnapshot = this.adminAuditLoaded;
    const sameFilters = Boolean(previousQuery
      && previousQuery.search === query.search
      && previousQuery.actor === query.actor
      && previousQuery.subject === query.subject);
    const retryWasVisible = Boolean(retry && !retry.hidden);
    const retryHadFocus = Boolean(retryWasVisible && document.activeElement === retry);
    let restoreRetryFocus = false;

    if (tableWrap) tableWrap.setAttribute("aria-busy", "true");
    pagination.setAttribute("inert", "");
    if (hasPreviousSnapshot) {
      status.textContent = sameFilters
        ? "در حال به‌روزرسانی گزارش‌های ممیزی؛ آخرین نتیجهٔ معتبر تا پاسخ جدید حفظ می‌شود."
        : "در حال دریافت گزارش‌ها با فیلترهای جدید؛ نتایج قبلی تا پاسخ معتبر حفظ می‌شوند و ممکن است با فیلترهای فعلی مطابقت نداشته باشند.";
    } else {
      tbody.innerHTML = renderAdminTableSkeleton("audit", 3)
        || '<tr><td colspan="4" class="admin-state-cell">در حال دریافت گزارش‌های ممیزی از سرور…</td></tr>';
      status.textContent = "در حال دریافت گزارش‌های ممیزی از سرور…";
    }
    status.dataset.state = "loading";
    if (refresh) refresh.disabled = true;
    if (retry) {
      if (retryWasVisible) {
        retry.setAttribute("aria-disabled", "true");
        retry.textContent = "در حال تازه‌سازی…";
      } else {
        retry.hidden = true;
      }
    }

    let succeeded = false;
    try {
      const data = await apiRequest("getAdminAuditLog", {
        query: { page: query.page, perPage: 25, q: query.search, actor: query.actor, subject: query.subject },
      });
      if (sequence !== this.adminAuditRequestSeq) return;
      if (!Array.isArray(data?.events) || data.events.length > 25
        || !data.events.every(isAdminAuditEvent)
        || !isAdminFixedPage(data?.pagination, query.page, 25)) {
        throw new Error("ساختار پاسخ گزارش ممیزی معتبر نیست؛ داده تا دریافت پاسخ سازگار نمایش داده نمی‌شود.");
      }
      this.adminAuditEvents = data.events;
      this.adminAuditPagination = {
        page: data.pagination.page,
        perPage: data.pagination.perPage,
        total: data.pagination.total,
        pages: data.pagination.pages,
      };
      this.adminAuditPage = data.pagination.page;
      this.adminAuditLastQuery = { ...query, page: data.pagination.page };
      this.adminAuditLoaded = true;
      this.renderAdminAudit();
      status.textContent = `${fmtNumber(this.adminAuditPagination.total)} رویداد ممیزی مطابق فیلترها پیدا شد.`;
      status.dataset.state = this.adminAuditPagination.total > 0 ? "ready" : "empty";
      restoreRetryFocus = Boolean(retryHadFocus && document.activeElement === retry);
      if (retry) {
        retry.hidden = true;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
      succeeded = true;
    } catch (error) {
      if (sequence !== this.adminAuditRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      if (hasPreviousSnapshot && previousQuery) {
        if (this.adminAuditSearchTimer !== null) window.clearTimeout(this.adminAuditSearchTimer);
        this.adminAuditSearchTimer = null;
        this.adminAuditPage = this.adminAuditPagination.page;
        const searchInput = byId("adminAuditSearch");
        const actorFilter = byId("adminAuditActor");
        const subjectFilter = byId("adminAuditSubject");
        if (searchInput) searchInput.value = previousQuery.search;
        if (actorFilter) actorFilter.value = previousQuery.actor;
        if (subjectFilter) subjectFilter.value = previousQuery.subject;
        this.renderAdminAudit();
        const sameQuery = sameFilters && previousQuery.page === query.page;
        const retained = sameQuery
          ? "تازه‌سازی ناموفق بود؛ آخرین نتیجهٔ معتبر همین فیلتر و صفحه حفظ شده است و ممکن است تازه نباشد."
          : sameFilters
            ? "دریافت صفحهٔ درخواستی ناموفق بود؛ آخرین صفحهٔ معتبر حفظ شده است."
            : "دریافت با فیلترهای جدید ناموفق بود؛ نتایج و فیلترهای آخرین درخواست معتبر بازگردانده شدند.";
        status.textContent = `${retained} ${error?.message || "برای تلاش دوباره اقدام کنید."}`;
      } else {
        this.adminAuditEvents = [];
        this.adminAuditLoaded = false;
        this.adminAuditPage = 1;
        this.adminAuditPagination = { page: 1, perPage: 25, total: 0, pages: 0 };
        tbody.innerHTML = '<tr><td colspan="4" class="admin-state-cell">دریافت گزارش ممیزی ناموفق بود؛ این خطا به معنی خالی بودن گزارش‌ها نیست.</td></tr>';
        pagination.replaceChildren();
        status.textContent = error?.message || "دریافت گزارش ممیزی ناموفق بود؛ برای تلاش دوباره از دکمهٔ تلاش مجدد استفاده کنید.";
      }
      status.dataset.state = "error";
      if (retry) {
        retry.hidden = false;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
    } finally {
      if (sequence === this.adminAuditRequestSeq) {
        if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
        pagination.removeAttribute("inert");
        if (refresh) refresh.disabled = false;
        if (retry) retry.removeAttribute("aria-disabled");
        if (succeeded && restoreRetryFocus) refresh?.focus();
      }
    }
  }

  renderAdminAudit() {
    const tbody = byId("adminAuditTbody");
    const pagination = byId("adminAuditPagination");
    if (!tbody || !pagination) return;
    const actorLabels = { user: "کاربر", admin: "مدیر", system: "سامانه", anonymous: "ناشناس" };
    const subjectLabels = { user: "حساب کاربری", project: "پروژه", registration_attempt: "درخواست ثبت‌نام", other: "سایر موضوع‌ها", none: "—" };
    const rows = this.adminAuditEvents.map((event) => `<tr>
      <td>${html(fmtDate(event.createdAt))}</td>
      <td>${html(actorLabels[event.actorType] || "ناشناس")}</td>
      <td><code class="admin-audit-action">${html(event.action)}</code></td>
      <td>${html(subjectLabels[event.subjectType] || "—")}</td>
    </tr>`);
    tbody.innerHTML = rows.length ? rows.join("") : '<tr><td colspan="4" class="admin-state-cell">رویدادی مطابق فیلترهای فعلی وجود ندارد.</td></tr>';

    const current = this.adminAuditPagination.page;
    const pages = this.adminAuditPagination.pages;
    if (pages < 2) {
      pagination.innerHTML = `<span class="admin-audit-page-summary">صفحه ${fmtNumber(current)} از ${fmtNumber(Math.max(1, pages))}</span>`;
      return;
    }
    pagination.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" data-audit-page="${current - 1}" ${current <= 1 ? "disabled" : ""}>قبلی</button>
      <span class="admin-audit-page-summary">صفحه ${fmtNumber(current)} از ${fmtNumber(pages)} · ${fmtNumber(this.adminAuditPagination.total)} رویداد</span>
      <button type="button" class="btn btn-outline btn-sm" data-audit-page="${current + 1}" ${current >= pages ? "disabled" : ""}>بعدی</button>`;
  }

  resetAdminSettingsSummary() {
    this.adminSettingsSnapshot = null;
    const grid = byId("adminSettingsGrid");
    if (grid) grid.setAttribute("aria-busy", "false");
    for (const id of [
      "adminSettingEnvironment", "adminSettingPublicBaseUrl", "adminSettingDatabase", "adminSettingEmail",
      "adminSettingTelegramBot", "adminSettingTelegramAdmin", "adminSettingTelegramNotifier", "adminSettingTelegramLink",
      "adminSettingAdminUsername", "adminSettingAdminPassword", "adminSettingAdminTotp",
      "adminSettingOtpKey", "adminSettingTermsVersion", "adminSettingSms",
    ]) {
      const node = byId(id);
      if (node) { node.textContent = "وضعیت قابل دریافت نیست"; node.dataset.state = "unknown"; }
    }
    const generatedAt = byId("adminSettingsGeneratedAt");
    if (generatedAt) { generatedAt.textContent = ""; generatedAt.hidden = true; }
    const retry = byId("adminSettingsRetryBtn");
    if (retry) { retry.hidden = true; retry.removeAttribute("aria-disabled"); retry.textContent = "تلاش دوباره"; }
  }

  async loadAdminSettings() {
    const retry = byId("adminSettingsRetryBtn");
    if (retry?.getAttribute("aria-disabled") === "true") return;
    const sequence = ++this.adminSettingsRequestSeq;
    const status = byId("adminSettingsStatus");
    const refresh = byId("adminSettingsRefreshBtn");
    const generatedAt = byId("adminSettingsGeneratedAt");
    const grid = byId("adminSettingsGrid");
    const hadSnapshot = Boolean(this.adminSettingsSnapshot);
    const retryWasVisible = Boolean(retry && !retry.hidden);
    if (!status || !this.isAdminSettingsRoute()) return;

    if (grid) grid.setAttribute("aria-busy", "true");
    status.textContent = hadSnapshot
      ? "در حال تازه‌سازی خلاصهٔ تنظیمات؛ آخرین گزارش معتبر تا دریافت پاسخ جدید حفظ می‌شود."
      : "خلاصهٔ تنظیمات امن از سرور دریافت می‌شود…";
    status.dataset.state = "loading";
    if (refresh) refresh.disabled = true;
    if (retry) {
      if (retryWasVisible) {
        retry.setAttribute("aria-disabled", "true");
        retry.textContent = "در حال تازه‌سازی…";
      } else {
        retry.hidden = true;
      }
    }
    if (generatedAt && !hadSnapshot) generatedAt.hidden = true;

    const requiredBooleans = [
      "publicBaseUrlConfigured", "emailConfigurationComplete", "telegramBotTokenFormatValid",
      "telegramAdminDestinationPresent", "telegramNotifierBotPresent", "telegramUserLinkPrerequisitesPresent",
      "adminUsernamePresent", "adminPasswordHashRecognized", "adminTotpSecretPresent",
      "otpHmacKeyMinimumLengthMet", "termsVersionValid", "smsEnabled",
    ];
    const environmentValues = new Set(["local_default", "local", "staging", "production", "unrecognized"]);
    try {
      const data = await apiRequest("getAdminSettingsSummary");
      if (sequence !== this.adminSettingsRequestSeq) return;
      if (!data || !environmentValues.has(data.environment)
        || data.databaseConnectionForRequest !== "established"
        || typeof data.generatedAt !== "string" || Number.isNaN(Date.parse(data.generatedAt))
        || requiredBooleans.some((key) => typeof data[key] !== "boolean")
        || data.smsEnabled !== false) {
        throw new Error("ساختار پاسخ خلاصهٔ تنظیمات معتبر نیست؛ مقادیر ناقص یا ناسازگار نمایش داده نمی‌شوند.");
      }
      // Keep only the already-validated, non-secret summary fields for this AdminPanel session.
      this.adminSettingsSnapshot = {
        environment: data.environment,
        generatedAt: data.generatedAt,
        ...Object.fromEntries(requiredBooleans.map((key) => [key, data[key]])),
      };
      const snapshot = this.adminSettingsSnapshot;
      const setField = (id, label, state = "unknown") => {
        const node = byId(id);
        if (!node) return;
        node.textContent = label;
        node.dataset.state = state;
      };
      const yesNo = (value, yes = "پیکربندی‌شده", no = "پیکربندی ناقص") => ({
        label: value ? yes : no,
        state: value ? "ready" : "attention",
      });
      const envLabels = {
        local_default: "محلی (پیش‌فرض در نبود تنظیم)",
        local: "محلی",
        staging: "آزمایشی (Staging)",
        production: "تولیدی (Production)",
        unrecognized: "مقدار ناشناخته — نیازمند بازبینی",
      };
      const envState = snapshot.environment === "unrecognized" ? "attention" : "unknown";
      setField("adminSettingEnvironment", envLabels[snapshot.environment], envState);
      let item = yesNo(snapshot.publicBaseUrlConfigured, "موجود و معتبر", "تعریف نشده یا نامعتبر");
      setField("adminSettingPublicBaseUrl", item.label, item.state);
      setField("adminSettingDatabase", "اتصال درخواست جاری برقرار شد", "ready");
      item = yesNo(snapshot.emailConfigurationComplete, "مقادیر ساختاری کامل", "مقادیر ناقص یا نامعتبر");
      setField("adminSettingEmail", item.label, item.state);
      item = yesNo(snapshot.telegramBotTokenFormatValid, "قالب توکن معتبر", "توکن تنظیم نشده یا قالب نامعتبر");
      setField("adminSettingTelegramBot", item.label, item.state);
      item = yesNo(snapshot.telegramAdminDestinationPresent, "موجود", "تنظیم نشده");
      setField("adminSettingTelegramAdmin", item.label, item.state);
      item = yesNo(snapshot.telegramNotifierBotPresent, "موجود", "تنظیم نشده");
      setField("adminSettingTelegramNotifier", item.label, item.state);
      item = yesNo(snapshot.telegramUserLinkPrerequisitesPresent, "پیش‌نیازهای محلی کامل", "یک یا چند پیش‌نیاز ناقص");
      setField("adminSettingTelegramLink", item.label, item.state);
      item = yesNo(snapshot.adminUsernamePresent, "موجود", "تنظیم نشده");
      setField("adminSettingAdminUsername", item.label, item.state);
      item = yesNo(snapshot.adminPasswordHashRecognized, "الگوریتم هش شناخته‌شده", "هش خالی یا الگوریتم ناشناخته");
      setField("adminSettingAdminPassword", item.label, item.state);
      item = yesNo(snapshot.adminTotpSecretPresent, "موجود", "تنظیم نشده");
      setField("adminSettingAdminTotp", item.label, item.state);
      item = yesNo(snapshot.otpHmacKeyMinimumLengthMet, "حداقل طول برقرار است", "حداقل طول برقرار نیست");
      setField("adminSettingOtpKey", item.label, item.state);
      item = yesNo(snapshot.termsVersionValid, "قالب مقدار معتبر است", "مقدار مفقود یا قالب نامعتبر است");
      setField("adminSettingTermsVersion", item.label, item.state);
      setField("adminSettingSms", snapshot.smsEnabled ? "فعال" : "غیرفعال طبق تصمیم محصول", snapshot.smsEnabled ? "attention" : "unknown");
      if (generatedAt) {
        generatedAt.textContent = `زمان تولید این خلاصه (UTC): ${fmtDate(snapshot.generatedAt)}`;
        generatedAt.hidden = false;
      }
      status.textContent = "خلاصهٔ تنظیمات از درخواست جاری دریافت شد. وضعیت‌های Provider صرفاً بر پایهٔ پیکربندی محلی هستند و اتصال/تحویل واقعی را تأیید نمی‌کنند.";
      status.dataset.state = "ready";
      if (retry) {
        retry.hidden = true;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
    } catch (error) {
      if (sequence !== this.adminSettingsRequestSeq) return;
      if (Number(error?.status) === 401) {
        this.resetAdminSettingsSummary();
        if (this.handleAdminAuthFailure(error)) return;
      }
      if (this.adminSettingsSnapshot) {
        const databaseNode = byId("adminSettingDatabase");
        if (databaseNode) databaseNode.textContent = "اتصال درخواست زمان تولید آخرین خلاصه برقرار بود";
        const lastGenerated = this.adminSettingsSnapshot.generatedAt;
        if (generatedAt) {
          generatedAt.textContent = `زمان آخرین خلاصهٔ معتبر (UTC): ${fmtDate(lastGenerated)}`;
          generatedAt.hidden = false;
        }
        status.textContent = `تازه‌سازی ناموفق بود؛ آخرین خلاصهٔ معتبر حفظ شده است و وضعیت فعلی سرور را اثبات نمی‌کند. ${error?.message || "برای دریافت مجدد تلاش کنید."}`;
      } else {
        this.resetAdminSettingsSummary();
        status.textContent = error?.message || "دریافت تنظیمات ناموفق بود؛ این خطا به معنی ناقص یا سالم بودن تنظیمات نیست.";
      }
      status.dataset.state = "error";
      if (retry) {
        retry.hidden = false;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
    } finally {
      if (sequence === this.adminSettingsRequestSeq) {
        if (grid) grid.setAttribute("aria-busy", "false");
        if (refresh) refresh.disabled = false;
        if (retry) retry.removeAttribute("aria-disabled");
      }
    }
  }

  resetAdminMessages() {
    this.adminMessageRequestSeq += 1;
    if (this.adminMessageSearchTimer !== null) {
      window.clearTimeout(this.adminMessageSearchTimer);
      this.adminMessageSearchTimer = null;
    }
    this.adminMessages = [];
    this.adminMessagesLoaded = false;
    this.adminMessageLastQuery = null;
    this.adminMessagePage = 1;
    this.adminMessagePagination = { page: 1, perPage: 25, total: 0, pages: 0 };
    this.adminMessageUnreadCount = 0;

    const tableWrap = byId("adminMessagesTableWrap");
    const tbody = byId("adminMessagesTbody");
    const pagination = byId("adminMessagesPagination");
    const count = byId("adminMessagesUnreadCount");
    const status = byId("adminMessagesStatus");
    const refresh = byId("adminMessagesRefreshBtn");
    const retry = byId("adminMessagesRetryBtn");
    const searchInput = byId("adminMessagesSearch");
    const senderFilter = byId("adminMessagesSender");
    const readFilter = byId("adminMessagesRead");
    if (searchInput) searchInput.value = "";
    if (senderFilter) senderFilter.value = "all";
    if (readFilter) readFilter.value = "all";
    if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
    if (pagination) {
      pagination.removeAttribute("inert");
      pagination.replaceChildren();
    }
    if (tbody) {
      const row = document.createElement("tr");
      const cell = document.createElement("td");
      cell.colSpan = 7;
      cell.className = "admin-state-cell";
      cell.textContent = "برای مشاهدهٔ پیام‌ها، نشست معتبر مدیریت لازم است.";
      row.append(cell);
      tbody.replaceChildren(row);
    }
    if (count) count.textContent = "—";
    if (status) {
      status.textContent = "برای مشاهدهٔ پیام‌ها، نشست معتبر مدیریت لازم است.";
      status.dataset.state = "idle";
    }
    if (refresh) refresh.disabled = false;
    if (retry) {
      retry.hidden = true;
      retry.removeAttribute("aria-disabled");
      retry.textContent = "تلاش دوباره";
    }
  }

  async loadAdminMessages() {
    const sequence = ++this.adminMessageRequestSeq;
    const status = byId("adminMessagesStatus");
    const tbody = byId("adminMessagesTbody");
    const pagination = byId("adminMessagesPagination");
    const tableWrap = byId("adminMessagesTableWrap");
    const retry = byId("adminMessagesRetryBtn");
    const refresh = byId("adminMessagesRefreshBtn");
    if (!status || !tbody || !pagination || !this.isAdminMessagesRoute()) {
      if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
      if (pagination) pagination.removeAttribute("inert");
      return;
    }

    const hasPreviousSnapshot = this.adminMessagesLoaded;
    const retryWasVisible = Boolean(retry && !retry.hidden);
    const search = String(byId("adminMessagesSearch")?.value || "").trim();
    const sender = String(byId("adminMessagesSender")?.value || "all");
    const read = String(byId("adminMessagesRead")?.value || "all");
    const requestedPage = this.adminMessagePage;
    const previousFiltersMatchRequest = Boolean(this.adminMessageLastQuery
      && this.adminMessageLastQuery.search === search
      && this.adminMessageLastQuery.sender === sender
      && this.adminMessageLastQuery.read === read);
    const previousQueryMatchesRequest = previousFiltersMatchRequest
      && this.adminMessageLastQuery.page === requestedPage;

    if (tableWrap) tableWrap.setAttribute("aria-busy", "true");
    pagination.setAttribute("inert", "");
    if (hasPreviousSnapshot) {
      status.textContent = previousFiltersMatchRequest
        ? "در حال تازه‌سازی صندوق پیام‌ها؛ آخرین نتایج معتبر تا دریافت پاسخ جدید حفظ می‌شوند."
        : "در حال دریافت نتایج برای فیلترهای انتخاب‌شده؛ نتایج قبلی تا پاسخ جدید حفظ می‌شوند و ممکن است با فیلترهای فعلی مطابقت نداشته باشند.";
    } else {
      tbody.innerHTML = renderAdminTableSkeleton("messages", 3)
        || '<tr><td colspan="7" class="admin-state-cell">در حال دریافت پیام‌ها از سرور…</td></tr>';
      status.textContent = "در حال دریافت پیام‌ها از سرور…";
    }
    status.dataset.state = "loading";
    if (refresh) refresh.disabled = true;
    if (retry) {
      if (retryWasVisible) {
        retry.setAttribute("aria-disabled", "true");
        retry.textContent = "در حال تازه‌سازی…";
      } else {
        retry.hidden = true;
      }
    }

    try {
      const data = await apiRequest("getAdminMessages", {
        query: { page: requestedPage, perPage: 25, q: search, sender, read },
      });
      if (sequence !== this.adminMessageRequestSeq) return;
      if (!Array.isArray(data?.messages) || data.messages.length > 25
        || !data.messages.every(isAdminMessageListItem)
        || !isAdminFixedPage(data?.pagination, requestedPage, 25)
        || !Number.isSafeInteger(data?.unreadCustomerCount) || data.unreadCustomerCount < 0) {
        throw new Error("پاسخ صندوق پیام‌ها ناقص یا ناسازگار است؛ داده به‌عنوان نتیجهٔ معتبر نمایش داده نمی‌شود.");
      }
      this.adminMessages = data.messages;
      this.adminMessagePagination = {
        page: data.pagination.page,
        perPage: data.pagination.perPage,
        total: data.pagination.total,
        pages: data.pagination.pages,
      };
      this.adminMessagePage = data.pagination.page;
      this.adminMessageUnreadCount = data.unreadCustomerCount;
      this.adminMessageLastQuery = { page: requestedPage, search, sender, read };
      this.adminMessagesLoaded = true;
      this.renderAdminMessages();
      const countNode = byId("adminMessagesUnreadCount");
      if (countNode) countNode.textContent = fmtNumber(this.adminMessageUnreadCount);
      status.textContent = `${fmtNumber(this.adminMessagePagination.total)} پیام مطابق فیلترها پیدا شد؛ ${fmtNumber(this.adminMessageUnreadCount)} پیام مشتری خوانده‌نشده است.`;
      status.dataset.state = this.adminMessagePagination.total > 0 ? "ready" : "empty";
      if (retry) {
        retry.hidden = true;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
    } catch (error) {
      if (sequence !== this.adminMessageRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      if (hasPreviousSnapshot) {
        // Keep the last validated rows, pagination, count and filter controls coherent.
        this.adminMessagePage = this.adminMessagePagination.page;
        if (!previousFiltersMatchRequest && this.adminMessageLastQuery) {
          const searchInput = byId("adminMessagesSearch");
          const senderFilter = byId("adminMessagesSender");
          const readFilter = byId("adminMessagesRead");
          if (searchInput) searchInput.value = this.adminMessageLastQuery.search;
          if (senderFilter) senderFilter.value = this.adminMessageLastQuery.sender;
          if (readFilter) readFilter.value = this.adminMessageLastQuery.read;
        }
        const snapshotNotice = previousQueryMatchesRequest
          ? "تازه‌سازی صندوق پیام‌ها ناموفق بود؛ آخرین نتایج معتبر همین صفحه و فیلترها حفظ شده‌اند، اما وضعیت فعلی سرور را اثبات نمی‌کنند."
          : previousFiltersMatchRequest
            ? "دریافت صفحهٔ درخواستی ناموفق بود؛ آخرین صفحهٔ معتبر حفظ شده است."
            : "دریافت با فیلترهای درخواستی ناموفق بود؛ نتایج و فیلترهای آخرین درخواست معتبر حفظ شده‌اند.";
        status.textContent = `${snapshotNotice} ${error?.message || "برای دریافت مجدد تلاش کنید."}`;
      } else {
        this.adminMessages = [];
        this.adminMessagePage = 1;
        this.adminMessagePagination = { page: 1, perPage: 25, total: 0, pages: 0 };
        this.adminMessageUnreadCount = 0;
        const row = document.createElement("tr");
        const cell = document.createElement("td");
        cell.colSpan = 7;
        cell.className = "admin-state-cell";
        cell.textContent = "دریافت صندوق پیام‌ها ناموفق بود؛ این خطا به معنی خالی بودن صندوق نیست.";
        row.append(cell);
        tbody.replaceChildren(row);
        pagination.replaceChildren();
        const countNode = byId("adminMessagesUnreadCount");
        if (countNode) countNode.textContent = "—";
        status.textContent = error?.message || "دریافت صندوق پیام‌ها ناموفق بود؛ برای تلاش دوباره از دکمهٔ تلاش مجدد استفاده کنید.";
      }
      status.dataset.state = "error";
      if (retry) {
        retry.hidden = false;
        retry.removeAttribute("aria-disabled");
        retry.textContent = "تلاش دوباره";
      }
    } finally {
      if (sequence === this.adminMessageRequestSeq) {
        if (tableWrap) tableWrap.setAttribute("aria-busy", "false");
        pagination.removeAttribute("inert");
        if (refresh) refresh.disabled = false;
        if (retry) retry.removeAttribute("aria-disabled");
      }
    }
  }

  updateAdminMessagesReadStateForProject(projectId, markedCount = 0) {
    const safeProjectId = Number(projectId);
    if (!Number.isSafeInteger(safeProjectId) || safeProjectId < 1) return;
    for (const item of this.adminMessages) {
      if (Number(item.projectId) === safeProjectId && item.senderType === "user") item.read = true;
    }
    const tbody = byId("adminMessagesTbody");
    tbody?.querySelectorAll("[data-message-project]").forEach((button) => {
      if (Number(button.dataset.messageProject) !== safeProjectId) return;
      const badge = button.closest("tr")?.querySelector(".admin-message-read-state");
      if (!badge) return;
      badge.dataset.state = "read";
      badge.textContent = "خوانده‌شده";
    });
    this.adminMessageUnreadCount = Math.max(0, this.adminMessageUnreadCount - Math.max(0, Number(markedCount) || 0));
    const countNode = byId("adminMessagesUnreadCount");
    if (countNode) countNode.textContent = fmtNumber(this.adminMessageUnreadCount);
    const status = byId("adminMessagesStatus");
    if (status && status.dataset.state !== "error") {
      status.textContent = `${fmtNumber(this.adminMessagePagination.total)} پیام مطابق فیلترها پیدا شد؛ ${fmtNumber(this.adminMessageUnreadCount)} پیام مشتری خوانده‌نشده است.`;
    }
  }

  renderAdminMessages() {
    const tbody = byId("adminMessagesTbody");
    const pagination = byId("adminMessagesPagination");
    if (!tbody || !pagination) return;
    const senderLabels = { user: "مشتری", admin: "مدیریت", system: "سامانه" };
    const rows = this.adminMessages.map((item) => {
      const sender = Object.prototype.hasOwnProperty.call(senderLabels, item.senderType) ? senderLabels[item.senderType] : "سامانه";
      const unreadCustomer = item.senderType === "user" && item.read !== true;
      const readLabel = unreadCustomer ? "خوانده‌نشده" : item.read === true ? "خوانده‌شده" : "—";
      const stateLabel = item.projectArchived ? "بایگانی‌شده" : (item.projectStatus || "—");
      const truncatedNote = item.bodyTruncated ? '<span class="admin-message-truncated">پیش‌نمایش کوتاه‌شده</span>' : "";
      return `<tr>
        <td>${html(fmtDate(item.createdAt))}</td>
        <td><strong>${html(item.ownerName || "—")}</strong><small class="admin-message-subline">${html(item.ownerUsername || "—")}</small></td>
        <td><strong>${html(item.projectTitle || "پروژه بدون عنوان")}</strong><small class="admin-message-subline">${html(item.trackingCode || "—")} · ${html(stateLabel)}</small></td>
        <td>${html(sender)}</td>
        <td class="admin-message-preview-cell"><p>${html(item.body || "")}</p>${truncatedNote}</td>
        <td><span class="admin-message-read-state" data-state="${unreadCustomer ? "unread" : item.read === true ? "read" : "neutral"}">${readLabel}</span></td>
        <td><button type="button" class="btn btn-outline btn-sm" data-message-project="${Number(item.projectId) || 0}" ${Number(item.projectId) > 0 ? "" : "disabled"}>بازکردن گفت‌وگو</button></td>
      </tr>`;
    });
    tbody.innerHTML = rows.length ? rows.join("") : '<tr><td colspan="7" class="admin-state-cell">پیامی مطابق فیلترهای فعلی وجود ندارد.</td></tr>';

    const current = this.adminMessagePagination.page;
    const pages = this.adminMessagePagination.pages;
    if (pages < 2) {
      pagination.innerHTML = `<span class="admin-messages-page-summary">صفحه ${fmtNumber(current)} از ${fmtNumber(Math.max(1, pages))}</span>`;
      return;
    }
    pagination.innerHTML = `
      <button type="button" class="btn btn-outline btn-sm" data-messages-page="${current - 1}" ${current <= 1 ? "disabled" : ""}>قبلی</button>
      <span class="admin-messages-page-summary">صفحه ${fmtNumber(current)} از ${fmtNumber(pages)} · ${fmtNumber(this.adminMessagePagination.total)} پیام</span>
      <button type="button" class="btn btn-outline btn-sm" data-messages-page="${current + 1}" ${current >= pages ? "disabled" : ""}>بعدی</button>`;
  }

  createAdminNotificationSkeleton() {
    const article = document.createElement("article");
    article.className = "admin-notification-item admin-notification-skeleton";
    article.setAttribute("aria-hidden", "true");
    article.dataset.adminNotificationSkeleton = "true";

    const content = document.createElement("div");
    content.className = "admin-notification-content admin-notification-skeleton__content";
    const heading = document.createElement("div");
    heading.className = "admin-notification-heading";
    const title = document.createElement("span");
    title.className = "admin-skeleton-shape admin-notification-skeleton__title";
    const state = document.createElement("span");
    state.className = "admin-skeleton-shape admin-notification-skeleton__state";
    heading.append(title, state);

    const body = document.createElement("div");
    body.className = "admin-notification-skeleton__body";
    const bodyLine = document.createElement("span");
    bodyLine.className = "admin-skeleton-shape admin-notification-skeleton__line";
    const bodyLineShort = document.createElement("span");
    bodyLineShort.className = "admin-skeleton-shape admin-notification-skeleton__line admin-notification-skeleton__line--short";
    body.append(bodyLine, bodyLineShort);

    const meta = document.createElement("span");
    meta.className = "admin-skeleton-shape admin-notification-skeleton__meta";
    content.append(heading, body, meta);

    const action = document.createElement("span");
    action.className = "admin-skeleton-shape admin-notification-skeleton__action";
    article.append(content, action);
    return article;
  }

  setAdminNotificationSkeleton(list, visible, { append = false, count = 3 } = {}) {
    if (!list) return;
    list.querySelectorAll("[data-admin-notification-skeleton]").forEach((node) => node.remove());
    list.setAttribute("aria-busy", visible ? "true" : "false");
    if (!visible) return;
    const fragment = document.createDocumentFragment();
    for (let index = 0; index < count; index += 1) fragment.append(this.createAdminNotificationSkeleton());
    if (append) list.append(fragment);
    else list.replaceChildren(fragment);
  }

  resetAdminNotifications(statusMessage = "") {
    this.adminNotificationRequestSeq += 1;
    this.adminNotificationSessionGeneration += 1;
    this.adminNotificationLoading = false;
    this.adminNotifications = [];
    this.adminNotificationsLoaded = false;
    this.adminNotificationPage = 1;
    this.adminNotificationHasMore = false;
    this.adminNotificationUnreadCount = 0;
    this.adminNotificationRetryAppend = false;
    this.adminNotificationMarkingIds.clear();

    const list = document.getElementById("adminNotificationsList");
    this.setAdminNotificationSkeleton(list, false);
    if (list) {
      list.replaceChildren();
      list.inert = false;
    }
    const count = document.getElementById("adminNotificationsTabCount");
    if (count) {
      count.textContent = "0";
      count.hidden = true;
      count.setAttribute("aria-label", "0 اعلان خوانده‌نشده");
    }
    const retry = document.getElementById("adminNotificationsRetryBtn");
    if (retry) {
      retry.hidden = true;
      retry.removeAttribute("aria-disabled");
    }
    const more = document.getElementById("loadMoreAdminNotificationsBtn");
    if (more) {
      more.hidden = true;
      more.disabled = false;
    }
    const status = document.getElementById("adminNotificationsStatus");
    if (status) {
      status.textContent = statusMessage;
      status.dataset.state = statusMessage ? "error" : "idle";
    }
    const actionStatus = document.getElementById("adminNotificationsActionStatus");
    if (actionStatus) {
      actionStatus.textContent = "";
      actionStatus.dataset.state = "idle";
    }
  }

  cancelAdminNotificationRequest() {
    this.adminNotificationRequestSeq += 1;
    this.adminNotificationLoading = false;
    const list = document.getElementById("adminNotificationsList");
    this.setAdminNotificationSkeleton(list, false);
    if (list) list.inert = false;
    const status = document.getElementById("adminNotificationsStatus");
    if (status?.dataset.state === "loading") {
      status.textContent = this.adminNotificationsLoaded
        ? "آخرین اعلان‌های معتبر حفظ شده‌اند؛ با بازشدن دوبارهٔ صندوق تازه‌سازی می‌شوند."
        : "برای دریافت اعلان‌ها، صندوق را دوباره باز کنید.";
      status.dataset.state = this.adminNotificationsLoaded ? "ready" : "idle";
    }
    const retry = document.getElementById("adminNotificationsRetryBtn");
    if (retry) {
      retry.removeAttribute("aria-disabled");
      if (status?.dataset.state !== "error") retry.hidden = true;
    }
    const more = document.getElementById("loadMoreAdminNotificationsBtn");
    if (more) {
      more.disabled = false;
      more.hidden = !this.adminNotificationHasMore;
    }
  }

  async loadAdminNotifications({ append = false } = {}) {
    if (this.adminNotificationLoading || this.adminNotificationMarkingIds.size > 0 || !this.getAdminSession()) return;

    const seq = ++this.adminNotificationRequestSeq;
    const requestedPage = append ? this.adminNotificationPage + 1 : 1;
    const status = document.getElementById("adminNotificationsStatus");
    const list = document.getElementById("adminNotificationsList");
    const retry = document.getElementById("adminNotificationsRetryBtn");
    const more = document.getElementById("loadMoreAdminNotificationsBtn");
    const retryWasVisible = Boolean(retry && !retry.hidden);
    const retryHadFocus = Boolean(retryWasVisible && document.activeElement === retry);
    this.adminNotificationLoading = true;
    this.adminNotificationRetryAppend = append;

    if (status) {
      status.textContent = append ? "در حال دریافت اعلان‌های قدیمی‌تر…" : "در حال دریافت اعلان‌ها از سرور…";
      status.dataset.state = "loading";
    }
    // Keep the previous validated rows and unread count together during refresh.
    this.setAdminNotificationSkeleton(list, !this.adminNotificationsLoaded || append, {
      append: append && this.adminNotificationsLoaded,
      count: append ? 2 : 3,
    });
    if (list) {
      list.setAttribute("aria-busy", "true");
      list.inert = true;
    }
    if (retry) {
      if (retryWasVisible) retry.setAttribute("aria-disabled", "true");
      else retry.hidden = true;
    }
    if (more) more.disabled = true;

    let succeeded = false;
    try {
      const data = await apiRequest("getAdminNotifications", { query: { page: requestedPage, perPage: 25 } });
      if (seq !== this.adminNotificationRequestSeq) return;
      if (!this.getAdminSession()) {
        this.resetAdminNotifications();
        return;
      }
      if (!Array.isArray(data?.notifications) || data.notifications.length > 25
        || !data.notifications.every(isAdminNotificationItem)
        || !isAdminFixedPage(data?.pagination, requestedPage, 25)
        || !Number.isSafeInteger(data?.unreadCount) || data.unreadCount < 0) {
        throw new Error("ساختار صندوق اعلان‌های مدیریتی معتبر نیست؛ پاسخ ناقص یا خارج از قرارداد نمایش داده نمی‌شود.");
      }

      const incoming = data.notifications;
      if (append) {
        const existingIds = new Set(this.adminNotifications.map((item) => item.id));
        this.adminNotifications = this.adminNotifications.concat(incoming.filter((item) => !existingIds.has(item.id)));
      } else {
        this.adminNotifications = incoming;
      }
      this.adminNotificationsLoaded = true;
      this.adminNotificationPage = requestedPage;
      this.adminNotificationHasMore = data.pagination.hasMore;
      this.adminNotificationUnreadCount = Math.max(0, data.unreadCount);
      this.renderAdminNotifications();
      if (status) {
        status.textContent = this.adminNotifications.length === 0
          ? "اعلان جدیدی در صندوق داخلی مدیر وجود ندارد."
          : `${fmtNumber(this.adminNotifications.length)} اعلان بارگذاری شد؛ ${fmtNumber(this.adminNotificationUnreadCount)} اعلان خوانده‌نشده است.`;
        status.dataset.state = this.adminNotifications.length === 0 ? "empty" : "ready";
      }
      if (retry) {
        retry.hidden = true;
        retry.removeAttribute("aria-disabled");
      }
      if (more) more.hidden = !this.adminNotificationHasMore;
      succeeded = true;
    } catch (error) {
      if (seq !== this.adminNotificationRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      if (status) {
        const retained = this.adminNotificationsLoaded
          ? (append ? " اعلان‌های قبلی حفظ شدند." : " آخرین صندوق معتبر حفظ شده است؛ داده‌های نمایش‌داده‌شده ممکن است تازه نباشند.")
          : " هنوز دادهٔ معتبری برای نمایش دریافت نشده است.";
        status.textContent = `${error?.message || "دریافت صندوق اعلان‌ها ناموفق بود."}${retained}`;
        status.dataset.state = "error";
      }
      if (retry) {
        retry.hidden = false;
        retry.removeAttribute("aria-disabled");
      }
      if (more) more.hidden = !this.adminNotificationHasMore;
    } finally {
      if (seq === this.adminNotificationRequestSeq) {
        this.adminNotificationLoading = false;
        this.setAdminNotificationSkeleton(list, false);
        if (list) list.inert = false;
        if (retry) retry.removeAttribute("aria-disabled");
        if (more) more.disabled = false;
        if (succeeded && retryHadFocus) {
          const firstAction = list?.querySelector("[data-admin-notification-read]");
          (firstAction || document.getElementById("adminNotificationsTab"))?.focus();
        }
      }
    }
  }

  renderAdminNotifications() {
    const list = document.getElementById("adminNotificationsList");
    const count = document.getElementById("adminNotificationsTabCount");
    if (count) {
      count.textContent = fmtNumber(this.adminNotificationUnreadCount);
      count.hidden = this.adminNotificationUnreadCount < 1;
      count.setAttribute("aria-label", `${this.adminNotificationUnreadCount} اعلان خوانده‌نشده`);
    }
    if (!list) return;
    if (!this.adminNotifications.length) {
      list.innerHTML = '<p class="admin-notification-empty">اعلانی برای نمایش وجود ندارد.</p>';
      return;
    }
    list.innerHTML = this.adminNotifications.map((item) => {
      const read = item.read === true;
      const marking = this.adminNotificationMarkingIds.has(item.id);
      const actionLocked = this.adminNotificationMarkingIds.size > 0;
      const timestamp = typeof item.timestamp === "string" ? item.timestamp : "";
      return `<article class="admin-notification-item ${read ? "is-read" : "is-unread"}" ${marking ? 'aria-busy="true"' : ""}>
        <div class="admin-notification-content">
          <div class="admin-notification-heading"><h3>${html(item.title)}</h3><span>${read ? "خوانده‌شده" : "خوانده‌نشده"}</span></div>
          <p>${html(item.message)}</p>
          <time datetime="${html(timestamp)}">${html(fmtDate(timestamp))}</time>
        </div>
        ${read ? "" : `<button type="button" class="btn btn-outline btn-sm" data-admin-notification-read data-notification-id="${html(item.id)}" aria-describedby="adminNotificationsActionStatus" ${actionLocked ? 'aria-disabled="true"' : ""} ${marking ? 'aria-busy="true"' : ""}>${marking ? "در حال ثبت…" : "علامت‌گذاری به‌عنوان خوانده‌شده"}</button>`}
      </article>`;
    }).join("");
  }

  async markAdminNotificationRead(id, button) {
    const notification = typeof id === "string"
      ? this.adminNotifications.find((item) => item.id === id)
      : null;
    if (typeof id !== "string" || !/^[a-f0-9]{32}$/.test(id) || !notification || notification.read === true
      || this.adminNotificationMarkingIds.size > 0 || this.adminNotificationLoading || !this.getAdminSession()) return;

    const sessionGeneration = this.adminNotificationSessionGeneration;
    const notificationIndex = this.adminNotifications.findIndex((item) => item.id === id);
    const list = document.getElementById("adminNotificationsList");
    const actionStatus = document.getElementById("adminNotificationsActionStatus");
    const retry = document.getElementById("adminNotificationsRetryBtn");
    const more = document.getElementById("loadMoreAdminNotificationsBtn");
    const actionButton = button?.dataset.notificationId === id
      ? button
      : Array.from(list?.querySelectorAll("[data-admin-notification-read]") || []).find((item) => item.dataset.notificationId === id) || null;
    const originalLabel = actionButton?.textContent || "علامت‌گذاری به‌عنوان خوانده‌شده";

    this.adminNotificationMarkingIds.add(id);
    if (actionStatus) {
      actionStatus.textContent = "در حال ثبت وضعیت خوانده‌شدن اعلان…";
      actionStatus.dataset.state = "loading";
    }
    list?.querySelectorAll("[data-admin-notification-read]").forEach((control) => {
      control.setAttribute("aria-disabled", "true");
      if (control.dataset.notificationId === id) {
        control.setAttribute("aria-busy", "true");
        control.textContent = "در حال ثبت…";
        control.closest(".admin-notification-item")?.setAttribute("aria-busy", "true");
      }
    });
    if (retry && !retry.hidden) retry.setAttribute("aria-disabled", "true");
    if (more && !more.hidden) more.setAttribute("aria-disabled", "true");

    try {
      const data = await apiRequest("markAdminNotificationRead", { method: "POST", body: { id } });
      if (sessionGeneration !== this.adminNotificationSessionGeneration) return;
      if (!this.getAdminSession()) {
        this.resetAdminNotifications();
        return;
      }
      if (data?.id !== id || data?.read !== true || !Number.isSafeInteger(data?.unreadCount) || data.unreadCount < 0) {
        throw new Error("تأیید خوانده‌شدن اعلان از سرور معتبر نیست.");
      }

      this.adminNotifications = this.adminNotifications.map((item) => item.id === id ? { ...item, read: true } : item);
      this.adminNotificationUnreadCount = Math.max(0, data.unreadCount);
      const notificationsView = document.getElementById("adminNotificationsView");
      const focusedControl = list?.contains(document.activeElement) ? document.activeElement : null;
      const focusedNotificationId = focusedControl?.getAttribute("data-notification-id") || "";
      const restoreListFocus = Boolean(focusedControl && !notificationsView?.hidden);
      this.adminNotificationMarkingIds.delete(id);
      this.renderAdminNotifications();

      const status = document.getElementById("adminNotificationsStatus");
      if (status) {
        status.textContent = `${fmtNumber(this.adminNotifications.length)} اعلان نمایش داده می‌شود؛ ${fmtNumber(this.adminNotificationUnreadCount)} اعلان خوانده‌نشده است.`;
        status.dataset.state = "ready";
      }
      if (actionStatus) {
        actionStatus.textContent = "وضعیت اعلان با تأیید سرور به خوانده‌شده تغییر کرد.";
        actionStatus.dataset.state = "success";
      }

      if (restoreListFocus && !notificationsView?.hidden) {
        const actions = Array.from(list?.querySelectorAll("[data-admin-notification-read]") || []);
        const sameNotificationAction = focusedNotificationId && focusedNotificationId !== id
          ? actions.find((item) => item.dataset.notificationId === focusedNotificationId)
          : null;
        const followingUnread = this.adminNotifications.slice(Math.max(0, notificationIndex + 1)).find((item) => !item.read)
          || this.adminNotifications.slice(0, Math.max(0, notificationIndex)).reverse().find((item) => !item.read);
        const nextAction = sameNotificationAction || (followingUnread
          ? actions.find((item) => item.dataset.notificationId === followingUnread.id)
          : null);
        (nextAction || document.getElementById("adminNotificationsTab"))?.focus({ preventScroll: true });
      }
    } catch (error) {
      if (sessionGeneration !== this.adminNotificationSessionGeneration) return;
      if (this.handleAdminAuthFailure(error)) return;
      if (actionStatus) {
        actionStatus.textContent = error?.message || "وضعیت اعلان تغییر نکرد؛ دوباره تلاش کنید.";
        actionStatus.dataset.state = "error";
      }
      this.notification?.error(error?.message || "وضعیت اعلان تغییر نکرد؛ دوباره تلاش کنید.");
    } finally {
      if (sessionGeneration === this.adminNotificationSessionGeneration) {
        this.adminNotificationMarkingIds.delete(id);
        if (actionButton?.isConnected) {
          actionButton.removeAttribute("aria-disabled");
          actionButton.removeAttribute("aria-busy");
          actionButton.textContent = originalLabel;
          actionButton.closest(".admin-notification-item")?.removeAttribute("aria-busy");
        }
        list?.querySelectorAll("[data-admin-notification-read]").forEach((control) => {
          control.removeAttribute("aria-disabled");
          if (control.dataset.notificationId === id) control.removeAttribute("aria-busy");
        });
        retry?.removeAttribute("aria-disabled");
        more?.removeAttribute("aria-disabled");
      }
    }
  }

  setStatsSkeleton(isLoading) {
    ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => {
      const node = byId(id);
      if (!node) return;
      node.classList.toggle("is-loading", isLoading);
      if (isLoading) {
        node.textContent = "";
        node.setAttribute("aria-hidden", "true");
      } else {
        node.removeAttribute("aria-hidden");
      }
    });
  }

  resetAdminStats() {
    this.statsLoaded = false;
    const metrics = byId("adminMetrics");
    if (metrics) metrics.setAttribute("aria-busy", "false");
    this.setStatsSkeleton(false);
    ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => this.setMetric(id, null));
    const retry = byId("adminStatsRetryBtn");
    if (retry) {
      retry.hidden = true;
      retry.disabled = false;
      retry.removeAttribute("aria-disabled");
      retry.textContent = "تلاش دوباره برای دریافت آمار";
    }
    const status = byId("adminStatsStatus");
    if (status) {
      status.textContent = "آمار پس از تأیید نشست مدیریت از سرور دریافت می‌شود.";
      status.dataset.state = "idle";
    }
  }

  async loadStats() {
    const seq = ++this.statsRequestSeq;
    const status = byId("adminStatsStatus");
    const metrics = byId("adminMetrics");
    const retry = byId("adminStatsRetryBtn");
    const retryWasVisible = Boolean(retry && !retry.hidden);
    const hasPreviousStats = this.statsLoaded;

    if (metrics) metrics.setAttribute("aria-busy", "true");
    if (!hasPreviousStats) this.setStatsSkeleton(true);
    if (status) {
      status.textContent = hasPreviousStats
        ? "در حال به‌روزرسانی آمار؛ مقادیر معتبر قبلی تا دریافت پاسخ جدید حفظ می‌شوند."
        : "در حال دریافت آمار از سرور…";
      status.dataset.state = "loading";
    }
    if (retry) {
      retry.setAttribute("aria-disabled", "true");
      if (retryWasVisible) retry.textContent = "در حال به‌روزرسانی آمار…";
    }

    try {
      const data = await apiRequest("getAdminStats");
      if (seq !== this.statsRequestSeq) return;
      const projects = data?.projects;
      const users = data?.users;
      if (!projects || !users) throw new Error("ساختار آمار دریافتی معتبر نیست.");
      this.populateProjectTypes(Array.isArray(data.projectTypes) ? data.projectTypes : []);
      this.setMetric("metricTotalUsers", users.totalUsers);
      this.setMetric("metricPending", projects.pending);
      this.setMetric("metricInProgress", projects.inProgress);
      this.setMetric("metricCompleted", projects.completed);
      this.statsLoaded = true;
      this.setStatsSkeleton(false);
      if (status) {
        status.textContent = "آمار از داده‌های ثبت‌شدهٔ سرور دریافت شد.";
        status.dataset.state = "ready";
      }
      if (retry) {
        retry.hidden = !retryWasVisible;
        retry.textContent = retryWasVisible ? "به‌روزرسانی آمار" : "تلاش دوباره برای دریافت آمار";
      }
    } catch (error) {
      if (seq !== this.statsRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      if (!hasPreviousStats) {
        this.setStatsSkeleton(false);
        ["metricTotalUsers", "metricPending", "metricInProgress", "metricCompleted"].forEach((id) => this.setMetric(id, null));
      }
      if (status) {
        const message = error?.message || "دریافت آمار ناموفق بود؛ اعداد نمایش داده نمی‌شوند.";
        status.textContent = hasPreviousStats
          ? `به‌روزرسانی آمار ناموفق بود؛ آخرین مقادیر دریافت‌شده حفظ شده‌اند. ${message}`
          : message;
        status.dataset.state = "error";
      }
      if (retry) {
        retry.hidden = false;
        retry.textContent = "تلاش دوباره برای دریافت آمار";
      }
    } finally {
      if (seq === this.statsRequestSeq) {
        if (metrics) metrics.setAttribute("aria-busy", "false");
        if (retry) {
          retry.disabled = false;
          retry.removeAttribute("aria-disabled");
        }
      }
    }
  }

  setMetric(id, value) {
    const node = document.getElementById(id);
    if (node) node.textContent = value === null || value === undefined ? "—" : fmtNumber(value);
  }

  populateProjectTypes(types) {
    const select = ADMIN_DOM.adminFilterType;
    if (!select) return;
    const current = select.value || "all";
    const existing = new Set(Array.from(select.options).map((option) => option.value));
    const labels = {
      fashion: "مد و پوشاک", tech: "فناوری", food: "رستوران و غذا", health: "پزشکی و سلامت",
      education: "آموزش", realestate: "املاک", travel: "گردشگری", art: "هنر و طراحی", sport: "ورزش", other: "سایر",
    };
    const options = [['all', 'همه نوع پروژه']];
    for (const type of types) {
      if (typeof type !== "string" || !/^[a-z0-9_]{1,64}$/.test(type)) continue;
      const industry = type.split("_")[0];
      options.push([type, `${labels[industry] || industry} · ${type}`]);
    }
    const signature = options.map(([value, label]) => `${value}:${label}`).join("|");
    if (select.dataset.optionsSignature === signature) return;
    select.innerHTML = options.map(([value, label]) => `<option value="${html(value)}">${html(label)}</option>`).join("");
    select.dataset.optionsSignature = signature;
    select.value = options.some(([value]) => value === current) ? current : "all";
  }

  projectQuery() {
    return {
      page: this.projectPage,
      perPage: this.perPage,
      q: ADMIN_DOM.adminSearchInput?.value?.trim() || "",
      status: ADMIN_DOM.adminFilterStatus?.value || "all",
      projectType: ADMIN_DOM.adminFilterType?.value || "all",
      user: ADMIN_DOM.adminProjectOwnerFilter?.value?.trim() || "",
      date: ADMIN_DOM.adminProjectDateFilter?.value || "",
      sort: ADMIN_DOM.adminProjectSort?.value || "created_at",
      direction: ADMIN_DOM.adminProjectSortDirection?.value || "desc",
    };
  }

  async loadProjects() {
    const seq = ++this.projectRequestSeq;
    const statusNode = document.getElementById("adminProjectsStatus");
    const tableWrap = byId("adminProjectsTableWrap");
    const pagination = ADMIN_DOM.adminPagination;
    const tbody = ADMIN_DOM.adminDataTbody;
    const hasPreviousRows = this.projects.length > 0 && Boolean(tbody?.querySelector("tr:not(.admin-skeleton-row)"));
    if (tableWrap) {
      tableWrap.setAttribute("aria-busy", "true");
      tableWrap.setAttribute("inert", "");
    }
    pagination?.setAttribute("inert", "");
    if (statusNode) {
      statusNode.textContent = hasPreviousRows
        ? "در حال به‌روزرسانی فهرست پروژه‌ها؛ نتایج قبلی تا دریافت پاسخ جدید حفظ شده‌اند."
        : "در حال دریافت پروژه‌ها از سرور…";
      statusNode.dataset.state = "loading";
    }
    if (!hasPreviousRows && tbody) tbody.innerHTML = renderAdminTableSkeleton("projects");
    try {
      const data = await apiRequest("getAll", { query: this.projectQuery() });
      if (seq !== this.projectRequestSeq) return;
      if (!Array.isArray(data?.projects) || !data.projects.every(isAdminProjectListItem)
        || !isAdminPagination(data?.pagination)) {
        throw new Error("ساختار پاسخ فهرست پروژه‌ها معتبر نیست؛ شناسه یا وضعیت خارج از قرارداد است و داده نمایش داده نمی‌شود.");
      }
      this.projects = data.projects;
      this.projectPagination = data.pagination;
      this.renderProjects();
      if (statusNode) {
        statusNode.textContent = this.projects.length ? `${fmtNumber(this.projectPagination.total)} پروژه مطابق فیلترهای فعلی.` : "پروژه‌ای با این فیلترها پیدا نشد.";
        statusNode.dataset.state = this.projects.length ? "ready" : "empty";
      }
    } catch (error) {
      if (seq !== this.projectRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      this.projects = [];
      this.projectPagination = { page: this.projectPage, perPage: this.perPage, total: 0, pages: 0 };
      if (ADMIN_DOM.adminDataTbody) ADMIN_DOM.adminDataTbody.innerHTML = '<tr><td colspan="8" class="admin-state-cell">فهرست پروژه‌ها در دسترس نیست.</td></tr>';
      if (ADMIN_DOM.adminPagination) ADMIN_DOM.adminPagination.innerHTML = "";
      if (statusNode) { statusNode.textContent = error?.message || "دریافت پروژه‌ها ناموفق بود؛ این خطا به‌معنی فهرست خالی نیست."; statusNode.dataset.state = "error"; }
    } finally {
      if (seq === this.projectRequestSeq) {
        if (tableWrap) {
          tableWrap.setAttribute("aria-busy", "false");
          tableWrap.removeAttribute("inert");
        }
        pagination?.removeAttribute("inert");
      }
    }
  }

  renderProjects() {
    const tbody = ADMIN_DOM.adminDataTbody;
    if (!tbody) return;
    if (!this.projects.length) {
      tbody.innerHTML = '<tr><td colspan="8" class="admin-state-cell">پروژه‌ای برای نمایش وجود ندارد.</td></tr>';
      this.renderPagination("project");
      return;
    }
    tbody.innerHTML = this.projects.map((project) => {
      const terminal = ["completed", "rejected", "cancelled"].includes(project.status);
      const features = Array.isArray(project.featuresLabels) ? project.featuresLabels : [];
      const allowed = new Set([project.status, ...(PROJECT_TRANSITIONS[project.status] || [])]);
      const statusOptions = PROJECT_STATUSES.filter(([key]) => allowed.has(key)).map(([key, label]) => `<option value="${key}" ${project.status === key ? "selected" : ""}>${label}</option>`).join("");
      return `<tr>
        <td data-label="کد پیگیری"><strong class="admin-tracking-code">${html(project.trackingCode || "—")}</strong><div class="admin-cell-muted">${html(fmtDate(project.createdAt))}</div></td>
        <td data-label="مشتری"><div class="admin-cell-primary">${html(project.fullName || "—")}</div><div class="admin-cell-muted">${html(project.phone || "—")}</div>${project.email ? `<div class="admin-cell-muted">${html(project.email)}</div>` : ""}</td>
        <td data-label="نوع پروژه"><div class="admin-cell-primary">${html(project.industryLabel || "نامشخص")}</div><div class="admin-cell-muted">${html(project.purposeLabel || project.title || "نامشخص")}</div></td>
        <td data-label="درخواست"><div>${html(project.styleLabel || "نامشخص")}</div><div class="admin-cell-muted">${html(project.timelineLabel || "نامشخص")}</div></td>
        <td data-label="وضعیت و پیشرفت"><div class="admin-status-control"><select class="form-input form-select" data-project-status="${html(project.id)}" aria-label="وضعیت پروژه ${html(project.trackingCode)}">${statusOptions}</select><span class="admin-cell-muted">${html(project.statusLabel || project.status)}</span></div><div class="admin-progress"><progress max="100" value="${Math.max(0, Math.min(100, Number(project.progress) || 0))}"></progress><span>${fmtNumber(project.progress)}٪</span></div></td>
        <td data-label="توضیح مشتری"><div class="admin-note-preview" title="${html(project.userNote || "")}">${html(project.userNote || "—")}</div>${features.length ? `<div class="admin-cell-muted">${html(features.slice(0, 3).join("، "))}${features.length > 3 ? ` (+${fmtNumber(features.length - 3)})` : ""}</div>` : ""}</td>
        <td data-label="یادداشت داخلی"><div class="admin-note-preview" title="${html(project.adminNote || "")}">${html(project.adminNote || "—")}</div><div class="admin-cell-muted">یادداشت داخلی مدیریت</div></td>
        <td data-label="عملیات" class="admin-row-actions"><button type="button" class="btn btn-outline btn-sm" data-project-view="${html(project.id)}">جزئیات</button>${terminal ? `<button type="button" class="btn btn-outline btn-sm btn-danger" data-project-archive="${html(project.id)}">بایگانی</button>` : ""}</td>
      </tr>`;
    }).join("");
    this.renderPagination("project");
  }

  renderPagination(kind) {
    const isProject = kind === "project";
    const node = isProject ? ADMIN_DOM.adminPagination : document.getElementById("adminUserPagination");
    const state = isProject ? this.projectPagination : this.userPagination;
    const page = isProject ? this.projectPage : this.userPage;
    if (!node) return;
    const pages = Math.max(0, Number(state.pages) || Math.ceil((Number(state.total) || 0) / this.perPage));
    if (pages <= 1) { node.innerHTML = ""; return; }
    const previous = Math.max(1, page - 1);
    const next = Math.min(pages, page + 1);
    node.innerHTML = `<div class="admin-pagination-controls"><button type="button" class="btn btn-outline btn-sm" data-admin-page="${previous}" ${page <= 1 ? "disabled" : ""}>قبلی</button><span>صفحه ${fmtNumber(page)} از ${fmtNumber(pages)} · ${fmtNumber(state.total)} نتیجه</span><button type="button" class="btn btn-outline btn-sm" data-admin-page="${next}" ${page >= pages ? "disabled" : ""}>بعدی</button></div>`;
    // Bind both navigation buttons; each has a unique target.
    node.querySelectorAll("[data-admin-page]:not([disabled])").forEach((button) => {
      button.onclick = () => {
        const target = Number(button.dataset.adminPage);
        if (isProject) { this.projectPage = target; void this.loadProjects(); }
        else { this.userPage = target; void this.loadUsers(); }
      };
    });
  }

  async updateProjectStatus(select) {
    const id = Number(select.dataset.projectStatus);
    const project = this.projects.find((item) => Number(item.id) === id);
    if (!project) return;
    const target = select.value;
    if (target === project.status) return;
    let reason = "";
    if (["needs_information", "rejected", "cancelled"].includes(target)) {
      const promptText = target === "needs_information"
        ? "پیام قابل‌مشاهده برای مشتری را وارد کنید؛ چه اطلاعاتی لازم است؟"
        : (target === "rejected" ? "دلیل رد پروژه را وارد کنید:" : "دلیل لغو پروژه را وارد کنید:");
      reason = window.prompt(promptText) || "";
      if (!reason.trim()) { this.renderProjects(); return; }
    }
    const label = PROJECT_STATUSES.find(([key]) => key === target)?.[1] || target;
    if (["completed", "rejected", "cancelled"].includes(target) && !window.confirm(`وضعیت پروژه به «${label}» تغییر کند؟`)) {
      this.renderProjects();
      return;
    }
    select.disabled = true;
    try {
      await apiRequest("adminUpdate", { method: "POST", body: { id, field: "status", value: target, ...(reason ? { reason: reason.trim() } : {}) } });
      this.notification?.success("تغییر وضعیت توسط سرور ثبت شد.");
      await Promise.all([this.loadProjects(), this.loadStats()]);
      if (this.currentDetailId === id) await this.openProjectDetail(id);
    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "تغییر وضعیت ثبت نشد؛ مقدار سرور تغییر داده نشد.");
      this.renderProjects();
    }
  }

  async archiveProject(id) {
    const project = this.projects.find((item) => Number(item.id) === Number(id));
    if (!project || !["completed", "rejected", "cancelled"].includes(project.status)) return;
    if (!window.confirm(`پروژه ${project.trackingCode || "انتخاب‌شده"} از فهرست فعال مدیریت بایگانی شود؟ داده‌ها حذف نخواهند شد.`)) return;
    const button = ADMIN_DOM.adminDataTbody?.querySelector(`[data-project-archive="${Number(id)}"]`);
    if (button) button.disabled = true;
    try {
      await apiRequest("deleteRequest", { method: "POST", body: { id: Number(id) } });
      this.notification?.success("پروژه بایگانی شد؛ سوابق آن حذف نشده‌اند.");
      await Promise.all([this.loadProjects(), this.loadStats()]);
    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "بایگانی پروژه تأیید نشد.");
      if (button) button.disabled = false;
    }
  }

  async openProjectDetail(id, trigger = null) {
    if (!Number.isInteger(id) || id < 1) return;
    const modal = document.getElementById("adminProjectDetailModal");
    const title = document.getElementById("adminProjectDetailTitle");
    const body = document.getElementById("adminProjectDetailBody");
    const status = document.getElementById("adminProjectDetailStatus");
    const skeleton = document.getElementById("adminProjectDetailSkeleton");
    const retry = document.getElementById("adminProjectDetailRetryBtn");
    if (!modal || !body || !status) return;
    const wasModalAlreadyOpen = !modal.hidden;
    this.currentDetailId = id;
    const detailRequestSeq = ++this.currentDetailRequestSeq;
    if (!wasModalAlreadyOpen) {
      this.detailReturnFocus = trigger instanceof HTMLElement ? trigger : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    } else if (trigger instanceof HTMLElement && !modal.contains(trigger)) {
      this.detailReturnFocus = trigger;
    }
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    if (this.isStandaloneWorkspace() && !wasModalAlreadyOpen) {
      const backgroundNodes = this.isUserDetailRoute()
      ? [ADMIN_DOM.adminDataModal].filter(Boolean)
      : [ADMIN_DOM.adminDataModal, this.isAdminMessagesRoute() ? byId("adminMessagesPage") : null, document.querySelector(".admin-page-topbar"), document.getElementById("adminPageNotices")].filter(Boolean);
      this.detailBackgroundState = backgroundNodes.map((node) => ({
        node,
        inert: Boolean(node.inert),
        ariaHidden: node.getAttribute("aria-hidden"),
      }));
      for (const node of backgroundNodes) {
        node.inert = true;
        node.setAttribute("aria-hidden", "true");
      }
    }
    this.syncBodyScrollLock();
    if (title) title.textContent = "جزئیات پروژه";
    status.textContent = "در حال دریافت اطلاعات واقعی پروژه…";
    status.dataset.state = "loading";
    if (skeleton) skeleton.hidden = false;
    body.setAttribute("aria-busy", "true");
    body.replaceChildren();
    if (retry) retry.hidden = true;
    this.currentDetailData = null;
    const noteInput = document.getElementById("adminInternalNoteInput");
    if (noteInput) {
      noteInput.value = "";
      noteInput.disabled = true;
    }
    const noteSubmit = document.getElementById("adminInternalNoteSubmit");
    if (noteSubmit) noteSubmit.disabled = true;
    document.getElementById("adminDetailClose")?.focus();
    try {
      const data = await apiRequest("getAdminProjectDetail", { query: { id } });
      if (this.currentDetailId !== id || detailRequestSeq !== this.currentDetailRequestSeq || modal.hidden) return;
      if (!isAdminProjectDetail(data, id)) throw new Error("ساختار جزئیات پروژه با قرارداد سرور تطبیق ندارد؛ دادهٔ ناقص نمایش داده نمی‌شود.");
      const messages = data.customerMessages;
      if (messages.some((message) => message.senderType === "user" && message.read !== true)) {
        try {
          const receipt = await apiRequest("markAdminMessagesRead", { method: "POST", body: { id } });
          if (receipt?.read === true) {
            data.customerMessages = messages.map((message) => message.senderType === "user" ? { ...message, read: true } : message);
            if (this.isAdminMessagesRoute()) this.updateAdminMessagesReadStateForProject(id, Number(receipt.markedCount) || 0);
          }
        } catch (receiptError) {
          if (this.handleAdminAuthFailure(receiptError)) return;
          // Failed receipt leaves the server-provided unread state visible.
        }
      }
      if (this.currentDetailId !== id || detailRequestSeq !== this.currentDetailRequestSeq || modal.hidden) return;
      if (skeleton) skeleton.hidden = true;
      body.setAttribute("aria-busy", "false");
      this.renderProjectDetail(data);
      status.textContent = "جزئیات از سرور دریافت شد. پیام‌ها و اعلان‌ها از وضعیت ثبت‌شدهٔ سرور پیروی می‌کنند.";
      status.dataset.state = "ready";
      if (retry) retry.hidden = true;
      const noteInput = document.getElementById("adminInternalNoteInput");
      if (noteInput) noteInput.disabled = false;
      const noteSubmit = document.getElementById("adminInternalNoteSubmit");
      if (noteSubmit) noteSubmit.disabled = false;
    } catch (error) {
      if (this.currentDetailId !== id || detailRequestSeq !== this.currentDetailRequestSeq || modal.hidden) return;
      if (skeleton) skeleton.hidden = true;
      body.setAttribute("aria-busy", "false");
      if (this.handleAdminAuthFailure(error)) return;
      status.textContent = error?.message || "دریافت جزئیات پروژه ناموفق بود.";
      status.dataset.state = "error";
      body.replaceChildren();
      if (retry) retry.hidden = false;
    }
  }

  renderProjectDetail(data) {
    const project = data.project;
    const title = document.getElementById("adminProjectDetailTitle");
    const body = document.getElementById("adminProjectDetailBody");
    if (!body) return;
    if (title) title.textContent = `${project.trackingCode || "پروژه"} — ${project.title || project.purposeLabel || "جزئیات"}`;
    const owner = data.owner || {};
    const brief = data.brief || {};
    const colors = [
      ...(Array.isArray(project.paletteLabels) ? project.paletteLabels : []),
      ...(brief.colors && typeof brief.colors === "object" && Array.isArray(brief.colors.customColors) ? brief.colors.customColors : []),
    ];
    const contentLabels = project.contentLabels && typeof project.contentLabels === "object" ? project.contentLabels : {};
    const contentSummary = [["لوگو", contentLabels.logo], ["متن", contentLabels.text], ["تصاویر", contentLabels.images]]
      .filter(([, value]) => typeof value === "string" && value.trim() !== "")
      .map(([label, value]) => `${label}: ${value}`)
      .join(" · ");
    const events = Array.isArray(data.lifecycleHistory) ? data.lifecycleHistory : [];
    const messages = Array.isArray(data.customerMessages) ? data.customerMessages : [];
    const notes = Array.isArray(data.internalNotes) ? data.internalNotes : [];
    const milestones = Array.isArray(data.milestones) ? data.milestones : [];
    const list = (items, renderer, emptyText) => items.length ? `<ul class="admin-detail-list">${items.map(renderer).join("")}</ul>` : `<p class="admin-detail-empty">${emptyText}</p>`;
    body.innerHTML = `
      <section class="admin-detail-section"><h4>وضعیت و مشخصات پروژه</h4><dl class="admin-detail-grid">
        <div><dt>کد پیگیری</dt><dd>${html(project.trackingCode || "—")}</dd></div>
        <div><dt>عنوان</dt><dd>${html(project.title || "—")}</dd></div>
        <div><dt>وضعیت</dt><dd>${html(project.statusLabel || project.status || "—")}</dd></div>
        <div><dt>پیشرفت ثبت‌شده</dt><dd>${fmtNumber(project.progress)}٪</dd></div>
        <div><dt>ثبت درخواست</dt><dd>${html(fmtDate(project.createdAt))}</dd></div>
        <div><dt>آخرین به‌روزرسانی</dt><dd>${html(fmtDate(project.updatedAt))}</dd></div>
        ${project.statusReason ? `<div class="admin-detail-wide"><dt>دلیل وضعیت نهایی</dt><dd>${html(project.statusReason)}</dd></div>` : ""}
        <div><dt>نوع / هدف</dt><dd>${html([project.industryLabel, project.purposeLabel].filter(Boolean).join(" — ") || project.projectType || "—")}</dd></div>
        <div><dt>سبک / زمان‌بندی</dt><dd>${html([project.styleLabel, project.timelineLabel].filter(Boolean).join(" — ") || "—")}</dd></div>
        <div><dt>رنگ‌های منتخب</dt><dd>${html(colors.join("، ") || "—")}</dd></div>
        <div><dt>امکانات درخواستی</dt><dd>${html(Array.isArray(project.featuresLabels) ? project.featuresLabels.join("، ") : "—")}</dd></div>
        <div><dt>وضعیت محتوا</dt><dd>${html(contentSummary || "—")}</dd></div>
        <div><dt>راه ارتباطی</dt><dd>${html([project.messengerLabel, project.messengerId].filter(Boolean).join(" — ") || "—")}</dd></div>
        <div class="admin-detail-wide"><dt>توضیح مشتری</dt><dd>${html(project.userNote || "—")}</dd></div>
        <div class="admin-detail-wide"><dt>وضعیت Brief</dt><dd>${data.briefAvailable ? "داده درخواست قابل‌خواندن است." : "بخشی از Brief ذخیره‌شده قابل‌خواندن نیست؛ داده خام نمایش داده نمی‌شود."}</dd></div>
      </dl></section>
      <section class="admin-detail-section"><h4>اطلاعات حساب مالک</h4><dl class="admin-detail-grid">
        <div><dt>نام</dt><dd>${html(owner.fullName || "—")}</dd></div><div><dt>نام کاربری</dt><dd>${html(owner.username || "—")}</dd></div>
        <div><dt>تلفن حساب</dt><dd>${html(owner.phone || "—")}</dd></div><div><dt>ایمیل حساب</dt><dd>${html(owner.email || "—")}</dd></div>
        <div><dt>وضعیت حساب</dt><dd>${html(ACCOUNT_STATUSES[owner.accountStatus] || owner.accountStatus || "—")}</dd></div>
        <div><dt>تماس ثبت‌شده در درخواست</dt><dd>${html([project.phone, project.email].filter(Boolean).join(" · ") || "—")}</dd></div>
      </dl></section>
      <section class="admin-detail-section"><h4>مراحل پروژه</h4>${list(milestones, (item) => `<li><strong>${html(item.name || item.code)}</strong><span>${html(item.status || "—")} · ${fmtNumber(item.weight)}٪</span><small>${html(fmtDate(item.startedAt))}${item.completedAt ? ` · تکمیل: ${html(fmtDate(item.completedAt))}` : ""}</small></li>`, "برای این پروژه مرحله‌ای ثبت نشده است.")}</section>
      <section class="admin-detail-section"><h4>تاریخچه چرخه عمر</h4>${list(events, (item) => `<li><strong>${html(item.type || "رویداد")}</strong><span>${html(item.actorType || "system")} · ${html([item.fromStatusLabel, item.toStatusLabel].filter(Boolean).join(" ← ") || "رویداد")}</span><small>${html(fmtDate(item.createdAt))}</small></li>`, "تاریخچه‌ای ثبت نشده است.")}</section>
      <section class="admin-detail-section"><h4>پیام‌های مشتری و مدیریت</h4>${list(messages, (item) => `<li><strong>${html(item.senderLabel || item.senderType || "پیام")}</strong><p>${html(item.body || "")}</p><small>${html(fmtDate(item.createdAt))} · ${item.read ? "خوانده‌شده" : "خوانده‌نشده"}</small></li>`, "پیامی ثبت نشده است.")}
        <form id="adminProjectReplyForm" class="admin-project-reply-form" novalidate>
          <label for="adminProjectReplyInput">پاسخ عمومی برای مشتری</label>
          <textarea id="adminProjectReplyInput" name="text" rows="3" maxlength="2000" required placeholder="پیام قابل‌مشاهده برای مشتری را بنویسید…"></textarea>
          <p class="admin-project-reply-status" id="adminProjectReplyStatus" role="status" aria-live="polite"></p>
          <button type="submit" class="btn btn-primary btn-sm" id="adminProjectReplySubmit">ثبت و اعلان به مشتری</button>
        </form>
      </section>
      <section class="admin-detail-section admin-internal-notes"><h4>یادداشت‌های داخلی مدیریت</h4><p>این یادداشت‌ها در مسیر جداگانه ذخیره می‌شوند و در timeline و اعلان مشتری قرار نمی‌گیرند.</p>${list(notes, (item) => `<li><strong>${html(item.adminId || "مدیریت")}</strong><p>${html(item.body || "")}</p><small>${html(fmtDate(item.createdAt))}</small></li>`, "یادداشت داخلی‌ای وجود ندارد.")}</section>`;
    this.currentDetailData = data;
  }

  closeProjectDetail(restoreFocus = true) {
    const modal = document.getElementById("adminProjectDetailModal");
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    const detailSkeleton = document.getElementById("adminProjectDetailSkeleton");
    const detailBody = document.getElementById("adminProjectDetailBody");
    const detailRetry = document.getElementById("adminProjectDetailRetryBtn");
    if (detailSkeleton) detailSkeleton.hidden = true;
    if (detailBody) detailBody.setAttribute("aria-busy", "false");
    if (detailRetry) detailRetry.hidden = true;
    for (const state of this.detailBackgroundState || []) {
      if (!state?.node?.isConnected) continue;
      state.node.inert = state.inert;
      if (state.ariaHidden === null) state.node.removeAttribute("aria-hidden");
      else state.node.setAttribute("aria-hidden", state.ariaHidden);
    }
    this.detailBackgroundState = [];
    this.currentDetailRequestSeq += 1;
    this.currentDetailId = null;
    const noteInput = document.getElementById("adminInternalNoteInput");
    if (noteInput) {
      noteInput.value = "";
      noteInput.disabled = true;
    }
    this.syncBodyScrollLock();
    const refreshUnreadInbox = this.isAdminMessagesRoute() && byId("adminMessagesRead")?.value === "unread";
    if (restoreFocus && refreshUnreadInbox) byId("adminMessagesTitle")?.focus();
    else if (restoreFocus && this.detailReturnFocus?.isConnected) this.detailReturnFocus.focus();
    this.detailReturnFocus = null;
    if (refreshUnreadInbox) void this.loadAdminMessages();
  }

  async sendProjectReply(form) {
    const id = this.currentDetailId;
    const detailSeqAtStart = this.currentDetailRequestSeq;
    const input = form.querySelector("#adminProjectReplyInput");
    const status = form.querySelector("#adminProjectReplyStatus");
    const button = form.querySelector("#adminProjectReplySubmit");
    const text = String(input?.value || "").trim();
    if (!id || !text || text.length > 2000) {
      if (status) { status.textContent = "پیام باید بین ۱ تا ۲۰۰۰ نویسه باشد."; status.dataset.state = "error"; }
      input?.focus();
      return;
    }
    if (this.replySendingId === id) return;
    this.replySendingId = id;
    let fingerprint = null;
    try { fingerprint = await sha256Hex(text); } catch { fingerprint = null; }
    const storageKey = fingerprint ? adminReplyPendingStorageKey(id) : null;
    let priorOperation = this.replyPendingOperations.get(id);
    if (!priorOperation || priorOperation.text !== text) {
      priorOperation = null;
      if (storageKey) {
        try {
          const stored = JSON.parse(globalThis.sessionStorage?.getItem(storageKey) || "null");
          if (stored && stored.fingerprint === fingerprint && typeof stored.key === "string" && /^[a-f0-9]{48}$/.test(stored.key)) {
            priorOperation = { text, fingerprint, key: stored.key };
          }
        } catch { /* Storage may be unavailable or blocked; the in-memory retry path remains active. */ }
      }
    }
    const operation = priorOperation || { text, fingerprint, key: newIdempotencyKey() };
    this.replyPendingOperations.set(id, operation);
    if (storageKey) {
      try { globalThis.sessionStorage?.setItem(storageKey, JSON.stringify({ fingerprint, key: operation.key })); } catch { /* Do not block message sending when tab storage is unavailable. */ }
    }
    if (button) button.disabled = true;
    if (status) { status.textContent = "در حال ثبت پاسخ در سرور…"; status.dataset.state = "loading"; }
    try {
      const result = await apiRequest("adminSendMessage", {
        method: "POST",
        headers: { "Idempotency-Key": operation.key },
        body: { id, text },
      });
      if (result?.recorded !== true || result?.deliveryStatus !== "stored" || !Number.isInteger(result?.id)) {
        throw new Error("ثبت پاسخ توسط سرور تأیید نشد.");
      }
      if (input) input.value = "";
      if (this.replyPendingOperations.get(id)?.key === operation.key) this.replyPendingOperations.delete(id);
      if (storageKey) {
        try {
          const stored = JSON.parse(globalThis.sessionStorage?.getItem(storageKey) || "null");
          if (stored?.key === operation.key) globalThis.sessionStorage?.removeItem(storageKey);
        } catch { /* A stale non-secret idempotency fingerprint can be cleared by tab close. */ }
      }
      this.notification?.success("پاسخ و اعلان مشتری در سرور ثبت شدند؛ مشاهدهٔ اعلان هنوز تأیید نشده است.");
      const detailModal = document.getElementById("adminProjectDetailModal");
      if (this.currentDetailId === id && detailSeqAtStart === this.currentDetailRequestSeq && detailModal && !detailModal.hidden) {
        await this.openProjectDetail(id);
      }
    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      if (status) { status.textContent = error?.message || "ثبت پاسخ تأیید نشد؛ متن برای تلاش دوباره حفظ شده است."; status.dataset.state = "error"; }
      this.notification?.error(error?.message || "ثبت پاسخ تأیید نشد.");
    } finally {
      if (this.replySendingId === id) this.replySendingId = null;
      const currentForm = document.getElementById("adminProjectReplyForm");
      const currentButton = currentForm?.querySelector("#adminProjectReplySubmit");
      if (currentButton && this.currentDetailId === id && !document.getElementById("adminProjectDetailModal")?.hidden) currentButton.disabled = false;
    }
  }

  async addInternalNote() {
    const id = this.currentDetailId;
    const detailSeqAtStart = this.currentDetailRequestSeq;
    const input = document.getElementById("adminInternalNoteInput");
    const button = document.getElementById("adminInternalNoteSubmit");
    const text = input?.value?.trim() || "";
    if (!id || !text) {
      this.notification?.error("متن یادداشت داخلی را وارد کنید.");
      return;
    }
    if (this.noteSavingId === id) return;
    this.noteSavingId = id;
    if (button) button.disabled = true;
    try {
      await apiRequest("adminUpdate", { method: "POST", body: { id, field: "adminNote", value: text } });
      if (input) input.value = "";
      this.notification?.success("یادداشت داخلی ذخیره شد و به مشتری نمایش داده نمی‌شود.");
      const detailModal = document.getElementById("adminProjectDetailModal");
      if (this.currentDetailId === id && detailSeqAtStart === this.currentDetailRequestSeq && detailModal && !detailModal.hidden) {
        await this.openProjectDetail(id);
      }
      await this.loadProjects();
    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      this.notification?.error(error?.message || "ذخیره یادداشت داخلی تأیید نشد.");
    } finally {
      if (this.noteSavingId === id) this.noteSavingId = null;
      if (button && !document.getElementById("adminProjectDetailModal")?.hidden) button.disabled = false;
    }
  }

  async loadUsers() {
    const seq = ++this.userRequestSeq;
    const statusNode = document.getElementById("adminUsersStatus");
    const tbody = document.getElementById("adminUsersTbody");
    const tableWrap = byId("adminUsersTableWrap");
    const pagination = byId("adminUserPagination");
    const hasPreviousRows = this.users.length > 0 && Boolean(tbody?.querySelector("tr:not(.admin-skeleton-row)"));
    if (tableWrap) {
      tableWrap.setAttribute("aria-busy", "true");
      tableWrap.setAttribute("inert", "");
    }
    pagination?.setAttribute("inert", "");
    if (statusNode) {
      statusNode.textContent = hasPreviousRows
        ? "در حال به‌روزرسانی فهرست کاربران؛ نتایج قبلی تا دریافت پاسخ جدید حفظ شده‌اند."
        : "در حال دریافت کاربران از سرور…";
      statusNode.dataset.state = "loading";
    }
    if (!hasPreviousRows && tbody) tbody.innerHTML = renderAdminTableSkeleton("users");
    const filter = document.getElementById("adminUserFilterStatus")?.value || "all";
    const q = document.getElementById("adminUserSearchInput")?.value?.trim() || "";
    try {
      const sort = document.getElementById("adminUserSort")?.value || "created_at";
      const direction = document.getElementById("adminUserSortDirection")?.value || "desc";
      const date = document.getElementById("adminUserDateFilter")?.value || "";
      const data = await apiRequest("getAllUsers", { query: { page: this.userPage, perPage: this.perPage, status: filter, q, date, sort, direction } });
      if (seq !== this.userRequestSeq) return;
      if (!Array.isArray(data?.users) || !data.users.every(isAdminUserListItem)
        || !isAdminPagination(data?.pagination)) {
        throw new Error("ساختار پاسخ فهرست کاربران معتبر نیست؛ شناسه یا وضعیت خارج از قرارداد است و داده نمایش داده نمی‌شود.");
      }
      this.users = data.users;
      this.userPagination = data.pagination;
      this.renderUsers();
      if (statusNode) { statusNode.textContent = this.users.length ? `${fmtNumber(this.userPagination.total)} کاربر مطابق فیلترهای فعلی.` : "کاربری مطابق این فیلترها پیدا نشد."; statusNode.dataset.state = this.users.length ? "ready" : "empty"; }
    } catch (error) {
      if (seq !== this.userRequestSeq) return;
      if (this.handleAdminAuthFailure(error)) return;
      this.users = [];
      if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="admin-state-cell">فهرست کاربران در دسترس نیست.</td></tr>';
      const pagination = document.getElementById("adminUserPagination");
      if (pagination) pagination.innerHTML = "";
      if (statusNode) { statusNode.textContent = error?.message || "دریافت کاربران ناموفق بود؛ این خطا به‌معنی فهرست خالی نیست."; statusNode.dataset.state = "error"; }
    } finally {
      if (seq === this.userRequestSeq) {
        if (tableWrap) {
          tableWrap.setAttribute("aria-busy", "false");
          tableWrap.removeAttribute("inert");
        }
        pagination?.removeAttribute("inert");
      }
    }
  }

  renderUsers() {
    const tbody = document.getElementById("adminUsersTbody");
    if (!tbody) return;
    if (!this.users.length) tbody.innerHTML = '<tr><td colspan="6" class="admin-state-cell">کاربری برای نمایش وجود ندارد.</td></tr>';
    else tbody.innerHTML = this.users.map((user) => {
      const canToggle = ["active", "suspended"].includes(user.accountStatus);
      const target = user.accountStatus === "active" ? "suspended" : "active";
      return `<tr>
        <td data-label="کاربر"><strong>${html(user.fullName || "—")}</strong><div class="admin-cell-muted">@${html(user.username || "—")}</div></td>
        <td data-label="اطلاعات تماس">${html(user.phone || "—")}<div class="admin-cell-muted">${html(user.email || "—")}</div></td>
        <td data-label="وضعیت حساب"><span class="admin-account-status" data-status="${html(user.accountStatus)}">${html(user.accountStatusLabel || ACCOUNT_STATUSES[user.accountStatus] || user.accountStatus)}</span></td>
        <td data-label="تعداد پروژه">${fmtNumber(user.projectCount)}</td>
        <td data-label="تاریخ و آخرین ورود">${html(fmtDate(user.createdAt))}<div class="admin-cell-muted">آخرین ورود: ${html(fmtDate(user.lastLoginAt))}</div></td>
        <td data-label="عملیات"><div class="admin-user-row-actions"><a class="btn btn-outline btn-sm" href="admin-user.php?id=${encodeURIComponent(String(user.publicId || ""))}">مشاهده پرونده</a>${canToggle ? `<button type="button" class="btn btn-outline btn-sm ${target === "suspended" ? "btn-danger" : ""}" data-user-id="${html(user.id)}" data-user-status="${target}">${target === "suspended" ? "تعلیق حساب" : "فعال‌سازی حساب"}</button>` : '<span class="admin-cell-muted">تغییر وضعیت از این مسیر مجاز نیست</span>'}</div></td>
      </tr>`;
    }).join("");
    this.renderPagination("user");
  }

  async openUserDetail(publicId, trigger = null) {
    if (typeof publicId !== "string" || !/^[a-f0-9]{32}$/.test(publicId)) return;
    const modal = document.getElementById("adminUserDetailModal");
    const title = document.getElementById("adminUserDetailTitle");
    const status = document.getElementById("adminUserDetailStatus");
    const body = document.getElementById("adminUserDetailBody");
    const retryPanel = document.getElementById("adminUserDetailRetryPanel");
    const retryButton = document.getElementById("adminUserDetailRetryBtn");
    if (!modal || !status || !body) return;

    const wasAlreadyOpen = !modal.hidden;
    const retryInProgress = wasAlreadyOpen && retryPanel && !retryPanel.hidden;
    if (!wasAlreadyOpen) {
      this.userDetailReturnFocus = trigger instanceof HTMLElement
        ? trigger
        : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
      const backgroundNodes = [ADMIN_DOM.adminDataModal, document.querySelector(".admin-page-topbar"), document.getElementById("adminPageNotices")].filter(Boolean);
      this.userDetailBackgroundState = backgroundNodes.map((node) => ({ node, inert: Boolean(node.inert), ariaHidden: node.getAttribute("aria-hidden") }));
      for (const node of backgroundNodes) { node.inert = true; node.setAttribute("aria-hidden", "true"); }
      this.syncBodyScrollLock();
      title?.focus();
    }

    this.currentUserDetailId = publicId;
    const seq = ++this.userDetailRequestSeq;
    if (title) title.textContent = "پروندهٔ کاربر";
    status.textContent = retryInProgress
      ? "تلاش دوباره برای دریافت اطلاعات مجاز حساب از سرور…"
      : "در حال دریافت اطلاعات مجاز حساب از سرور…";
    status.dataset.state = "loading";
    body.setAttribute("aria-busy", "true");
    body.replaceChildren(createAdminUserDossierSkeleton());
    if (retryButton) {
      retryButton.setAttribute("aria-disabled", "true");
      retryButton.textContent = "در حال دریافت…";
    }
    if (retryPanel && !retryInProgress) retryPanel.hidden = true;

    try {
      const data = await apiRequest("getAdminUserDetail", { query: { id: publicId } });
      if (seq !== this.userDetailRequestSeq || this.currentUserDetailId !== publicId || modal.hidden) return;
      if (!isAdminUserDetail(data, publicId)) {
        throw new Error("ساختار پروندهٔ کاربر با قرارداد سرور تطبیق ندارد؛ دادهٔ ناقص نمایش داده نمی‌شود.");
      }
      this.renderUserDetail(data);
      status.textContent = "پرونده از API محافظت‌شده دریافت شد؛ شناسه‌های نشست و داده‌های احراز هویت نمایش داده نمی‌شوند.";
      status.dataset.state = "ready";
      if (retryPanel) retryPanel.hidden = true;
    } catch (error) {
      if (seq !== this.userDetailRequestSeq || this.currentUserDetailId !== publicId || modal.hidden) return;
      if (this.handleAdminAuthFailure(error)) return;
      status.textContent = error?.message || "دریافت پروندهٔ کاربر ناموفق بود؛ اطلاعات خالی فرض نشده است.";
      status.dataset.state = "error";
      body.replaceChildren();
      if (retryPanel) retryPanel.hidden = false;
      if (retryButton) {
        retryButton.removeAttribute("aria-disabled");
        retryButton.textContent = "تلاش دوباره برای دریافت پرونده";
      }
    } finally {
      if (seq === this.userDetailRequestSeq && this.currentUserDetailId === publicId && !modal.hidden) {
        body.setAttribute("aria-busy", "false");
        if (retryButton && (retryPanel?.hidden ?? true)) {
          retryButton.removeAttribute("aria-disabled");
          retryButton.textContent = "تلاش دوباره برای دریافت پرونده";
        }
      }
    }
  }

  renderUserDetail(data) {
    const body = document.getElementById("adminUserDetailBody");
    const title = document.getElementById("adminUserDetailTitle");
    if (!body) return;
    const user = data.user;
    if (title) title.textContent = `پروندهٔ ${user.fullName || user.username || "کاربر"}`;
    const list = (items, render, empty) => items.length ? `<ul class="admin-detail-list">${items.map(render).join("")}</ul>` : `<p class="admin-detail-empty">${empty}</p>`;
    const statusLabel = ACCOUNT_STATUSES[user.accountStatus] || user.accountStatus || "نامشخص";
    body.innerHTML = `
      <section class="admin-detail-section"><h4>اطلاعات حساب</h4><dl class="admin-detail-grid">
        <div><dt>نام</dt><dd>${html(user.fullName || "—")}</dd></div><div><dt>نام کاربری</dt><dd>${html(user.username || "—")}</dd></div>
        <div><dt>تلفن</dt><dd>${html(user.phone || "—")}</dd></div><div><dt>وضعیت حساب</dt><dd>${html(statusLabel)}</dd></div>
        <div><dt>تاریخ ثبت (UTC)</dt><dd>${html(fmtDate(user.createdAt))}</dd></div><div><dt>آخرین ورود</dt><dd>${html(fmtDate(user.lastLoginAt))}</dd></div>
        <div><dt>تعداد پروژه‌های غیرآرشیوی</dt><dd>${fmtNumber(user.projectCount)}</dd></div>
      </dl></section>
      <section class="admin-detail-section"><h4>کانال‌های ارتباطی و وضعیت تأیید</h4>${list(data.channels, (item) => `<li><strong>${html(item.type === "email" ? "ایمیل" : item.type === "telegram" ? "تلگرام" : "کانال")}${item.primary ? " · اصلی" : ""}</strong><span>${html(item.value || "—")}</span><small>${item.verified ? `تأییدشده · ${html(fmtDate(item.verifiedAt))}` : "تأییدنشده"}</small></li>`, "کانال ثبت‌شده‌ای برای این حساب موجود نیست.")}</section>
      <section class="admin-detail-section"><h4>پروژه‌ها</h4>${list(data.projects, (item) => `<li><strong>${html(item.title || item.trackingCode || "پروژه")}</strong><span>${html(item.trackingCode || "—")} · ${html(item.statusLabel || item.status || "—")} · ${fmtNumber(item.progress)}٪</span><small>${html(fmtDate(item.createdAt))}${item.archived ? " · آرشیوشده" : ""}</small></li>`, "پروژه‌ای برای این حساب ثبت نشده است.")}${data.projectsPagination?.hasMore ? `<p class="admin-detail-empty">برای این حساب بیش از ${fmtNumber(data.projects.length)} پروژه وجود دارد؛ نمایش فعلی به اولین صفحه محدود است.</p>` : ""}</section>
      <section class="admin-detail-section"><h4>پیام‌های اخیر پروژه‌ها</h4>${list(data.messages, (item) => `<li><strong>${html(item.projectTitle || item.trackingCode || "پروژه")}</strong><span>${html(item.senderType === "user" ? "مشتری" : item.senderType === "admin" ? "مدیر" : "سامانه")} · ${html(fmtDate(item.createdAt))}</span><p>${html(item.body || "")}</p><small>${item.read ? "خوانده‌شده" : "خوانده‌نشده"}</small></li>`, "پیام ثبت‌شده‌ای برای پروژه‌های این حساب موجود نیست.")}</section>
      <section class="admin-detail-section"><h4>فرادادهٔ امن نشست‌ها</h4>${list(data.sessions, (item) => `<li><strong>${item.revoked ? "نشست لغوشده" : "نشست ثبت‌شده"}</strong><span>آخرین فعالیت: ${html(fmtDate(item.lastActivityAt))}</span><small>ایجاد: ${html(fmtDate(item.createdAt))} · انقضا: ${html(fmtDate(item.expiresAt))}${item.revokedAt ? ` · لغو: ${html(fmtDate(item.revokedAt))}` : ""}${item.revokeReason ? ` · علت: ${html(item.revokeReason)}` : ""}</small></li>`, "فرادادهٔ نشست قابل‌نمایشی وجود ندارد.")}</section>
      <section class="admin-detail-section"><h4>رویدادهای امنیتی/حسابرسی مرتبط با حساب</h4>${list(data.securityEvents, (item) => `<li><strong>${html(item.action || "رویداد")}</strong><span>${html(item.actorType || "system")}</span><small>${html(fmtDate(item.createdAt))}</small></li>`, "رویداد حسابرسی مرتبطی در محدودهٔ این گزارش وجود ندارد.")}</section>`;
  }

  closeUserDetail(restoreFocus = true, navigateRoute = true) {
    const modal = document.getElementById("adminUserDetailModal");
    if (!modal || modal.hidden) return;
    modal.hidden = true;
    modal.setAttribute("aria-hidden", "true");
    this.currentUserDetailId = null;
    this.userDetailRequestSeq += 1;
    const userDetailBody = document.getElementById("adminUserDetailBody");
    userDetailBody?.setAttribute("aria-busy", "false");
    userDetailBody?.replaceChildren();
    const retryPanel = document.getElementById("adminUserDetailRetryPanel");
    const retryButton = document.getElementById("adminUserDetailRetryBtn");
    if (retryPanel) retryPanel.hidden = true;
    if (retryButton) {
      retryButton.removeAttribute("aria-disabled");
      retryButton.textContent = "تلاش دوباره برای دریافت پرونده";
    }
    for (const state of this.userDetailBackgroundState) {
      if (!state.node?.isConnected) continue;
      state.node.inert = state.inert;
      if (state.ariaHidden === null) state.node.removeAttribute("aria-hidden"); else state.node.setAttribute("aria-hidden", state.ariaHidden);
    }
    this.userDetailBackgroundState = [];
    this.syncBodyScrollLock();
    if (navigateRoute && this.isUserDetailRoute()) {
      window.location.assign("admin.php");
      return;
    }
    if (restoreFocus && this.userDetailReturnFocus?.isConnected) this.userDetailReturnFocus.focus();
    this.userDetailReturnFocus = null;
  }

  updateUserStatus(id, target, trigger = null) {
    const user = this.users.find((item) => Number(item.id) === Number(id));
    if (!user || !["active", "suspended"].includes(target)) return;
    if (!ADMIN_DOM.adminUserStatusReasonModal || !ADMIN_DOM.adminUserStatusReasonForm) {
      this.notification?.error("فرم امن تغییر وضعیت حساب در این صفحه در دسترس نیست.");
      return;
    }
    this.pendingUserStatusChange = { id: Number(id), target, user };
    this.userStatusReturnFocus = trigger instanceof HTMLElement ? trigger : (document.activeElement instanceof HTMLElement ? document.activeElement : null);
    const modal = ADMIN_DOM.adminUserStatusReasonModal;
    const name = String(user.fullName || user.username || "کاربر");
    if (ADMIN_DOM.adminUserStatusReasonTitle) {
      ADMIN_DOM.adminUserStatusReasonTitle.textContent = target === "suspended" ? "تعلیق حساب کاربر" : "فعال‌سازی حساب کاربر";
    }
    if (ADMIN_DOM.adminUserStatusReasonImpact) {
      ADMIN_DOM.adminUserStatusReasonImpact.textContent = target === "suspended"
        ? `حساب ${name} تعلیق می‌شود و نشست‌های فعال کاربر در سمت سرور باطل خواهند شد.`
        : `حساب ${name} دوباره فعال می‌شود؛ نشست‌های قبلی برنمی‌گردند و کاربر باید دوباره وارد شود.`;
    }
    if (ADMIN_DOM.adminUserStatusReasonInput) ADMIN_DOM.adminUserStatusReasonInput.value = "";
    if (ADMIN_DOM.adminUserStatusReasonStatus) {
      ADMIN_DOM.adminUserStatusReasonStatus.textContent = "";
      ADMIN_DOM.adminUserStatusReasonStatus.dataset.state = "idle";
    }
    if (ADMIN_DOM.adminUserStatusReasonSubmit) {
      ADMIN_DOM.adminUserStatusReasonSubmit.textContent = target === "suspended" ? "تأیید تعلیق حساب" : "تأیید فعال‌سازی حساب";
      ADMIN_DOM.adminUserStatusReasonSubmit.disabled = false;
    }
    this.userStatusBackgroundState = [
      document.getElementById("adminDataModal"),
      document.getElementById("adminPageTopbar"),
      document.getElementById("adminPageNotices"),
    ].filter(Boolean).map((element) => ({
      element,
      inert: Boolean(element.inert),
      ariaHidden: element.getAttribute("aria-hidden"),
    }));
    this.userStatusBackgroundState.forEach(({ element }) => {
      element.inert = true;
      element.setAttribute("aria-hidden", "true");
    });
    modal.hidden = false;
    modal.setAttribute("aria-hidden", "false");
    this.syncBodyScrollLock();
    window.requestAnimationFrame(() => ADMIN_DOM.adminUserStatusReasonInput?.focus());
  }

  closeUserStatusReasonModal(restoreFocus = true) {
    const modal = ADMIN_DOM.adminUserStatusReasonModal;
    if (modal) {
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
    }
    if (ADMIN_DOM.adminUserStatusReasonInput) ADMIN_DOM.adminUserStatusReasonInput.value = "";
    if (ADMIN_DOM.adminUserStatusReasonStatus) {
      ADMIN_DOM.adminUserStatusReasonStatus.textContent = "";
      ADMIN_DOM.adminUserStatusReasonStatus.dataset.state = "idle";
    }
    for (const state of this.userStatusBackgroundState || []) {
      if (!state.element?.isConnected) continue;
      state.element.inert = state.inert;
      if (state.ariaHidden === null) state.element.removeAttribute("aria-hidden");
      else state.element.setAttribute("aria-hidden", state.ariaHidden);
    }
    this.userStatusBackgroundState = null;
    const returnFocus = this.userStatusReturnFocus;
    this.userStatusReturnFocus = null;
    this.pendingUserStatusChange = null;
    this.syncBodyScrollLock();
    if (restoreFocus && returnFocus?.isConnected) returnFocus.focus();
  }

  async submitUserStatusReason(event) {
    event.preventDefault();
    const pending = this.pendingUserStatusChange;
    if (!pending || !ADMIN_DOM.adminUserStatusReasonModal || ADMIN_DOM.adminUserStatusReasonModal.hidden) return;

    const reason = ADMIN_DOM.adminUserStatusReasonInput?.value?.trim() || "";
    const feedback = ADMIN_DOM.adminUserStatusReasonStatus;
    const length = Array.from(reason).length;
    if (!reason || length > 500 || /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/u.test(reason)) {
      if (feedback) {
        feedback.textContent = !reason ? "ثبت دلیل الزامی است." : "دلیل باید حداکثر ۵۰۰ نویسه و فاقد نویسه‌های کنترلی باشد.";
        feedback.dataset.state = "error";
      }
      ADMIN_DOM.adminUserStatusReasonInput?.focus();
      return;
    }

    const submit = ADMIN_DOM.adminUserStatusReasonSubmit;
    const cancel = document.getElementById("adminUserStatusReasonCancel");
    const close = document.getElementById("adminUserStatusReasonClose");
    if (submit) submit.disabled = true;
    if (cancel) cancel.disabled = true;
    if (close) close.disabled = true;
    if (feedback) { feedback.textContent = "در حال ثبت تغییر وضعیت در سرور…"; feedback.dataset.state = "loading"; }

    let result;
    try {
      result = await apiRequest("adminUpdateUser", {
        method: "POST",
        body: { id: pending.id, status: pending.target, reason },
      });
    } catch (error) {
      if (this.handleAdminAuthFailure(error)) return;
      if (feedback) {
        feedback.textContent = error?.message || "تأیید تغییر وضعیت از سرور دریافت نشد؛ پیش از تلاش دوباره وضعیت حساب را تازه‌سازی کنید.";
        feedback.dataset.state = "error";
      }
      this.notification?.error(error?.message || "نتیجهٔ تغییر وضعیت مشخص نیست؛ پیش از تلاش دوباره وضعیت حساب را تازه‌سازی کنید.");
      return;
    } finally {
      if (ADMIN_DOM.adminUserStatusReasonModal && !ADMIN_DOM.adminUserStatusReasonModal.hidden) {
        if (submit) submit.disabled = false;
        if (cancel) cancel.disabled = false;
        if (close) close.disabled = false;
      }
    }

    if (result?.accountStatus !== pending.target) {
      if (feedback) {
        feedback.textContent = "پاسخ سرور تغییر وضعیت را تأیید نکرد؛ پیش از تکرار عملیات، وضعیت حساب را تازه‌سازی کنید.";
        feedback.dataset.state = "error";
      }
      this.notification?.error("پاسخ سرور برای تأیید تغییر وضعیت کافی نبود؛ وضعیت حساب را تازه‌سازی کنید.");
      return;
    }

    const successMessage = `وضعیت حساب به «${ACCOUNT_STATUSES[pending.target]}» تغییر کرد و در سابقهٔ مدیریتی ثبت شد.`;
    this.closeUserStatusReasonModal(false);
    this.notification?.success(successMessage);
    try {
      await Promise.all([this.loadUsers(), this.loadStats()]);
    } catch (refreshError) {
      this.notification?.error("تغییر وضعیت در سرور ثبت شد، اما تازه‌سازی فهرست کامل نشد. پیش از هر اقدام دوباره، فهرست را تازه کنید.");
    }
    document.getElementById("adminUsersStatus")?.focus();
  }

  async exportCSV() {
    const button = ADMIN_DOM.adminExportBtn;
    const status = byId("adminExportStatus");
    if (!button || button.getAttribute("aria-disabled") === "true") return;

    const originalLabel = button.textContent;
    const originalBusy = button.getAttribute("aria-busy");
    const originalAriaDisabled = button.getAttribute("aria-disabled");
    const current = this.projectQuery();

    // Keep the focused control in the tab order while making pending work explicit.
    button.setAttribute("aria-busy", "true");
    button.setAttribute("aria-disabled", "true");
    button.textContent = "در حال دریافت خروجی…";
    if (status) {
      status.textContent = "در حال دریافت خروجی CSV فیلترشده از سرور…";
      status.dataset.state = "loading";
    }

    try {
      const { blob, filename } = await downloadApiFile("exportProjectsCsv", {
        query: { q: current.q, status: current.status, projectType: current.projectType, user: current.user, date: current.date, sort: current.sort, direction: current.direction },
        accept: "text/csv",
        timeoutMs: 60000,
      });
      if (!(blob instanceof Blob) || blob.size === 0) {
        throw new Error("فایل خروجی خالی یا نامعتبر است.");
      }
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename || `mrneon-projects-${new Date().toISOString().slice(0, 10)}.csv`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      if (status) {
        status.textContent = "فایل خروجی آماده شد و دانلود به مرورگر واگذار شد.";
        status.dataset.state = "success";
      }
      this.notification?.success("خروجی فیلترشدهٔ پروژه‌ها از سرور آماده شد.");
    } catch (error) {
      const handledAuthFailure = this.handleAdminAuthFailure(error);
      if (status) {
        status.textContent = handledAuthFailure
          ? "نشست مدیریت معتبر نیست؛ برای ادامه دوباره وارد شوید."
          : "دریافت خروجی ناموفق بود؛ پیام خطا در اعلان‌ها نمایش داده شد.";
        status.dataset.state = "error";
      }
      if (!handledAuthFailure) {
        this.notification?.error(error?.message || "ساخت خروجی پروژه‌ها ناموفق بود.");
      }
    } finally {
      button.textContent = originalLabel;
      if (originalBusy === null) button.removeAttribute("aria-busy");
      else button.setAttribute("aria-busy", originalBusy);
      if (originalAriaDisabled === null) button.removeAttribute("aria-disabled");
      else button.setAttribute("aria-disabled", originalAriaDisabled);
    }
  }
}
