/**
 * MR NEON central same-origin API client.
 * Server contract: { data, meta: { requestId } } on success and RFC 9457
 * application/problem+json on failure. No bearer/API secret is stored in Browser Storage.
 */
const DEFAULT_API_ENDPOINT = "api.php";
const CSRF_PATTERN = /^[a-f0-9]{64}$/;
let csrfToken = null;
let configuredEndpoint = DEFAULT_API_ENDPOINT;

export class ApiError extends Error {
  constructor(message, { status = 0, code = "API_REQUEST_FAILED", requestId = null, title = "", retryAfter = null } = {}) {
    super(message || "درخواست انجام نشد.");
    this.name = "ApiError";
    this.status = Number.isInteger(status) ? status : 0;
    this.code = typeof code === "string" ? code : "API_REQUEST_FAILED";
    this.requestId = typeof requestId === "string" ? requestId : null;
    this.title = typeof title === "string" ? title : "";
    this.retryAfter = retryAfter;
  }
}

export function configureApiEndpoint(endpoint) {
  if (typeof endpoint !== "string" || endpoint.trim() === "") {
    throw new TypeError("API endpoint must be a non-empty string.");
  }
  const url = new URL(endpoint, document.baseURI);
  if (url.origin !== window.location.origin) {
    throw new TypeError("MR NEON API must be same-origin.");
  }
  configuredEndpoint = url.href;
}

function actionUrl(action, query = {}) {
  if (typeof action !== "string" || !/^[A-Za-z][A-Za-z0-9]{0,63}$/.test(action)) {
    throw new TypeError("Invalid API action.");
  }
  const url = new URL(configuredEndpoint, document.baseURI);
  url.searchParams.set("action", action);
  if (query !== null && typeof query !== "object") throw new TypeError("API query must be an object.");
  for (const [key, value] of Object.entries(query || {})) {
    if (key === "action" || !/^[A-Za-z][A-Za-z0-9_]{0,63}$/.test(key)) throw new TypeError("Invalid API query parameter.");
    if (value === undefined || value === null) continue;
    if (!["string", "number", "boolean"].includes(typeof value)) throw new TypeError("API query values must be scalar.");
    const text = String(value);
    if (text.length > 256 || /[\r\n\0]/.test(text)) throw new TypeError("Invalid API query value.");
    url.searchParams.set(key, text);
  }
  return url.href;
}

function normalizedError(payload, response, fallbackCode = "API_REQUEST_FAILED") {
  const body = payload && typeof payload === "object" ? payload : {};
  const detail = typeof body.detail === "string"
    ? body.detail
    : (typeof body.error?.message === "string" ? body.error.message : "درخواست انجام نشد.");
  return new ApiError(detail, {
    status: response?.status ?? 0,
    code: typeof body.code === "string" ? body.code : (typeof body.error?.code === "string" ? body.error.code : fallbackCode),
    requestId: typeof body.requestId === "string" ? body.requestId : (typeof body.meta?.requestId === "string" ? body.meta.requestId : null),
    title: typeof body.title === "string" ? body.title : "",
    retryAfter: response?.headers?.get("Retry-After") ?? null,
  });
}

