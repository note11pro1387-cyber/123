<?php
/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: api.php
 * 🎯 Responsibility: Router اصلی، Security Headers، CORS، توابع Helper عمومی، Authentication و Rate Limiting
 * 🔌 Dependencies: config.php, core/auth-core.php, core/notifier.php, core/project-core.php
 * 🔌 Dependants: Frontend (script.js) با fetch به این فایل درخواست می‌دهد.
 * 🚫 Constraints: هیچ منطق مستقیم پروژه یا Auth در این فایل پیاده‌سازی نمی‌شود. فقط Router و Helper.
 * 📦 Exported: تمام توابع Helper عمومی (readJsonFile, writeJsonFile, jsonSuccess, jsonError, ...)
 * 🧠 AI NOTE: این فایل نقطه ورود اصلی API است. تغییر مسیرها فقط در switch case این فایل انجام شود.
 */

// ====================== 1. CONFIGURATION ======================
// Keep PHP warnings/notices out of JSON responses; diagnostics remain in the server error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

// ====================== 2. SECURITY HEADERS ======================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Same-origin is the default. No reflective CORS headers are emitted.
// A cross-origin client must not gain access merely by supplying an Origin header.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestId = RequestIdMiddleware::handle();

// ====================== 3. LOAD CORE MODULES ======================
require_once __DIR__ . '/core/auth-core.php';
require_once __DIR__ . '/core/notifier.php';
require_once __DIR__ . '/core/project-core.php';

// ====================== 4. HELPER FUNCTIONS ======================
function readJsonFile(string $filePath): array {
    if (!file_exists($filePath)) return [];
    $fp = fopen($filePath, 'r');
    if (!$fp) return [];
    $content = '';
    if (flock($fp, LOCK_SH)) {
        $size = filesize($filePath);
        if ($size > 0) $content = fread($fp, $size);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    if (empty($content)) return [];
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

function writeJsonFile(string $filePath, array $data): void {
    $fp = fopen($filePath, 'c+');
    if (!$fp) {
        error_log("Failed to open: $filePath");
        return;
    }
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function jsonSuccess($data = null): never {
    ApiResponse::success($data);
}

function jsonError(string $message, int $code = 400, $details = null): never {
    $machineCode = is_array($details) && is_string($details['code'] ?? null)
        ? $details['code']
        : match ($code) {
            400 => 'BAD_REQUEST', 401 => 'AUTH_REQUIRED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT', 413 => 'REQUEST_TOO_LARGE',
            415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED',
            503 => 'SERVICE_UNAVAILABLE', default => ($code >= 500 ? 'INTERNAL_ERROR' : 'REQUEST_REJECTED'),
        };
    $title = match ($code) {
        400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found',
        405 => 'Method not allowed', 409 => 'Conflict', 413 => 'Content too large',
        415 => 'Unsupported media type', 422 => 'Unprocessable content', 429 => 'Too many requests',
        503 => 'Service unavailable', default => ($code >= 500 ? 'Internal server error' : 'Request rejected'),
    };
    error_log('MR NEON API error: requestId=' . ApiResponse::requestId() . ' status=' . $code . ' code=' . (preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $machineCode) ? $machineCode : 'INTERNAL_ERROR'));
    ApiResponse::problem($code, $machineCode, $title, $message);
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false) jsonError('Failed to read request body', 400);
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonError('Invalid JSON request body', 400, ['code' => 'INVALID_JSON']);
    }
    return is_array($data) ? $data : [];
}

