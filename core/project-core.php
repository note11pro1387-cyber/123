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

function customerProjectView(array $project): array {
    unset($project['adminNote'], $project['changesNotified']);
    $visibleTypes = ['created', 'edited', 'progress', 'status'];
    $history = [];
    foreach (($project['activityLog'] ?? []) as $event) {
        if (!is_array($event) || !in_array(($event['type'] ?? ''), $visibleTypes, true)) continue;
        unset($event['note'], $event['adminNote'], $event['internalNote']);
        $history[] = $event;
    }
    $project['activityLog'] = $history;
    return $project;
}

function handleSaveNeo(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $session = verifyUserSession();
    $userId = (string)($session['user_id'] ?? '');
    if ($userId === '') jsonError('Authentication required', 401);
    $input = getInput();
    if (!$input) jsonError('Empty request body', 400);
    $allowed = ['industry','purpose','projectType','colors','style','features','content','timeline','fullName','phone','email','messenger','messengerId','userNote'];
    if (array_diff(array_keys($input), $allowed)) jsonError('Unsupported project fields', 422);
    foreach (['industry'=>80,'purpose'=>120,'projectType'=>120,'style'=>80,'timeline'=>40,'fullName'=>100,'phone'=>24,'email'=>254,'messenger'=>40,'messengerId'=>100,'userNote'=>3000] as $field=>$max) {
        if (isset($input[$field]) && !is_string($input[$field])) jsonError('Invalid field type', 422);
        if (isset($input[$field]) && mb_strlen(trim($input[$field]), 'UTF-8') > $max) jsonError('Field too long', 422);
    }
    foreach (['industry','purpose','style','timeline','fullName','phone'] as $field) {
        if (trim((string)($input[$field] ?? '')) === '') jsonError('Required field missing', 422);
    }
    if (!validatePhone(trim((string)$input['phone']))) jsonError('Invalid phone number', 422);
    if (!empty($input['email']) && !validateEmail(trim((string)$input['email']))) jsonError('Invalid email', 422);
    foreach (['colors','features','content'] as $field) if (!is_array($input[$field] ?? null)) jsonError('Invalid structured project field', 422);
    if (count($input['features']) > 50 || count($input['colors']) > 20 || count($input['content']) > 20) jsonError('Too many project values', 422);
    foreach ($input['features'] as $feature) if (!is_string($feature) || strlen($feature) > 80) jsonError('Invalid project feature', 422);
    foreach ($input['content'] as $key=>$value) {
        if (!is_string($key) || strlen($key) > 80 || (!is_string($value) && !is_array($value))) jsonError('Invalid project content', 422);
        if (is_string($value) && mb_strlen($value, 'UTF-8') > 5000) jsonError('Project content too long', 422);
    }
    $projectType = trim((string)($input['projectType'] ?? ''));
    if ($projectType === '') $projectType = trim($input['industry']) . '_' . trim($input['purpose']);
    $createdAt = date('Y-m-d H:i:s');
    $project = [
        'id'=>'req_' . bin2hex(random_bytes(16)), 'userId'=>$userId,
        'trackingCode'=>generateTrackingCode(), 'projectType'=>$projectType,
        'industry'=>trim($input['industry']), 'purpose'=>trim($input['purpose']),
        'colors'=>$input['colors'], 'style'=>trim($input['style']), 'features'=>array_values($input['features']),
        'content'=>$input['content'], 'timeline'=>trim($input['timeline']), 'fullName'=>trim($input['fullName']),
        'phone'=>trim($input['phone']), 'email'=>trim((string)($input['email'] ?? '')),
        'messenger'=>trim((string)($input['messenger'] ?? '')), 'messengerId'=>trim((string)($input['messengerId'] ?? '')),
        'userNote'=>trim((string)($input['userNote'] ?? '')), 'status'=>'new', 'progress'=>0, 'adminNote'=>'',
        'createdAt'=>$createdAt, 'persianDate'=>toPersianDate($createdAt), 'persianDateTime'=>toPersianDateTime($createdAt),
        'activityLog'=>[['type'=>'created','timestamp'=>$createdAt,'message'=>'درخواست ثبت شد','persianDateTime'=>toPersianDateTime($createdAt)]],
        'changesNotified'=>false,
    ];
    mutateJsonFile(DATA_DIR . '/requests.json', function (array $requests) use ($project,$userId) {
        $active = 0;
        foreach ($requests as $r) if (($r['userId'] ?? '') === $userId && !in_array(($r['status'] ?? ''), ['completed','rejected'], true)) $active++;
        if ($active >= 3) jsonError('شما ۳ پروژه فعال دارید. لطفاً صبر کنید.', 429);
        $requests[] = $project;
        return array_values($requests);
    });
    try { notifyNewProject($project); } catch (Throwable $e) { error_log('Project notification failed.'); }
    try {
        mutateJsonFile(DATA_DIR . '/notifications.json', function (array $notifs) use ($userId,$project,$createdAt) {
            $notifs[]=['id'=>'notif_'.bin2hex(random_bytes(12)),'userId'=>$userId,'title'=>'پروژه جدید ثبت شد','message'=>'پروژه با کد پیگیری '.$project['trackingCode'].' ثبت شد. تیم ما به‌زودی بررسی می‌کند.','read'=>false,'timestamp'=>$createdAt];
            return array_values($notifs);
        });
    } catch (Throwable $e) { error_log('Project notification persistence failed.'); }
    jsonSuccess(['id'=>$project['id'],'trackingCode'=>$project['trackingCode'],'projectType'=>$projectType,'persianDate'=>$project['persianDate'],'persianDateTime'=>$project['persianDateTime']]);
}