async function send(action, { method = "GET", body, signal, timeoutMs = 15000, headers = {}, query = {} } = {}, includeResponseMeta = false) {
  const upperMethod = String(method).toUpperCase();
  if (!/^(GET|HEAD|POST|PUT|PATCH|DELETE)$/.test(upperMethod)) {
    throw new TypeError("Unsupported API method.");
  }
  const requestHeaders = new Headers(headers);
  requestHeaders.set("Accept", "application/json, application/problem+json");
  let encodedBody;
  if (body !== undefined) {
    requestHeaders.set("Content-Type", "application/json; charset=utf-8");
    try {
      encodedBody = JSON.stringify(body);
    } catch {
      throw new ApiError("ساختار دادهٔ درخواست معتبر نیست.", { code: "INVALID_REQUEST_BODY" });
    }
  }
  if (!encodedBody && upperMethod !== "GET" && upperMethod !== "HEAD") {
    requestHeaders.set("Content-Type", "application/json; charset=utf-8");
    encodedBody = "{}";
  }
  if (upperMethod !== "GET" && upperMethod !== "HEAD") {
    if (!CSRF_PATTERN.test(csrfToken || "")) {
      await ensureCsrf();
    }
    if (!CSRF_PATTERN.test(csrfToken || "")) {
      throw new ApiError("امکان تأیید امن درخواست فراهم نشد.", { code: "CSRF_TOKEN_UNAVAILABLE" });
    }
    requestHeaders.set("X-CSRF-Token", csrfToken);
  }

  const controller = new AbortController();
  let timedOut = false;
  const timeout = setTimeout(() => {
    timedOut = true;
    controller.abort();
  }, Math.min(Math.max(timeoutMs, 1000), 60000));
  const abortFromCaller = () => controller.abort(signal?.reason);
  if (signal?.aborted) abortFromCaller();
  else signal?.addEventListener("abort", abortFromCaller, { once: true });
  let response;
  let payload;
  try {
    response = await fetch(actionUrl(action, query), {
      method: upperMethod,
      headers: requestHeaders,
      body: encodedBody,
      credentials: "same-origin",
      cache: "no-store",
      redirect: "error",
      referrerPolicy: "same-origin",
      signal: controller.signal,
    });
    const contentType = (response.headers.get("Content-Type") || "").toLowerCase();
    if (!contentType.includes("application/json") && !contentType.includes("application/problem+json")) {
      throw new ApiError("پاسخ سرور در قالب معتبر API نیست.", { status: response.status, code: "INVALID_API_CONTENT_TYPE", requestId: response.headers.get("X-Request-ID") });
    }
    try {
      payload = await response.json();
    } catch {
      throw new ApiError("پاسخ JSON سرور قابل‌خواندن نیست.", { status: response.status, code: "INVALID_API_JSON", requestId: response.headers.get("X-Request-ID") });
    }
  } catch (error) {
    if (error instanceof ApiError) throw error;
    if (timedOut) {
      throw new ApiError("پاسخ سرور در زمان مجاز دریافت نشد.", { code: "REQUEST_TIMEOUT" });
    }
    if (signal?.aborted || error?.name === "AbortError") {
      throw new ApiError("درخواست از سوی فراخواننده لغو شد.", { code: "REQUEST_ABORTED" });
    }
    throw new ApiError("ارتباط امن با سرور برقرار نشد.", { code: "NETWORK_ERROR" });
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener("abort", abortFromCaller);
  }

  if (!response.ok) {
    const apiError = normalizedError(payload, response);
    if (apiError.code === "CSRF_VALIDATION_FAILED" || apiError.code === "CSRF_TOKEN_UNAVAILABLE") csrfToken = null;
    throw apiError;
  }
  if (!payload || typeof payload !== "object" || !Object.prototype.hasOwnProperty.call(payload, "data") || !payload.meta || typeof payload.meta.requestId !== "string") {
    throw new ApiError("ساختار پاسخ موفق API معتبر نیست.", { status: response.status, code: "INVALID_API_ENVELOPE", requestId: response.headers.get("X-Request-ID") });
  }
  const newToken = payload.data && typeof payload.data === "object" ? payload.data.csrfToken : null;
  if (typeof newToken === "string" && CSRF_PATTERN.test(newToken)) csrfToken = newToken;
  if (includeResponseMeta) {
    return {
      data: payload.data,
      status: response.status,
      requestId: typeof payload.meta?.requestId === "string"
        ? payload.meta.requestId
        : (response.headers.get("X-Request-ID") || null),
    };
  }
  return payload.data;
}

async function ensureCsrf() {
  if (CSRF_PATTERN.test(csrfToken || "")) return;
  await send("getSession", { method: "GET", timeoutMs: 10000 });
}

export async function apiRequest(action, options = {}) {
  return send(action, options);
}

/**
 * Downloads a server-produced file through the configured same-origin API endpoint.
 * Error responses retain central RFC 9457 normalization and request-ID handling.
 */
