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
  getStorage,
  setStorage,
  sanitizeInput,
} from "./app.js";

import { AuthSystem } from "./auth.js";
import { apiFetch, getServerSession } from "./api-client.js";

const PROJECT_SUBMISSION_IDEMPOTENCY_SAFE_RETRY_WINDOW_MS = 22 * 60 * 60 * 1000;

function createSubmissionKey() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  if (globalThis.crypto?.getRandomValues) {
    const bytes = new Uint8Array(32);
    globalThis.crypto.getRandomValues(bytes);
    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
  }
  throw new Error("مرورگر امکان ساخت کلید امن برای ثبت درخواست را ندارد.");
}

function cloneSubmissionPayload(payload) {
  try {
    return JSON.parse(JSON.stringify(payload));
  } catch {
    return null;
  }
}

// Keep the contact-step preflight aligned with ProjectCreationDTO::plainText().
// PHP trim() defaults to this ASCII whitespace/control set; do not broaden it,
// otherwise the browser could accept a value the server later canonicalizes differently.
function trimNeoProjectText(value) {
  return String(value ?? "").replace(/^[\x00\x0B\t\n\r ]+|[\x00\x0B\t\n\r ]+$/g, "");
}

function neoProjectTextLength(value) {
  return Array.from(trimNeoProjectText(value)).length;
}

function hasForbiddenNeoProjectTextControl(value) {
  return /[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/u.test(String(value ?? ""));
}

function normalizeNeoPhoneForValidation(value) {
  const phone = trimNeoProjectText(value)
    .replace(/[۰-۹]/g, (digit) => String(digit.codePointAt(0) - 0x06f0))
    .replace(/[٠-٩]/g, (digit) => String(digit.codePointAt(0) - 0x0660))
    .replace(/[ \t\n\r\f\v().-]/g, "");

  if (/^09[0-9]{9}$/.test(phone)) return `+98${phone.slice(1)}`;
  if (/^\+989[0-9]{9}$/.test(phone)) return phone;
  if (/^00989[0-9]{9}$/.test(phone)) return `+${phone.slice(2)}`;
  return null;
}

const NEO_CUSTOM_COLOR_LIMIT = 4;
const NEO_CUSTOM_COLOR_PATTERN = /^#[0-9a-fA-F]{6}$/;

function normalizeNeoCustomColor(value) {
  if (typeof value !== "string") return null;
  const trimmed = value.trim();
  return NEO_CUSTOM_COLOR_PATTERN.test(trimmed) ? trimmed.toUpperCase() : null;
}

function neoColorContrast(hex) {
  const normalized = normalizeNeoCustomColor(hex);
  if (!normalized) return null;
  const channels = [1, 3, 5].map((offset) => Number.parseInt(normalized.slice(offset, offset + 2), 16) / 255);
  const linear = channels.map((channel) => channel <= 0.04045
    ? channel / 12.92
    : ((channel + 0.055) / 1.055) ** 2.4);
  const luminance = (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2]);
  const blackContrast = (luminance + 0.05) / 0.05;
  const whiteContrast = 1.05 / (luminance + 0.05);
  return blackContrast >= whiteContrast
    ? { textColor: "#000000", ratio: blackContrast }
    : { textColor: "#FFFFFF", ratio: whiteContrast };
}

