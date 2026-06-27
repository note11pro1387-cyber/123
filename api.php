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
require_once __DIR__ . '/config.php';

// ====================== 2. SECURITY HEADERS ======================
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $allowedOrigin");
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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

function jsonSuccess($data = null): void {
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400, $details = null): void {
    http_response_code($code);
    $response = ['success' => false, 'error' => $message];
    if ($details) $response['details'] = $details;
    error_log("API Error [$code]: $message" . ($details ? ' - ' . json_encode($details) : ''));
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function getInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false) jsonError('Failed to read request body', 400);
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('JSON parse error: ' . json_last_error_msg());
        jsonError('Invalid JSON: ' . json_last_error_msg(), 400);
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
function verifyApiKey(): void {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($apiKey !== API_KEY) jsonError('Invalid or missing API Key', 401);
}

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
    $username = $_SERVER['PHP_AUTH_USER'] ?? null;
    $password = $_SERVER['PHP_AUTH_PW'] ?? null;
    
    // Fallback برای XAMPP و هاست‌های ایرانی
    if (!$username || !$password) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? 
                $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($auth) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        
        if (preg_match('/Basic\s+(.*)$/i', $auth, $matches)) {
            $decoded = base64_decode($matches[1]);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($username, $password) = explode(':', $decoded, 2);
            }
        }
    }
    
    if ($username !== ADMIN_USERNAME || $password !== ADMIN_PASSWORD) {
        header('WWW-Authenticate: Basic realm="MR NEON Admin"');
        jsonError('Admin authentication required', 401);
    }
}

// ====================== 9. ROUTE HANDLER ======================
$action = $_GET['action'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$rateLimits = [
    'register' => [5, 300],
    'login' => [10, 300],
    'forgotPassword' => [5, 600],
    'sendVerificationCode' => [5, 300],
    'verifyRegisterCode' => [10, 300],
    'resendVerificationCode' => [3, 600],
];

$maxReqs = $rateLimits[$action][0] ?? 30;
$window = $rateLimits[$action][1] ?? 60;

if (!checkRateLimit($ip, $action, $maxReqs, $window)) {
    jsonError('Too many requests. Please try again later.', 429);
}

$publicActions = [
    'register', 'login', 'forgotPassword', 'verifyResetCode',
    'sendVerificationCode', 'verifyRegisterCode', 'resendVerificationCode'
];

if (!in_array($action, $publicActions)) verifyApiKey();

try {
    switch ($action) {
        case 'register': handleRegister(); break;
        case 'login': handleLogin(); break;
        case 'forgotPassword': handleForgotPassword(); break;
        case 'verifyResetCode': handleVerifyResetCode(); break;
        case 'sendVerificationCode': handleSendVerificationCode(); break;
        case 'verifyRegisterCode': handleVerifyRegisterCode(); break;
        case 'resendVerificationCode': handleResendVerificationCode(); break;
        case 'saveNeo': handleSaveNeo(); break;
        case 'getProjects': handleGetProjects(); break;
        case 'getProjectById': handleGetProjectById(); break;
        case 'updateProject': handleUpdateProject(); break;
        case 'deleteProject': handleDeleteProject(); break;
        case 'adminUpdate': handleAdminUpdate(); break;
        case 'getAll': handleGetAll(); break;
        case 'getAllUsers': handleGetAllUsers(); break;
        case 'getNotifications': handleGetNotifications(); break;
        case 'markNotificationRead': handleMarkNotificationRead(); break;
        case 'getActiveProjectsCount': handleGetActiveProjectsCount(); break;
        case 'deleteRequest': handleDeleteRequest(); break;
        case 'changeUsername': handleChangeUsername(); break;
        case 'changePassword': handleChangePassword(); break;
        default: jsonError('Unknown action: ' . $action, 400);
    }
} catch (Throwable $e) {
    error_log('API Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('Server error: ' . $e->getMessage(), 500);
}