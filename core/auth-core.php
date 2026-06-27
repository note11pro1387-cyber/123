<?php
/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: core/auth-core.php
 * 🎯 Responsibility: مدیریت کامل احراز هویت، ثبت‌نام، ورود، فراموشی رمز و تغییر مشخصات کاربر
 * 🔌 Dependencies: config.php (ثوابت), core/notifier.php (sendVerificationToTelegram, notifyNewUser)
 * 🔌 Dependants: api.php (از طریق Router فراخوانی می‌شود)
 * 🚫 Constraints: توابع Helper عمومی (jsonSuccess, jsonError و...) در api.php قرار دارند و اینجا فقط Handlerها را صدا می‌زنیم.
 * 📦 Exported: handleRegister, handleVerifyRegisterCode, handleLogin, handleForgotPassword, handleVerifyResetCode, handleSendVerificationCode, handleResendVerificationCode, handleChangeUsername, handleChangePassword, sendVerificationCode, verifyCode
 * 🧠 AI NOTE: منطق اعتبارسنجی توکن و API Key در api.php انجام می‌شود. این فایل فقط منطق خالص Auth را انجام می‌دهد.
 */

function sendVerificationCode(string $identifier, string $fullName, string $type = 'register'): array {
    $code = generateVerificationCode();
    $expiresAt = time() + 600; // 10 minutes
    
    $verifications = readJsonFile(VERIFICATION_FILE);
    $verifications = array_filter($verifications, fn($v) => $v['expiresAt'] > time());
    
    $verifications[] = [
        'id' => uniqid('ver_'),
        'identifier' => $identifier,
        'code' => password_hash($code, PASSWORD_BCRYPT),
        'type' => $type,
        'expiresAt' => $expiresAt,
        'attempts' => 0,
        'used' => false,
        'createdAt' => date('Y-m-d H:i:s'),
    ];
    writeJsonFile(VERIFICATION_FILE, array_values($verifications));
    
    // ارسال به تلگرام (همیشه)
    $telegramSent = sendVerificationToTelegram($identifier, $code, $fullName, $type);
    
    return [
        'code_sent' => $telegramSent,
        'telegram_sent' => $telegramSent,
        'method' => 'telegram',
    ];
}

function verifyCode(string $identifier, string $code, string $type = 'register'): bool {
    $verifications = readJsonFile(VERIFICATION_FILE);
    
    foreach ($verifications as $index => $v) {
        if ($v['identifier'] === $identifier &&
            $v['type'] === $type &&
            !$v['used'] &&
            $v['expiresAt'] > time() &&
            $v['attempts'] < 5 &&
            password_verify($code, $v['code'])) {
            
            $verifications[$index]['used'] = true;
            writeJsonFile(VERIFICATION_FILE, array_values($verifications));
            return true;
        }
        
        if ($v['identifier'] === $identifier && $v['type'] === $type && !$v['used'] && $v['expiresAt'] > time()) {
            $verifications[$index]['attempts']++;
            writeJsonFile(VERIFICATION_FILE, array_values($verifications));
        }
    }
    return false;
}