// ============================================================
// 🤖 مراحل NEO
// ============================================================
export const NEO_STEPS = [
  {
    id: 0,
    title: "👋 خوش آمدید",
    subtitle:
      "من «نیو» هستم؛ همراه شما برای ساختاردهی درخواست پروژه. در چند دقیقه نیازهای پروژه‌تان را مشخص کنید.",
    type: "welcome",
  },
  {
    id: 1,
    title: "🎯 صنعت و هدف سایت شما",
    subtitle: "ابتدا صنعت، سپس هدف را انتخاب کنید",
    type: "industry-purpose",
    industries: [],
    purposesByIndustry: {},
  },
  {
    id: 2,
    title: "🎨 پالت رنگی مورد علاقه",
    subtitle: "چند پالت انتخاب کنید یا رنگ دلخواه وارد کنید",
    type: "colors",
    palettes: [],
    allowCustomColor: true,
  },
  {
    id: 3,
    title: "✨ سبک طراحی",
    subtitle: "چه استایلی دوست داری؟",
    type: "single",
    options: [],
  },
  {
    id: 4,
    title: "🔧 امکانات ویژه",
    subtitle: "هر ویژگی که نیاز داری انتخاب کن",
    type: "multi",
    options: [],
  },
  {
    id: 5,
    title: "📝 وضعیت محتوا",
    subtitle: "بگو محتوا آماده‌ست یا نیاز به کمک داری",
    type: "content",
    questions: [],
  },
  {
    id: 6,
    title: "⏰ بازه زمانی",
    subtitle: "چقدر زود نیاز داری؟",
    type: "single",
    options: [],
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
        options: [],
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

// The browser wizard and PHP DTO consume the exact same public catalog file.
const NEO_CATALOG_URL = new URL("../assets/data/neo-catalog.json", import.meta.url);
let neoCatalogPromise = null;
let neoCatalogLoaded = false;

const NEO_CATALOG_MACHINE_ID_PATTERN = /^[a-z0-9][a-z0-9_]{0,63}$/;
const NEO_CATALOG_CATEGORY_PATTERN = /^[a-z][a-z0-9-]{0,63}$/;
const NEO_CATALOG_CONTROL_PATTERN = /[\u0000-\u001F\u007F-\u009F]/u;

function hasUnpairedNeoCatalogSurrogate(value) {
  for (let index = 0; index < value.length; index += 1) {
    const unit = value.charCodeAt(index);
    if (unit >= 0xD800 && unit <= 0xDBFF) {
      const next = value.charCodeAt(index + 1);
      if (!(next >= 0xDC00 && next <= 0xDFFF)) return true;
      index += 1;
    } else if (unit >= 0xDC00 && unit <= 0xDFFF) {
      return true;
    }
  }
  return false;
}

function isNeoCatalogText(value, maxCodePoints, allowEmpty = false) {
  return typeof value === "string"
    && Array.from(value).length <= maxCodePoints
    && ((allowEmpty && value === "") || value.trim() !== "")
    && !NEO_CATALOG_CONTROL_PATTERN.test(value)
    && !hasUnpairedNeoCatalogSurrogate(value);
}

function isNeoCatalogMachineId(value, allowEmpty = false) {
  return (allowEmpty && value === "")
    || (typeof value === "string" && NEO_CATALOG_MACHINE_ID_PATTERN.test(value));
}

function orderedAllowedItems(items, idKey = "value", allowEmptyId = false, rules = {}) {
  if (!Array.isArray(items)) throw new Error("فهرست کاتالوگ NEO ساختار معتبری ندارد.");
  const seen = new Set();
  for (const item of items) {
    const isPlainRecord = item !== null && typeof item === "object" && !Array.isArray(item)
      && (Object.getPrototypeOf(item) === Object.prototype || Object.getPrototypeOf(item) === null);
    const identifier = isPlainRecord ? item[idKey] : undefined;
    const validIdentifier = rules.identifierMode === "display"
      ? isNeoCatalogText(identifier, 64, allowEmptyId)
      : isNeoCatalogMachineId(identifier, allowEmptyId);
    const validLabel = isPlainRecord && isNeoCatalogText(item.label, 120);
    const validCategory = isPlainRecord && typeof item.category === "string"
      && NEO_CATALOG_CATEGORY_PATTERN.test(item.category);
    const hasContext = isPlainRecord && Object.prototype.hasOwnProperty.call(item, "context");
    const validContext = hasContext && (item.context === null || isNeoCatalogMachineId(item.context));
    const validEmoji = isPlainRecord
      && (!Object.prototype.hasOwnProperty.call(item, "emoji")
        || isNeoCatalogText(item.emoji, 16, true));
    const validOrder = isPlainRecord && Number.isSafeInteger(item.sortOrder) && item.sortOrder >= 0;
    if (!isPlainRecord || !validIdentifier || !validLabel || !validCategory || !validContext
        || typeof item.allowed !== "boolean" || !validOrder || !validEmoji) {
      throw new Error("یکی از گزینه‌های کاتالوگ NEO ناقص یا خارج از حدود مجاز است.");
    }
    if (Object.prototype.hasOwnProperty.call(rules, "expectedCategory")
      && item.category !== rules.expectedCategory) {
      throw new Error("دسته‌بندی یکی از گزینه‌های کاتالوگ NEO معتبر نیست.");
    }
    if (Object.prototype.hasOwnProperty.call(rules, "expectedContext")) {
      const expectedContext = typeof rules.contextForItem === "function"
        ? rules.contextForItem(item)
        : rules.expectedContext;
      if (item.context !== expectedContext) {
        throw new Error("زمینهٔ یکی از گزینه‌های کاتالوگ NEO معتبر نیست.");
      }
    }
    if (typeof rules.validateItem === "function" && !rules.validateItem(item)) {
      throw new Error("ساختار تکمیلی یکی از گزینه‌های کاتالوگ NEO معتبر نیست.");
    }
    if (seen.has(identifier)) throw new Error("شناسهٔ تکراری در کاتالوگ NEO پیدا شد.");
    seen.add(identifier);
  }
  return items.filter((item) => item.allowed === true)
    .sort((left, right) => left.sortOrder - right.sortOrder);
}

function applyNeoCatalog(catalog) {
  const isPlainCatalog = catalog !== null && typeof catalog === "object" && !Array.isArray(catalog)
    && (Object.getPrototypeOf(catalog) === Object.prototype || Object.getPrototypeOf(catalog) === null);
  if (!isPlainCatalog || catalog.schemaVersion !== 1) {
    throw new Error("نسخه یا ساختار کاتالوگ NEO پشتیبانی نمی‌شود.");
  }

  const industries = orderedAllowedItems(catalog.industries, "value", false, {
    expectedCategory: "industry", expectedContext: null,
    validateItem: (item) => Array.isArray(item.purposes) && item.purposes.length > 0,
  });
  // Validate nested purposes for every industry, including disabled entries, before applying any data.
  const purposesByIndustry = Object.create(null);
  for (const industryRecord of catalog.industries) {
    const purposes = orderedAllowedItems(industryRecord.purposes, "value", false, {
      expectedCategory: "purpose", expectedContext: industryRecord.value,
    });
    if (purposes.some((purpose) => `${industryRecord.value}_${purpose.value}`.length > 64)) {
      throw new Error("شناسهٔ ترکیبی صنعت و هدف از حد مجاز قرارداد پروژه بیشتر است.");
    }
    if (industryRecord.allowed === true) {
      if (!purposes.length) throw new Error("هر صنعت فعال باید حداقل یک هدف مجاز داشته باشد.");
      purposesByIndustry[industryRecord.value] = purposes.map((purpose) => ({
        value: purpose.value, label: purpose.label,
      }));
    }
  }

  const palettes = orderedAllowedItems(catalog.palettes, "value", false, {
    expectedCategory: "color-palette", expectedContext: null,
    validateItem: (item) => Array.isArray(item.colors) && item.colors.length >= 3
      && item.colors.every((color) => typeof color === "string" && /^#[0-9a-fA-F]{6}$/.test(color)),
  }).map((item) => ({ id: item.value, name: item.label, colors: [...item.colors] }));

  const styles = orderedAllowedItems(catalog.styles, "value", false, {
    expectedCategory: "style", expectedContext: null,
  }).map((item) => ({ value: item.value, label: item.label, emoji: item.emoji || "" }));

  const features = orderedAllowedItems(catalog.features, "value", false, {
    expectedCategory: "feature", expectedContext: null,
  }).map((item) => ({ value: item.value, label: item.label, emoji: item.emoji || "" }));

  const timelines = orderedAllowedItems(catalog.timelines, "value", false, {
    expectedCategory: "timeline", expectedContext: null,
  }).map((item) => ({ value: item.value, label: item.label, emoji: item.emoji || "" }));

  const contentRecords = orderedAllowedItems(catalog.content, "id", false, {
    expectedCategory: "content-status", contextForItem: (item) => item.id,
    expectedContext: null,
  });
  // Validate nested options for every question, including disabled records, before filtering.
  for (const question of catalog.content) {
    orderedAllowedItems(question.options, "value", false, {
      expectedCategory: "content-option", expectedContext: question.id,
    });
  }
  const contentQuestions = contentRecords.map((question) => {
    const options = orderedAllowedItems(question.options, "value", false, {
      expectedCategory: "content-option", expectedContext: question.id,
    });
    return { id: question.id, label: question.label, options: options.map((option) => ({
      value: option.value, label: option.label,
    })) };
  });

  const messengers = orderedAllowedItems(catalog.messengers, "value", true, {
    identifierMode: "display", expectedCategory: "messenger", expectedContext: null,
  }).map((item) => ({ value: item.value, label: item.label }));

  const contentFieldIds = contentQuestions.map((question) => question.id).sort();
  const expectedContentFieldIds = ["images", "logo", "text"];
  if (!industries.length || industries.some((industry) => !purposesByIndustry[industry.value]?.length)
      || !palettes.length || !styles.length || !features.length || !timelines.length
      || contentFieldIds.length !== expectedContentFieldIds.length
      || contentFieldIds.some((id, index) => id !== expectedContentFieldIds[index])
      || !messengers.some((item) => item.value === "")) {
    throw new Error("کاتالوگ NEO با قرارداد ثابت فرم سازگار نیست.");
  }

  // Apply only after every required collection has been validated.
  NEO_STEPS[1].industries = industries.map((item) => ({
    value: item.value, label: item.label, emoji: String(item.emoji || ""),
  }));
  NEO_STEPS[1].purposesByIndustry = purposesByIndustry;
  NEO_STEPS[2].palettes = palettes;
  NEO_STEPS[3].options = styles;
  NEO_STEPS[4].options = features;
  NEO_STEPS[5].questions = contentQuestions;
  NEO_STEPS[6].options = timelines;
  const messengerField = NEO_STEPS[7].fields.find((field) => field.id === "messenger");
  if (!messengerField) throw new Error("فیلد پیام‌رسان در فرم NEO پیدا نشد.");
  messengerField.options = messengers;
}

async function ensureNeoCatalogLoaded() {
  if (neoCatalogLoaded) return;
  if (!neoCatalogPromise) {
    neoCatalogPromise = (async () => {
      const response = await fetch(NEO_CATALOG_URL.href, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });
      if (!response.ok) throw new Error("فهرست استاندارد گزینه‌های NEO دریافت نشد.");
      const catalog = await response.json();
      applyNeoCatalog(catalog);
      neoCatalogLoaded = true;
    })().catch((error) => {
      neoCatalogPromise = null;
      throw error;
    });
  }
  return neoCatalogPromise;
}

// ============================================================
// 🛂 Pre-flight Check برای NEO
// ============================================================
export class NeoPreFlightCheck {
  static async canOpenNEO() {
    const verification = await NeoPreFlightCheck.verifyUserSession();
    if (!verification.allowed) return verification;
    const session = verification.session;
    try {
      const resp = await apiFetch(
        CONFIG.apiEndpoint + "?action=getActiveProjectsCount",
        { headers: {} },
      );
      const payload = await resp.json().catch(() => ({}));
      if (resp.status === 401 || payload.code === "AUTH_REQUIRED") {
        AuthSystem.applyServerSession({ authenticated: false, principalType: null });
        return {
          allowed: false,
          reason: "auth_required",
          message: "نشست منقضی شده است؛ برای جلوگیری از ثبت مهمان، دوباره وارد حساب شوید. پیش‌نویس حذف نمی‌شود.",
        };
      }
      if (!resp.ok || payload.success === false) {
        return {
          allowed: false,
          reason: "capacity_unavailable",
          message: payload.error || "بررسی ظرفیت پروژه از سرور انجام نشد؛ فعلاً امکان شروع ثبت پروژه نیست.",
        };
      }
      const finalSessionCheck = await NeoPreFlightCheck.verifyUserSession(session.data.userId);
      if (!finalSessionCheck.allowed) return finalSessionCheck;
      const activeCount = payload.data?.count ?? payload.count;
      if (!Number.isInteger(activeCount) || activeCount < 0) {
        return {
          allowed: false,
          reason: "capacity_unavailable",
          message: "پاسخ سرور برای بررسی ظرفیت معتبر نیست؛ لطفاً بعداً تلاش کنید.",
        };
      }
      if (activeCount >= 3) {
        return {
          allowed: false,
          reason: "max_projects",
          message: "شما ۳ پروژه فعال دارید. لطفاً تا پایان پروژه‌های قبلی صبر کنید.",
        };
      }
      return { allowed: true, userId: finalSessionCheck.session.data.userId };
    } catch (error) {
      return {
        allowed: false,
        reason: "capacity_unavailable",
        message: "ارتباط با سرور برای بررسی ظرفیت برقرار نشد؛ فعلاً امکان شروع ثبت پروژه نیست.",
      };
    }
  }

  static draftTtlMs = 24 * 60 * 60 * 1000;
  static draftSchemaVersion = 1;
  static draftMaxChars = 32768;

  static _draftKey(userId) {
    const id = String(userId ?? "");
    if (!/^[A-Za-z0-9_-]{1,128}$/.test(id)) return null;
    return `neo_draft_${id}`;
  }

  static _draftString(value, maxLength, defaultValue = "") {
    if (value === undefined || value === null) return defaultValue;
    if (typeof value !== "string" || value.length > maxLength) return null;
    return value;
  }

  static _draftStringList(value, maxItems, maxLength) {
    if (!Array.isArray(value) || value.length > maxItems) return null;
    const output = [];
    for (const item of value) {
      if (typeof item !== "string" || item.length > maxLength) return null;
      output.push(item);
    }
    return output;
  }

  static _normalizeDraftData(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return null;
    const onlyKeys = (candidate, allowed) => Object.keys(candidate).every((key) => allowed.includes(key));
    const allowedDataKeys = ["industry", "purpose", "colors", "style", "features", "content", "timeline", "fullName", "phone", "email", "messenger", "messengerId", "userNote"];
    if (!onlyKeys(value, allowedDataKeys)) return null;
    if (Object.prototype.hasOwnProperty.call(value, "colors")
        && (!value.colors || typeof value.colors !== "object" || Array.isArray(value.colors))) return null;
    if (Object.prototype.hasOwnProperty.call(value, "content")
        && (!value.content || typeof value.content !== "object" || Array.isArray(value.content))) return null;
    const read = (key, limit, fallback = "") => NeoPreFlightCheck._draftString(value[key], limit, fallback);
    const colorsSource = value.colors || {};
    const contentSource = value.content || {};
    if (!onlyKeys(colorsSource, ["palettes", "customColors"]) || !onlyKeys(contentSource, ["logo", "text", "images"])) return null;
    const palettes = NeoPreFlightCheck._draftStringList(colorsSource.palettes ?? [], 20, 128);
    // Keep malformed historical color strings as plain data so the color step can show
    // them as removable text; never trust them as CSS here.
    const customColors = NeoPreFlightCheck._draftStringList(colorsSource.customColors ?? [], 20, 64);
    const features = NeoPreFlightCheck._draftStringList(value.features ?? [], 20, 128);
    const industry = read("industry", 128);
    const purpose = read("purpose", 128);
    const style = read("style", 128);
    const timeline = read("timeline", 128);
    const fullName = read("fullName", 160);
    const phone = read("phone", 64);
    const email = read("email", 254);
    const messenger = read("messenger", 128);
    const messengerId = read("messengerId", 120);
    const userNote = read("userNote", 2000);
    const logo = NeoPreFlightCheck._draftString(contentSource.logo, 128);
    const text = NeoPreFlightCheck._draftString(contentSource.text, 128);
    const images = NeoPreFlightCheck._draftString(contentSource.images, 128);
    if ([palettes, customColors, features, industry, purpose, style, timeline, fullName, phone, email, messenger, messengerId, userNote, logo, text, images].some((item) => item === null)) return null;
    return {
      industry, purpose,
      colors: { palettes, customColors },
      style,
      features,
      content: { logo, text, images },
      timeline,
      fullName, phone, email, messenger, messengerId, userNote,
    };
  }

  static _normalizeSubmissionPayload(value) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return null;
    const expected = ["industry", "purpose", "colors", "style", "features", "content", "timeline", "fullName", "phone", "email", "messenger", "messengerId", "userNote"].sort();
    if (Object.keys(value).sort().join("\0") !== expected.join("\0")) return null;
    return NeoPreFlightCheck._normalizeDraftData(value);
  }

  static _normalizeDraftRecord(value, userId, legacy = false) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return null;
    const recordKeys = legacy
      ? ["data", "step", "submissionKey", "submissionKeyCreatedAtMs", "submissionPayload", "timestamp"]
      : ["schemaVersion", "userId", "savedAt", "expiresAt", "step", "data", "submissionKey", "submissionKeyCreatedAtMs", "submissionPayload"];
    if (!Object.keys(value).every((key) => recordKeys.includes(key))) return null;
    const now = Date.now();
    const scopeId = String(userId);
    const savedAt = Number(legacy ? value.timestamp : value.savedAt);
    const expiresAt = legacy ? savedAt + NeoPreFlightCheck.draftTtlMs : Number(value.expiresAt);
    if (!Number.isFinite(savedAt) || savedAt <= 0 || savedAt > now + 5 * 60 * 1000
        || !Number.isFinite(expiresAt) || expiresAt <= now || expiresAt <= savedAt
        || expiresAt - savedAt > NeoPreFlightCheck.draftTtlMs + 1000) return null;
    if (!legacy && (value.schemaVersion !== NeoPreFlightCheck.draftSchemaVersion || String(value.userId ?? "") !== scopeId)) return null;
    const step = Number(value.step);
    if (!Number.isInteger(step) || step < 1 || step > NEO_STEPS.length - 1) return null;
    const data = NeoPreFlightCheck._normalizeDraftData(value.data);
    if (!data) return null;

    let submissionKey = value.submissionKey ?? null;
    if (submissionKey !== null && (typeof submissionKey !== "string" || !/^(?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-f0-9]{64})$/i.test(submissionKey))) return null;
    let submissionKeyCreatedAtMs = value.submissionKeyCreatedAtMs ?? null;
    if (submissionKeyCreatedAtMs !== null) {
      submissionKeyCreatedAtMs = Number(submissionKeyCreatedAtMs);
      if (!Number.isFinite(submissionKeyCreatedAtMs) || submissionKeyCreatedAtMs <= 0 || submissionKeyCreatedAtMs > now + 5 * 60 * 1000) return null;
    }
    let submissionPayload = null;
    if (value.submissionPayload !== undefined && value.submissionPayload !== null) {
      submissionPayload = NeoPreFlightCheck._normalizeSubmissionPayload(value.submissionPayload);
      if (!submissionPayload) return null;
    }
    if (submissionKey === null && (submissionKeyCreatedAtMs !== null || submissionPayload !== null)) return null;
    return {
      schemaVersion: NeoPreFlightCheck.draftSchemaVersion,
      userId: scopeId,
      savedAt,
      expiresAt,
      step,
      data,
      submissionKey,
      submissionKeyCreatedAtMs,
      submissionPayload,
      persistedByConsent: !legacy,
      legacyDraft: legacy,
    };
  }

  static loadDraft(userId) {
    const key = NeoPreFlightCheck._draftKey(userId);
    if (!key) return null;
    let stored;
    try {
      const raw = sessionStorage.getItem(key);
      if (!raw) return null;
      if (raw.length > NeoPreFlightCheck.draftMaxChars) {
        sessionStorage.removeItem(key);
        return null;
      }
      stored = JSON.parse(raw);
    } catch (error) {
      try { sessionStorage.removeItem(key); } catch (storageError) { /* storage may be unavailable */ }
      return null;
    }

    const legacy = stored?.schemaVersion !== NeoPreFlightCheck.draftSchemaVersion;
    const normalized = NeoPreFlightCheck._normalizeDraftRecord(stored, userId, legacy);
    if (!normalized) {
      try { sessionStorage.removeItem(key); } catch (error) { /* storage may be unavailable */ }
      return null;
    }
    if (legacy) {
      // Previous releases saved without an explicit persistence choice. Import the data
      // into memory only if the old persistent copy can actually be removed.
      try {
        sessionStorage.removeItem(key);
      } catch (error) {
        return null;
      }
      normalized.persistedByConsent = false;
      normalized.legacyDraft = true;
    }
    return normalized;
  }

  static saveDraft(userId, data, persistAcrossRefresh = false) {
    const key = NeoPreFlightCheck._draftKey(userId);
    if (!key) return false;
    if (!persistAcrossRefresh) {
      NeoPreFlightCheck.clearDraft(userId);
      return true;
    }
    const step = Number(data?.step);
    if (!Number.isInteger(step) || step < 1 || step > NEO_STEPS.length - 1) return true;
    const normalizedData = NeoPreFlightCheck._normalizeDraftData(data?.data);
    if (!normalizedData) return false;
    const now = Date.now();
    const submissionKey = data?.submissionKey ?? null;
    if (submissionKey !== null && (typeof submissionKey !== "string" || !/^(?:[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}|[a-f0-9]{64})$/i.test(submissionKey))) return false;
    let createdAt = data?.submissionKeyCreatedAtMs ?? null;
    if (createdAt !== null) {
      createdAt = Number(createdAt);
      if (!Number.isFinite(createdAt) || createdAt <= 0 || createdAt > now + 5 * 60 * 1000) return false;
    }
    let payload = null;
    if (data?.submissionPayload !== undefined && data?.submissionPayload !== null) {
      payload = NeoPreFlightCheck._normalizeSubmissionPayload(data.submissionPayload);
      if (!payload) return false;
    }
    if (submissionKey === null && (createdAt !== null || payload !== null)) return false;
    const record = {
      schemaVersion: NeoPreFlightCheck.draftSchemaVersion,
      userId: String(userId),
      savedAt: now,
      expiresAt: now + NeoPreFlightCheck.draftTtlMs,
      step,
      data: normalizedData,
      submissionKey,
      submissionKeyCreatedAtMs: createdAt,
      submissionPayload: payload,
    };
    let serialized;
    try {
      serialized = JSON.stringify(record);
      if (serialized.length > NeoPreFlightCheck.draftMaxChars) return false;
      sessionStorage.setItem(key, serialized);
      return true;
    } catch (error) {
      return false;
    }
  }

  static clearDraft(userId) {
    const key = NeoPreFlightCheck._draftKey(userId);
    if (!key) return false;
    try {
      sessionStorage.removeItem(key);
      return true;
    } catch (error) {
      return false;
    }
  }

  static async verifyUserSession(expectedUserId = null) {
    const revisionAtStart = AuthSystem.sessionRevision;
    try {
      const serverSession = await getServerSession();
      // A newer explicit login/logout or another session restore wins over this late result.
      // Fail closed instead of replacing the newer in-memory principal with stale response data.
      if (AuthSystem.sessionRevision !== revisionAtStart) {
        return { allowed: false, reason: "session_changed", message: "وضعیت ورود هم‌زمان تغییر کرده است؛ برای جلوگیری از ثبت با حساب اشتباه، عملیات متوقف شد. وضعیت حساب را بررسی و دوباره تلاش کنید." };
      }
      const session = AuthSystem.applyServerSession(serverSession);
      if (!serverSession || serverSession.authenticated !== true || !session) {
        return { allowed: false, reason: "auth_required", message: "نشست کاربری معتبر نیست یا منقضی شده است؛ برای جلوگیری از ثبت مهمان، درخواست متوقف شد." };
      }
      if (session.type !== "user") {
        return { allowed: false, reason: "wrong_principal", message: "نشست فعلی مدیر است. برای ثبت پروژه باید از حساب کاربری مشتری استفاده کنید." };
      }
      if (session.expiresAt !== null && session.expiresAt <= Date.now()) {
        AuthSystem.applyServerSession({ authenticated: false, principalType: null });
        return { allowed: false, reason: "auth_required", message: "نشست کاربری منقضی شده است؛ درخواست متوقف شد و پیش‌نویس حفظ می‌شود." };
      }
      if (expectedUserId !== null && String(session.data.userId) !== String(expectedUserId)) {
        return { allowed: false, reason: "account_changed", message: "حساب کاربری با حسابی که این درخواست را شروع کرده متفاوت است؛ برای جلوگیری از انتقال اطلاعات بین حساب‌ها، ثبت انجام نشد." };
      }
      return { allowed: true, session };
    } catch (error) {
      return { allowed: false, reason: "session_unavailable", message: "بررسی نشست با سرور انجام نشد؛ درخواست ارسال نشد و اطلاعات فعلی در صفحه حفظ شده‌اند." };
    }
  }

}

