<?php
/**
 * 🧬 MR NEON ARCHITECTURE COMPONENT
 * 📄 File: core/project-core.php
 * 🎯 Responsibility: مدیریت کامل پروژه‌ها، ذخیره، بازیابی، به‌روزرسانی، حذف، اعلان‌ها و Labelهای کمکی
 * 🔌 Dependencies: config.php, core/notifier.php (notifyNewProject)
 * 🔌 Dependants: api.php (از طریق Router)
 * 🚫 Constraints: منطق احراز هویت باید در api.php یا auth-core.php انجام شود. این فایل فقط منطق پروژه را انجام می‌دهد.
 * 📦 Exported: handleSaveNeo, handleGetProjects, handleGetProjectById, handleUpdateProject, handleDeleteProject, handleAdminUpdate, handleGetAll, handleGetAllUsers, handleGetNotifications, handleMarkNotificationRead, handleGetActiveProjectsCount, handleDeleteRequest, countActiveProjectsByUser, Helper labels
 * 🧠 AI NOTE: Handlerهای Admin در این فایل verifyAdminAuth را صدا می‌زنند که در api.php است.
 */

function countActiveProjectsByUser(string $userId): int {
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $count = 0;
    foreach ($requests as $r) {
        if (($r['userId'] ?? '') === $userId && !in_array($r['status'] ?? '', ['completed', 'rejected'])) {
            $count++;
        }
    }
    return $count;
}

function handleSaveNeo(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);
    
    $input = getInput();
    if (empty($input)) jsonError('Empty request body', 400);
    
    $userId = null;
    $token = getBearerToken();
    if ($token) {
        try {
            $session = verifyUserSession();
            $userId = $session['user_id'];
            $activeCount = countActiveProjectsByUser($userId);
            if ($activeCount >= 3) jsonError('شما ۳ پروژه فعال دارید. لطفاً صبر کنید.', 429);
        } catch (Throwable $e) {
            $userId = null;
        }
    }
    
    $industry = sanitizeString($input['industry'] ?? '');
    $purpose = sanitizeString($input['purpose'] ?? '');
    $projectType = sanitizeString($input['projectType'] ?? '');
    
    if (empty($projectType) && !empty($industry) && !empty($purpose)) {
        $projectType = $industry . '_' . $purpose;
    }
    if (empty($projectType)) $projectType = 'custom';
    
    $trackingCode = $input['trackingCode'] ?? generateTrackingCode();
    $createdAt = date('Y-m-d H:i:s');
    
    $project = [
        'id' => uniqid('req_'),
        'userId' => $userId,
        'trackingCode' => $trackingCode,
        'projectType' => $projectType,
        'industry' => $industry,
        'purpose' => $purpose,
        'colors' => $input['colors'] ?? [],
        'style' => sanitizeString($input['style'] ?? ''),
        'features' => $input['features'] ?? [],
        'content' => $input['content'] ?? [],
        'timeline' => sanitizeString($input['timeline'] ?? ''),
        'fullName' => sanitizeString($input['fullName'] ?? ''),
        'phone' => sanitizeString($input['phone'] ?? ''),
        'email' => sanitizeString($input['email'] ?? ''),
        'messenger' => sanitizeString($input['messenger'] ?? ''),
        'messengerId' => sanitizeString($input['messengerId'] ?? ''),
        'userNote' => sanitizeString($input['userNote'] ?? ''),
        'status' => 'new',
        'progress' => 0,
        'adminNote' => '',
        'createdAt' => $createdAt,
        'persianDate' => toPersianDate($createdAt),
        'persianDateTime' => toPersianDateTime($createdAt),
        'activityLog' => [
            ['type' => 'created', 'timestamp' => $createdAt, 'message' => 'درخواست ثبت شد', 'persianDateTime' => toPersianDateTime($createdAt)]
        ],
        'changesNotified' => false,
    ];
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $requests[] = $project;
    writeJsonFile(DATA_DIR . '/requests.json', $requests);
    
    notifyNewProject($project);
    
    if ($userId) {
        $notifs = readJsonFile(DATA_DIR . '/notifications.json');
        $notifs[] = [
            'id' => uniqid('notif_'),
            'userId' => $userId,
            'title' => 'پروژه جدید ثبت شد',
            'message' => "پروژه با کد پیگیری $trackingCode ثبت شد. تیم ما به زودی بررسی می‌کند.",
            'read' => false,
            'timestamp' => $createdAt,
        ];
        writeJsonFile(DATA_DIR . '/notifications.json', $notifs);
    }
    
    jsonSuccess([
        'trackingCode' => $trackingCode,
        'projectType' => $projectType,
        'persianDate' => toPersianDate($createdAt),
        'persianDateTime' => toPersianDateTime($createdAt),
    ]);
}

