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

function sendVerificationCode(string $identifier, string $fullName, string $type = 'register', string $method = 'telegram', ?string $telegramChatId = null): array {
    $method = $method === 'email' ? 'email' : 'telegram';
    $code = generateVerificationCode();
    $verification = ['id'=>bin2hex(random_bytes(16)),'identifier'=>$identifier,'code'=>password_hash($code,PASSWORD_DEFAULT),'type'=>$type,'method'=>$method,'expiresAt'=>time()+120,'attempts'=>0,'used'=>false,'createdAt'=>date('Y-m-d H:i:s')];
    $verifications = readJsonFile(VERIFICATION_FILE);
    $verifications = array_values(array_filter($verifications, fn($v)=>(int)($v['expiresAt']??0)>time()));
    $verifications[] = $verification;
    writeJsonFile(VERIFICATION_FILE,$verifications);
    $sent = false;
    if ($method === 'email') {
        if (filter_var($identifier,FILTER_VALIDATE_EMAIL) && MR_NEON_MAIL_FROM !== '') {
            $subject='MR NEON verification code';
            $body="کد تأیید شما: {$code}\nاین کد فقط ۲ دقیقه اعتبار دارد. اگر شما درخواست نداده‌اید، این پیام را نادیده بگیرید.";
            $from=str_replace(["\r","\n"],'',MR_NEON_MAIL_FROM);
            $headers="From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
            $sent=@mail($identifier,$subject,$body,$headers);
        }
    } elseif ($telegramChatId !== null && preg_match('/^-?\\d{5,20}$/',$telegramChatId)) {
        $sent=sendVerificationToTelegram($identifier,$code,$fullName,$type,$telegramChatId);
    } elseif ($type === 'register') {
        $sent=sendVerificationToTelegram($identifier,$code,$fullName,$type);
    }
    if (!$sent) {
        $verifications=readJsonFile(VERIFICATION_FILE);
        $verifications=array_values(array_filter($verifications,fn($v)=>($v['id']??'')!==$verification['id']));
        writeJsonFile(VERIFICATION_FILE,$verifications);
    }
    return ['code_sent'=>$sent,'telegram_sent'=>$method==='telegram'&&$sent,'method'=>$method];
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $input = getInput();
    $identifier = sanitizeString($input['identifier'] ?? '');
    $type = sanitizeString($input['type'] ?? 'register');
    if ($type !== 'register' || $identifier === '') jsonError('Invalid registration challenge', 422);
    $identifier = '@' . ltrim($identifier, '@');
    $pendingRegs = readJsonFile(DATA_DIR . '/pending_registrations.json');
    $allowed = false;
    foreach ($pendingRegs as $pending) {
        if (($pending['telegramId'] ?? '') === $identifier && (int)($pending['expiresAt'] ?? 0) > time()) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) jsonError('Registration challenge not found', 404);
    $result = sendVerificationCode($identifier, '', 'register');
    if (empty($result['code_sent'])) jsonError('Verification delivery unavailable', 503);
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

function handleAdminLogin(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $input = getInput();
    $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    if ($username === '' || $password === '') jsonError('Invalid credentials', 401);
    if (ADMIN_USERNAME === '' || ADMIN_PASSWORD === '') jsonError('Admin login is not configured', 503);
    if (!hash_equals(ADMIN_USERNAME, $username) || !hash_equals(ADMIN_PASSWORD, $password)) jsonError('Invalid credentials', 401);
    $token = createSession('admin', ['username' => $username], 'admin');
    jsonSuccess(['username' => $username, 'token' => $token]);
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $input = getInput();
    $method = ($input['method'] ?? 'telegram') === 'email' ? 'email' : 'telegram';
    $identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
    if ($method === 'email') {
        $identifier = strtolower($identifier);
        if (!filter_var($identifier, FILTER_VALIDATE_EMAIL) || strlen($identifier) > 254) jsonError('Invalid identifier', 422);
    } else {
        $identifier = '@' . ltrim($identifier, '@');
        if (!preg_match('/^@[A-Za-z0-9_]{5,32}$/', $identifier)) jsonError('Invalid identifier', 422);
    }
    $users = readJsonFile(DATA_DIR . '/users.json');
    $found = null;
    foreach ($users as $user) {
        $matches = $method === 'email'
            ? strtolower((string)($user['email'] ?? '')) === $identifier
            : (string)($user['telegramId'] ?? '') === $identifier;
        if ($matches) { $found = $user; break; }
    }
    if ($found) {
        if ($method === 'email') {
            $result = sendVerificationCode($identifier, (string)($found['fullName'] ?? ''), 'password_reset', 'email');
        } else {
            $chatId = (string)($found['telegramChatId'] ?? '');
            $result = sendVerificationCode($identifier, (string)($found['fullName'] ?? ''), 'password_reset', 'telegram', $chatId !== '' ? $chatId : null);
        }
        if (empty($result['code_sent'])) error_log('Password recovery delivery unavailable.');
    }
    jsonSuccess(['message' => 'اگر حسابی با این شناسه و روش بازیابی ثبت شده باشد، کد ارسال خواهد شد.']);
}

function handleVerifyResetCode(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $input = getInput();
    $method = ($input['method'] ?? 'telegram') === 'email' ? 'email' : 'telegram';
    $identifier = is_string($input['identifier'] ?? null) ? trim($input['identifier']) : '';
    $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
    $newPassword = is_string($input['newPassword'] ?? null) ? $input['newPassword'] : '';
    $identifier = $method === 'email' ? strtolower($identifier) : '@' . ltrim($identifier, '@');
    if ($identifier === '' || !preg_match('/^\\d{6}$/', $code) || $newPassword === '') jsonError('Invalid or expired recovery request', 400);
    if (!validatePassword($newPassword)) jsonError('رمز عبور باید حداقل ۸ کاراکتر شامل حروف و عدد باشد', 422);
    if (!verifyCode($identifier, $code, 'password_reset')) jsonError('Invalid or expired recovery request', 400);
    $users = readJsonFile(DATA_DIR . '/users.json');
    $targetIndex = null;
    foreach ($users as $index => $user) {
        $matches = $method === 'email'
            ? strtolower((string)($user['email'] ?? '')) === $identifier
            : (string)($user['telegramId'] ?? '') === $identifier;
        if ($matches) { $targetIndex = $index; break; }
    }
    if ($targetIndex === null) jsonError('Invalid or expired recovery request', 400);
    $targetId = (string)$users[$targetIndex]['id'];
    $users[$targetIndex]['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
    writeJsonFile(DATA_DIR . '/users.json', $users);
    $sessions = readJsonFile(DATA_DIR . '/sessions.json');
    foreach ($sessions as $sid => $session) if ((string)($session['user_id'] ?? '') === $targetId) unset($sessions[$sid]);
    writeJsonFile(DATA_DIR . '/sessions.json', $sessions);
    jsonSuccess(['message' => 'رمز عبور با موفقیت بازنشانی شد. اکنون وارد شوید.']);
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