// ============================================================
// ◈ NEO — Project Intake / Brief Engine
// ============================================================
export class NeoAssistantPro {
  constructor() {
    this.step = 0;
    this.totalSteps = NEO_STEPS.length - 1;
    this.data = this._getEmptyData();
    this.submissionKey = null;
    this.submissionKeyCreatedAtMs = null;
    this.pendingSubmissionPayload = null;
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
    this._opening = false;
    this.openTriggerLabels = new Map();
    this._focusTrapActive = false;
    this._redirectingFocus = false;
    this._lastFocusedElement = null;
    this._focusRestoreTimer = null;
    this._boundModalKeydown = (event) => this._handleModalKeydown(event);
    this._boundModalFocusIn = (event) => this._handleModalFocusIn(event);
    this.userId = null;
    this.persistDraftAcrossRefresh = false;
    this._draftMayExistInStorage = false;
    this._draftResumed = false;
    this._legacyDraftResumed = false;
    this.init();
  }
  _getEmptyData() {
    return {
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
    };
  }
  init() {
    if (!this.elements.floatBtn || !this.elements.modal) return;
    this.elements.floatBtn.addEventListener("click", () => this.open());
    this.elements.closeBtn.addEventListener("click", () => this.close());
    this.elements.backdrop.addEventListener("click", () => this.close());
    this.elements.prevBtn.addEventListener("click", () => this.prev());
    this.elements.nextBtn.addEventListener("click", () => this.next());
    // One delegated listener survives renderStep() innerHTML replacement. Field-specific
    // handlers run on the target before these bubble listeners persist the updated state.
    this.elements.body?.addEventListener("input", () => this._saveDraftState());
    this.elements.body?.addEventListener("change", () => this._saveDraftState());
    // The body node survives renderStep() replacements, so keyboard progression is delegated once.
    this.elements.body?.addEventListener("keydown", (event) => this._handleStepKeydown(event));
  }
  setOpeningBusy(isBusy) {
    [this.elements.floatBtn, document.getElementById("openNeoFromContact")].forEach((trigger) => {
      if (!trigger) return;
      if (isBusy) {
        if (!this.openTriggerLabels.has(trigger)) {
          this.openTriggerLabels.set(trigger, {
            hadLabel: trigger.hasAttribute("aria-label"),
            label: trigger.getAttribute("aria-label"),
          });
        }
        trigger.setAttribute("aria-busy", "true");
        trigger.setAttribute("aria-disabled", "true");
        trigger.setAttribute("aria-label", "در حال بررسی نشست و آماده‌سازی فرم NEO");
      } else {
        trigger.removeAttribute("aria-busy");
        trigger.removeAttribute("aria-disabled");
        const previous = this.openTriggerLabels.get(trigger);
        if (previous?.hadLabel) trigger.setAttribute("aria-label", previous.label || "");
        else trigger.removeAttribute("aria-label");
        this.openTriggerLabels.delete(trigger);
      }
    });
  }
  async open() {
    if (this._opening || this.elements.modal?.classList.contains("active")) return;
    this._opening = true;
    this.setOpeningBusy(true);
    try {
      await this._openAfterPreflight();
    } catch (error) {
      this.notification?.error("آماده‌سازی فرم NEO انجام نشد؛ اتصال را بررسی کنید و دوباره تلاش کنید.");
    } finally {
      this._opening = false;
      this.setOpeningBusy(false);
    }
  }
  async _openAfterPreflight() {
    const check = await NeoPreFlightCheck.canOpenNEO();
    if (!check.allowed) {
      this.notification?.error(check.message);
      if (check.reason === "auth_required" || (check.reason === "session_changed" && !AuthSystem.checkSession()))
        setTimeout(() => window.authSystem?.open(), 500);
      return;
    }
    try {
      await ensureNeoCatalogLoaded();
    } catch (error) {
      const message = "فهرست استاندارد NEO در دسترس نیست؛ فرم باز نشد و هیچ درخواستی ارسال نشد. اتصال را بررسی و دوباره تلاش کنید.";
      this.notification?.error(message);
      return;
    }
    const openingSession = await NeoPreFlightCheck.verifyUserSession(check.userId);
    if (!openingSession.allowed) {
      this.notification?.error(openingSession.message);
      if (openingSession.reason === "auth_required" || (openingSession.reason === "session_changed" && !AuthSystem.checkSession()))
        window.setTimeout(() => window.authSystem?.open(), 300);
      return;
    }
    check.userId = openingSession.session.data.userId;
    const previousUserId = this.userId;
    if (previousUserId !== null && String(previousUserId) !== String(check.userId)) {
      // Never carry one account's in-memory brief into another principal. Its persisted
      // copy remains scoped to the previous user's storage key and is not deleted here.
      this.step = 0;
      this.data = this._getEmptyData();
      this.submissionKey = null;
      this.submissionKeyCreatedAtMs = null;
      this.pendingSubmissionPayload = null;
      this.persistDraftAcrossRefresh = false;
      this._draftMayExistInStorage = false;
      this._draftResumed = false;
      this._legacyDraftResumed = false;
    }
    if (this._focusRestoreTimer !== null) {
      window.clearTimeout(this._focusRestoreTimer);
      this._focusRestoreTimer = null;
    }
    this.userId = check.userId;
    this.previousActiveElement = document.activeElement;
    this.elements.floatBtn?.setAttribute("aria-expanded", "true");
    this.elements.modal?.classList.add("active");
    syncModalScrollLock();
    const draft = NeoPreFlightCheck.loadDraft(this.userId);
    if (draft?.persistedByConsent === true) this._draftMayExistInStorage = true;
    if (draft && !this._draftResumed) {
      this.step = draft.step;
      const empty = this._getEmptyData();
      this.data = {
        ...empty,
        ...draft.data,
        colors: { ...empty.colors, ...(draft.data.colors || {}) },
        content: { ...empty.content, ...(draft.data.content || {}) },
      };
      this.submissionKey = typeof draft.submissionKey === "string" ? draft.submissionKey : null;
      this.submissionKeyCreatedAtMs = Number.isFinite(draft.submissionKeyCreatedAtMs) ? draft.submissionKeyCreatedAtMs : null;
      this.pendingSubmissionPayload = draft.submissionPayload && typeof draft.submissionPayload === "object"
        ? cloneSubmissionPayload(draft.submissionPayload)
        : null;
      this.persistDraftAcrossRefresh = draft.persistedByConsent === true;
      this._legacyDraftResumed = draft.legacyDraft === true;
      this.renderStep(this.step);
      this._draftResumed = true;
      if (this._legacyDraftResumed) {
        this.notification?.info("پیش‌نویس قدیمی فقط برای این بازدید در حافظه بازیابی شد؛ چون ذخیره‌سازی نسخهٔ قدیمی رضایت روشن نداشت، نسخهٔ ماندگار آن پاک شد. برای ادامه پس از تازه‌سازی، ذخیرهٔ موقت را صریحاً فعال کنید.");
      }
    } else {
      this.renderStep(this.step);
    }
    this._trapFocus();
  }
  close(reset = false) {
    const wasOpen = this.elements.modal?.classList.contains("active") === true;
    this._removeTrap();
    this.elements.modal?.classList.remove("active");
    this.elements.floatBtn?.setAttribute("aria-expanded", "false");
    syncModalScrollLock();
    if (reset) {
      this.step = 0;
      this.data = this._getEmptyData();
      this._draftMayExistInStorage = !NeoPreFlightCheck.clearDraft(this.userId);
      this.persistDraftAcrossRefresh = false;
      this._draftResumed = false;
      this._legacyDraftResumed = false;
      this.submissionKey = null;
      this.submissionKeyCreatedAtMs = null;
      this.pendingSubmissionPayload = null;
    }

    const previous = this.previousActiveElement;
    this.previousActiveElement = null;
    if (wasOpen) {
      const restoreTarget = this._canRestoreFocus(previous)
        ? previous
        : (this._canRestoreFocus(this.elements.floatBtn) ? this.elements.floatBtn : null);
      if (restoreTarget) {
        if (this._focusRestoreTimer !== null) window.clearTimeout(this._focusRestoreTimer);
        this._focusRestoreTimer = window.setTimeout(() => {
          this._focusRestoreTimer = null;
          if (!this.elements.modal?.classList.contains("active") && this._canRestoreFocus(restoreTarget)) {
            restoreTarget.focus({ preventScroll: true });
          }
        }, 50);
      }
    }
  }
  _getFocusableElements() {
    const modal = this.elements.modal;
    if (!modal) return [];
    const selector = [
      'a[href]',
      'area[href]',
      'button:not([disabled])',
      'input:not([disabled]):not([type="hidden"])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      'summary',
      'iframe',
      'object',
      'embed',
      'audio[controls]',
      'video[controls]',
      '[contenteditable="true"]',
      '[tabindex]:not([tabindex="-1"])',
    ].join(",");

    return Array.from(modal.querySelectorAll(selector)).filter((element) => {
      if (element.tabIndex < 0 || element.matches(":disabled") || element.hidden) return false;
      if (element.closest('[hidden], [inert], [aria-hidden="true"]')) return false;
      const style = window.getComputedStyle(element);
      return style.display !== "none"
        && style.visibility !== "hidden"
        && element.getClientRects().length > 0;
    });
  }
  _canRestoreFocus(element) {
    if (!element || typeof element.focus !== "function" || !element.isConnected) return false;
    if (element === document.body || element.disabled || element.hidden) return false;
    if (element.closest('[hidden], [inert], [aria-hidden="true"]')) return false;
    const style = window.getComputedStyle(element);
    return style.display !== "none"
      && style.visibility !== "hidden"
      && element.getClientRects().length > 0;
  }
  _handleModalKeydown(event) {
    const modal = this.elements.modal;
    if (!modal?.classList.contains("active")) return;

    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      this.close();
      return;
    }
    if (event.key !== "Tab") return;