function handleGetProjects(): void {
    $session = verifyUserSession();
    $userId = $session['user_id'];
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $userProjects = array_values(array_filter($requests, fn($r) => ($r['userId'] ?? '') === $userId));
    
    usort($userProjects, fn($a, $b) => strtotime($b['createdAt'] ?? '') <=> strtotime($a['createdAt'] ?? ''));
    
    foreach ($userProjects as &$project) {
        $project['persianDate'] = toPersianDate($project['createdAt'] ?? '');
        $project['persianDateTime'] = toPersianDateTime($project['createdAt'] ?? '');
    }
    
    jsonSuccess(['projects' => $userProjects]);
}

function handleGetProjectById(): void {
    $session = verifyUserSession();
    $id = $_GET['id'] ?? '';
    if (!$id) jsonError('ID required', 422);
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    foreach ($requests as $r) {
        if ($r['id'] === $id && ($r['userId'] ?? '') === $session['user_id']) {
            $r['persianDate'] = toPersianDate($r['createdAt'] ?? '');
            $r['persianDateTime'] = toPersianDateTime($r['createdAt'] ?? '');
            jsonSuccess($r);
        }
    }
    
    jsonError('Project not found', 404);
}

function handleUpdateProject(): void {
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['projectId'] ?? $input['id'] ?? null;
    if (!$id) jsonError('Project ID required', 422);
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $found = false;
    
    foreach ($requests as &$r) {
        if ($r['id'] === $id && ($r['userId'] ?? '') === $session['user_id']) {
            $allowed = ['userNote', 'fullName', 'phone', 'email', 'messenger', 'messengerId'];
            foreach ($allowed as $f) {
                if (isset($input[$f])) $r[$f] = sanitizeString($input[$f]);
            }
            $now = date('Y-m-d H:i:s');
            $r['activityLog'][] = [
                'type' => 'edited',
                'timestamp' => $now,
                'message' => 'ویرایش توسط کاربر',
                'persianDateTime' => toPersianDateTime($now)
            ];
            $found = true;
            break;
        }
    }
    
    if (!$found) jsonError('Project not found', 404);
    writeJsonFile(DATA_DIR . '/requests.json', $requests);
    jsonSuccess();
}

function handleDeleteProject(): void {
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['projectId'] ?? $input['id'] ?? null;
    if (!$id) jsonError('Project ID required', 422);
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $filtered = array_filter($requests, fn($r) => !(($r['id'] ?? '') === $id && ($r['userId'] ?? '') === $session['user_id']));
    
    if (count($filtered) === count($requests)) jsonError('Project not found', 404);
    writeJsonFile(DATA_DIR . '/requests.json', array_values($filtered));
    jsonSuccess();
}