function sanitizeString(?string $value): string {
    if ($value === null) return '';
    return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function generateTrackingCode(): string {
    return 'MR-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)) . '-' . 
           strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function generateVerificationCode(): string {
    return str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

// ====================== 5. PERSIAN DATE (Accurate) ======================
function toPersianDate(?string $date): string {
    if (empty($date)) return '';
    try {
        $timestamp = strtotime($date);
        if (!$timestamp) return $date;
        
        if (class_exists('IntlDateFormatter')) {
            $tz = new DateTimeZone('Asia/Tehran');
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone($tz);
            $formatter = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                $tz,
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd'
            );
            return $formatter->format($dt);
        }
        
        return gregorianToJalali(date('Y', $timestamp), date('m', $timestamp), date('d', $timestamp));
    } catch (Exception $e) {
        return $date;
    }
}

function toPersianDateTime(?string $date): string {
    if (empty($date)) return '';
    try {
        $timestamp = strtotime($date);
        if (!$timestamp) return $date;
        
        if (class_exists('IntlDateFormatter')) {
            $tz = new DateTimeZone('Asia/Tehran');
            $dt = new DateTime('@' . $timestamp);
            $dt->setTimezone($tz);
            $formatter = new IntlDateFormatter(
                'fa_IR@calendar=persian',
                IntlDateFormatter::NONE,
                IntlDateFormatter::SHORT,
                $tz,
                IntlDateFormatter::TRADITIONAL,
                'yyyy/MM/dd - HH:mm'
            );
            return $formatter->format($dt);
        }
        
        return toPersianDate($date) . ' - ' . date('H:i', $timestamp);
    } catch (Exception $e) {
        return $date;
    }
}

function gregorianToJalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

// ====================== 6. VALIDATION ======================
function validatePhone(string $phone): bool {
    return preg_match('/^09[0-9]{9}$/', $phone) === 1;
}

function validateEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateUsername(string $username): bool {
    return preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username) === 1;
}

function validatePassword(string $password): bool {
    return strlen($password) >= 8 &&
        preg_match('/[A-Za-z]/', $password) &&
        preg_match('/[0-9]/', $password);
}

// ====================== 7. RATE LIMITING ======================
function checkRateLimit(string $ip, string $action, int $maxRequests = 30, int $window = 60): bool {
    $limits = readJsonFile(RATE_LIMIT_FILE);
    $key = md5($ip . '_' . $action);
    $now = time();
    
    if (isset($limits[$key]) && $limits[$key]['reset'] < $now) unset($limits[$key]);
    if (!isset($limits[$key])) $limits[$key] = ['count' => 0, 'reset' => $now + $window];
    
    $limits[$key]['count']++;
    writeJsonFile(RATE_LIMIT_FILE, $limits);
    return $limits[$key]['count'] <= $maxRequests;
}

// ====================== 8. AUTHENTICATION ======================
/**
 * Legacy API-key authentication was removed: a key shipped to a browser is public,
 * not an authentication factor. The router below currently fails closed until
 * Wave 3 installs server-side authorization.
 */

function getBearerToken(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ??
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($auth) && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    
    if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) return $matches[1];
    return null;
}

function verifyUserSession(): array {
    $token = getBearerToken();
    if (!$token) jsonError('Authentication required', 401);
    
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    if (!isset($sessions[$token])) jsonError('Invalid session', 401);
    
    $session = $sessions[$token];
    if ($session['expires_at'] < time()) {
        unset($sessions[$token]);
        writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
        jsonError('Session expired', 401);
    }
    
    $sessions[$token]['last_activity'] = time();
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    return $session;
}

function createSession(string $userId, array $userData): string {
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    $sessionId = bin2hex(random_bytes(32));
    
    $sessions[$sessionId] = [
        'user_id' => $userId,
        'user_data' => $userData,
        'created_at' => time(),
        'expires_at' => time() + SESSION_TIMEOUT,
        'last_activity' => time(),
    ];
    
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    return $sessionId;
}

function verifyAdminAuth(): void {
    // Temporary fail-closed guard. Basic credentials and browser-held tokens are not
    // accepted as the future admin authentication design; Wave 3 must replace this.
    jsonError(
        'عملیات مدیریتی تا تکمیل احراز هویت امن مدیر موقتاً غیرفعال است.',
        503,
        ['code' => 'ADMIN_AUTH_FOUNDATION_REQUIRED']
    );
}

// ====================== 9. ROUTE HANDLER ======================
try {
    $action = ApiRequest::action();
    $method = ApiRequest::method();
    MethodPolicy::enforce($action, $method);
} catch (ApiProblemException $exception) {
    ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
}
// Wave 3 activates server-side session/authentication; W4-P2 adds the temporary registration-attempt route.
// W4-P3 activates registration Challenge lifecycle; W4-P5 activates Recovery with reset-only authorization.
if ($action === 'adminLogin') {
    (new AdminAuthController())->dispatch();
}
// W4-P2 creates the registration attempt; W4-P3 owns Challenge lifecycle, verification and completion.
// Telegram's webhook is authenticated by its dedicated provider secret header, not browser CSRF/session.
if ($action === 'telegramWebhook') {
    (new TelegramWebhookController())->dispatch();
}
if ($action === 'telegramLinkStatus') {
    (new TelegramLinkController())->dispatchStatus();
}
if ($action === 'register') {
    (new RegistrationController())->dispatch();
}
if (in_array($action, ['forgotPassword', 'verifyResetCode', 'resetPassword', 'verifyPasswordResetCode'], true)) {
    (new RecoveryController())->dispatch($action);
}
if (in_array($action, ['sendVerificationCode', 'verifyRegisterCode', 'resendVerificationCode'], true)) {
    (new VerificationController())->dispatch($action);
}
if (in_array($action, ['getSession', 'login', 'logout'], true)) {
    (new AuthController())->dispatch($action);
}
// Wave 5/P1: canonical project creation and the advisory capacity preflight.
// These routes must be dispatched before legacy authorization and fail-closed action gates.
if (in_array($action, ['saveNeo', 'getActiveProjectsCount'], true)) {
    (new ProjectController())->dispatch($action);
}
// Customer reads and communication use the DB-backed project owner scope, not legacy JSON handlers.
if (in_array($action, ['getProjects', 'getProjectById', 'sendMessage', 'getNotifications', 'markNotificationRead', 'markCustomerMessagesRead'], true)) {
    (new CustomerController())->dispatch($action);
}
// Admin dashboard and project operations use the canonical DB model and server-side admin session.
if (in_array($action, ['getAll', 'getAllUsers', 'getAdminUserDetail', 'getAdminStats', 'getAdminProjectDetail', 'adminUpdate', 'adminUpdateUser', 'deleteRequest', 'adminSendMessage', 'markAdminMessagesRead', 'getAdminNotifications', 'getAdminMessages', 'getAdminAuditLog', 'getAdminSettingsSummary', 'markAdminNotificationRead', 'exportProjectsCsv'], true)) {
    (new AdminProjectController())->dispatch($action);
}