    const focusable = this._getFocusableElements();
    if (!focusable.length) {
      event.preventDefault();
      modal.focus({ preventScroll: true });
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const activeIndex = focusable.indexOf(document.activeElement);
    if (event.shiftKey && activeIndex <= 0) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && (activeIndex === -1 || activeIndex === focusable.length - 1)) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }
  _handleModalFocusIn(event) {
    const modal = this.elements.modal;
    if (!modal?.classList.contains("active")) return;
    if (modal.contains(event.target)) {
      this._lastFocusedElement = event.target;
      return;
    }
    if (this._redirectingFocus) return;

    const lastFocused = this._lastFocusedElement;
    const target = lastFocused && modal.contains(lastFocused) && this._canRestoreFocus(lastFocused)
      ? lastFocused
      : (this._getFocusableElements()[0] || modal);
    this._redirectingFocus = true;
    try {
      target.focus({ preventScroll: true });
    } finally {
      this._redirectingFocus = false;
    }
  }
  _trapFocus() {
    const modal = this.elements.modal;
    if (!modal?.classList.contains("active") || this._focusTrapActive) return;
    document.addEventListener("keydown", this._boundModalKeydown, true);
    document.addEventListener("focusin", this._boundModalFocusIn, true);
    this._focusTrapActive = true;
  }
  _removeTrap() {
    if (!this._focusTrapActive) return;
    document.removeEventListener("keydown", this._boundModalKeydown, true);
    document.removeEventListener("focusin", this._boundModalFocusIn, true);
    this._focusTrapActive = false;
    this._redirectingFocus = false;
    this._lastFocusedElement = null;
  }
  _renderRadioOptions(options, { idPrefix, name, selectedValue, choice, contentKey = null } = {}) {
    const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (match) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[match]);
    return options.map((option, index) => {
      const value = String(option.value ?? "");
      const label = String(option.label ?? "");
      const emoji = String(option.emoji ?? "");
      const id = `${idPrefix}-${index + 1}`;
      const selected = selectedValue === value;
      const contentAttribute = contentKey === null ? "" : ` data-content-key="${esc(contentKey)}"`;
      const emojiMarkup = emoji ? `<span class="neo-option-emoji" aria-hidden="true">${esc(emoji)}</span>` : "";
      return `<label class="neo-option-card${selected ? " selected" : ""}" for="${id}"><input class="neo-choice-input" type="radio" id="${id}" name="${esc(name)}" value="${esc(value)}" data-neo-choice="${esc(choice)}"${contentAttribute}${selected ? " checked" : ""}>${emojiMarkup}<span class="neo-option-label">${esc(label)}</span></label>`;
    }).join("");
  }
  _clearStepValidationError() {
    const body = this.elements.body;
    if (!body) return;
    const error = body.querySelector("#neoStepValidationError");
    if (error) {
      error.hidden = true;
      error.textContent = "";
    }
    body.querySelectorAll("[aria-describedby]").forEach((element) => {
      const tokens = (element.getAttribute("aria-describedby") || "")
        .split(/\s+/).filter((token) => token && token !== "neoStepValidationError");
      if (tokens.length) element.setAttribute("aria-describedby", tokens.join(" "));
      else element.removeAttribute("aria-describedby");
    });
    body.querySelectorAll('[aria-invalid="true"]').forEach((element) => element.removeAttribute("aria-invalid"));
  }
  _showStepValidationError(message, targetSelector) {
    const body = this.elements.body;
    if (!body) return;
    this._clearStepValidationError();
    const error = body.querySelector("#neoStepValidationError");
    const target = body.querySelector(targetSelector);
    if (!error || !target) return;

    error.textContent = message;
    error.hidden = false;
    const describedBy = (element) => {
      const tokens = (element.getAttribute("aria-describedby") || "")
        .split(/\s+/).filter(Boolean);
      if (!tokens.includes("neoStepValidationError")) tokens.push("neoStepValidationError");
      element.setAttribute("aria-describedby", tokens.join(" "));
    };
    describedBy(target);

    const controls = target.matches("input, select, textarea, button")
      ? [target]
      : Array.from(target.querySelectorAll('input:not([type="hidden"]), select, textarea, button:not([disabled])'));
    controls.forEach((control) => {
      describedBy(control);
      if (control.matches("input, select, textarea")) control.setAttribute("aria-invalid", "true");
    });
    const focusTarget = target.matches('input:not([type="hidden"]), select, textarea, button:not([disabled])')
      ? target
      : controls[0];
    if (focusTarget && typeof focusTarget.focus === "function") focusTarget.focus({ preventScroll: true });
    this._shakeNext();
  }
  renderStep(sn, { focusHeading = true } = {}) {
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
    if (step.id === 7 && !this.data.messenger) this.data.messengerId = "";
    const percent = Math.round((sn / this.totalSteps) * 100);
    const circ = 2 * Math.PI * 15.9155;
    if (this.elements.progressRing) {
      this.elements.progressRing.setAttribute("stroke-dasharray", String(circ));
      this.elements.progressRing.setAttribute(
        "stroke-dashoffset",
        String(circ * (1 - percent / 100)),
      );
    }
    const progressBar = this.elements.progressRing?.closest(".progress-ring");
    if (progressBar) {
      progressBar.setAttribute("aria-valuenow", String(percent));
      progressBar.setAttribute("aria-valuetext", `مرحله ${sn} از ${this.totalSteps}؛ تکمیل ${percent} درصد`);
    }
    if (this.elements.progressText)
      this.elements.progressText.textContent = `${percent}%`;
    if (this.elements.stepInfo) {
      const stepNumber = this.elements.stepInfo.querySelector("span");
      if (stepNumber) {
        stepNumber.textContent = String(sn);
      } else {
        this.elements.stepInfo.textContent = `مرحله ${sn} از ${this.totalSteps}`;
      }
    }
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
    if (focusHeading) {
      this.elements.body.querySelector(".neo-step-title")?.focus({ preventScroll: true });
    }
  }
  _discardInMemoryDraftAfterAccountSwitch() {
    this.close(false);
    this.step = 0;
    this.data = this._getEmptyData();
    this.submissionKey = null;
    this.submissionKeyCreatedAtMs = null;
    this.pendingSubmissionPayload = null;
    this.persistDraftAcrossRefresh = false;
    this._draftResumed = false;
    this._legacyDraftResumed = false;
    // Keep this.userId, the prior owner's storage-presence state, and the persistent key intact.
    // The next open() will
    // compare principals; an opted-in draft can only be restored under its original user ID.
  }
  _draftSnapshot() {
    return {
      data: this.data,
      step: this.step,
      submissionKey: this.submissionKey,
      submissionKeyCreatedAtMs: this.submissionKeyCreatedAtMs,
      submissionPayload: this.pendingSubmissionPayload,
    };
  }
  _saveDraftState() {
    if (!this.userId) return false;
    if (!this.persistDraftAcrossRefresh) {
      const cleared = NeoPreFlightCheck.clearDraft(this.userId);
      this._draftMayExistInStorage = !cleared;
      if (!cleared) this._setDraftPersistenceStatus("ذخیرهٔ موقت خاموش است، اما مرورگر حذف نسخهٔ قبلی را تأیید نکرد؛ صفحه را ببندید و از باقی‌نماندن داده در این تب مطمئن شوید.");
      return cleared;
    }
    // The welcome step contains no form data yet; consent takes effect from the first real step.
    if (this.step === 0) return true;
    const saved = NeoPreFlightCheck.saveDraft(this.userId, this._draftSnapshot(), true);
    if (saved) {
      this._draftMayExistInStorage = true;
      this._setDraftPersistenceStatus("ذخیرهٔ موقت فعال است؛ آخرین تغییرها در همین تب، حداکثر تا ۲۴ ساعت پس از آخرین ویرایش، نگه‌داری می‌شوند.");
      return true;
    }
    // If the browser storage is unavailable or the allowlisted record cannot be serialized,
    // do not claim persistence is on. The in-memory wizard remains intact.
    this.persistDraftAcrossRefresh = false;
    const cleared = NeoPreFlightCheck.clearDraft(this.userId);
    this._draftMayExistInStorage = !cleared;
    const toggle = this.elements.body?.querySelector("#neoPersistDraftAcrossRefresh");
    if (toggle) toggle.checked = false;
    this._setDraftPersistenceStatus(cleared
      ? "ذخیرهٔ مرورگری انجام نشد؛ نسخهٔ ذخیره‌شده حذف شد و اطلاعات فعلی فقط در حافظهٔ همین صفحه باقی می‌مانند."
      : "ذخیرهٔ مرورگری انجام نشد و حذف نسخهٔ قبلی نیز از سوی مرورگر تأیید نشد؛ صفحه را ببندید و از باقی‌نماندن داده در این تب مطمئن شوید.");
    return false;
  }
  _setDraftPersistenceStatus(message) {
    const status = this.elements.body?.querySelector("#neoDraftPersistenceStatus");
    if (status) status.textContent = message;
  }
  _renderDraftPersistenceControl() {
    const checked = this.persistDraftAcrossRefresh === true;
    const state = checked
      ? "ذخیرهٔ موقت فعال است؛ آخرین تغییرها در همین تب، حداکثر تا ۲۴ ساعت پس از آخرین ویرایش، نگه‌داری می‌شوند."
      : (this._legacyDraftResumed
        ? "پیش‌نویس قدیمی فقط در حافظهٔ همین صفحه بازیابی شده؛ برای نگه‌داری پس از تازه‌سازی، گزینه را فعال کنید."
        : "حالت پیش‌فرض: فقط در حافظهٔ همین صفحه؛ با تازه‌سازی، پیش‌نویس بازیابی نمی‌شود.");
    return `<aside class="neo-draft-persistence" aria-labelledby="neoDraftPersistenceTitle"><label class="neo-draft-persistence-toggle" for="neoPersistDraftAcrossRefresh"><input id="neoPersistDraftAcrossRefresh" type="checkbox"${checked ? " checked" : ""}><span><strong id="neoDraftPersistenceTitle">ذخیرهٔ موقت برای ادامه پس از تازه‌سازی</strong><span>اختیاری و خاموش به‌صورت پیش‌فرض</span></span></label><p id="neoDraftPersistenceStatus" class="neo-draft-persistence-status" role="status" aria-live="polite">${state}</p><details class="neo-draft-persistence-details"><summary>چه چیزی ذخیره می‌شود؟</summary><p>در صورت فعال‌سازی، داده‌های لازم فرم از جمله اطلاعات تماس و یادداشت، و در صورت نامشخص‌بودن نتیجهٔ ثبت، کلید و نسخهٔ دقیق همان درخواست در ذخیره‌سازی موقت همین تب قرار می‌گیرند. نسخهٔ ذخیره‌شده پس از ۲۴ ساعت از آخرین تغییر منقضی می‌شود و با بسته‌شدن تب نیز معمولاً از بین می‌رود. رمز عبور، کد یک‌بارمصرف، توکن نشست و Secret ذخیره نمی‌شوند. با برداشتن تیک، نسخهٔ مرورگری همان لحظه پاک می‌شود؛ اطلاعات فعلی تا وقتی این صفحه باز است در حافظه می‌مانند.</p></details></aside>`;
  }
  _getStepHtml(step) {
    const esc = (value) => String(value ?? "").replace(/[&<>"']/g, (match) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;",
    })[match]);
    let h = `<div class="neo-step-content"><h3 class="neo-step-title" tabindex="-1">${esc(step.title)}</h3><p class="neo-step-subtitle" id="neoStepSubtitle">${esc(step.subtitle)}</p>${this._renderDraftPersistenceControl()}<div id="neoStepValidationError" class="neo-inline-error" role="alert" aria-live="polite" hidden></div>`;
    switch (step.type) {
      case "welcome":
        h += `<div class="neo-welcome-panel"><div class="neo-welcome-icon" aria-hidden="true">🚀</div><button type="button" class="chat-btn chat-btn-primary btn-lg neo-start-btn neo-welcome-start-action">شروع پروژه جدید</button>${NeoPreFlightCheck.loadDraft(this.userId) ? '<p class="neo-welcome-resume-note"><button type="button" id="neoContinuePrev" class="btn btn-link">ادامه درخواست قبلی</button></p>' : ""}</div>`;
        break;
      case "industry-purpose": {
        const currentIndustry = step.industries.find((industry) => industry.value === this.data.industry);
        const purposeOptions = step.purposesByIndustry[this.data.industry] || [];
        h += `<fieldset class="neo-choice-fieldset" id="neoIndustryGroup"><legend class="neo-choice-legend">صنعت</legend><div class="neo-options-grid" id="neoIndustries">${this._renderRadioOptions(step.industries, { idPrefix: "neo-industry", name: "neoIndustry", selectedValue: this.data.industry, choice: "industry" })}</div></fieldset>`;
        h += `<p class="neo-field-help neo-purpose-status" id="neoPurposeStatus" aria-live="polite">${currentIndustry ? `هدف‌های متناسب با ${esc(currentIndustry.label)} نمایش داده شده‌اند.` : "ابتدا صنعت را انتخاب کنید تا هدف‌های متناسب نمایش داده شوند."}</p>`;
        h += `<fieldset class="neo-choice-fieldset" id="neoPurposes"${this.data.industry ? "" : " hidden"}><legend class="neo-choice-legend">هدف سایت</legend><div class="neo-options-grid" id="neoPurposeOptions">${this._renderRadioOptions(purposeOptions, { idPrefix: "neo-purpose", name: "neoPurpose", selectedValue: this.data.purpose, choice: "purpose" })}</div></fieldset>`;
        break;
      }
      case "colors": {
        // Catalog palette colors are strict #RRGGBB values; restored custom colors are re-normalized below.
        const customColors = Array.isArray(this.data.colors?.customColors) ? this.data.colors.customColors : [];
        const customColorLimitReached = customColors.length >= NEO_CUSTOM_COLOR_LIMIT;
        h += `<div class="palette-search"><label class="neo-field-label" for="paletteSearchInput">جستجوی پالت رنگی</label><input type="search" id="paletteSearchInput" placeholder="نام یا شناسهٔ پالت را جستجو کنید" aria-controls="paletteGrid"></div><section id="neoPaletteGroup" class="neo-palette-group" role="group" aria-labelledby="neoPaletteLegend"><h4 id="neoPaletteLegend" class="neo-choice-legend">پالت‌های رنگی (انتخاب چندگانه)</h4><div class="palette-grid" id="paletteGrid">${step.palettes.map((palette, index) => {
          const selected = this.data.colors.palettes.includes(palette.id);
          const checkboxId = `neo-palette-${index + 1}`;
          const firstColor = normalizeNeoCustomColor(palette.colors[0]);
          const contrast = neoColorContrast(firstColor);
          const contrastMarkup = firstColor && contrast
            ? `<svg class="palette-contrast-preview" viewBox="0 0 42 34" role="img" aria-label="پیش‌نمایش متن روی ${esc(firstColor)}؛ نسبت کنتراست ${contrast.ratio.toFixed(2)} به یک"><rect x="0.5" y="0.5" width="41" height="33" rx="7" fill="${esc(firstColor)}" stroke="rgba(255,255,255,.22)" stroke-width="1"></rect><text x="21" y="22" text-anchor="middle" fill="${esc(contrast.textColor)}" font-family="Arial, sans-serif" font-size="13" font-weight="800">Aa</text></svg>`
            : "";
          return `<label class="palette-card${selected ? " selected" : ""}" for="${checkboxId}" data-palette-id="${esc(palette.id)}"><input class="palette-choice-input" type="checkbox" id="${checkboxId}" name="palettes[]" value="${esc(palette.id)}"${selected ? " checked" : ""}><span class="palette-colors" aria-hidden="true">${palette.colors.map((color) => `<svg class="palette-color" viewBox="0 0 28 28" aria-hidden="true" focusable="false"><circle cx="14" cy="14" r="14" fill="${esc(color)}"></circle></svg>`).join("")}</span>${contrastMarkup}<span class="palette-name">${esc(palette.name)}</span></label>`;
        }).join("")}</div></section>`;
        if (step.allowCustomColor) {
          const customColorBadges = customColors.map((color) => {
            const normalized = normalizeNeoCustomColor(color);
            const contrast = neoColorContrast(normalized);
            const contrastMarkup = normalized && contrast
              ? `<svg class="custom-color-contrast" viewBox="0 0 42 34" role="img" aria-label="پیش‌نمایش متن روی ${esc(normalized)}؛ نسبت کنتراست ${contrast.ratio.toFixed(2)} به یک"><rect x="0.5" y="0.5" width="41" height="33" rx="7" fill="${esc(normalized)}" stroke="rgba(255,255,255,.22)" stroke-width="1"></rect><text x="21" y="22" text-anchor="middle" fill="${esc(contrast.textColor)}" font-family="Arial, sans-serif" font-size="13" font-weight="800">Aa</text></svg><span class="custom-color-contrast-ratio">${contrast.ratio.toFixed(2)}:1</span>`
              : `<span class="custom-color-invalid-label">نیازمند اصلاح</span>`;
            return `<span class="custom-color-badge" role="listitem">${contrastMarkup}<code>${esc(color)}</code><button type="button" data-remove-color="${esc(color)}" aria-label="حذف رنگ ${esc(color)}">×</button></span>`;
          }).join("");
          const countLabel = `${customColors.length} از ${NEO_CUSTOM_COLOR_LIMIT} رنگ دلخواه`;
          h += `<section class="custom-color-section" aria-labelledby="customColorLegend"><h4 id="customColorLegend" class="neo-choice-legend">رنگ دلخواه</h4><p id="customColorHelp" class="custom-color-help">انتخابگر رنگ ابزار اصلی است. در صورت ورود دستی، کد باید دقیقاً به شکل <code>#RRGGBB</code> باشد؛ نام رنگ و CSS آزاد پذیرفته نیست.</p><div class="custom-color-controls"><div class="custom-color-picker-wrap"><label for="customColorPicker">انتخاب رنگ</label><input type="color" id="customColorPicker" name="customColorPicker" value="#ff5733" aria-describedby="customColorHelp customColorFeedback"></div><div class="custom-color-hex-wrap"><label for="customColorHex">کد HEX</label><input type="text" id="customColorHex" name="customColorHex" placeholder="#FF5733" value="" pattern="#[0-9A-Fa-f]{6}" maxlength="7" autocapitalize="characters" spellcheck="false" autocomplete="off" aria-describedby="customColorHelp customColorFeedback" title="قالب مجاز: #RRGGBB"></div><button type="button" class="btn btn-sm custom-color-add-button" id="addCustomColorBtn"${customColorLimitReached ? " disabled" : ""}>افزودن رنگ</button></div><p id="customColorFeedback" class="custom-color-feedback" role="status" aria-live="polite" hidden></p><p class="custom-color-count" id="customColorCount" aria-live="polite">${esc(countLabel)}${customColorLimitReached ? "؛ برای افزودن، ابتدا رنگی را حذف کنید." : ""}</p><div class="custom-colors-preview" id="customColorsPreview" role="list" aria-label="رنگ‌های سفارشی انتخاب‌شده">${customColorBadges}</div></section>`;
        }
        break;
      }
      case "single": {
        const isTimeline = step.id === 6;
        const choice = isTimeline ? "timeline" : "style";
        const selectedValue = isTimeline ? this.data.timeline : this.data.style;
        const groupId = isTimeline ? "neoTimelineGroup" : "neoStyleGroup";
        const groupName = isTimeline ? "neoTimeline" : "neoStyle";
        const legend = isTimeline ? "بازهٔ زمانی موردنظر" : "سبک طراحی موردنظر";
        h += `<fieldset class="neo-choice-fieldset" id="${groupId}"><legend class="neo-choice-legend">${legend}</legend><div class="neo-options-grid">${this._renderRadioOptions(step.options, { idPrefix: isTimeline ? "neo-timeline" : "neo-style", name: groupName, selectedValue, choice })}</div></fieldset>`;
        break;
      }
      case "multi":
        h += `<fieldset class="neo-choice-fieldset" id="neoFeaturesGroup"><legend class="neo-choice-legend">امکانات ویژه (انتخاب چند مورد امکان‌پذیر است)</legend><div class="neo-checkbox-grid">${step.options.map((option, index) => {
          const selected = this.data.features.includes(option.value);
          const id = `neo-feature-${index + 1}`;
          return `<label class="neo-checkbox-card${selected ? " checked" : ""}" for="${id}"><input type="checkbox" id="${id}" name="features[]" value="${esc(option.value)}" data-neo-feature ${selected ? "checked" : ""}><span class="neo-checkbox-label">${esc(option.emoji)} ${esc(option.label)}</span></label>`;
        }).join("")}</div></fieldset>`;
        break;
      case "content":
        step.questions.forEach((question) => {
          h += `<fieldset class="neo-choice-fieldset neo-content-field" id="neoContentGroup-${esc(question.id)}"><legend class="neo-choice-legend">${esc(question.label)}</legend><div class="neo-options-grid">${this._renderRadioOptions(question.options, { idPrefix: `neo-content-${question.id}`, name: `neoContent-${question.id}`, selectedValue: this.data.content[question.id], choice: "content", contentKey: question.id })}</div></fieldset>`;
        });
        break;
      case "contact-info":
        h += step.fields.map((field) => {
          const value = this.data[field.id] || "";
          const fieldId = `neo_${field.id}`;
          const wrapperId = `neo-field-${field.id}`;
          const hidden = field.conditional && !this.data[field.conditional] ? " hidden" : "";
          const required = field.required ? " required aria-required=\"true\"" : "";
          const autoComplete = ({ fullName: "name", phone: "tel", email: "email" })[field.id] || "off";
          const label = `${esc(field.label)}${field.required ? ' <span class="neo-required-indicator" aria-hidden="true">*</span>' : ""}`;
          const fieldHelp = ({
            fullName: "حداکثر ۱۶۰ نویسه.",
            phone: "شمارهٔ همراه ایران را با قالب 09xxxxxxxxx، ‎+989xxxxxxxxx یا 00989xxxxxxxxx وارد کنید؛ ارقام فارسی و عربی و جداکننده‌های معمول نیز پذیرفته می‌شوند.",
            email: "اختیاری است؛ در صورت ورود، نشانی ایمیل معتبر وارد کنید.",
            messengerId: "حداکثر ۱۲۰ نویسه.",
            userNote: "حداکثر ۲۰۰۰ نویسه.",
          })[field.id] || "";
          const helpId = `neo-${field.id}-help`;
          const helpMarkup = fieldHelp ? `<p class="neo-field-help" id="${helpId}">${esc(fieldHelp)}</p>` : "";
          const describedBy = field.id === "messenger"
            ? ' aria-describedby="neo_messenger_help"'
            : (fieldHelp ? ` aria-describedby="${helpId}"` : "");
          const inputMode = field.id === "phone" ? ' inputmode="tel"' : (field.id === "email" ? ' inputmode="email"' : "");
          if (field.type === "select") {
            const options = Array.isArray(field.options) ? field.options : [];
            return `<div class="neo-input-group${field.conditional ? " neo-conditional-field" : ""}" id="${esc(wrapperId)}"${hidden}><label for="${esc(fieldId)}">${label}</label><select class="neo-input-field" id="${esc(fieldId)}" name="${esc(field.id)}" data-neo-field="${esc(field.id)}" autocomplete="${autoComplete}"${required}${describedBy}>${options.map((option) => {
              const optionValue = typeof option === "string" ? option : String(option?.value ?? "");
              const optionLabel = typeof option === "string" ? (option || "انتخاب کنید") : String(option?.label || optionValue || "انتخاب کنید");
              return `<option value="${esc(optionValue)}"${value === optionValue ? " selected" : ""}>${esc(optionLabel)}</option>`;
            }).join("")}</select>${field.id === "messenger" ? '<p class="neo-field-help" id="neo_messenger_help">با انتخاب پیام‌رسان، فیلد آیدی آن نمایش داده می‌شود. اگر پیام‌رسانی انتخاب نکنید، آیدی پاک می‌شود.</p>' : ""}</div>`;
          }
          if (field.type === "textarea") {
            return `<div class="neo-input-group${field.conditional ? " neo-conditional-field" : ""}" id="${esc(wrapperId)}"${hidden}><label for="${esc(fieldId)}">${label}</label><textarea class="neo-input-field neo-textarea" id="${esc(fieldId)}" name="${esc(field.id)}" data-neo-field="${esc(field.id)}" autocomplete="${autoComplete}" placeholder="یادداشت اختیاری..."${describedBy}>${esc(value)}</textarea>${helpMarkup}</div>`;
          }
          return `<div class="neo-input-group${field.conditional ? " neo-conditional-field" : ""}" id="${esc(wrapperId)}"${hidden}><label for="${esc(fieldId)}">${label}</label><input type="${esc(field.type)}" class="neo-input-field" id="${esc(fieldId)}" name="${esc(field.id)}" data-neo-field="${esc(field.id)}" autocomplete="${autoComplete}"${inputMode} placeholder="${field.required ? "الزامی" : "اختیاری"}" value="${esc(value)}"${required}${describedBy}>${helpMarkup}</div>`;
        }).join("");
        break;
      case "review":
        h += this._getReviewHtml();
        break;
    }
    h += "</div>";
    return h;
  }
  _getReviewHtml() {
    const esc = (value) =>
      value
        ? String(value).replace(
            /[&<>"']/g,
            (match) =>
              ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#039;",
              })[match],
          )
        : "-";
    const industryMap = new Map(NEO_STEPS[1].industries.map((item) => [item.value, item.label]));
    const purposes = NEO_STEPS[1].purposesByIndustry[this.data.industry] || [];
    const selectedPurpose = purposes.find((purpose) => purpose.value === this.data.purpose);
    const paletteMap = new Map(NEO_STEPS[2].palettes.map((palette) => [palette.id, palette.name]));
    const styleMap = new Map(NEO_STEPS[3].options.map((option) => [option.value, option.label]));
    const featureMap = new Map(NEO_STEPS[4].options.map((option) => [option.value, option.label]));
    const timelineMap = new Map(NEO_STEPS[6].options.map((option) => [option.value, option.label]));
    const messengerOptions = NEO_STEPS[7].fields.find((field) => field.id === "messenger")?.options || [];
    const selectedMessenger = messengerOptions.find((option) => option.value === this.data.messenger);
    const contentOptionLabel = (field, value) => {
      const question = NEO_STEPS[5].questions.find((item) => item.id === field);
      return question?.options.find((option) => option.value === value)?.label || "-";
    };
    const selectedPalettes = (this.data.colors.palettes || []).map((value) => paletteMap.get(value) || "نامشخص");
    const messengerSummary = this.data.messenger
      ? `${selectedMessenger?.label || "نامشخص"}${this.data.messengerId ? `: ${this.data.messengerId}` : ""}`
      : "-";
    const rows = [
      { label: "👤 نام", value: this.data.fullName || "-", step: 7 },
      { label: "📱 تلفن", value: this.data.phone || "-", step: 7 },
      { label: "🎯 صنعت", value: industryMap.get(this.data.industry) || "-", step: 1 },
      { label: "🎯 هدف", value: selectedPurpose?.label || "-", step: 1 },
      {
        label: "🎨 رنگ‌ها",
        value: selectedPalettes.join(", ") + (this.data.colors.customColors.length
          ? " / سفارشی: " + this.data.colors.customColors.join(", ")
          : ""),
        step: 2,
      },
      { label: "✨ سبک", value: styleMap.get(this.data.style) || "-", step: 3 },
      {
        label: "🔧 امکانات",
        value: this.data.features.map((feature) => featureMap.get(feature) || "نامشخص").join("، ") || "-",
        step: 4,
      },
      { label: "📝 لوگو", value: contentOptionLabel("logo", this.data.content.logo), step: 5 },
      { label: "📝 متن", value: contentOptionLabel("text", this.data.content.text), step: 5 },
      { label: "📝 تصاویر", value: contentOptionLabel("images", this.data.content.images), step: 5 },
      { label: "⏰ زمان", value: timelineMap.get(this.data.timeline) || "-", step: 6 },
      { label: "📲 پیام‌رسان", value: messengerSummary, step: 7 },
      { label: "📝 یادداشت", value: this.data.userNote || "-", step: 7 },
    ];
    return `<div>${rows.map((row) => `<div class="neo-review-section"><div class="neo-review-label">${esc(row.label)}</div><div class="neo-review-value">${esc(row.value)}</div><button type="button" class="neo-review-edit" data-step="${row.step}" aria-label="ویرایش ${esc(row.label)}">✏️ ویرایش</button></div>`).join("")}</div>`;
  }
  _attachEvents(step) {
    const body = this.elements.body;
    body.querySelector("#neoPersistDraftAcrossRefresh")?.addEventListener("change", (event) => {
      const enabled = event.currentTarget.checked === true;
      this.persistDraftAcrossRefresh = enabled;
      if (!enabled) {
        const cleared = NeoPreFlightCheck.clearDraft(this.userId);
        this._draftMayExistInStorage = !cleared;
        this._legacyDraftResumed = false;
        this._setDraftPersistenceStatus(cleared
          ? "نسخهٔ ذخیره‌شدهٔ مرورگر پاک شد؛ اطلاعات فعلی تا بسته‌شدن یا تازه‌سازی همین صفحه در حافظه می‌مانند."
          : "ذخیرهٔ موقت خاموش است، اما مرورگر حذف نسخهٔ قبلی را تأیید نکرد؛ صفحه را ببندید و از باقی‌نماندن داده در این تب مطمئن شوید.");
        return;
      }
      if (this.step === 0) {
        this._setDraftPersistenceStatus("فعال شد؛ ذخیرهٔ موقت از شروع مراحل فرم انجام می‌شود.");
        return;
      }
      this._saveDraftState();
      this._legacyDraftResumed = false;
    });
    body.querySelector(".neo-start-btn")?.addEventListener("click", () => this.next());
    body.querySelector("#neoContinuePrev")?.addEventListener("click", (event) => {
      event.preventDefault();
      const draft = NeoPreFlightCheck.loadDraft(this.userId);
      if (draft) {
        this.data = { ...this._getEmptyData(), ...draft.data };
        if (!this.data.messenger) this.data.messengerId = "";
        this.submissionKey = typeof draft.submissionKey === "string" ? draft.submissionKey : null;
        this.submissionKeyCreatedAtMs = Number.isFinite(draft.submissionKeyCreatedAtMs) ? draft.submissionKeyCreatedAtMs : null;
        this.pendingSubmissionPayload = draft.submissionPayload && typeof draft.submissionPayload === "object"
          ? cloneSubmissionPayload(draft.submissionPayload)
          : null;
        this.step = draft.step;
        this.renderStep(this.step);
      }
    });

    const handleChoiceChange = (event) => {
      const radio = event.currentTarget;
      if (!radio.checked) return;
      this._clearStepValidationError();
      const choice = radio.dataset.neoChoice;
      const value = radio.value;
      const grid = radio.closest(".neo-options-grid");
      grid?.querySelectorAll(".neo-option-card").forEach((card) => {
        card.classList.toggle("selected", card.contains(radio));
      });

      if (choice === "industry") {
        if (this.data.industry !== value) this.data.purpose = "";
        this.data.industry = value;
        const purposeGroup = body.querySelector("#neoPurposes");
        const purposeGrid = body.querySelector("#neoPurposeOptions");
        const purposeStatus = body.querySelector("#neoPurposeStatus");
        const selectedIndustry = NEO_STEPS[1].industries.find((industry) => industry.value === value);
        if (purposeGrid && purposeGroup) {
          const purposes = NEO_STEPS[1].purposesByIndustry[value] || [];
          purposeGrid.innerHTML = this._renderRadioOptions(purposes, {
            idPrefix: "neo-purpose",
            name: "neoPurpose",
            selectedValue: this.data.purpose,
            choice: "purpose",
          });
          purposeGroup.hidden = purposes.length === 0;
          purposeGrid.querySelectorAll(".neo-choice-input").forEach((input) => input.addEventListener("change", handleChoiceChange));
        }
        if (purposeStatus) {
          purposeStatus.textContent = selectedIndustry
            ? `هدف‌های متناسب با ${selectedIndustry.label} نمایش داده شده‌اند.`
            : "ابتدا صنعت را انتخاب کنید تا هدف‌های متناسب نمایش داده شوند.";
        }
      } else if (choice === "purpose") {
        this.data.purpose = value;
      } else if (choice === "style") {
        this.data.style = value;
      } else if (choice === "timeline") {
        this.data.timeline = value;
      } else if (choice === "content") {
        const key = radio.dataset.contentKey;
        if (key && Object.prototype.hasOwnProperty.call(this.data.content, key)) this.data.content[key] = value;
      }
    };
    body.querySelectorAll(".neo-choice-input").forEach((radio) => radio.addEventListener("change", handleChoiceChange));

    body.querySelectorAll(".palette-choice-input").forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        const id = checkbox.value;
        if (checkbox.checked) {
          if (!this.data.colors.palettes.includes(id)) this.data.colors.palettes.push(id);
        } else {
          this.data.colors.palettes = this.data.colors.palettes.filter((value) => value !== id);
        }
        checkbox.closest(".palette-card")?.classList.toggle("selected", checkbox.checked);
        this._clearStepValidationError();
      });
    });
    const searchInput = body.querySelector("#paletteSearchInput");
    searchInput?.addEventListener("input", (event) => {
      const query = String(event.target.value || "").trim().toLocaleLowerCase();
      body.querySelectorAll(".palette-card").forEach((card) => {
        const haystack = `${card.dataset.paletteId || ""} ${card.querySelector(".palette-name")?.textContent || ""}`.toLocaleLowerCase();
        card.hidden = !haystack.includes(query);
      });
    });
    const colorPicker = body.querySelector("#customColorPicker");
    const colorHexInput = body.querySelector("#customColorHex");
    const colorFeedback = body.querySelector("#customColorFeedback");
    const colorAddButton = body.querySelector("#addCustomColorBtn");
    const colorCount = body.querySelector("#customColorCount");
    const readCustomColors = () => Array.isArray(this.data.colors?.customColors) ? this.data.colors.customColors : [];
    const setColorFeedback = (message = "", invalid = false) => {
      if (colorFeedback) {
        colorFeedback.textContent = message;
        colorFeedback.hidden = message === "";
      }
      if (colorHexInput) {
        if (invalid) colorHexInput.setAttribute("aria-invalid", "true");
        else colorHexInput.removeAttribute("aria-invalid");
      }
    };
    const updateCustomColorLimitState = () => {
      const count = readCustomColors().length;
      const limitReached = count >= NEO_CUSTOM_COLOR_LIMIT;
      if (colorAddButton) colorAddButton.disabled = limitReached;
      if (colorCount) {
        colorCount.textContent = `${count} از ${NEO_CUSTOM_COLOR_LIMIT} رنگ دلخواه${limitReached ? "؛ برای افزودن، ابتدا رنگی را حذف کنید." : ""}`;
      }
      return limitReached;
    };

    colorPicker?.addEventListener("input", () => {
      const normalized = normalizeNeoCustomColor(colorPicker.value);
      if (!normalized) return;
      if (colorHexInput) colorHexInput.value = normalized;
      setColorFeedback(updateCustomColorLimitState() ? "سقف چهار رنگ دلخواه پر شده است؛ برای افزودن رنگ جدید ابتدا یکی را حذف کنید." : "");
      this._clearStepValidationError();
    });
    colorHexInput?.addEventListener("input", () => {
      const raw = colorHexInput.value.trim();
      if (raw === "") {
        setColorFeedback(updateCustomColorLimitState() ? "سقف چهار رنگ دلخواه پر شده است؛ برای افزودن رنگ جدید ابتدا یکی را حذف کنید." : "");
        this._clearStepValidationError();
        return;
      }
      const normalized = normalizeNeoCustomColor(raw);
      if (!normalized) {
        setColorFeedback("کد رنگ باید دقیقاً هفت نویسه و به شکل #RRGGBB باشد.", true);
        return;
      }
      if (colorPicker) colorPicker.value = normalized.toLowerCase();
      setColorFeedback(updateCustomColorLimitState() ? "سقف چهار رنگ دلخواه پر شده است؛ برای افزودن رنگ جدید ابتدا یکی را حذف کنید." : "");
      this._clearStepValidationError();
    });
    colorAddButton?.addEventListener("click", () => {
      const raw = colorHexInput?.value.trim() || "";
      const normalized = normalizeNeoCustomColor(raw);
      if (!normalized) {
        setColorFeedback("برای افزودن، رنگ را از انتخابگر بردارید یا یک کد دقیق #RRGGBB وارد کنید.", true);
        colorHexInput?.focus({ preventScroll: true });
        return;
      }
      const currentColors = readCustomColors();
      if (currentColors.length >= NEO_CUSTOM_COLOR_LIMIT) {
        updateCustomColorLimitState();
        setColorFeedback("حداکثر چهار رنگ دلخواه می‌توانید انتخاب کنید؛ ابتدا یکی را حذف کنید.");
        body.querySelector("button[data-remove-color]")?.focus({ preventScroll: true });
        return;
      }
      if (currentColors.some((value) => normalizeNeoCustomColor(value) === normalized)) {
        setColorFeedback("این رنگ قبلاً اضافه شده است؛ رنگ دیگری انتخاب کنید.", true);
        colorHexInput?.focus({ preventScroll: true });
        return;
      }
      this.data.colors.customColors = [...currentColors, normalized];
      this.renderStep(2, { focusHeading: false });
      const newBody = this.elements.body;
      const updatedColors = Array.isArray(this.data.colors.customColors) ? this.data.colors.customColors : [];
      if (updatedColors.length >= NEO_CUSTOM_COLOR_LIMIT) {
        newBody.querySelector("button[data-remove-color]")?.focus({ preventScroll: true });
      } else {
        newBody.querySelector("#customColorPicker")?.focus({ preventScroll: true });
      }
    });
    body.querySelectorAll("button[data-remove-color]").forEach((removeButton) => {
      removeButton.addEventListener("click", () => {
        const color = removeButton.dataset.removeColor;
        this.data.colors.customColors = readCustomColors().filter((value) => String(value).toUpperCase() !== String(color).toUpperCase());
        this.renderStep(2, { focusHeading: false });
        const newBody = this.elements.body;
        const nextRemove = newBody.querySelector("button[data-remove-color]");
        (nextRemove || newBody.querySelector("#customColorPicker"))?.focus({ preventScroll: true });
      });
    });

    body.querySelectorAll(".neo-checkbox-card input[type=checkbox]").forEach((checkbox) => {
      checkbox.addEventListener("change", () => {
        const value = checkbox.value;
        if (checkbox.checked) {
          if (!this.data.features.includes(value)) this.data.features.push(value);
        } else {
          this.data.features = this.data.features.filter((feature) => feature !== value);
        }
        checkbox.closest(".neo-checkbox-card")?.classList.toggle("checked", checkbox.checked);
        this._clearStepValidationError();
      });
    });

    const updateContactField = (event) => {
      const element = event.currentTarget;
      const fieldId = element.dataset.neoField;
      if (!fieldId) return;
      const nextValue = String(element.value ?? "");
      this._clearStepValidationError();
      if (fieldId === "messenger") {
        const previousMessenger = String(this.data.messenger || "");
        if (previousMessenger !== nextValue) this.data.messengerId = "";
        this.data.messenger = nextValue;
        const messengerIdWrapper = body.querySelector("#neo-field-messengerId");
        const messengerIdInput = body.querySelector("#neo_messengerId");
        const hasMessenger = nextValue !== "";
        if (messengerIdWrapper) messengerIdWrapper.hidden = !hasMessenger;
        if (!hasMessenger || previousMessenger !== nextValue) {
          this.data.messengerId = "";
          if (messengerIdInput) messengerIdInput.value = "";
        }
      } else {
        this.data[fieldId] = nextValue;
      }
    };
    body.querySelectorAll("[data-neo-field]").forEach((field) => {
      field.addEventListener("input", updateContactField);
      field.addEventListener("change", updateContactField);
    });

    body.querySelectorAll(".neo-review-edit").forEach((edit) => {
      edit.addEventListener("click", () => {
        const stepNumber = Number.parseInt(edit.dataset.step, 10);
        if (Number.isInteger(stepNumber) && stepNumber >= 0 && stepNumber <= this.totalSteps) {
          this.step = stepNumber;
          this.renderStep(stepNumber);
        }
      });
    });

    if (step.id !== 0) this._saveDraftState();
  }
  _handleStepKeydown(event) {
    if (event.defaultPrevented || event.key !== "Enter" || event.repeat
        || event.isComposing === true || event.keyCode === 229
        || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

    if (!this.elements.modal?.classList.contains("active")) return;
    const body = this.elements.body;
    const target = event.target;
    if (!body || !(target instanceof Element) || !body.contains(target)) return;
    if (target.isContentEditable || target.closest('[contenteditable="true"]')
        || target.closest("form") || target.form
        || target.closest("button, a[href], select, textarea, summary, [role=button], [role=combobox], [role=listbox]")) return;
    if (target.tagName !== "INPUT" || target.disabled || target.readOnly
        || target.autocomplete === "one-time-code" || target.matches("[data-neo-no-enter-advance]")) return;

    const type = String(target.type || "text").toLowerCase();

    // Enter in the custom HEX field means “add this color”, not “leave the step”.
    if (target.id === "customColorHex") {
      const addButton = body.querySelector("#addCustomColorBtn");
      if (addButton && !addButton.disabled) {
        event.preventDefault();
        addButton.click();
      }
      return;
    }

    // Radio/checkbox controls keep native Arrow/Space behavior; Enter advances only after selection.
    if (type === "radio" || type === "checkbox") {
      if (!target.checked) return;
      event.preventDefault();
      this.next();
      return;
    }

    // Search, color picker, file/button inputs and other specialized controls retain native behavior.
    if (!["text", "email", "tel", "url", "number"].includes(type)) return;

    event.preventDefault();
    this.next();
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
        const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches === true;
        body.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
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
    this._clearStepValidationError();
    if (step.type === "industry-purpose") {
      if (!this.data.industry) {
        this._showStepValidationError("لطفاً صنعت پروژه را انتخاب کنید.", "#neoIndustryGroup");
        return false;
      }
      if (!this.data.purpose) {
        this._showStepValidationError("لطفاً هدف متناسب با صنعت انتخاب‌شده را مشخص کنید.", "#neoPurposes");
        return false;
      }
    }
    if (step.type === "colors") {
      const customColors = this.data.colors?.customColors;
      const palettes = this.data.colors?.palettes;
      if (!Array.isArray(customColors) || !Array.isArray(palettes)) {
        this._showStepValidationError("ساختار انتخاب‌های رنگ معتبر نیست؛ رنگ‌های سفارشی را اصلاح و دوباره بررسی کنید.", "#customColorsPreview");
        return false;
      }
      const normalizedCustomColors = customColors.map((color) => normalizeNeoCustomColor(color));
      if (customColors.length > NEO_CUSTOM_COLOR_LIMIT || normalizedCustomColors.some((color) => color === null)) {
        this._showStepValidationError("رنگ سفارشی باید دقیقاً #RRGGBB باشد و حداکثر چهار رنگ دلخواه مجاز است. مقادیر قدیمی نامعتبر را حذف کنید.", "#customColorsPreview");
        return false;
      }
      if (new Set(normalizedCustomColors).size !== normalizedCustomColors.length) {
        this._showStepValidationError("رنگ‌های سفارشی تکراری هستند؛ موارد تکراری را حذف کنید.", "#customColorsPreview");
        return false;
      }
      if (palettes.length === 0 && customColors.length === 0) {
        this._showStepValidationError("حداقل یک پالت رنگی یا رنگ دلخواه انتخاب کنید.", "#neoPaletteGroup");
        return false;
      }
    }
    if (step.id === 3 && !this.data.style) {
      this._showStepValidationError("لطفاً سبک طراحی را انتخاب کنید.", "#neoStyleGroup");
      return false;
    }
    if (step.id === 5) {
      const missingQuestion = step.questions.find((question) => !this.data.content[question.id]);
      if (missingQuestion) {
        this._showStepValidationError(`لطفاً وضعیت «${missingQuestion.label}» را انتخاب کنید.`, `#neoContentGroup-${missingQuestion.id}`);
        return false;
      }
    }
    if (step.id === 6 && !this.data.timeline) {
      this._showStepValidationError("لطفاً بازهٔ زمانی را انتخاب کنید.", "#neoTimelineGroup");
      return false;
    }
    if (step.id === 7) {
      const fullName = trimNeoProjectText(this.data.fullName);
      if (!fullName) {
        this._showStepValidationError("نام و نام خانوادگی الزامی است.", "#neo_fullName");
        return false;
      }
      if (hasForbiddenNeoProjectTextControl(fullName)) {
        this._showStepValidationError("نام نباید شامل نویسه‌های کنترلی نامعتبر باشد.", "#neo_fullName");
        return false;
      }
      if (neoProjectTextLength(fullName) > 160) {
        this._showStepValidationError("نام و نام خانوادگی نمی‌تواند بیشتر از ۱۶۰ نویسه باشد.", "#neo_fullName");
        return false;
      }

      const phone = trimNeoProjectText(this.data.phone);
      if (!phone) {
        this._showStepValidationError("شماره تماس الزامی است.", "#neo_phone");
        return false;
      }
      if (!normalizeNeoPhoneForValidation(phone)) {
        this._showStepValidationError("شمارهٔ همراه ایران معتبر نیست. از قالب 09xxxxxxxxx، ‎+989xxxxxxxxx یا 00989xxxxxxxxx استفاده کنید.", "#neo_phone");
        return false;
      }

      const email = trimNeoProjectText(this.data.email);
      const emailInput = this.elements.body?.querySelector("#neo_email");
      if (hasForbiddenNeoProjectTextControl(email)) {
        this._showStepValidationError("ایمیل نباید شامل نویسه‌های کنترلی نامعتبر باشد.", "#neo_email");
        return false;
      }
      if (neoProjectTextLength(email) > 254) {
        this._showStepValidationError("نشانی ایمیل نمی‌تواند بیشتر از ۲۵۴ نویسه باشد.", "#neo_email");
        return false;
      }
      if (email && emailInput?.validity?.typeMismatch) {
        this._showStepValidationError("قالب نشانی ایمیل معتبر نیست؛ آن را اصلاح کنید یا خالی بگذارید.", "#neo_email");
        return false;
      }

      const messengerId = trimNeoProjectText(this.data.messengerId);
      if (hasForbiddenNeoProjectTextControl(messengerId)) {
        this._showStepValidationError("آیدی پیام‌رسان شامل نویسهٔ نامعتبر است.", "#neo_messengerId");
        return false;
      }
      if (neoProjectTextLength(messengerId) > 120) {
        this._showStepValidationError("آیدی پیام‌رسان نمی‌تواند بیشتر از ۱۲۰ نویسه باشد.", "#neo_messengerId");
        return false;
      }

      const userNote = trimNeoProjectText(this.data.userNote);
      if (hasForbiddenNeoProjectTextControl(userNote)) {
        this._showStepValidationError("یادداشت شامل نویسه‌های کنترلی نامعتبر است.", "#neo_userNote");
        return false;
      }
      if (neoProjectTextLength(userNote) > 2000) {
        this._showStepValidationError("یادداشت نمی‌تواند بیشتر از ۲۰۰۰ نویسه باشد.", "#neo_userNote");
        return false;
      }
    }
    return true;
  }
  _shakeNext() {
    const button = this.elements.nextBtn;
    if (!button || window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches === true
      || button.classList.contains("neo-next-shake")) return;

    button.addEventListener("animationend", (event) => {
      if (event.target === button && event.animationName === "shake") {
        button.classList.remove("neo-next-shake");
      }
    }, { once: true });
    button.classList.add("neo-next-shake");
  }
  async _submit() {
    const sessionCheck = await NeoPreFlightCheck.verifyUserSession(this.userId);
    if (!sessionCheck.allowed) {
      this._saveDraftState();
      const activeSession = AuthSystem.checkSession();
      const sameDraftOwner = activeSession?.type === "user"
        && String(activeSession.data.userId) === String(this.userId);
      const changedPrincipal = sessionCheck.reason === "account_changed"
        || sessionCheck.reason === "wrong_principal"
        || (sessionCheck.reason === "session_changed" && Boolean(activeSession) && !sameDraftOwner);
      if (changedPrincipal) {
        this._discardInMemoryDraftAfterAccountSwitch();
        this.notification?.warning("حساب فعال تغییر کرده است؛ فرم قبلی بسته شد تا اطلاعات شخصی بین حساب‌ها نمایش داده نشود. پیش‌نویس ذخیره‌شده فقط زیر حساب اصلی باقی می‌ماند؛ اگر ذخیرهٔ موقت روشن نبود، دادهٔ حافظه‌ای عمداً نگه‌داری نمی‌شود.");
      } else if (sessionCheck.reason === "auth_required" || (sessionCheck.reason === "session_changed" && !activeSession)) {
        this.notification?.warning(`${sessionCheck.message} ${this.persistDraftAcrossRefresh ? "پیش‌نویس و اطلاعات لازم برای تلاش امن دوباره ذخیره می‌مانند." : "این صفحه باز بماند تا اطلاعات فعلی در حافظه از بین نرود."}`);
        this.close();
        window.setTimeout(() => window.authSystem?.open(), 300);
      } else {
        this.notification?.error(sessionCheck.message);
      }
      return;
    }
    const payload = {
      industry: this.data.industry,
      purpose: this.data.purpose,
      colors: this.data.colors,
      style: this.data.style,
      features: this.data.features,
      content: this.data.content,
      timeline: this.data.timeline,
      fullName: this.data.fullName,
      phone: this.data.phone,
      email: this.data.email,
      messenger: this.data.messenger,
      messengerId: this.data.messengerId,
      userNote: this.data.userNote,
    };
    try {
      if (this.submissionKey && this.pendingSubmissionPayload
          && JSON.stringify(payload) !== JSON.stringify(this.pendingSubmissionPayload)) {
        const pendingPayload = cloneSubmissionPayload(this.pendingSubmissionPayload);
        if (!pendingPayload) throw new Error("نسخه اصلی درخواست قبلی قابل بازیابی نیست؛ برای جلوگیری از ثبت تکراری، ارسال متوقف شد.");
        this.data = { ...this._getEmptyData(), ...pendingPayload };
        this.step = this.totalSteps;
        this.renderStep(this.step);
        this.notification?.warning("نتیجه درخواست قبلی هنوز قطعی نیست. اطلاعات به همان نسخه ارسال‌شده برگردانده شد تا درخواست تکراری ساخته نشود؛ همین درخواست را دوباره ارسال کنید.");
        return;
      }
      if (this.submissionKey) {
        const createdAtMs = Number(this.submissionKeyCreatedAtMs) || 0;
        const retryWindowExpired = !this.pendingSubmissionPayload || createdAtMs <= 0 || Date.now() - createdAtMs >= PROJECT_SUBMISSION_IDEMPOTENCY_SAFE_RETRY_WINDOW_MS;
        if (retryWindowExpired) {
          const confirmed = window.confirm(
            "بیش از ۲۲ ساعت از کلید ثبت قبلی گذشته یا زمان آن معلوم نیست. ابتدا پنل پروژه‌های خود را باز کنید و بررسی کنید آیا درخواست قبلی ثبت شده است. فقط اگر پس از بررسی مطمئنید پروژهٔ متناظر ساخته نشده، تأیید کنید تا با کلید تازه تلاش شود. ادامه ممکن است پروژهٔ تکراری ایجاد کند."
          );
          if (!confirmed) {
            window.notificationSystem?.warning("درخواست قبلی و پیش‌نویس حفظ شدند. پیش از تلاش مجدد، فهرست پروژه‌ها را بررسی کنید.");
            return;
          }
          this.submissionKey = createSubmissionKey();
          this.submissionKeyCreatedAtMs = Date.now();
          this.pendingSubmissionPayload = cloneSubmissionPayload(payload);
        }
      }
      if (!this.submissionKey) {
        this.submissionKey = createSubmissionKey();
        this.submissionKeyCreatedAtMs = Date.now();
      }
      if (!this.pendingSubmissionPayload) this.pendingSubmissionPayload = cloneSubmissionPayload(payload);
      if (!this.pendingSubmissionPayload) throw new Error("نسخه ثابت درخواست برای ارسال امن ذخیره نشد.");
      // A retry always reuses the same key and the exact payload from its first attempt.
      this._saveDraftState();
    } catch (keyError) {
      window.notificationSystem?.error(keyError.message || "کلید امن ثبت درخواست ساخته نشد.");
      return;
    }

    const nextBtn = this.elements.nextBtn;
    const originalBtnHtml = nextBtn.innerHTML;
    nextBtn.disabled = true;
    nextBtn.innerHTML =
      '<span class="loader-spinner"></span> در حال ثبت اطلاعات...';

    // A new attempt must never leave an older project's receipt available as a false fallback.
    window.lastSubmittedProject = null;
    let resultData;
    try {
      const resp = await apiFetch(CONFIG.apiEndpoint + "?action=saveNeo", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Idempotency-Key": this.submissionKey,
        },
        body: JSON.stringify(payload),
      });
      const result = await resp.json();
      if (!resp.ok) {
        const message = typeof result?.error === "string"
          ? result.error
          : (typeof result?.detail === "string" ? result.detail : "ثبت توسط سرور تأیید نشد.");
        const apiError = new Error(message);
        apiError.status = resp.status;
        apiError.code = typeof result?.code === "string" ? result.code : "PROJECT_SUBMISSION_REJECTED";
        throw apiError;
      }
      if (resp.status !== 201) {
        throw new Error("پاسخ ثبت پروژه با قرارداد HTTP 201 مطابقت نداشت؛ نتیجه را قطعی فرض نکردیم و پیش‌نویس و کلید درخواست حفظ شدند.");
      }
      const canonicalReceipt = normalizeCanonicalProjectReceipt(result?.data);
      if (!canonicalReceipt) {
        throw new Error("پاسخ سرور همهٔ فیلدهای canonical رسید را برنگرداند؛ ثبت قطعی فرض نشد و پیش‌نویس و کلید درخواست حفظ شدند.");
      }
      // Retain only canonical fields returned by the server; never merge the submitted Brief.
      resultData = canonicalReceipt;
      window.lastSubmittedProject = canonicalReceipt;
    } catch (e) {
      // A definitive client/request rejection means no project was created; a new corrected
      // submission may use a new key. Timeouts, server/network failures and conflicts retain
      // the original key + exact payload so a successful but unacknowledged write is not duplicated.
      const status = Number(e?.status) || 0;
      const authExpired = status === 401 || e?.code === "AUTH_REQUIRED";
      if (authExpired) {
        // A 401 is not an anonymous-project fallback and must not discard the pending
        // idempotency key/payload. The server rejected this authenticated mutation; keep
        // the exact retry record (if the user opted into browser persistence).
        AuthSystem.applyServerSession({ authenticated: false, principalType: null });
        this._saveDraftState();
        window.notificationSystem?.warning("نشست هنگام ثبت منقضی شد؛ ثبت پروژه متوقف شد. کلید و نسخهٔ درخواست برای جلوگیری از ثبت تکراری حفظ شده‌اند. دوباره وارد شوید و سپس همین درخواست را ادامه دهید.");
        this.close();
        window.setTimeout(() => window.authSystem?.open(), 300);
        return;
      }
      if (status >= 400 && status < 500 && status !== 409) {
        this.submissionKey = null;
        this.submissionKeyCreatedAtMs = null;
        this.pendingSubmissionPayload = null;
      }
      this._saveDraftState();
      window.notificationSystem?.error(
        e.message || "ثبت پروژه توسط سرور تأیید نشد؛ پیش‌نویس و کلید درخواست حفظ شده‌اند.",
      );
      return;
    } finally {
      nextBtn.disabled = false;
      nextBtn.innerHTML = originalBtnHtml;
    }

    // Clear draft only after HTTP 201 and a canonical receipt. If browser storage
    // refuses removal, do not report deletion as successful; preserve this fact for UI.
    const mayContainSavedDraft = this._draftMayExistInStorage || this.persistDraftAcrossRefresh;
    const draftCleared = NeoPreFlightCheck.clearDraft(this.userId);
    this._draftMayExistInStorage = !draftCleared;
    const draftCleanupWarning = mayContainSavedDraft && !draftCleared;
    this.persistDraftAcrossRefresh = false;
    this._draftResumed = false;
    this._legacyDraftResumed = false;
    this.submissionKey = null;
    this.submissionKeyCreatedAtMs = null;
    this.pendingSubmissionPayload = null;

    const isLoggedIn = AuthSystem.checkSession()?.type === "user";
    const trackingCode = resultData.trackingCode;
    // Hand modal ownership to ThankYouModal; do not restore focus to the launcher
    // while the next modal is about to receive focus.
    this._removeTrap();
    this.elements.modal?.classList.remove("active");
    this.elements.floatBtn?.setAttribute("aria-expanded", "false");
    this.previousActiveElement = null;
    syncModalScrollLock();
    // Move focus out of the closing dialog before ThankYouModal snapshots its return target.
    if (this._canRestoreFocus(this.elements.floatBtn)) {
      this.elements.floatBtn.focus({ preventScroll: true });
    }
    setTimeout(() => {
      window.thankYouModal?.show(trackingCode, isLoggedIn);
      if (draftCleanupWarning) {
        this.notification?.warning("پروژه با موفقیت ثبت شد، اما مرورگر حذف نسخهٔ موقت پیش‌نویس را تأیید نکرد. ممکن است دادهٔ قبلی در ذخیره‌سازی همین تب باقی مانده باشد؛ ذخیره‌سازی مرورگر را پاک کنید و این هشدار را موفقیت‌آمیز بودن حذف تلقی نکنید.");
      }
    }, 300);
    this.step = 0;
    this.data = this._getEmptyData();
    this._draftResumed = false;
  }
}