function handleRegister(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $required = ['fullName', 'phone', 'username', 'password', 'telegramId'];
    foreach ($required as $f) {
        if (empty($input[$f])) jsonError("Field '$f' is required", 422);
    }
    
    $fullName = sanitizeString($input['fullName']);
    $phone = sanitizeString($input['phone']);
    $email = !empty($input['email']) ? sanitizeString($input['email']) : null;
    $telegramId = sanitizeString($input['telegramId']);
    $username = sanitizeString($input['username']);
    $password = $input['password'];
    
    // Clean telegram ID
    $telegramId = '@' . ltrim($telegramId, '@');
    
    if (!validatePhone($phone)) jsonError('شماره تلفن نامعتبر است (مثال: 09123456789)', 422);
    if ($email && !validateEmail($email)) jsonError('ایمیل نامعتبر است', 422);
    if (!validateUsername($username)) jsonError('نام کاربری باید ۴-۲۰ کاراکتر انگلیسی/عدد/زیرخط باشد', 422);
    if (!validatePassword($password)) jsonError('رمز عبور باید حداقل ۸ کاراکتر شامل حروف و عدد باشد', 422);
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    foreach ($users as $user) {
        if (strtolower($user['username']) === strtolower($username)) jsonError('این نام کاربری قبلاً ثبت شده', 409);
        if ($user['phone'] === $phone) jsonError('این شماره تلفن قبلاً ثبت شده', 409);
        if ($email && !empty($user['email']) && strtolower($user['email']) === strtolower($email)) {
            jsonError('این ایمیل قبلاً ثبت شده', 409);
        }
    }
    
    $userId = uniqid('user_pending_');
    
    $sendResult = sendVerificationCode($telegramId, $fullName, 'register');
    
    if (!$sendResult['code_sent']) {
        jsonError('ارسال کد تایید با مشکل مواجه شد. لطفاً آیدی تلگرام خود را بررسی کنید.', 500);
    }
    
    $pendingRegs = readJsonFile(DATA_DIR . '/pending_registrations.json');
    $pendingRegs[$userId] = [
        'fullName' => $fullName,
        'phone' => $phone,
        'email' => $email,
        'telegramId' => $telegramId,
        'username' => $username,
        'password' => password_hash($password, PASSWORD_ARGON2ID),
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => time() + 900,
    ];
    writeJsonFile(DATA_DIR . '/pending_registrations.json', $pendingRegs);
    
    jsonSuccess([
        'pending' => true,
        'userId' => $userId,
        'identifier' => $telegramId,
        'method' => 'telegram',
        'message' => "کد تایید به تلگرام شما ارسال شد. لطفاً کد ۶ رقمی را وارد کنید.",
    ]);
}

function handleVerifyRegisterCode(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $userId = $input['userId'] ?? '';
    $code = $input['code'] ?? '';
    
    if (empty($userId) || empty($code)) jsonError('User ID and code are required', 422);
    
    $pendingRegs = readJsonFile(DATA_DIR . '/pending_registrations.json');
    if (!isset($pendingRegs[$userId])) jsonError('درخواست ثبت‌نام یافت نشد یا منقضی شده است', 400);
    
    $pending = $pendingRegs[$userId];
    if ($pending['expiresAt'] < time()) {
        unset($pendingRegs[$userId]);
        writeJsonFile(DATA_DIR . '/pending_registrations.json', $pendingRegs);
        jsonError('کد تایید منقضی شده. لطفاً دوباره ثبت‌نام کنید.', 400);
    }
    
    if (!verifyCode($pending['telegramId'], $code, 'register')) {
        jsonError('کد تایید نامعتبر است. دوباره تلاش کنید.', 400);
    }
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    $finalUserId = uniqid('user_');
    
    $newUser = [
        'id' => $finalUserId,
        'fullName' => $pending['fullName'],
        'phone' => $pending['phone'],
        'email' => $pending['email'],
        'telegramId' => $pending['telegramId'],
        'username' => $pending['username'],
        'password' => $pending['password'],
        'verified' => true,
        'createdAt' => date('Y-m-d H:i:s'),
        'lastLogin' => date('Y-m-d H:i:s'),
    ];
    
    $users[] = $newUser;
    writeJsonFile(DATA_DIR . '/users.json', $users);
    
    unset($pendingRegs[$userId]);
    writeJsonFile(DATA_DIR . '/pending_registrations.json', $pendingRegs);
    
    $sessionId = createSession($finalUserId, [
        'username' => $pending['username'],
        'fullName' => $pending['fullName'],
        'phone' => $pending['phone'],
        'email' => $pending['email'],
    ]);
    
    notifyNewUser(
        $pending['fullName'],
        $pending['username'],
        $pending['phone'],
        $pending['email'],
        $pending['telegramId']
    );
    
    jsonSuccess([
        'verified' => true,
        'userId' => $finalUserId,
        'username' => $pending['username'],
        'fullName' => $pending['fullName'],
        'phone' => $pending['phone'],
        'email' => $pending['email'],
        'token' => $sessionId,
    ]);
}