export async function downloadApiFile(action, { query = {}, accept = "text/csv", signal, timeoutMs = 60000 } = {}) {
  if (typeof accept !== "string" || !/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i.test(accept)) {
    throw new TypeError("Invalid download media type.");
  }
  const controller = new AbortController();
  let timedOut = false;
  const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, Math.min(Math.max(timeoutMs, 1000), 60000));
  const abortFromCaller = () => controller.abort(signal?.reason);
  if (signal?.aborted) abortFromCaller();
  else signal?.addEventListener("abort", abortFromCaller, { once: true });
  try {
    const response = await fetch(actionUrl(action, query), {
      method: "GET",
      headers: { Accept: `${accept}, application/problem+json` },
      credentials: "same-origin",
      cache: "no-store",
      redirect: "error",
      referrerPolicy: "same-origin",
      signal: controller.signal,
    });
    const contentType = (response.headers.get("Content-Type") || "").split(";")[0].trim().toLowerCase();
    if (!response.ok) {
      let payload = {};
      if (contentType === "application/problem+json" || contentType === "application/json") {
        try { payload = await response.json(); } catch { /* use normalized fallback */ }
      }
      throw normalizedError(payload, response, "FILE_DOWNLOAD_FAILED");
    }
    if (contentType !== accept.toLowerCase()) {
      throw new ApiError("نوع فایل دریافتی با قرارداد دانلود سازگار نیست.", {
        status: response.status,
        code: "INVALID_DOWNLOAD_CONTENT_TYPE",
        requestId: response.headers.get("X-Request-ID"),
      });
    }
    const blob = await response.blob();
    const disposition = response.headers.get("Content-Disposition") || "";
    const match = disposition.match(/filename="([A-Za-z0-9._-]{1,128})"/i);
    return { blob, filename: match ? match[1] : null, requestId: response.headers.get("X-Request-ID") };
  } catch (error) {
    if (error instanceof ApiError) throw error;
    if (timedOut) throw new ApiError("دریافت فایل در زمان مجاز کامل نشد.", { code: "REQUEST_TIMEOUT" });
    if (signal?.aborted || error?.name === "AbortError") throw new ApiError("دریافت فایل لغو شد.", { code: "REQUEST_ABORTED" });
    throw new ApiError("ارتباط امن برای دریافت فایل برقرار نشد.", { code: "NETWORK_ERROR" });
  } finally {
    clearTimeout(timeout);
    signal?.removeEventListener("abort", abortFromCaller);
  }
}

export async function getServerSession() {
  return apiRequest("getSession", { method: "GET" });
}

export function clearApiClientState() {
  csrfToken = null;
}

/**
 * Temporary compatibility adapter for Wave 4/5/6 consumers that still expect
 * Response.ok + a legacy { success, data } wrapper. All transport, JSON parsing,
 * CSRF, origin enforcement, timeout and RFC 9457 normalization remain centralized.
 * Remove this adapter only after every domain consumer adopts apiRequest directly.
 */
export async function apiFetch(input, options = {}) {
  try {
    const url = new URL(typeof input === "string" ? input : input.url, document.baseURI);
    if (url.origin !== window.location.origin) {
      throw new ApiError("درخواست API باید هم‌مبدأ باشد.", { code: "CROSS_ORIGIN_API_REJECTED" });
    }
    const action = url.searchParams.get("action");
    if (!action) throw new ApiError("نام عملیات API مشخص نیست.", { code: "API_ACTION_MISSING" });
    const query = {};
    for (const [key, value] of url.searchParams.entries()) {
      if (key === "action") continue;
      if (Object.prototype.hasOwnProperty.call(query, key)) throw new ApiError("پارامتر تکراری در نشانی درخواست وجود دارد.", { code: "DUPLICATE_API_QUERY_PARAMETER" });
      query[key] = value;
    }
    const headers = new Headers(options.headers || {});
    // Cookie session is the only authentication source; silently discard legacy bearer headers.
    headers.delete("Authorization");
    let body = options.body;
    if (typeof body === "string" && body !== "") {
      try {
        body = JSON.parse(body);
      } catch {
        throw new ApiError("ساختار دادهٔ درخواست معتبر نیست.", { code: "INVALID_REQUEST_BODY" });
      }
    }
    if (body === undefined || body === "") body = undefined;
    const result = await send(action, {
      method: options.method || "GET",
      body,
      headers,
      signal: options.signal,
      timeoutMs: options.timeoutMs || 15000,
      query,
    }, true);
    const envelope = { success: true, data: result.data, meta: { requestId: result.requestId } };
    return {
      ok: true,
      status: result.status,
      headers: new Headers({
        "Content-Type": "application/json; charset=utf-8",
        ...(result.requestId ? { "X-Request-ID": result.requestId } : {}),
      }),
      json: async () => envelope,
    };
  } catch (error) {
    const apiError = error instanceof ApiError
      ? error
      : new ApiError("درخواست انجام نشد.", { code: "API_REQUEST_FAILED" });
    const envelope = {
      success: false,
      error: apiError.message,
      code: apiError.code,
      requestId: apiError.requestId,
    };
    return {
      ok: false,
      status: apiError.status || 0,
      headers: new Headers(apiError.retryAfter ? { "Retry-After": apiError.retryAfter } : {}),
      json: async () => envelope,
    };
  }
}