// Shared modal scroll-lock reconciliation for stacked MR NEON dialogs.
function syncModalScrollLock() {
  const activeModal = document.querySelector(
    ".auth-modal.active, .register-modal.active, .terms-modal.active, .user-panel-modal.active, .neo-modal.active, .thank-you-modal.active, .receipt-modal.active, .admin-login-modal.active, .admin-data-modal.active, .customer-project-detail-modal.active, .admin-project-detail-modal:not([hidden]), .tour-overlay.active",
  );
  document.body.classList.toggle("modal-scroll-locked", Boolean(activeModal));
}

/**
 * Accept only the canonical, public receipt contract returned by saveNeo.
 * This intentionally drops every field outside the five receipt fields and
 * never merges client form/brief values into a server-confirmed receipt.
 */
function normalizeCanonicalProjectReceipt(data) {
  if (!data || typeof data !== "object" || Array.isArray(data)) return null;

  const projectId = typeof data.projectId === "string" ? data.projectId.trim() : "";
  const trackingCode = typeof data.trackingCode === "string" ? data.trackingCode.trim() : "";
  const title = typeof data.title === "string" ? data.title.trim() : "";
  const status = data.status;
  const createdAt = typeof data.createdAt === "string" ? data.createdAt : "";

  // These constraints mirror the current server's public-ID/tracking/title contract.
  if (!/^[a-f0-9]{32}$/.test(projectId)) return null;
  if (!/^MN-[A-HJ-NP-Z2-9]{12}$/.test(trackingCode)) return null;
  if (!title || title.length > 180 || /[\u0000-\u001F\u007F]/.test(title)) return null;
  if (status !== "submitted") return null;
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(createdAt)) return null;

  const parsedCreatedAt = new Date(createdAt);
  if (Number.isNaN(parsedCreatedAt.getTime())
      || parsedCreatedAt.toISOString().replace(/\.000Z$/, "Z") !== createdAt) return null;

  return Object.freeze({ projectId, trackingCode, title, status, createdAt });
}