function handleGetProjects(): void {
    $session = verifyUserSession();
    $userId = $session['user_id'];
    
    $requests = readJsonFile(DATA_DIR . '/requests.json');
    $userProjects = array_values(array_map('customerProjectView', array_filter($requests, fn($r) => ($r['userId'] ?? '') === $userId)));
    
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
            jsonSuccess(customerProjectView($r));
        }
    }
    
    jsonError('Project not found', 404);
}

function handleUpdateProject(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['projectId'] ?? $input['id'] ?? null;
    if (!is_string($id) || $id === '') jsonError('Project ID required', 422);
    $allowed = ['userNote','fullName','phone','email','messenger','messengerId'];
    if (array_diff(array_keys($input), array_merge($allowed, ['projectId','id']))) jsonError('Unsupported project fields', 422);
    foreach ($allowed as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) jsonError('Invalid field type', 422);
        if (isset($input[$field]) && mb_strlen(trim($input[$field]), 'UTF-8') > ($field === 'userNote' ? 3000 : 254)) jsonError('Field too long', 422);
    }
    if (isset($input['phone']) && !validatePhone(trim($input['phone']))) jsonError('Invalid phone number', 422);
    if (isset($input['email']) && $input['email'] !== '' && !validateEmail(trim($input['email']))) jsonError('Invalid email', 422);
    $found = false;
    mutateJsonFile(DATA_DIR . '/requests.json', function (array $requests) use ($input,$id,$session,&$found) {
        foreach ($requests as &$project) {
            if (($project['id'] ?? '') !== $id || ($project['userId'] ?? '') !== $session['user_id']) continue;
            foreach (['userNote','fullName','phone','email','messenger','messengerId'] as $field) {
                if (array_key_exists($field, $input)) $project[$field] = trim($input[$field]);
            }
            $now = date('Y-m-d H:i:s');
            $project['activityLog'] = is_array($project['activityLog'] ?? null) ? $project['activityLog'] : [];
            $project['activityLog'][] = ['type'=>'edited','timestamp'=>$now,'message'=>'ویرایش توسط کاربر','persianDateTime'=>toPersianDateTime($now)];
            $found = true;
            break;
        }
        unset($project);
        return array_values($requests);
    });
    if (!$found) jsonError('Project not found', 404);
    jsonSuccess(['updated'=>true]);
}

function handleDeleteProject(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['projectId'] ?? $input['id'] ?? null;
    if (!is_string($id) || $id === '') jsonError('Project ID required', 422);
    $deleted = false;
    mutateJsonFile(DATA_DIR . '/requests.json', function (array $requests) use ($id,$session,&$deleted) {
        $next = [];
        foreach ($requests as $project) {
            if (($project['id'] ?? '') === $id && ($project['userId'] ?? '') === $session['user_id']) { $deleted = true; continue; }
            $next[] = $project;
        }
        return $next;
    });
    if (!$deleted) jsonError('Project not found', 404);
    jsonSuccess(['deleted'=>true]);
}