if (!MethodPolicy::known($action)) {
    ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
}

try {
    LegacyActionAuthorization::enforce($action, $method);
} catch (ApiProblemException $exception) {
    ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
} catch (Throwable $exception) {
    error_log('MR NEON authorization dependency failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
    ApiResponse::problem(503, 'AUTHORIZATION_UNAVAILABLE', 'Service unavailable', 'بررسی مجوز موقتاً در دسترس نیست.');
}

$actionPolicy = [
    'login' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'ورود تا تکمیل نشست امن سمت سرور موقتاً غیرفعال است.'],
    'forgotPassword' => ['code' => 'RECOVERY_UPGRADE_REQUIRED', 'message' => 'بازیابی رمز تا تکمیل جریان امن موقتاً غیرفعال است.'],
    'verifyResetCode' => ['code' => 'RECOVERY_UPGRADE_REQUIRED', 'message' => 'بازیابی رمز تا تکمیل جریان امن موقتاً غیرفعال است.'],
    'getProjects' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'دریافت پروژه‌ها تا تکمیل نشست امن موقتاً غیرفعال است.'],
    'getProjectById' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'دریافت پروژه تا تکمیل نشست امن موقتاً غیرفعال است.'],
    'updateProject' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'ویرایش پروژه تا تکمیل مجوزدهی امن موقتاً غیرفعال است.'],
    'deleteProject' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'عملیات پروژه تا تکمیل مجوزدهی امن موقتاً غیرفعال است.'],
    'getNotifications' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'اعلان‌ها تا تکمیل نشست امن موقتاً غیرفعال است.'],
    'markNotificationRead' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'تغییر اعلان تا تکمیل نشست امن موقتاً غیرفعال است.'],
    'changeUsername' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'تغییر حساب تا تکمیل نشست امن موقتاً غیرفعال است.'],
    'changePassword' => ['code' => 'SESSION_FOUNDATION_REQUIRED', 'message' => 'تغییر رمز تا تکمیل نشست امن موقتاً غیرفعال است.'],
];

// The active Wave 3 actions are dispatched above. All remaining registered legacy
// actions are deliberately held closed until their domain/auth wave is accepted.
if (!isset($actionPolicy[$action])) {
    ApiResponse::problem(404, 'ACTION_NOT_IMPLEMENTED', 'Not found', 'این عملیات هنوز در API پیاده‌سازی نشده است.');
}

// Closed legacy actions do not execute their old JSON-backed rate limiter: even a rejected
// request must not mutate the legacy data store. Active authentication uses the DB limiter.

// No handler currently passes through this gate. Wave 3/4/5 must deliberately
// remove each matching gate only after its new auth/domain contract and tests land.
jsonError($actionPolicy[$action]['message'], 503, ['code' => $actionPolicy[$action]['code']]);

try {
    switch ($action) {
        case 'login': handleLogin(); break;
        case 'forgotPassword': handleForgotPassword(); break;
        case 'verifyResetCode': handleVerifyResetCode(); break;
        case 'sendVerificationCode': handleSendVerificationCode(); break;
        case 'verifyRegisterCode': handleVerifyRegisterCode(); break;
        case 'resendVerificationCode': handleResendVerificationCode(); break;
        case 'getProjects': handleGetProjects(); break;
        case 'getProjectById': handleGetProjectById(); break;
        case 'updateProject': handleUpdateProject(); break;
        case 'deleteProject': handleDeleteProject(); break;
        case 'adminUpdate': handleAdminUpdate(); break;
        case 'getAll': handleGetAll(); break;
        case 'getAllUsers': handleGetAllUsers(); break;
        case 'getNotifications': handleGetNotifications(); break;
        case 'markNotificationRead': handleMarkNotificationRead(); break;
        case 'deleteRequest': handleDeleteRequest(); break;
        case 'changeUsername': handleChangeUsername(); break;
        case 'changePassword': handleChangePassword(); break;
        default: jsonError('Unknown action: ' . $action, 400);
    }
} catch (Throwable $e) {
    global $requestId;
    error_log('MR NEON API exception: requestId=' . ($requestId ?? 'unavailable') . ' type=' . get_class($e));
    jsonError('خطای داخلی سرور رخ داد. شناسهٔ درخواست را برای پیگیری نگه دارید.', 500, ['code' => 'INTERNAL_ERROR']);
}