function handleAdminUpdate(): void {
    verifyAdminAuth();
    $input = getInput();
    $id = $input['id'] ?? null;
    $field = $input['field'] ?? null;
    $value = $input['value'] ?? null;
    
    if (!$id || !$field) jsonError('ID and field required', 422);
    
    $allowed = ['progress', 'adminNote', 'status'];
    if (!in_array($field, $allowed)) jsonError('Field not allowed', 422);
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $found = false;
    
    foreach ($requests as &$r) {
        if ($r['id'] === $id) {
            $r[$field] = ($field === 'progress') ? (int)$value : sanitizeString($value);
            $now = date('Y-m-d H:i:s');
            
            if ($field === 'progress') {
                $r['activityLog'][] = [
                    'type' => 'progress', 'timestamp' => $now,
                    'message' => "پیشرفت به {$value}٪ تغییر کرد",
                    'persianDateTime' => toPersianDateTime($now)
                ];
                
                if (!empty($r['userId'])) {
                    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
                    $notifs[] = [
                        'id' => uniqid('notif_'),
                        'userId' => $r['userId'],
                        'title' => 'پیشرفت پروژه',
                        'message' => "پروژه «{$r['trackingCode']}» به {$value}٪ رسید",
                        'read' => false,
                        'timestamp' => $now,
                    ];
                    writeJsonFile(DATA_DIR . '/notifications.json', $notifs);
                }
            } elseif ($field === 'adminNote') {
                $r['activityLog'][] = [
                    'type' => 'admin_note', 'timestamp' => $now,
                    'message' => 'یادداشت جدید',
                    'note' => sanitizeString($value),
                    'persianDateTime' => toPersianDateTime($now)
                ];
                
                if (!empty($r['userId'])) {
                    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
                    $notifs[] = [
                        'id' => uniqid('notif_'),
                        'userId' => $r['userId'],
                        'title' => 'یادداشت جدید از ادمین',
                        'message' => sanitizeString($value),
                        'read' => false,
                        'timestamp' => $now,
                    ];
                    writeJsonFile(DATA_DIR . '/notifications.json', $notifs);
                }
            } elseif ($field === 'status') {
                $statusLabels = [
                    'new' => 'در انتظار بررسی', 'read' => 'خوانده‌شده',
                    'replied' => 'پاسخ‌داده‌شده', 'in_progress' => 'در حال انجام',
                    'completed' => 'تکمیل شده', 'rejected' => 'رد شده'
                ];
                $label = $statusLabels[$value] ?? $value;
                $r['activityLog'][] = [
                    'type' => 'status', 'timestamp' => $now,
                    'message' => "وضعیت به «$label» تغییر کرد",
                    'persianDateTime' => toPersianDateTime($now)
                ];
                
                if (!empty($r['userId'])) {
                    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
                    $notifs[] = [
                        'id' => uniqid('notif_'),
                        'userId' => $r['userId'],
                        'title' => 'تغییر وضعیت پروژه',
                        'message' => "پروژه شما به وضعیت «$label» تغییر کرد",
                        'read' => false,
                        'timestamp' => $now,
                    ];
                    writeJsonFile(DATA_DIR . '/notifications.json', $notifs);
                }
            }
            
            $found = true;
            break;
        }
    }
    
    if (!$found) jsonError('Project not found', 404);
    writeJsonFile(DATA_DIR . '/requests.json', $requests);
    jsonSuccess();
}

function handleGetAll(): void {
    verifyAdminAuth();
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    usort($requests, fn($a, $b) => strtotime($b['createdAt'] ?? '') <=> strtotime($a['createdAt'] ?? ''));
    
    foreach ($requests as &$r) {
        $r['persianDate'] = toPersianDate($r['createdAt'] ?? '');
        $r['persianDateTime'] = toPersianDateTime($r['createdAt'] ?? '');
        $r['projectTypeLabel'] = getProjectTypeLabel($r['projectType'] ?? '');
        $r['industryLabel'] = getIndustryLabel($r['industry'] ?? '');
        $r['purposeLabel'] = getPurposeLabel($r['purpose'] ?? '');
        $r['styleLabel'] = getStyleLabel($r['style'] ?? '');
        $r['timelineLabel'] = getTimelineLabel($r['timeline'] ?? '');
        $r['featuresLabels'] = array_map(fn($f) => getFeatureLabel($f), $r['features'] ?? []);
    }
    
    jsonSuccess($requests);
}

function handleGetAllUsers(): void {
    verifyAdminAuth();
    $users = readJsonFile(DATA_DIR . '/users.json');
    foreach ($users as &$user) {
        $user['persianDate'] = toPersianDate($user['createdAt'] ?? '');
    }
    jsonSuccess(['users' => $users, 'count' => count($users)]);
}

function handleGetNotifications(): void {
    $session = verifyUserSession();
    $userId = $session['user_id'];
    
    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
    $userNotifs = array_values(array_filter($notifs, fn($n) => ($n['userId'] ?? '') === $userId));
    usort($userNotifs, fn($a, $b) => strtotime($b['timestamp'] ?? '') <=> strtotime($a['timestamp'] ?? ''));
    
    foreach ($userNotifs as &$n) {
        $n['persianDate'] = toPersianDate($n['timestamp'] ?? '');
        $n['persianDateTime'] = toPersianDateTime($n['timestamp'] ?? '');
    }
    
    jsonSuccess(['notifications' => $userNotifs]);
}

function handleMarkNotificationRead(): void {
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['id'] ?? null;
    if (!$id) jsonError('Notification ID required', 422);
    
    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
    foreach ($notifs as &$n) {
        if ($n['id'] === $id && ($n['userId'] ?? '') === $session['user_id']) {
            $n['read'] = true;
            break;
        }
    }
    
    writeJsonFile(DATA_DIR . '/notifications.json', $notifs);
    jsonSuccess();
}

function handleGetActiveProjectsCount(): void {
    $session = verifyUserSession();
    jsonSuccess(['count' => countActiveProjectsByUser($session['user_id'])]);
}