function handleSendVerificationCode(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    $input = getInput();
    $identifier = sanitizeString($input['identifier'] ?? '');
    $fullName = sanitizeString($input['fullName'] ?? '');
    $type = sanitizeString($input['type'] ?? 'register');
    
    if (empty($identifier)) jsonError('Identifier required', 422);
    
    $result = sendVerificationCode($identifier, $fullName, $type);
    jsonSuccess($result);
}

function handleResendVerificationCode(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $userId = $input['userId'] ?? '';
    if (empty($userId)) jsonError('User ID required', 422);
    
    $pendingRegs = readJsonFile(DATA_DIR . '/pending_registrations.json');
    if (!isset($pendingRegs[$userId])) jsonError('Registration not found', 404);
    
    $pending = $pendingRegs[$userId];
    
    $verifications = readJsonFile(VERIFICATION_FILE);
    $recentCount = 0;
    foreach ($verifications as $v) {
        if ($v['identifier'] === $pending['telegramId'] &&
            $v['createdAt'] > date('Y-m-d H:i:s', time() - 600)) {
            $recentCount++;
        }
    }
    
    if ($recentCount >= 3) jsonError('تعداد دفعات ارسال کد بیش از حد مجاز است. ۱۰ دقیقه صبر کنید.', 429);
    
    $result = sendVerificationCode($pending['telegramId'], $pending['fullName'], 'register');
    jsonSuccess($result);
}

function handleLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $username = sanitizeString($input['username'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) jsonError('نام کاربری و رمز عبور الزامی است', 422);
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    $found = null;
    
    foreach ($users as $user) {
        if (strtolower($user['username']) === strtolower($username)) {
            $found = $user;
            break;
        }
    }
    
    if (!$found || !password_verify($password, $found['password'])) {
        jsonError('نام کاربری یا رمز عبور اشتباه است', 401);
    }
    
    foreach ($users as &$u) {
        if ($u['id'] === $found['id']) {
            $u['lastLogin'] = date('Y-m-d H:i:s');
            break;
        }
    }
    writeJsonFile(DATA_DIR . '/users.json', $users);
    
    $sessionId = createSession($found['id'], [
        'username' => $found['username'],
        'fullName' => $found['fullName'],
        'phone' => $found['phone'] ?? '',
        'email' => $found['email'] ?? '',
        'telegramId' => $found['telegramId'] ?? '',
    ]);
    
    jsonSuccess([
        'userId' => $found['id'],
        'username' => $found['username'],
        'fullName' => $found['fullName'],
        'phone' => $found['phone'] ?? '',
        'email' => $found['email'] ?? '',
        'token' => $sessionId,
    ]);
}

function handleForgotPassword(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $identifier = sanitizeString($input['identifier'] ?? '');
    
    if (empty($identifier)) jsonError('شناسه الزامی است', 422);
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    $found = null;
    
    foreach ($users as $user) {
        if (!empty($user['telegramId']) && $user['telegramId'] === $identifier) {
            $found = $user; break;
        }
        if ($user['phone'] === $identifier) {
            $found = $user; break;
        }
    }
    
    if (!$found) {
        jsonSuccess([
            'message' => 'If the account exists, a reset code will be sent',
            'found' => false,
        ]);
        return;
    }
    
    $telegramId = $found['telegramId'] ?? $identifier;
    $result = sendVerificationCode($telegramId, $found['fullName'], 'password_reset');
    
    $resets = readJsonFile(DATA_DIR . '/password_resets.json');
    $resets[] = [
        'id' => uniqid('reset_'),
        'userId' => $found['id'],
        'identifier' => $telegramId,
        'expiresAt' => time() + 900,
        'createdAt' => date('Y-m-d H:i:s'),
    ];
    writeJsonFile(DATA_DIR . '/password_resets.json', $resets);
    
    jsonSuccess([
        'found' => true,
        'userId' => $found['id'],
        'method' => 'telegram',
        'identifier' => $telegramId,
        'message' => "کد بازیابی به تلگرام ارسال شد",
    ]);
}