function handleAdminUpdate(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    verifyAdminAuth();
    $input=getInput(); $id=$input['id']??null; $field=$input['field']??null; $value=$input['value']??null;
    if (!is_string($id)||$id===''||!is_string($field)) jsonError('ID and field required',422);
    if (!in_array($field,['progress','adminNote','status'],true)) jsonError('Field not allowed',422);
    if ($field==='progress') { if (!is_numeric($value)||(int)$value<0||(int)$value>100) jsonError('Invalid progress',422); $value=(int)$value; }
    elseif (!is_string($value)||mb_strlen($value,'UTF-8')>3000) jsonError('Invalid field value',422);
    $labels=['new'=>'در انتظار بررسی','read'=>'خوانده‌شده','replied'=>'پاسخ‌داده‌شده','in_progress'=>'در حال انجام','completed'=>'تکمیل شده','rejected'=>'رد شده'];
    if ($field==='status'&&!isset($labels[$value])) jsonError('Invalid status',422);
    $changed=null;
    mutateJsonFile(DATA_DIR.'/requests.json',function(array $requests)use($id,$field,$value,$labels,&$changed){
        foreach($requests as &$r){
            if(($r['id']??'')!==$id)continue;
            $r[$field]=$value; $now=date('Y-m-d H:i:s');
            if(in_array($field,['progress','status'],true)){
                $message=$field==='progress'?"پیشرفت به {$value}٪ تغییر کرد":"وضعیت به «{$labels[$value]}» تغییر کرد";
                $r['activityLog']=is_array($r['activityLog']??null)?$r['activityLog']:[];
                $r['activityLog'][]=['type'=>$field,'timestamp'=>$now,'message'=>$message,'persianDateTime'=>toPersianDateTime($now)];
            }
            $changed=['userId'=>$r['userId']??null,'trackingCode'=>$r['trackingCode']??'','field'=>$field,'value'=>$value,'timestamp'=>$now]; break;
        } unset($r); return array_values($requests);
    });
    if($changed===null)jsonError('Project not found',404);
    if(!empty($changed['userId'])&&in_array($field,['progress','status'],true)){
        try{mutateJsonFile(DATA_DIR.'/notifications.json',function(array $notifs)use($changed,$labels){
            $v=$changed['value'];$title=$changed['field']==='progress'?'پیشرفت پروژه به‌روزرسانی شد':'تغییر وضعیت پروژه';
            $message=$changed['field']==='progress'?"پروژه «{$changed['trackingCode']}» به {$v}٪ رسید":"وضعیت پروژه شما به «{$labels[$v]}» تغییر کرد";
            $notifs[]=['id'=>'notif_'.bin2hex(random_bytes(12)),'userId'=>$changed['userId'],'title'=>$title,'message'=>$message,'read'=>false,'timestamp'=>$changed['timestamp']];
            return array_values($notifs);
        });}catch(Throwable $e){error_log('Project notification persistence failed.');}
    }
    jsonSuccess(['updated'=>true]);
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
    $safeUsers = array_map(static fn($u) => array_intersect_key($u, array_flip(['id','fullName','username','phone','email','telegramId','verified','createdAt','lastLogin'])), $users);
    jsonSuccess(['users' => $safeUsers, 'count' => count($safeUsers)]);
}

function handleGetNotifications(): void {
    $session = verifyUserSession();
    $userId = $session['user_id'];
    
    $notifs = readJsonFile(DATA_DIR . '/notifications.json');
    $userNotifs = array_values(array_filter($notifs, fn($n) => ($n['userId'] ?? '') === $userId && ($n['title'] ?? '') !== 'یادداشت جدید از ادمین'));
    usort($userNotifs, fn($a, $b) => strtotime($b['timestamp'] ?? '') <=> strtotime($a['timestamp'] ?? ''));
    
    foreach ($userNotifs as &$n) {
        $n['persianDate'] = toPersianDate($n['timestamp'] ?? '');
        $n['persianDateTime'] = toPersianDateTime($n['timestamp'] ?? '');
    }
    
    jsonSuccess(['notifications' => $userNotifs]);
}

function handleMarkNotificationRead(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    $session = verifyUserSession();
    $input = getInput();
    $id = $input['id'] ?? null;
    if (!is_string($id) || $id === '') jsonError('Notification ID required', 422);
    $found = false;
    mutateJsonFile(DATA_DIR . '/notifications.json', function (array $notifs) use ($id,$session,&$found) {
        foreach ($notifs as &$notification) {
            if (($notification['id'] ?? '') === $id && ($notification['userId'] ?? '') === $session['user_id']) {
                $notification['read'] = true;
                $found = true;
                break;
            }
        }
        unset($notification);
        return array_values($notifs);
    });
    if (!$found) jsonError('Notification not found', 404);
    jsonSuccess(['updated'=>true]);
}

function handleGetActiveProjectsCount(): void {
    $session = verifyUserSession();
    jsonSuccess(['count' => countActiveProjectsByUser($session['user_id'])]);
}

function handleDeleteRequest(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') jsonError('Method not allowed', 405);
    verifyAdminAuth();
    $input = getInput();
    $id = $input['id'] ?? null;
    if (!is_string($id) || $id === '') jsonError('ID required', 422);
    $deleted = false;
    mutateJsonFile(DATA_DIR . '/requests.json', function (array $requests) use ($id,&$deleted) {
        $next = [];
        foreach ($requests as $project) {
            if (($project['id'] ?? '') === $id) { $deleted = true; continue; }
            $next[] = $project;
        }
        return $next;
    });
    if (!$deleted) jsonError('Request not found', 404);
    jsonSuccess(['deleted'=>true]);
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