function createReceiptNode(tagName, className = "", text = undefined) {
  const node = document.createElement(tagName);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = String(text);
  return node;
}

function formatCanonicalReceiptDate(createdAt) {
  const date = new Date(createdAt);
  return new Intl.DateTimeFormat("fa-IR", {
    calendar: "persian",
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Tehran",
  }).format(date);
}

async function copyTextWithFallback(value) {
  const text = String(value ?? "");
  if (!text) throw new Error("متنی برای کپی وجود ندارد.");
  if (navigator.clipboard?.writeText && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const field = document.createElement("textarea");
  const previouslyFocused = document.activeElement instanceof HTMLElement
    ? document.activeElement
    : null;
  field.className = "neo-clipboard-fallback-field";
  field.value = text;
  field.setAttribute("readonly", "");
  field.setAttribute("aria-hidden", "true");
  field.tabIndex = -1;
  document.body.appendChild(field);
  let copied = false;
  try {
    field.select();
    copied = document.execCommand("copy");
  } finally {
    field.remove();
    // Selecting a temporary fallback field can move focus away from the
    // invoking control. Restore only the element that was focused at entry.
    if (previouslyFocused?.isConnected && typeof previouslyFocused.focus === "function") {
      try {
        previouslyFocused.focus({ preventScroll: true });
      } catch {
        previouslyFocused.focus();
      }
    }
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
      if (typeof window.html2canvas === "function") {
        resolve(window.html2canvas);
      } else {
        script.remove();
        reject(new Error("ابزار ذخیرهٔ تصویر بارگذاری شد اما در دسترس نیست."));
      }
    };
    const onError = () => {
      cleanup();
      script.remove();
      reject(new Error("ابزار ذخیرهٔ تصویر محلی بارگذاری نشد."));
    };
    script.addEventListener("load", onLoad, { once: true });
    script.addEventListener("error", onError, { once: true });
    if (!existing) {
      script.src = new URL("../assets/js/html2canvas.min.js", import.meta.url).href;
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

// Shared, active-only and stack-aware focus lifecycle for post-submit dialogs.
// NEO's primary wizard keeps its own controller; Thank You and Receipt use this one.
const activeDialogFocusControllers = [];

class DialogFocusController {
  constructor(dialog, onEscape) {
    this.dialog = dialog;
    this.onEscape = onEscape;
    this.active = false;
    this.returnFocus = null;
    this.lastFocused = null;
    this.redirecting = false;
    this.handleKeydown = this.handleKeydown.bind(this);
    this.handleFocusIn = this.handleFocusIn.bind(this);
  }

  isOpen() {
    return Boolean(
      this.dialog?.classList.contains("active")
      && !this.dialog.hidden
      && this.dialog.getAttribute("aria-hidden") !== "true",
    );
  }

  isTopMost() {
    return activeDialogFocusControllers[activeDialogFocusControllers.length - 1] === this;
  }

  isVisible(element) {
    if (!(element instanceof HTMLElement) || !element.isConnected) return false;
    if (element === document.body || element.disabled || element.hidden) return false;
    if (element.closest("[hidden], [inert], [aria-hidden=\"true\"]")) return false;
    const style = window.getComputedStyle(element);
    return style.display !== "none"
      && style.visibility !== "hidden"
      && element.getClientRects().length > 0;
  }

  getFocusableElements() {
    if (!this.dialog) return [];
    const selector = [
      'a[href]',
      'area[href]',
      'button:not([disabled]):not([hidden])',
      'input:not([disabled]):not([type="hidden"]):not([hidden])',
      'select:not([disabled]):not([hidden])',
      'textarea:not([disabled]):not([hidden])',
      'summary',
      'iframe',
      'object',
      'embed',
      'audio[controls]',
      'video[controls]',
      '[contenteditable="true"]',
      '[tabindex]:not([tabindex="-1"])',
    ].join(",");

    return Array.from(this.dialog.querySelectorAll(selector)).filter((element) => (
      element.tabIndex >= 0
      && !element.matches(":disabled")
      && this.isVisible(element)
    ));
  }

  activate(initialTarget = null) {
    if (this.active || !this.isOpen()) return;

    this.returnFocus = this.isVisible(document.activeElement) ? document.activeElement : null;
    this.active = true;
    activeDialogFocusControllers.push(this);
    document.addEventListener("keydown", this.handleKeydown, true);
    document.addEventListener("focusin", this.handleFocusIn, true);

    const focusable = this.getFocusableElements();
    const target = initialTarget && focusable.includes(initialTarget)
      ? initialTarget
      : (focusable[0] || this.dialog);
    target?.focus({ preventScroll: true });
  }

  deactivate({ restoreFocus = true } = {}) {
    if (!this.active) return;

    document.removeEventListener("keydown", this.handleKeydown, true);
    document.removeEventListener("focusin", this.handleFocusIn, true);
    const wasTopMost = this.isTopMost();
    const stackIndex = activeDialogFocusControllers.lastIndexOf(this);
    if (stackIndex !== -1) activeDialogFocusControllers.splice(stackIndex, 1);
    this.active = false;
    this.redirecting = false;
    this.lastFocused = null;

    const target = this.returnFocus;
    this.returnFocus = null;
    if (restoreFocus && wasTopMost && this.isVisible(target)) target.focus({ preventScroll: true });
  }

  handleKeydown(event) {
    if (!this.active || !this.isTopMost() || !this.isOpen()) return;

    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      this.onEscape?.();
      return;
    }
    if (event.key !== "Tab") return;

    const focusable = this.getFocusableElements();
    if (!focusable.length) {
      event.preventDefault();
      this.dialog?.focus({ preventScroll: true });
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const activeIndex = focusable.indexOf(document.activeElement);
    if (activeIndex === -1) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus({ preventScroll: true });
    } else if (event.shiftKey && activeIndex === 0) {
      event.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!event.shiftKey && activeIndex === focusable.length - 1) {
      event.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  handleFocusIn(event) {
    if (!this.active || !this.isTopMost() || !this.isOpen()) return;
    if (this.dialog.contains(event.target)) {
      this.lastFocused = event.target;
      return;
    }
    if (this.redirecting) return;

    const target = this.isVisible(this.lastFocused) && this.dialog.contains(this.lastFocused)
      ? this.lastFocused
      : (this.getFocusableElements()[0] || this.dialog);
    if (!target) return;

    this.redirecting = true;
    try {
      target.focus({ preventScroll: true });
    } finally {
      this.redirecting = false;
    }
  }
}

// ============================================================
// 🎉 مودال تشکر
// ============================================================
export class ThankYouModal {
  constructor() {
    this.modal = DOM.thankYouModal;
    this.focusController = new DialogFocusController(this.modal, () => this.close());
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
  }
  show(trackingCode, isLoggedIn) {
    const receipt = normalizeCanonicalProjectReceipt(window.lastSubmittedProject);
    if (!receipt || receipt.trackingCode !== String(trackingCode || "")) {
      window.notificationSystem?.error("تأیید ثبت درخواست از سرور برای نمایش رسید معتبر نیست.");
      return;
    }
    window.lastSubmittedProject = receipt;
    const trackingNode = document.getElementById("thankYouTrackingCode");
    const panelButton = document.getElementById("goToPanelBtn");
    if (trackingNode) trackingNode.textContent = receipt.trackingCode;
    if (panelButton) panelButton.hidden = !isLoggedIn;
    this.modal?.classList.add("active");
    this.modal?.removeAttribute("hidden");
    this.modal?.setAttribute("aria-hidden", "false");
    syncModalScrollLock();
    this.focusController.activate(document.getElementById("thankYouCopyBtn"));
  }
  close() {
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncModalScrollLock();
    this.focusController.deactivate();
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
    this.actionStatus = this.modal?.querySelector("#receiptActionStatus") || null;
    this.focusController = new DialogFocusController(this.modal, () => this.close());
    this.actionInProgress = null;
    this.receipt = null;
    this._printCleanup = null;
    this.init();
  }

  init() {
    this.closeBtn?.addEventListener("click", () => this.close());
    this.overlay?.addEventListener("click", () => this.close());
    this.copyBtn?.addEventListener("click", () => void this.copyReceiptCode());
    this.shareBtn?.addEventListener("click", () => void this.shareReceipt());
    this.downloadBtn?.addEventListener("click", () => void this.downloadReceiptImage());
    this.printBtn?.addEventListener("click", () => this.printReceipt());
  }

  getActionButtons() {
    return [this.copyBtn, this.shareBtn, this.downloadBtn, this.printBtn]
      .filter((button) => button instanceof HTMLButtonElement && button.isConnected);
  }

  setActionStatus(message, state = "idle") {
    if (!this.actionStatus) return;
    const text = typeof message === "string" ? message : "";
    this.actionStatus.textContent = text;
    this.actionStatus.dataset.state = state;
  }

  beginAction(name, button, pendingMessage) {
    if (this.actionInProgress || !this.modal?.classList.contains("active")) return null;

    const snapshots = this.getActionButtons().map((actionButton) => ({
      button: actionButton,
      text: actionButton.textContent,
      ariaBusy: actionButton.getAttribute("aria-busy"),
      ariaDisabled: actionButton.getAttribute("aria-disabled"),
    }));
    const action = { name, button, pendingMessage, snapshots };
    this.actionInProgress = action;

    // Keep every control focusable while the shared receipt action lock is active.
    // aria-disabled is semantic only, so every handler still checks actionInProgress.
    snapshots.forEach(({ button: actionButton }) => {
      actionButton.setAttribute("aria-disabled", "true");
    });
    if (button) {
      button.setAttribute("aria-busy", "true");
      button.textContent = pendingMessage;
    }
    this.setActionStatus(pendingMessage, "loading");
    return action;
  }

  finishAction(action, message, state = "success") {
    if (!action || this.actionInProgress !== action) return;

    action.snapshots.forEach(({ button, text, ariaBusy, ariaDisabled }) => {
      if (!button.isConnected) return;
      button.textContent = text;
      if (ariaBusy === null) button.removeAttribute("aria-busy");
      else button.setAttribute("aria-busy", ariaBusy);
      if (ariaDisabled === null) button.removeAttribute("aria-disabled");
      else button.setAttribute("aria-disabled", ariaDisabled);
    });

    this.actionInProgress = null;
    this.setActionStatus(message, state);
  }

  async copyReceiptCode() {
    const receipt = this.receipt;
    if (!receipt || !this.modal?.classList.contains("active")) {
      window.notificationSystem?.error("رسید معتبر برای کپی در دسترس نیست.");
      return;
    }

    const action = this.beginAction("copy", this.copyBtn, "در حال کپی کد پیگیری…");
    if (!action) return;
    let statusMessage = "کپی کد پیگیری انجام نشد.";
    let statusState = "error";
    try {
      await copyTextWithFallback(receipt.trackingCode);
      statusMessage = "کد پیگیری در کلیپ‌بورد قرار گرفت.";
      statusState = "success";
      window.notificationSystem?.success(statusMessage);
    } catch (error) {
      statusMessage = error?.message || "کپی کد در دسترس نیست.";
      window.notificationSystem?.error(statusMessage);
    } finally {
      this.finishAction(action, statusMessage, statusState);
    }
  }

  getStatusLabel(status) {
    return status === "submitted" ? "ثبت‌شده" : "وضعیت نامعتبر";
  }

  getDisplayDate(receipt) {
    return `${formatCanonicalReceiptDate(receipt.createdAt)} (به وقت تهران)`;
  }

  appendReceiptRow(container, field, label, value, direction = "auto") {
    const row = createReceiptNode("div", "receipt-row");
    row.dataset.receiptField = field;
    const labelNode = createReceiptNode("span", "receipt-label", label);
    const valueNode = createReceiptNode("span", "receipt-value", value);
    if (direction === "ltr") {
      valueNode.dir = "ltr";
      valueNode.classList.add("receipt-value-ltr");
    }
    row.append(labelNode, valueNode);
    container.append(row);
  }

  renderCanonicalReceipt(receipt) {
    if (!this.body) return false;
    this.body.replaceChildren();

    const header = createReceiptNode("div", "receipt-print-header");
    const logo = createReceiptNode("div", "receipt-logo-text", "MR•NEON");
    logo.dir = "ltr";
    const title = createReceiptNode("h2", "receipt-title", "رسید تأیید دریافت درخواست پروژه");
    const codeBox = createReceiptNode("div", "receipt-code-box");
    const codeLabel = createReceiptNode("span", "receipt-code-label", "کد پیگیری");
    const codeValue = createReceiptNode("strong", "receipt-code-val", receipt.trackingCode);
    codeValue.dir = "ltr";
    codeBox.append(codeLabel, codeValue);
    const date = createReceiptNode("p", "receipt-date", `تاریخ ثبت: ${this.getDisplayDate(receipt)}`);
    header.append(logo, title, codeBox, date);

    const details = createReceiptNode("div", "receipt-details-grid");
    this.appendReceiptRow(details, "project-public-id", "شناسه عمومی پروژه", receipt.projectId, "ltr");
    this.appendReceiptRow(details, "project-title", "عنوان پروژه", receipt.title);
    this.appendReceiptRow(details, "project-status", "وضعیت ثبت", this.getStatusLabel(receipt.status));

    const note = createReceiptNode("div", "receipt-footer-note");
    note.append(createReceiptNode(
      "p",
      "",
      "این رسید فقط تأیید دریافت و ثبت درخواست است و به‌منزلهٔ تأیید نهایی قرارداد یا شروع تحویل پروژه نیست."
    ));

    this.body.append(header, details, note);
    return true;
  }

  open() {
    if (!this.receipt) {
      window.notificationSystem?.error("رسید فقط پس از دریافت پاسخ canonical معتبر از سرور قابل نمایش است.");
      return;
    }
    this.modal?.classList.add("active");
    this.modal?.removeAttribute("hidden");
    this.modal?.setAttribute("aria-hidden", "false");
    syncModalScrollLock();
    this.focusController.activate(this.closeBtn);
  }

  close() {
    this._printCleanup?.("closed");
    this.modal?.classList.remove("active");
    this.modal?.setAttribute("hidden", "");
    this.modal?.setAttribute("aria-hidden", "true");
    syncModalScrollLock();
    this.focusController.deactivate();
  }

  async shareReceipt() {
    const currentReceipt = this.receipt;
    if (!currentReceipt || !this.modal?.classList.contains("active")) {
      window.notificationSystem?.error("رسید معتبر برای اشتراک‌گذاری در دسترس نیست.");
      return;
    }

    const action = this.beginAction("share", this.shareBtn, "در حال آماده‌سازی اشتراک‌گذاری…");
    if (!action) return;

    // Snapshot canonical values before any asynchronous share/clipboard operation.
    const receipt = Object.freeze({ ...currentReceipt });
    const text = [
      "درخواست پروژه در MR NEON ثبت شد.",
      `عنوان پروژه: ${receipt.title}`,
      `شناسه عمومی پروژه: ${receipt.projectId}`,
      `کد پیگیری: ${receipt.trackingCode}`,
      `وضعیت: ${this.getStatusLabel(receipt.status)}`,
      `تاریخ ثبت: ${this.getDisplayDate(receipt)}`,
    ].join("\n");

    let statusMessage = "اشتراک‌گذاری رسید انجام نشد.";
    let statusState = "error";
    try {
      if (typeof navigator.share === "function") {
        await navigator.share({ title: "MR NEON — رسید ثبت پروژه", text });
        statusMessage = "محتوای رسید به ابزار اشتراک‌گذاری مرورگر واگذار شد؛ تحویل به گیرنده قابل تأیید نیست.";
        statusState = "info";
        window.notificationSystem?.info(statusMessage);
      } else {
        await copyTextWithFallback(text);
        statusMessage = "متن رسید در کلیپ‌بورد قرار گرفت و برای اشتراک‌گذاری دستی آماده است.";
        statusState = "success";
        window.notificationSystem?.success(statusMessage);
      }
    } catch (error) {
      if (error?.name === "AbortError") {
        statusMessage = "اشتراک‌گذاری لغو شد؛ رسید تغییری نکرد.";
        statusState = "cancelled";
        return;
      }
      try {
        await copyTextWithFallback(text);
        statusMessage = "اشتراک‌گذاری مستقیم در دسترس نبود؛ متن رسید کپی شد و می‌توانید آن را دستی ارسال کنید.";
        statusState = "success";
        window.notificationSystem?.success(statusMessage);
      } catch (fallbackError) {
        statusMessage = fallbackError?.message || error?.message || "اشتراک‌گذاری رسید انجام نشد.";
        statusState = "error";
        window.notificationSystem?.error(statusMessage);
      }
    } finally {
      this.finishAction(action, statusMessage, statusState);
    }
  }

  printReceipt() {
    if (!this.receipt || !this.modal?.classList.contains("active")) {
      window.notificationSystem?.error("برای چاپ، ابتدا یک رسید معتبر را باز کنید.");
      return;
    }
    if (typeof window.print !== "function") {
      const message = "چاپ در این مرورگر در دسترس نیست.";
      this.setActionStatus(message, "error");
      window.notificationSystem?.error(message);
      return;
    }

    const action = this.beginAction("print", this.printBtn, "در حال آماده‌سازی پنجره چاپ…");
    if (!action) return;

    const body = document.body;
    let printMedia = null;
    let cleaned = false;
    const onMediaChange = (event) => { if (!event.matches) cleanup("finished"); };
    const onAfterPrint = () => cleanup("finished");
    const cleanup = (reason = "finished", error = null) => {
      if (cleaned) return;
      cleaned = true;
      body.classList.remove("receipt-print-mode");
      window.removeEventListener("afterprint", onAfterPrint);
      if (typeof printMedia?.removeEventListener === "function") {
        printMedia.removeEventListener("change", onMediaChange);
      } else {
        printMedia?.removeListener?.(onMediaChange);
      }
      if (this._printCleanup === cleanup) this._printCleanup = null;

      if (reason === "finished") {
        this.finishAction(
          action,
          "فرایند چاپ به مرورگر واگذار شد؛ انجام چاپ یا ذخیرهٔ PDF از اینجا قابل تأیید نیست.",
          "info",
        );
      } else if (reason === "failed") {
        const message = error?.message || "چاپ رسید آغاز نشد.";
        this.finishAction(action, message, "error");
        window.notificationSystem?.error(message);
      } else {
        this.finishAction(action, "", "idle");
      }
    };

    this._printCleanup = cleanup;
    try {
      if (typeof window.matchMedia === "function") printMedia = window.matchMedia("print");
      body.classList.add("receipt-print-mode");
      window.addEventListener("afterprint", onAfterPrint);
      if (typeof printMedia?.addEventListener === "function") {
        printMedia.addEventListener("change", onMediaChange);
      } else {
        printMedia?.addListener?.(onMediaChange);
      }
      window.print();
    } catch (error) {
      cleanup("failed", error);
    }
  }

  async downloadReceiptImage() {
    const currentReceipt = this.receipt;
    if (!currentReceipt || !this.modal?.classList.contains("active")) {
      window.notificationSystem?.error("برای دانلود، ابتدا یک رسید معتبر را باز کنید.");
      return;
    }

    const action = this.beginAction("download", this.downloadBtn, "در حال آماده‌سازی تصویر…");
    if (!action) return;

    // Freeze canonical filename data at click time; the active-action lock also
    // prevents another receipt action from changing the rendered source mid-export.
    const receiptSnapshot = Object.freeze({ ...currentReceipt });
    let clone = null;
    let objectUrl = null;
    let downloadAnchor = null;
    let statusMessage = "ذخیره تصویر رسید انجام نشد.";
    let statusState = "error";
    try {
      const sourceContainer = this.modal.querySelector(".receipt-container");
      if (!sourceContainer || !this.body?.querySelector(".receipt-code-val")) {
        throw new Error("محتوای رسید برای ذخیره در دسترس نیست.");
      }

      clone = sourceContainer.cloneNode(true);
      clone.removeAttribute("id");
      clone.querySelectorAll("[id]").forEach((node) => node.removeAttribute("id"));
      // The canonical receipt already contains its own brand/title header. The modal
      // chrome is intentionally excluded so PNG and print present the same receipt.
      clone.querySelector(".receipt-header")?.remove();
      clone.querySelector(".receipt-footer")?.remove();
      clone.querySelector(".receipt-close")?.remove();
      clone.classList.add("receipt-export-clone");
      clone.setAttribute("aria-hidden", "true");
      clone.setAttribute("inert", "");
      document.body.appendChild(clone);

      const html2canvas = await loadLocalHtml2Canvas();
      const canvas = await html2canvas(clone, {
        backgroundColor: "#ffffff",
        scale: Math.min(Math.max(window.devicePixelRatio || 1, 1), 2),
        useCORS: false,
        logging: false,
        width: clone.scrollWidth,
        height: clone.scrollHeight,
        windowWidth: Math.max(document.documentElement.clientWidth, clone.scrollWidth),
        windowHeight: Math.max(document.documentElement.clientHeight, clone.scrollHeight),
      });
      if (!canvas || typeof canvas.toBlob !== "function") {
        throw new Error("خروجی تصویر رسید در این مرورگر قابل دریافت نیست.");
      }
      const blob = await new Promise((resolve, reject) => {
        canvas.toBlob((value) => value && value.size > 0
          ? resolve(value)
          : reject(new Error("تصویر رسید تولید نشد یا خالی بود.")), "image/png");
      });
      objectUrl = URL.createObjectURL(blob);
      downloadAnchor = document.createElement("a");
      downloadAnchor.href = objectUrl;
      downloadAnchor.download = `${receiptSnapshot.trackingCode}-receipt.png`;
      downloadAnchor.hidden = true;
      document.body.appendChild(downloadAnchor);
      downloadAnchor.click();
      const scheduledUrl = objectUrl;
      window.setTimeout(() => URL.revokeObjectURL(scheduledUrl), 1000);
      objectUrl = null;
      // The browser exposes no reliable confirmation that the file was saved.
      statusMessage = "درخواست دانلود تصویر رسید به مرورگر سپرده شد؛ ذخیرهٔ نهایی را مرورگر مدیریت می‌کند.";
      statusState = "info";
      window.notificationSystem?.info(statusMessage);
    } catch (error) {
      statusMessage = error?.message || "ذخیرهٔ تصویر رسید انجام نشد.";
      statusState = "error";
      window.notificationSystem?.error(statusMessage);
    } finally {
      downloadAnchor?.remove();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      clone?.remove();
      this.finishAction(action, statusMessage, statusState);
    }
  }

  show(data) {
    const receipt = normalizeCanonicalProjectReceipt(data);
    if (!receipt) {
      window.notificationSystem?.error("رسید فقط با شناسه عمومی، کد پیگیری، عنوان، وضعیت و زمان ثبت canonical معتبر سرور قابل نمایش است.");
      return false;
    }
    if (!this.modal || !this.body) {
      window.notificationSystem?.error("بخش نمایش رسید در صفحه در دسترس نیست.");
      return false;
    }
    if (this.actionInProgress) {
      window.notificationSystem?.info("عملیات رسید قبلی هنوز در حال انجام است؛ پس از پایان آن دوباره تلاش کنید.");
      return false;
    }
    this.setActionStatus("", "idle");
    this.receipt = receipt;
    if (!this.renderCanonicalReceipt(receipt)) {
      this.receipt = null;
      window.notificationSystem?.error("محتوای رسید ساخته نشد.");
      return false;
    }
    this.open();
    return true;
  }
}