function handleDeleteRequest(): void {
    verifyAdminAuth();
    $input = getInput();
    $id = $input['id'] ?? null;
    if (!$id) jsonError('ID required', 422);
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $filtered = array_filter($requests, fn($r) => ($r['id'] ?? '') !== $id);
    
    if (count($filtered) === count($requests)) jsonError('Request not found', 404);
    writeJsonFile(DATA_DIR . '/requests.json', array_values($filtered));
    jsonSuccess();
}

// ====================== 12. HELPER LABELS ======================
function getProjectTypeLabel(string $type): string {
    $labels = [
        'ecommerce' => 'فروشگاهی', 'corporate' => 'شرکتی', 'webapp' => 'وب‌اپ',
        'portfolio' => 'نمونه‌کار', 'personal' => 'شخصی',
    ];
    foreach ($labels as $key => $label) {
        if (str_contains($type, $key)) return $label;
    }
    return $type ?: 'نامشخص';
}

function getIndustryLabel(string $industry): string {
    $labels = [
        'fashion' => '👗 مد و پوشاک', 'tech' => '💻 فناوری', 'food' => '🍕 رستوران',
        'health' => '🏥 پزشکی', 'education' => '🎓 آموزش', 'realestate' => '🏠 املاک',
        'travel' => '✈️ گردشگری', 'art' => '🎨 هنر', 'sport' => '⚽ ورزش', 'other' => '📦 سایر',
    ];
    return $labels[$industry] ?? $industry;
}

function getPurposeLabel(string $purpose): string {
    $labels = [
        'online_shop' => 'فروشگاه آنلاین', 'catalog' => 'نمایش کالکشن', 'booking' => 'رزرو',
        'company_site' => 'معرفی شرکت', 'saas' => 'SaaS', 'portfolio' => 'پورتفولیو',
        'blog' => 'وبلاگ', 'menu_order' => 'منوی آنلاین', 'reservation' => 'رزرو میز',
        'clinic_site' => 'سایت کلینیک', 'lms' => 'LMS', 'school_site' => 'سایت مدرسه',
        'listing' => 'لیست املاک', 'agency_site' => 'سایت مشاور', 'guide' => 'راهنما',
        'shop' => 'فروشگاه', 'club_site' => 'سایت باشگاه', 'landing' => 'صفحه فرود',
        'custom' => 'سفارشی',
    ];
    return $labels[$purpose] ?? $purpose;
}

function getStyleLabel(string $style): string {
    $labels = [
        'modern' => 'مدرن', 'classic' => 'کلاسیک', 'creative' => 'خلاقانه',
        'luxury' => 'لوکس', 'industrial' => 'صنعتی', 'cyberpunk' => 'سایبرپانک',
        'neumorphism' => 'نئومورفیسم', 'glassmorphism' => 'گلس‌مورفیسم', 'retro' => 'رترو',
        'brutalist' => 'بروتالیست', 'organic' => 'ارگانیک', '3d' => 'سه‌بعدی',
        'flat' => 'فلت', 'material' => 'متریال', 'dark' => 'دارک', 'light' => 'لایت',
        'gradient' => 'گرادیانت', 'monochrome' => 'مونوکروم',
    ];
    return $labels[$style] ?? $style;
}

function getTimelineLabel(string $timeline): string {
    $labels = [
        'urgent' => '⚡ فوری (۷۲ ساعت)', 'normal' => '📅 عادی (۱-۲ هفته)',
        'flexible' => '📆 منعطف (۱ ماه)', 'agreement' => '🤝 توافقی',
    ];
    return $labels[$timeline] ?? $timeline;
}

function getFeatureLabel(string $feature): string {
    $labels = [
        'payment' => '💳 درگاه پرداخت', 'membership' => '👤 عضویت',
        'admin_panel' => '🎛️ پنل مدیریت', 'live_chat' => '💬 چت آنلاین',
        'booking' => '📅 رزرو نوبت', 'pwa' => '📱 PWA', 'maps' => '🗺️ نقشه',
        'gallery' => '🖼️ گالری', 'blog' => '📝 وبلاگ', 'multilingual' => '🌍 چندزبانه',
        'social' => '🔗 شبکه‌های اجتماعی', 'seo' => '📈 سئو', 'comments' => '💭 نظرات',
        'sms_auth' => '📲 احراز هویت پیامکی', 'mobile_app' => '📱 اپ موبایل',
        'push' => '🔔 Push', 'video' => '🎥 ویدیو', 'cart' => '🛒 سبد خرید',
        'dashboard' => '📊 داشبورد', 'ai' => '🤖 AI',
    ];
    return $labels[$feature] ?? $feature;
}