function handleVerifyResetCode(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    $identifier = sanitizeString($input['identifier'] ?? '');
    $code = sanitizeString($input['code'] ?? '');
    $newPassword = $input['newPassword'] ?? '';
    $userId = $input['userId'] ?? '';
    
    if (empty($identifier) || empty($code) || empty($newPassword)) jsonError('تمام فیلدها الزامی است', 422);
    if (!validatePassword($newPassword)) jsonError('رمز عبور باید حداقل ۸ کاراکتر شامل حروف و عدد باشد', 422);
    
    if (!verifyCode($identifier, $code, 'password_reset')) {
        jsonError('کد نامعتبر یا منقضی شده است', 400);
    }
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    $found = false;
    $targetId = null;
    
    foreach ($users as &$u) {
        if ($u['id'] === $userId ||
            ($u['telegramId'] ?? '') === $identifier ||
            $u['phone'] === $identifier) {
            $u['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
            $found = true;
            $targetId = $u['id'];
            break;
        }
    }
    
    if (!$found) jsonError('User not found', 404);
    writeJsonFile(DATA_DIR . '/users.json', $users);
    
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    $sessions = array_filter($sessions, fn($s) => $s['user_id'] !== $targetId);
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    
    jsonSuccess(['message' => 'رمز عبور با موفقیت بازنشانی شد']);
}

function handleChangeUsername(): void {
    $session = verifyUserSession();
    $input = getInput();
    $newUsername = sanitizeString($input['newUsername'] ?? '');
    
    if (!validateUsername($newUsername)) jsonError('نام کاربری نامعتبر است', 422);
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    foreach ($users as $u) {
        if (strtolower($u['username']) === strtolower($newUsername)) jsonError('این نام کاربری قبلاً گرفته شده', 409);
    }
    
    foreach ($users as &$u) {
        if ($u['id'] === $session['user_id']) {
            $u['username'] = $newUsername;
            break;
        }
    }
    writeJsonFile(DATA_DIR . '/users.json', $users);
    
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    foreach ($sessions as $sid => &$s) {
        if ($s['user_id'] === $session['user_id']) {
            $s['user_data']['username'] = $newUsername;
            break;
        }
    }
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    
    jsonSuccess();
}

function handleChangePassword(): void {
    $session = verifyUserSession();
    $input = getInput();
    $current = $input['currentPassword'] ?? '';
    $newPass = $input['newPassword'] ?? '';
    
    if (!validatePassword($newPass)) jsonError('رمز جدید باید حداقل ۸ کاراکتر شامل حروف و عدد باشد', 422);
    
    $users = readJsonFile(DATA_DIR . '/users.json');
    $found = false;
    $targetId = null;
    
    foreach ($users as &$u) {
        if ($u['id'] === $session['user_id']) {
            if (!password_verify($current, $u['password'])) jsonError('رمز عبور فعلی اشتباه است', 403);
            $u['password'] = password_hash($newPass, PASSWORD_ARGON2ID);
            $found = true;
            $targetId = $u['id'];
            break;
        }
    }
    
    if (!$found) jsonError('User not found', 404);
    writeJsonFile(DATA_DIR . '/users.json', $users);
    
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    $sessions = array_filter($sessions, fn($s) => $s['user_id'] !== $targetId);
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    
    jsonSuccess(['message' => 'رمز عبور با موفقیت تغییر کرد']);
}