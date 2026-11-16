<?php
declare(strict_types=1);

/** Admin-only project operations backed by the canonical DB model. */
final class AdminProjectController
{
    private const TRANSITIONS = [
        'submitted' => ['under_review', 'needs_information', 'rejected', 'cancelled'],
        'under_review' => ['needs_information', 'in_progress', 'waiting_for_client', 'rejected', 'cancelled'],
        'needs_information' => ['under_review', 'in_progress', 'waiting_for_client', 'rejected', 'cancelled'],
        'in_progress' => ['needs_information', 'waiting_for_client', 'completed', 'rejected', 'cancelled'],
        'waiting_for_client' => ['needs_information', 'in_progress', 'completed', 'rejected', 'cancelled'],
        'completed' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    private const STATUS_LABELS = [
        'submitted' => 'ثبت‌شده',
        'under_review' => 'در حال بررسی',
        'needs_information' => 'نیازمند اطلاعات',
        'in_progress' => 'در حال انجام',
        'waiting_for_client' => 'در انتظار مشتری',
        'completed' => 'تکمیل‌شده',
        'rejected' => 'ردشده',
        'cancelled' => 'لغوشده',
    ];

    private const ACCOUNT_STATUS_LABELS = [
        'active' => 'فعال', 'suspended' => 'تعلیق‌شده', 'pending' => 'در انتظار تأیید', 'closed' => 'بسته‌شده',
    ];

    public function dispatch(string $action): never
    {
        try {
            $principal = AdminAuthMiddleware::requireAdmin();
            if (in_array($action, ['adminUpdate', 'deleteRequest', 'adminUpdateUser', 'adminSendMessage', 'markAdminMessagesRead', 'markAdminNotificationRead'], true)) {
                CsrfMiddleware::assertMutationAllowed();
            }
            $adminId = $principal['principalId'] ?? $principal['admin']['username'] ?? null;
            if (!is_string($adminId) || trim($adminId) === '' || strlen($adminId) > 128) {
                throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست مدیر معتبر نیست؛ دوباره وارد شوید.');
            }
            $pdo = Database::connection();
            RateLimitMiddleware::enforceAdminAction($pdo, $action, ApiRequest::clientIp(), $adminId);

            switch ($action) {
                case 'exportProjectsCsv':
                    $search = self::queryText('q', 100);
                    $ownerFilter = self::queryText('user', 128);
                    $date = self::queryDate('date');
                    $status = self::queryFilter('status', ProjectRepository::statuses());
                    $projectType = self::queryType();
                    $sort = self::queryEnum('sort', ['created_at', 'updated_at', 'tracking_code', 'status', 'owner_name', 'progress'], 'created_at');
                    $direction = self::queryEnum('direction', ['asc', 'desc'], 'desc');
                    $repository = new ProjectRepository($pdo);
                    $rows = $repository->listForAdminExport($status, $projectType, $search, 5001, $ownerFilter === '' ? null : $ownerFilter, $date, $sort, $direction);
                    if (count($rows) > 5000) {
                        throw new ApiProblemException(422, 'EXPORT_RESULT_TOO_LARGE', 'Unprocessable content', 'خروجی بیش از ۵۰۰۰ پروژه است؛ فیلترهای جست‌وجو را محدودتر کنید.');
                    }
                    if ($rows === []) {
                        throw new ApiProblemException(422, 'EXPORT_EMPTY_RESULT', 'Unprocessable content', 'برای فیلترهای فعلی پروژه‌ای برای خروجی وجود ندارد.');
                    }
                    $csv = self::buildProjectsCsv($rows);
                    (new AuditLogRepository($pdo))->append(
                        'admin', $adminId, 'admin.projects_export_generated', 'project', 'filtered_dataset',
                        ApiResponse::requestId(), null, null,
                        ['row_count' => count($rows), 'status_filter' => $status ?? 'all', 'project_type_filter' => $projectType ?? 'all', 'owner_filter_applied' => $ownerFilter !== '', 'date_filter_applied' => $date !== null, 'sort' => $sort, 'direction' => $direction]
                    );
                    self::sendProjectsCsv($csv);

                case 'getAll':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 10, 1, 100);
                    $search = self::queryText('q', 100);
                    $ownerFilter = self::queryText('user', 128);
                    $date = self::queryDate('date');
                    $status = self::queryFilter('status', ProjectRepository::statuses());
                    $projectType = self::queryType();
                    $sort = self::queryEnum('sort', ['created_at', 'updated_at', 'tracking_code', 'status', 'owner_name', 'progress'], 'created_at');
                    $direction = self::queryEnum('direction', ['asc', 'desc'], 'desc');
                    $repository = new ProjectRepository($pdo);
                    $result = $repository->listForAdmin($page, $perPage, $status, $projectType, $search, $sort, $direction, $ownerFilter === '' ? null : $ownerFilter, $date);
                    $items = array_map(self::projectListDTO(...), $result['items']);
                    ApiResponse::success([
                        'projects' => $items,
                        'pagination' => self::pagination($result),
                    ]);

                case 'getAdminNotifications':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 25, 1, 50);
                    $repository = new NotificationRepository($pdo);
                    $result = $repository->listForAdminInbox(self::requireAdminInboxId(), $page, $perPage);
                    $items = [];
                    foreach ($result['items'] as $notification) {
                        $items[] = [
                            'id' => (string) $notification['public_id'],
                            'title' => (string) $notification['title'],
                            'message' => (string) $notification['body'],
                            'timestamp' => self::isoUtc($notification['created_at'] ?? null),
                            'read' => is_string($notification['read_at'] ?? null) && $notification['read_at'] !== '',
                            'category' => (string) $notification['category'],
                        ];
                    }
                    ApiResponse::success([
                        'notifications' => $items,
                        'pagination' => self::pagination($result),
                        'unreadCount' => $repository->countUnreadForAdminInbox(self::requireAdminInboxId()),
                    ]);

                case 'markAdminNotificationRead':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertKeys($body, ['id']);
                    $publicId = $body['id'] ?? null;
                    if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(422, 'INVALID_NOTIFICATION_REFERENCE', 'Unprocessable content', 'شناسهٔ اعلان معتبر نیست.');
                    }
                    $notifications = new NotificationRepository($pdo);
                    if (!$notifications->markReadForAdminInbox($publicId, self::requireAdminInboxId())) {
                        throw new ApiProblemException(404, 'NOTIFICATION_NOT_FOUND', 'Not found', 'اعلان پیدا نشد یا دسترسی به آن مجاز نیست.');
                    }
                    ApiResponse::success([
                        'id' => $publicId,
                        'read' => true,
                        'unreadCount' => $notifications->countUnreadForAdminInbox(self::requireAdminInboxId()),
                    ]);

                case 'getAdminMessages':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 25, 1, 50);
                    $search = self::queryText('q', 100);
                    $sender = self::queryEnum('sender', ['all', 'user', 'admin', 'system'], 'all');
                    $read = self::queryEnum('read', ['all', 'unread', 'read'], 'all');
                    $result = (new ProjectMessageRepository($pdo))->listForAdminInbox($page, $perPage, $search, $sender, $read);
                    $messages = array_map(static function (array $message): array {
                        $senderType = (string) ($message['sender_type'] ?? 'system');
                        $status = (string) ($message['project_status'] ?? '');
                        return [
                            // Internal project ID is supplied only to this authenticated admin DTO
                            // so the existing project-detail endpoint can authorize and load the record.
                            'projectId' => max(0, (int) ($message['project_id'] ?? 0)),
                            'trackingCode' => (string) ($message['tracking_code'] ?? ''),
                            'projectTitle' => (string) ($message['project_title'] ?? ''),
                            'projectStatus' => $status,
                            'projectArchived' => !empty($message['archived_at']),
                            'ownerName' => (string) ($message['owner_full_name'] ?? ''),
                            'ownerUsername' => (string) ($message['owner_username'] ?? ''),
                            'senderType' => in_array($senderType, ['user', 'admin', 'system'], true) ? $senderType : 'system',
                            'body' => (string) ($message['body_preview'] ?? ''),
                            'bodyTruncated' => (int) ($message['body_truncated'] ?? 0) === 1,
                            'createdAt' => self::isoUtc($message['created_at'] ?? null),
                            'read' => is_string($message['read_at'] ?? null) && $message['read_at'] !== '',
                        ];
                    }, $result['items']);
                    ApiResponse::success([
                        'messages' => $messages,
                        'pagination' => self::pagination($result),
                        'unreadCustomerCount' => max(0, (int) ($result['unread_customer_count'] ?? 0)),
                    ]);

                case 'getAdminSettingsSummary':
                    $environmentValue = getenv('MRNEON_ENV');
                    $environmentRaw = is_string($environmentValue) ? strtolower(trim($environmentValue)) : '';
                    $environment = $environmentRaw === ''
                        ? 'local_default'
                        : (in_array($environmentRaw, ['local', 'staging', 'production'], true) ? $environmentRaw : 'unrecognized');
                    $publicBaseUrl = defined('PUBLIC_BASE_URL') && is_string(PUBLIC_BASE_URL) ? PUBLIC_BASE_URL : '';
                    $smtpConfiguration = SmtpConfiguration::fromEnvironment();
                    $telegramClient = new TelegramBotApiClient();
                    $adminUsernameValue = getenv('MRNEON_ADMIN_USERNAME');
                    $adminUsername = is_string($adminUsernameValue) ? trim($adminUsernameValue) : '';
                    $adminPasswordHashValue = getenv('MRNEON_ADMIN_PASSWORD_HASH');
                    $adminPasswordHash = is_string($adminPasswordHashValue) ? $adminPasswordHashValue : '';
                    $passwordHashInfo = password_get_info($adminPasswordHash);
                    $totpSecretValue = getenv('MRNEON_ADMIN_TOTP_SECRET');
                    $totpSecret = is_string($totpSecretValue) ? $totpSecretValue : '';
                    $otpHmacKey = getenv('MRNEON_OTP_HMAC_KEY');
                    $termsVersionValue = getenv('MRNEON_TERMS_VERSION');
                    $termsVersion = is_string($termsVersionValue) ? trim($termsVersionValue) : '';
                    $termsVersionValid = $termsVersion !== ''
                        && strlen($termsVersion) <= 64
                        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $termsVersion) === 1;

                    // This action reaches the switch only after Database::connection() and the
                    // normal admin authorization/rate-limit chain have succeeded for this request.
                    // Status flags below reveal configuration presence/shape, never values/secrets.
                    ApiResponse::success([
                        'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                        'environment' => $environment,
                        'databaseConnectionForRequest' => 'established',
                        'publicBaseUrlConfigured' => $publicBaseUrl !== '',
                        'emailConfigurationComplete' => $smtpConfiguration instanceof SmtpConfiguration,
                        'telegramBotTokenFormatValid' => $telegramClient->isConfigured(),
                        'telegramAdminDestinationPresent' => defined('TELEGRAM_ADMIN_CHAT_ID')
                            && trim((string) TELEGRAM_ADMIN_CHAT_ID) !== '',
                        'telegramNotifierBotPresent' => defined('TELEGRAM_NOTIFIER_BOT')
                            && trim((string) TELEGRAM_NOTIFIER_BOT) !== '',
                        'telegramUserLinkPrerequisitesPresent' => TelegramLinkConfiguration::publicWebhookUrl() !== null,
                        'adminUsernamePresent' => $adminUsername !== '',
                        'adminPasswordHashRecognized' => $adminPasswordHash !== ''
                            && (($passwordHashInfo['algoName'] ?? 'unknown') !== 'unknown'),
                        'adminTotpSecretPresent' => $totpSecret !== '',
                        'otpHmacKeyMinimumLengthMet' => is_string($otpHmacKey) && strlen($otpHmacKey) >= 32,
                        'termsVersionValid' => $termsVersionValid,
                        // SMS is intentionally unavailable under the approved product contract.
                        'smsEnabled' => false,
                    ]);

                case 'getAdminAuditLog':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 25, 1, 50);
                    $actorType = self::queryEnum('actor', ['all', 'user', 'admin', 'system', 'anonymous'], 'all');
                    $subjectType = self::queryEnum('subject', ['all', 'user', 'project', 'registration_attempt', 'none', 'other'], 'all');
                    $search = self::queryText('q', 80);
                    $result = (new AuditLogRepository($pdo))->listForAdminTimeline($page, $perPage, $actorType, $subjectType, $search);
                    $events = array_map(static function (array $event): array {
                        $actor = (string) ($event['actor_type'] ?? 'anonymous');
                        $actionName = (string) ($event['action'] ?? '');
                        $subject = $event['subject_type'] ?? null;
                        return [
                            'actorType' => in_array($actor, ['user', 'admin', 'system', 'anonymous'], true) ? $actor : 'anonymous',
                            'action' => preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $actionName) ? $actionName : 'unknown_event',
                            'subjectType' => is_string($subject) && in_array($subject, ['user', 'project', 'registration_attempt'], true)
                                ? $subject
                                : (is_string($subject) && $subject !== '' ? 'other' : 'none'),
                            'createdAt' => self::isoUtc($event['created_at'] ?? null),
                        ];
                    }, $result['items']);
                    ApiResponse::success([
                        'events' => $events,
                        'pagination' => self::pagination($result),
                    ]);

                case 'getAdminStats':
                    $projectStats = (new ProjectRepository($pdo))->adminStats();
                    $userStats = (new UserRepository($pdo))->adminCounts();
                    ApiResponse::success([
                        'projects' => $projectStats,
                        'users' => $userStats,
                        'projectTypes' => (new ProjectRepository($pdo))->listActiveProjectTypes(),
                    ]);

                case 'getAllUsers':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 10, 1, 100);
                    $search = self::queryText('q', 100);
                    $status = self::queryFilter('status', ['active', 'suspended', 'pending', 'closed']);
                    $date = self::queryDate('date');
                    $sort = self::queryEnum('sort', ['created_at', 'last_activity', 'name', 'username', 'account_status', 'project_count'], 'created_at');
                    $direction = self::queryEnum('direction', ['asc', 'desc'], 'desc');
                    $result = (new UserRepository($pdo))->listForAdmin($page, $perPage, $status, $search, $sort, $direction, $date);
                    $items = array_map(self::userDTO(...), $result['items']);
                    ApiResponse::success(['users' => $items, 'pagination' => self::pagination($result)]);

                case 'getAdminUserDetail':
                    $publicId = self::queryText('id', 32);
                    if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(422, 'INVALID_USER_REFERENCE', 'Unprocessable content', 'شناسهٔ عمومی کاربر معتبر نیست.');
                    }
                    $userRepository = new UserRepository($pdo);
                    $user = $userRepository->findAdminDetailByPublicId($publicId);
                    if ($user === null) {
                        throw new ApiProblemException(404, 'USER_NOT_FOUND', 'Not found', 'کاربر پیدا نشد.');
                    }
                    $userId = (int) $user['id'];
                    $channels = (new UserChannelRepository($pdo))->listForUser($userId);
                    $channelDTOs = array_map(static function (array $channel): array {
                        $type = (string) ($channel['channel_type'] ?? '');
                        return [
                            'type' => in_array($type, ['email', 'telegram'], true) ? $type : 'unknown',
                            'value' => (string) ($channel['channel_value'] ?? ''),
                            'verified' => (int) ($channel['is_verified'] ?? 0) === 1,
                            'verifiedAt' => self::isoUtc($channel['verified_at'] ?? null),
                            'primary' => (int) ($channel['is_primary'] ?? 0) === 1,
                        ];
                    }, $channels);
                    $projectResult = (new ProjectRepository($pdo))->listForAdminUserDossier($userId, 1, 50);
                    $projectDTOs = array_map(static function (array $project): array {
                        $status = (string) ($project['status'] ?? '');
                        return [
                            'publicId' => (string) ($project['public_id'] ?? ''),
                            'trackingCode' => (string) ($project['tracking_code'] ?? ''),
                            'title' => (string) ($project['title'] ?? ''),
                            'projectType' => (string) ($project['project_type'] ?? ''),
                            'status' => $status,
                            'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
                            'progress' => max(0, min(100, (int) ($project['progress'] ?? 0))),
                            'createdAt' => self::isoUtc($project['created_at'] ?? null),
                            'updatedAt' => self::isoUtc($project['updated_at'] ?? null),
                            'archived' => !empty($project['archived_at']),
                        ];
                    }, $projectResult['items']);
                    $messages = array_map(static function (array $message): array {
                        $sender = (string) ($message['sender_type'] ?? 'system');
                        return [
                            'projectPublicId' => (string) ($message['project_public_id'] ?? ''),
                            'trackingCode' => (string) ($message['tracking_code'] ?? ''),
                            'projectTitle' => (string) ($message['project_title'] ?? ''),
                            'senderType' => in_array($sender, ['user', 'admin', 'system'], true) ? $sender : 'system',
                            'body' => (string) ($message['body_preview'] ?? ''),
                            'createdAt' => self::isoUtc($message['created_at'] ?? null),
                            'read' => is_string($message['read_at'] ?? null) && $message['read_at'] !== '',
                        ];
                    }, (new ProjectMessageRepository($pdo))->listRecentForUser($userId, 30));
                    $sessions = array_map(static function (array $session): array {
                        return [
                            'createdAt' => self::isoUtc($session['created_at'] ?? null),
                            'expiresAt' => self::isoUtc($session['expires_at'] ?? null),
                            'lastActivityAt' => self::isoUtc($session['last_activity_at'] ?? null),
                            'revoked' => is_string($session['revoked_at'] ?? null) && $session['revoked_at'] !== '',
                            'revokedAt' => self::isoUtc($session['revoked_at'] ?? null),
                            'revokeReason' => is_string($session['revoke_reason'] ?? null) ? $session['revoke_reason'] : null,
                        ];
                    }, (new AuthSessionRepository($pdo))->listSafeMetadataForUser($userId, 20));
                    $securityEvents = array_map(static function (array $event): array {
                        return [
                            'actorType' => (string) ($event['actor_type'] ?? 'system'),
                            'action' => (string) ($event['action'] ?? ''),
                            'createdAt' => self::isoUtc($event['created_at'] ?? null),
                        ];
                    }, (new AuditLogRepository($pdo))->listForUserSubject($userId, 30));
                    ApiResponse::success([
                        'user' => [
                            'publicId' => (string) $user['public_id'],
                            'fullName' => (string) $user['full_name'],
                            'username' => (string) $user['username'],
                            'phone' => (string) $user['phone'],
                            'accountStatus' => (string) $user['account_status'],
                            'createdAt' => self::isoUtc($user['created_at'] ?? null),
                            'updatedAt' => self::isoUtc($user['updated_at'] ?? null),
                            'lastLoginAt' => self::isoUtc($user['last_login_at'] ?? null),
                            'projectCount' => max(0, (int) ($user['project_count'] ?? 0)),
                        ],
                        'channels' => $channelDTOs,
                        'projects' => $projectDTOs,
                        'projectsPagination' => self::pagination($projectResult),
                        'messages' => $messages,
                        'sessions' => $sessions,
                        'securityEvents' => $securityEvents,
                    ]);

                case 'getAdminProjectDetail':
                    $projectId = self::queryInt('id', 0, 1, PHP_INT_MAX);
                    $project = (new ProjectRepository($pdo))->findByIdForAdmin($projectId);
                    if ($project === null) {
                        throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                    }
                    $projectIdValue = (int) $project['id'];
                    $events = (new ProjectEventRepository($pdo))->listForProject($projectIdValue, 1, 100);
                    $messages = (new ProjectMessageRepository($pdo))->listForAdminProject($projectIdValue, 1, 200);
                    $notes = (new InternalNoteRepository($pdo))->listForProject($projectIdValue, 1, 100);
                    $milestones = (new ProjectMilestoneRepository($pdo))->listForProject($projectIdValue);
                    $eventDTO = [];
                    foreach ($events as $event) {
                        $from = is_string($event['from_status'] ?? null) ? $event['from_status'] : null;
                        $to = is_string($event['to_status'] ?? null) ? $event['to_status'] : null;
                        $eventDTO[] = [
                            'type' => (string) ($event['event_type'] ?? ''),
                            'actorType' => (string) ($event['actor_type'] ?? 'system'),
                            'fromStatus' => $from,
                            'fromStatusLabel' => $from === null ? null : (self::STATUS_LABELS[$from] ?? $from),
                            'toStatus' => $to,
                            'toStatusLabel' => $to === null ? null : (self::STATUS_LABELS[$to] ?? $to),
                            'createdAt' => self::isoUtc($event['created_at'] ?? null),
                        ];
                    }
                    $messageDTO = [];
                    foreach ($messages as $message) {
                        $senderType = (string) ($message['sender_type'] ?? 'system');
                        if (!in_array($senderType, ['user', 'admin', 'system'], true)) $senderType = 'system';
                        $messageDTO[] = [
                            'senderType' => $senderType,
                            'senderLabel' => match ($senderType) { 'user' => 'مشتری', 'admin' => 'مدیریت', default => 'سامانه' },
                            'body' => (string) ($message['body'] ?? ''),
                            'createdAt' => self::isoUtc($message['created_at'] ?? null),
                            'read' => is_string($message['read_at'] ?? null) && $message['read_at'] !== '',
                        ];
                    }
                    $noteDTO = [];
                    foreach ($notes as $note) {
                        $noteDTO[] = [
                            'id' => (int) $note['id'],
                            'adminId' => (string) $note['admin_id'],
                            'body' => (string) $note['body'],
                            'createdAt' => self::isoUtc($note['created_at'] ?? null),
                            'updatedAt' => self::isoUtc($note['updated_at'] ?? null),
                        ];
                    }
                    $milestoneDTO = [];
                    foreach ($milestones as $milestone) {
                        $milestoneDTO[] = [
                            'code' => (string) $milestone['code'],
                            'name' => (string) $milestone['name'],
                            'status' => (string) $milestone['status'],
                            'weight' => max(0, min(100, (int) $milestone['weight'])),
                            'startedAt' => self::isoUtc($milestone['started_at'] ?? null),
                            'completedAt' => self::isoUtc($milestone['completed_at'] ?? null),
                        ];
                    }
                    $owner = [
                        'fullName' => (string) ($project['owner_full_name'] ?? ''),
                        'username' => (string) ($project['owner_username'] ?? ''),
                        'phone' => (string) ($project['owner_phone'] ?? ''),
                        'email' => is_string($project['owner_email'] ?? null) ? $project['owner_email'] : '',
                        'accountStatus' => (string) ($project['owner_account_status'] ?? ''),
                    ];
                    $projectDTO = self::projectListDTO($project);
                    $projectDTO['statusReason'] = is_string($project['rejection_reason'] ?? null) && $project['rejection_reason'] !== ''
                        ? $project['rejection_reason']
                        : (is_string($project['cancel_reason'] ?? null) && $project['cancel_reason'] !== '' ? $project['cancel_reason'] : null);
                    ApiResponse::success([
                        'project' => $projectDTO,
                        'owner' => $owner,
                        'briefAvailable' => !empty($project['brief_available']),
                        'brief' => is_array($project['brief'] ?? null) ? $project['brief'] : [],
                        'lifecycleHistory' => $eventDTO,
                        'customerMessages' => $messageDTO,
                        'internalNotes' => $noteDTO,
                        'milestones' => $milestoneDTO,
                    ]);

                case 'markAdminMessagesRead':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertKeys($body, ['id']);
                    $projectId = self::bodyPositiveId($body['id'] ?? null, 'شناسه پروژه معتبر نیست.');
                    $project = (new ProjectRepository($pdo))->findByIdForAdmin($projectId);
                    if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                    $marked = (new ProjectMessageRepository($pdo))->markUnreadForAdminProject($projectId);
                    ApiResponse::success(['projectId' => $projectId, 'read' => true, 'markedCount' => $marked]);

                case 'adminSendMessage':
                    $body = ApiRequest::jsonBody(8192);
                    self::assertKeys($body, ['id', 'text']);
                    $projectId = self::bodyPositiveId($body['id'] ?? null, 'شناسه پروژه معتبر نیست.');
                    $text = $body['text'] ?? null;
                    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
                    if (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey)) {
                        throw new ApiProblemException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Bad request', 'برای ثبت ایمن پاسخ، کلید Idempotency-Key معتبر لازم است.');
                    }
                    if (!is_string($text)) {
                        throw new ApiProblemException(422, 'INVALID_MESSAGE', 'Unprocessable content', 'متن پیام معتبر نیست.');
                    }
                    $text = trim($text);
                    $length = preg_match_all('/./us', $text);
                    if ($text === '' || $length === false || $length > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
                        throw new ApiProblemException(422, 'INVALID_MESSAGE', 'Unprocessable content', 'پیام باید بین ۱ تا ۲۰۰۰ نویسه و فاقد نویسه‌های کنترلی باشد.');
                    }
                    $scopeType = 'admin';
                    $scopeId = $adminId;
                    $route = 'POST:api.php?action=adminSendMessage:project:' . $projectId;
                    $requestHash = hash('sha256', $projectId . ':' . strlen($text) . ':' . $text);
                    $idempotency = new IdempotencyKeyRepository($pdo);
                    if (!$pdo->beginTransaction()) {
                        throw new RuntimeException('Could not begin admin-message transaction.');
                    }
                    try {
                        $existing = $idempotency->find($scopeType, $scopeId, $route, $idempotencyKey);
                        if ($existing !== null) {
                            $replay = self::resolveAdminMessageIdempotencyReplay($existing, $requestHash);
                            if (!$pdo->commit()) throw new RuntimeException('Admin-message replay transaction commit was not confirmed.');
                            header('Idempotency-Replayed: true');
                            ApiResponse::success($replay['data'], $replay['status']);
                        }
                        $reserved = $idempotency->reserve(
                            $scopeType, $scopeId, $route, $idempotencyKey, $requestHash,
                            gmdate('Y-m-d H:i:s', time() + 86400)
                        );
                        if (!$reserved) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $existing = $idempotency->find($scopeType, $scopeId, $route, $idempotencyKey);
                            if ($existing === null) {
                                throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'وضعیت ثبت پاسخ مشخص نیست؛ با همان متن و همان کلید دوباره تلاش کنید.');
                            }
                            $replay = self::resolveAdminMessageIdempotencyReplay($existing, $requestHash);
                            header('Idempotency-Replayed: true');
                            ApiResponse::success($replay['data'], $replay['status']);
                        }
                        $project = (new ProjectRepository($pdo))->findByIdForAdmin($projectId, true);
                        if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                        if (is_string($project['archived_at'] ?? null) && $project['archived_at'] !== '') {
                            throw new ApiProblemException(409, 'PROJECT_ARCHIVED', 'Conflict', 'برای پروژه بایگانی‌شده پیام جدید ثبت نمی‌شود.');
                        }
                        $messageId = (new ProjectMessageRepository($pdo))->create($projectId, 'admin', $adminId, $text);
                        $messageStatement = $pdo->prepare('SELECT created_at FROM project_messages WHERE id = ? AND project_id = ? LIMIT 1');
                        $messageStatement->execute([$messageId, $projectId]);
                        $createdAt = $messageStatement->fetchColumn();
                        if (!is_string($createdAt) || $createdAt === '') throw new RuntimeException('Saved admin message timestamp was unavailable.');
                        (new NotificationRepository($pdo))->createForUser(
                            (int) $project['owner_user_id'], $projectId, 'project_message', 'پاسخ جدید از مدیریت',
                            'برای پروژه ' . (string) $project['tracking_code'] . ' پیام جدیدی در پنل شما ثبت شده است.',
                            '/#user-panel'
                        );
                        (new AuditLogRepository($pdo))->append('admin', $adminId, 'project.message_sent', 'project', (string) $projectId, ApiResponse::requestId(), null, null, ['message_id' => $messageId]);
                        $responseData = [
                            'id' => $messageId,
                            'projectId' => $projectId,
                            'createdAt' => self::isoUtc($createdAt),
                            'recorded' => true,
                            'deliveryStatus' => 'stored',
                            'message' => 'پاسخ در سرور ثبت شد و اعلان داخل حساب مشتری ذخیره شد؛ مشاهدهٔ اعلان هنوز تأیید نشده است.',
                        ];
                        if (!$idempotency->complete($scopeType, $scopeId, $route, $idempotencyKey, $requestHash, 201, ['data' => $responseData])) {
                            throw new RuntimeException('Admin-message idempotency completion failed.');
                        }
                        if (!$pdo->commit()) throw new RuntimeException('Admin-message transaction commit was not confirmed.');
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            try { $pdo->rollBack(); } catch (Throwable) { /* retain original exception */ }
                        }
                        throw $exception;
                    }
                    ApiResponse::success($responseData, 201);

                case 'adminUpdate':
                    $body = ApiRequest::jsonBody(8192);
                    self::assertKeys($body, ['id', 'field', 'value', 'reason']);
                    $projectId = self::bodyPositiveId($body['id'] ?? null, 'شناسه پروژه معتبر نیست.');
                    $field = $body['field'] ?? null;
                    $value = $body['value'] ?? null;
                    if (!is_string($field) || !in_array($field, ['status', 'adminNote'], true)) {
                        throw new ApiProblemException(422, 'INVALID_ADMIN_FIELD', 'Unprocessable content', 'فیلد مدیریتی پشتیبانی نمی‌شود.');
                    }
                    if ($field === 'adminNote') {
                        if (!is_string($value)) throw new ApiProblemException(422, 'INVALID_INTERNAL_NOTE', 'Unprocessable content', 'متن یادداشت داخلی معتبر نیست.');
                        $note = trim($value);
                        $length = preg_match_all('/./us', $note);
                        if ($note === '' || $length === false || $length > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $note)) {
                            throw new ApiProblemException(422, 'INVALID_INTERNAL_NOTE', 'Unprocessable content', 'یادداشت داخلی باید بین ۱ تا ۲۰۰۰ نویسه و فاقد نویسه‌های کنترلی باشد.');
                        }
                        $created = self::transaction($pdo, function () use ($pdo, $projectId, $adminId, $note): array {
                            $project = (new ProjectRepository($pdo))->findByIdForAdmin($projectId, true);
                            if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                            if (is_string($project['archived_at'] ?? null) && $project['archived_at'] !== '') {
                                throw new ApiProblemException(409, 'PROJECT_ARCHIVED', 'Conflict', 'برای پروژه بایگانی‌شده یادداشت جدید ثبت نمی‌شود.');
                            }
                            $noteId = (new InternalNoteRepository($pdo))->create($projectId, $adminId, $note);
                            (new AuditLogRepository($pdo))->append('admin', $adminId, 'project.internal_note_added', 'project', (string) $projectId, ApiResponse::requestId(), null, null, ['note_id' => $noteId]);
                            $stmt = $pdo->prepare('SELECT created_at FROM internal_notes WHERE id = ? AND project_id = ? LIMIT 1');
                            $stmt->execute([$noteId, $projectId]);
                            $createdAt = $stmt->fetchColumn();
                            if (!is_string($createdAt) || $createdAt === '') throw new RuntimeException('Saved internal-note timestamp was unavailable.');
                            return ['id' => $noteId, 'createdAt' => self::isoUtc($createdAt)];
                        });
                        ApiResponse::success(['projectId' => $projectId, 'internalNote' => $created]);
                    }

                    if (!is_string($value) || !in_array($value, ProjectRepository::statuses(), true)) {
                        throw new ApiProblemException(422, 'INVALID_PROJECT_STATUS', 'Unprocessable content', 'وضعیت انتخاب‌شده معتبر نیست.');
                    }
                    $reason = $body['reason'] ?? null;
                    if ($reason !== null && !is_string($reason)) {
                        throw new ApiProblemException(422, 'INVALID_STATUS_REASON', 'Unprocessable content', 'توضیح تغییر وضعیت معتبر نیست.');
                    }
                    $reason = is_string($reason) ? trim($reason) : null;
                    $reasonLength = $reason === null ? 0 : preg_match_all('/./us', $reason);
                    if ($reason !== null && ($reasonLength === false || $reasonLength > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason))) {
                        throw new ApiProblemException(422, 'INVALID_STATUS_REASON', 'Unprocessable content', 'توضیح تغییر وضعیت باید حداکثر ۵۰۰ نویسه و فاقد نویسه نامعتبر باشد.');
                    }
                    $result = self::transaction($pdo, function () use ($pdo, $projectId, $value, $reason, $adminId): array {
                        $projects = new ProjectRepository($pdo);
                        $project = $projects->findByIdForAdmin($projectId, true);
                        if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                        if (is_string($project['archived_at'] ?? null) && $project['archived_at'] !== '') {
                            throw new ApiProblemException(409, 'PROJECT_ARCHIVED', 'Conflict', 'وضعیت پروژه بایگانی‌شده قابل تغییر نیست.');
                        }
                        $from = (string) ($project['status'] ?? '');
                        if ($from === $value) throw new ApiProblemException(409, 'PROJECT_STATUS_UNCHANGED', 'Conflict', 'این وضعیت از قبل ثبت شده است.');
                        if (!in_array($value, self::TRANSITIONS[$from] ?? [], true)) {
                            throw new ApiProblemException(409, 'INVALID_STATUS_TRANSITION', 'Conflict', 'انتقال از وضعیت فعلی به وضعیت انتخاب‌شده مجاز نیست.');
                        }
                        if (in_array($value, ['needs_information', 'rejected', 'cancelled'], true) && ($reason === null || $reason === '')) {
                            throw new ApiProblemException(422, 'STATUS_REASON_REQUIRED', 'Unprocessable content', $value === 'needs_information'
                                ? 'برای درخواست اطلاعات از مشتری، پیام قابل‌مشاهده برای مشتری الزامی است.'
                                : 'برای رد یا لغو پروژه، توضیح کوتاه الزامی است.');
                        }
                        if ($value === 'completed') {
                            (new ProjectMilestoneRepository($pdo))->completeAllForProject($projectId);
                        }
                        if (!$projects->updateStatus($projectId, $value, $reason, $adminId)) {
                            throw new RuntimeException('The project status update was not persisted.');
                        }
                        (new ProjectEventRepository($pdo))->append(
                            $projectId, 'admin', $adminId, 'project.status_changed', $from, $value,
                            in_array($value, ['needs_information', 'rejected', 'cancelled'], true) ? $reason : null,
                            [], ApiResponse::requestId()
                        );
                        $statusLabel = self::STATUS_LABELS[$value];
                        (new NotificationRepository($pdo))->createForUser(
                            (int) $project['owner_user_id'], $projectId, 'project_status', 'تغییر وضعیت پروژه',
                            'وضعیت درخواست ' . (string) $project['tracking_code'] . ' به «' . $statusLabel . '» تغییر کرد.'
                                . (in_array($value, ['needs_information', 'rejected', 'cancelled'], true) && is_string($reason) && $reason !== '' ? "\nدلیل: " . $reason : ''),
                            '/#user-panel'
                        );
                        (new AuditLogRepository($pdo))->append('admin', $adminId, 'project.status_changed', 'project', (string) $projectId, ApiResponse::requestId(), null, null, ['from_status' => $from, 'to_status' => $value]);
                        return ['id' => $projectId, 'fromStatus' => $from, 'status' => $value, 'statusLabel' => $statusLabel];
                    });
                    ApiResponse::success($result);

                case 'adminUpdateUser':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertKeys($body, ['id', 'status', 'reason']);
                    $userId = self::bodyPositiveId($body['id'] ?? null, 'شناسه کاربر معتبر نیست.');
                    $target = $body['status'] ?? null;
                    if (!is_string($target) || !in_array($target, ['active', 'suspended'], true)) {
                        throw new ApiProblemException(422, 'INVALID_ACCOUNT_STATUS', 'Unprocessable content', 'وضعیت حساب قابل انتخاب نیست.');
                    }
                    $reason = $body['reason'] ?? null;
                    if (!is_string($reason)) {
                        throw new ApiProblemException(422, 'ACCOUNT_STATUS_REASON_REQUIRED', 'Unprocessable content', 'برای تغییر وضعیت حساب، دلیل مدیریتی الزامی است.');
                    }
                    $reason = trim($reason);
                    $reasonLength = preg_match_all('/./us', $reason);
                    if ($reason === '' || $reasonLength === false || $reasonLength > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason)) {
                        throw new ApiProblemException(422, 'ACCOUNT_STATUS_REASON_REQUIRED', 'Unprocessable content', 'دلیل تغییر وضعیت باید بین ۱ تا ۵۰۰ نویسه و فاقد نویسه‌های کنترلی باشد.');
                    }
                    $result = self::transaction($pdo, function () use ($pdo, $userId, $target, $reason, $adminId): array {
                        $users = new UserRepository($pdo);
                        $current = $users->findAccountStateForUpdate($userId);
                        if ($current === null) throw new ApiProblemException(404, 'USER_NOT_FOUND', 'Not found', 'کاربر پیدا نشد.');
                        $from = (string) ($current['account_status'] ?? '');
                        if (!in_array($from, ['active', 'suspended'], true)) {
                            throw new ApiProblemException(409, 'ACCOUNT_STATUS_LOCKED', 'Conflict', 'حساب‌های در انتظار تأیید یا بسته‌شده از این مسیر قابل تغییر نیستند.');
                        }
                        if ($from === $target) throw new ApiProblemException(409, 'ACCOUNT_STATUS_UNCHANGED', 'Conflict', 'وضعیت انتخاب‌شده از قبل ثبت شده است.');
                        if (!$users->changeAccountStatus($userId, $target)) {
                            throw new RuntimeException('The account status update was not persisted.');
                        }
                        $revoked = 0;
                        if ($target === 'suspended') $revoked = (new AuthSessionRepository($pdo))->revokeAllForUser($userId, 'admin_suspended');
                        (new AuditLogRepository($pdo))->append(
                            'admin', $adminId, $target === 'suspended' ? 'user.account_suspended' : 'user.account_reactivated',
                            'user', (string) $userId, ApiResponse::requestId(), null, null,
                            ['from_status' => $from, 'to_status' => $target, 'reason' => $reason, 'revoked_sessions' => $revoked]
                        );
                        return ['id' => $userId, 'accountStatus' => $target, 'accountStatusLabel' => self::ACCOUNT_STATUS_LABELS[$target]];
                    });
                    ApiResponse::success($result);

                case 'deleteRequest':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertKeys($body, ['id']);
                    $projectId = self::bodyPositiveId($body['id'] ?? null, 'شناسه پروژه معتبر نیست.');
                    $result = self::transaction($pdo, function () use ($pdo, $projectId, $adminId): array {
                        $projects = new ProjectRepository($pdo);
                        $project = $projects->findByIdForAdmin($projectId, true);
                        if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد.');
                        if (is_string($project['archived_at'] ?? null) && $project['archived_at'] !== '') {
                            throw new ApiProblemException(409, 'PROJECT_ALREADY_ARCHIVED', 'Conflict', 'این پروژه قبلاً بایگانی شده است.');
                        }
                        if (!in_array((string) $project['status'], ['completed', 'rejected', 'cancelled'], true)) {
                            throw new ApiProblemException(409, 'PROJECT_NOT_TERMINAL', 'Conflict', 'فقط پروژه تکمیل‌شده، ردشده یا لغوشده قابل بایگانی است.');
                        }
                        if (!$projects->archive($projectId)) throw new RuntimeException('Project archival was not persisted.');
                        (new ProjectEventRepository($pdo))->append($projectId, 'admin', $adminId, 'project.archived', (string) $project['status'], (string) $project['status'], null, [], ApiResponse::requestId());
                        (new AuditLogRepository($pdo))->append('admin', $adminId, 'project.archived', 'project', (string) $projectId, ApiResponse::requestId(), null, null, ['status' => (string) $project['status']]);
                        return ['id' => $projectId, 'archived' => true];
                    });
                    ApiResponse::success($result);
            }
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (InvalidArgumentException $exception) {
            ApiResponse::problem(422, 'INVALID_ADMIN_INPUT', 'Unprocessable content', 'یکی از مقادیر ارسالی معتبر نیست.');
        } catch (Throwable $exception) {
            error_log('MR NEON admin operation failure: requestId=' . ApiResponse::requestId() . ' action=' . $action . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'ADMIN_OPERATION_UNAVAILABLE', 'Service unavailable', 'عملیات مدیریتی در حال حاضر در دسترس نیست؛ دوباره تلاش کنید.');
        }
    }

    /** Build the complete bounded export before recording its generation in the audit trail. */
    private static function buildProjectsCsv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (!is_resource($stream)) throw new RuntimeException('Could not allocate CSV output stream.');
        try {
            $bom = "\xEF\xBB\xBF";
            if (fwrite($stream, $bom) !== strlen($bom)) throw new RuntimeException('Could not write CSV BOM.');
            $headers = ['کد پیگیری', 'نام', 'نوع پروژه', 'تاریخ', 'وضعیت', 'یادداشت مشتری', 'یادداشت داخلی مدیریت'];
            if (fputcsv($stream, array_map(self::csvSafeCell(...), $headers), ',', '"', '') === false) {
                throw new RuntimeException('Could not write CSV header.');
            }
            foreach ($rows as $row) {
                $brief = is_array($row['brief'] ?? null) ? $row['brief'] : [];
                $status = (string) ($row['status'] ?? 'submitted');
                $cells = [
                    (string) ($row['tracking_code'] ?? ''),
                    is_string($brief['fullName'] ?? null) ? $brief['fullName'] : '',
                    (string) ($row['project_type'] ?? ''),
                    self::isoUtc($row['created_at'] ?? null) ?? '',
                    self::STATUS_LABELS[$status] ?? $status,
                    is_string($brief['userNote'] ?? null) ? $brief['userNote'] : '',
                    is_string($row['latest_internal_note'] ?? null) ? $row['latest_internal_note'] : '',
                ];
                if (fputcsv($stream, array_map(self::csvSafeCell(...), $cells), ',', '"', '') === false) {
                    throw new RuntimeException('Could not write CSV row.');
                }
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv) || $csv === '') throw new RuntimeException('Could not finalize CSV output.');
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    /** Emit only a fully generated admin-only CSV payload with conservative cache and MIME headers. */
    private static function sendProjectsCsv(string $csv): never
    {
        $filename = 'mrneon-projects-' . gmdate('Y-m-d') . '.csv';
        http_response_code(200);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($csv));
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: ' . ApiResponse::requestId());
        echo $csv;
        exit;
    }

    /** Neutralize spreadsheet formulas before CSV quoting; quoting alone does not prevent formula execution. */
    private static function csvSafeCell(mixed $value): string
    {
        $text = str_replace("\0", '', (string) $value);
        if (preg_match('/^(?:[\x00-\x20]|\xC2\xA0|\xE3\x80\x80|\xEF\xBB\xBF|\xE2\x80[\x8B-\x8F]|\xE2\x81\xA0)*[=+\-@]/', $text) === 1) {
            $text = "'" . $text;
        }
        return $text;
    }

    private static function projectListDTO(array $row): array
    {
        $brief = is_array($row['brief'] ?? null) ? $row['brief'] : [];
        $features = is_array($brief['features'] ?? null) ? array_values(array_filter($brief['features'], 'is_string')) : [];
        $colors = is_array($brief['colors'] ?? null) ? $brief['colors'] : [];
        $palettes = is_array($colors['palettes'] ?? null) ? array_values(array_filter($colors['palettes'], 'is_string')) : [];
        $content = is_array($brief['content'] ?? null) ? $brief['content'] : [];
        $industry = is_string($brief['industry'] ?? null) ? $brief['industry'] : '';
        $purpose = is_string($brief['purpose'] ?? null) ? $brief['purpose'] : '';
        $style = is_string($brief['style'] ?? null) ? $brief['style'] : '';
        $timeline = is_string($brief['timeline'] ?? null) ? $brief['timeline'] : '';
        $featureLabels = array_map(static fn (string $feature): string => NeoCatalog::label('features', $feature), $features);
        $status = (string) ($row['status'] ?? 'submitted');
        $createdAt = self::isoUtc($row['created_at'] ?? null);
        $briefName = is_string($brief['fullName'] ?? null) ? $brief['fullName'] : '';
        $briefPhone = is_string($brief['phone'] ?? null) ? $brief['phone'] : '';
        $briefEmail = is_string($brief['email'] ?? null) ? $brief['email'] : '';
        $messenger = is_string($brief['messenger'] ?? null) ? $brief['messenger'] : '';
        $messengerId = is_string($brief['messengerId'] ?? null) ? $brief['messengerId'] : '';
        return [
            'id' => (int) $row['id'],
            'publicId' => (string) ($row['public_id'] ?? ''),
            'trackingCode' => (string) ($row['tracking_code'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'projectType' => (string) ($row['project_type'] ?? ''),
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? $status,
            'progress' => max(0, min(100, (int) ($row['progress'] ?? 0))),
            'createdAt' => $createdAt,
            'submittedAt' => self::isoUtc($row['submitted_at'] ?? null),
            'updatedAt' => self::isoUtc($row['updated_at'] ?? null),
            'archivedAt' => self::isoUtc($row['archived_at'] ?? null),
            'fullName' => $briefName !== '' ? $briefName : (string) ($row['owner_full_name'] ?? ''),
            'phone' => $briefPhone !== '' ? $briefPhone : (string) ($row['owner_phone'] ?? ''),
            'email' => $briefEmail !== '' ? $briefEmail : (string) ($row['owner_email'] ?? ''),
            'ownerAccountStatus' => (string) ($row['owner_account_status'] ?? ''),
            'industry' => $industry,
            'industryLabel' => NeoCatalog::label('industries', $industry),
            'purpose' => $purpose,
            'purposeLabel' => NeoCatalog::purposeLabel($industry, $purpose),
            'style' => $style,
            'styleLabel' => NeoCatalog::label('styles', $style),
            'timeline' => $timeline,
            'timelineLabel' => NeoCatalog::label('timelines', $timeline),
            'paletteLabels' => array_map(static fn (string $palette): string => NeoCatalog::label('palettes', $palette), $palettes),
            'featuresLabels' => $featureLabels,
            'contentLabels' => NeoCatalog::contentLabels($content),
            'userNote' => is_string($brief['userNote'] ?? null) ? $brief['userNote'] : '',
            'messenger' => $messenger,
            'messengerLabel' => $messenger !== '' ? NeoCatalog::label('messengers', $messenger) : '',
            'messengerId' => $messengerId,
            'adminNote' => is_string($row['latest_internal_note'] ?? null) ? $row['latest_internal_note'] : '',
            'briefAvailable' => !empty($row['brief_available']),
        ];
    }

    private static function userDTO(array $row): array
    {
        $status = (string) ($row['account_status'] ?? 'pending');
        return [
            'id' => (int) $row['id'],
            'publicId' => (string) ($row['public_id'] ?? ''),
            'fullName' => (string) ($row['full_name'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => is_string($row['email'] ?? null) ? $row['email'] : '',
            'accountStatus' => $status,
            'accountStatusLabel' => self::ACCOUNT_STATUS_LABELS[$status] ?? $status,
            'createdAt' => self::isoUtc($row['created_at'] ?? null),
            'lastLoginAt' => self::isoUtc($row['last_login_at'] ?? null),
            'projectCount' => max(0, (int) ($row['project_count'] ?? 0)),
        ];
    }

    private static function requireAdminInboxId(): string
    {
        $adminInboxId = NotificationRepository::configuredAdminInboxId();
        if ($adminInboxId === null) {
            throw new ApiProblemException(503, 'ADMIN_INBOX_NOT_CONFIGURED', 'Service unavailable', 'شناسهٔ ثابت صندوق اعلان مدیر در تنظیمات خصوصی سرور پیکربندی نشده است.');
        }
        return $adminInboxId;
    }

    private static function pagination(array $result): array
    {
        $page = (int) $result['page'];
        $perPage = (int) $result['per_page'];
        $total = (int) $result['total'];
        return ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / max(1, $perPage)), 'hasMore' => ($page * $perPage) < $total];
    }

    private static function queryInt(string $name, int $default, int $min, int $max): int
    {
        $raw = $_GET[$name] ?? null;
        if ($raw === null) {
            if ($default >= $min && $default <= $max) return $default;
            throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر صفحه‌بندی معتبر نیست.');
        }
        if (!is_string($raw) || !ctype_digit($raw)) throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر صفحه‌بندی معتبر نیست.');
        $value = (int) $raw;
        if ($value < $min || $value > $max) throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر صفحه‌بندی خارج از محدوده مجاز است.');
        return $value;
    }

    private static function queryText(string $name, int $max): string
    {
        $raw = $_GET[$name] ?? '';
        if (!is_string($raw) || strlen($raw) > ($max * 4) || preg_match('/[\x00-\x1F\x7F]/u', $raw)) {
            throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'عبارت جست‌وجو معتبر نیست.');
        }
        $length = preg_match_all('/./us', trim($raw));
        if ($length === false || $length > $max) throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'عبارت جست‌وجو بیش از حد مجاز است.');
        return trim($raw);
    }

    private static function queryFilter(string $name, array $allowed): ?string
    {
        $raw = $_GET[$name] ?? 'all';
        if (!is_string($raw) || $raw === '') throw new ApiProblemException(400, 'INVALID_FILTER', 'Bad request', 'فیلتر ارسال‌شده معتبر نیست.');
        if ($raw === 'all') return null;
        if (!in_array($raw, $allowed, true)) throw new ApiProblemException(400, 'INVALID_FILTER', 'Bad request', 'فیلتر ارسال‌شده معتبر نیست.');
        return $raw;
    }

    private static function queryType(): ?string
    {
        $raw = $_GET['projectType'] ?? 'all';
        if (!is_string($raw) || $raw === '') throw new ApiProblemException(400, 'INVALID_FILTER', 'Bad request', 'نوع پروژه معتبر نیست.');
        if ($raw === 'all') return null;
        if (!preg_match('/^[a-z0-9_]{1,64}$/D', $raw)) throw new ApiProblemException(400, 'INVALID_FILTER', 'Bad request', 'نوع پروژه معتبر نیست.');
        return $raw;
    }

    /** Parse a server-defined enum query parameter; never accept SQL identifiers from the client. */
    private static function queryEnum(string $name, array $allowed, string $default): string
    {
        $raw = $_GET[$name] ?? $default;
        if (!is_string($raw) || !in_array($raw, $allowed, true)) {
            throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر مرتب‌سازی معتبر نیست.');
        }
        return $raw;
    }

    /** Parse an exact calendar date; invalid dates fail closed instead of broadening the result set. */
    private static function queryDate(string $name): ?string
    {
        $raw = $_GET[$name] ?? '';
        if ($raw === '') return null;
        if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $matches)) {
            throw new ApiProblemException(400, 'INVALID_DATE_FILTER', 'Bad request', 'تاریخ فیلتر معتبر نیست.');
        }
        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new ApiProblemException(400, 'INVALID_DATE_FILTER', 'Bad request', 'تاریخ فیلتر معتبر نیست.');
        }
        return $raw;
    }

    private static function bodyPositiveId(mixed $raw, string $message): int
    {
        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < 1) throw new ApiProblemException(422, 'INVALID_RESOURCE_ID', 'Unprocessable content', $message);
        return $value;
    }

    private static function assertKeys(array $body, array $allowed): void
    {
        foreach ($body as $key => $_) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ApiProblemException(422, 'UNKNOWN_ADMIN_FIELD', 'Unprocessable content', 'درخواست شامل فیلد پشتیبانی‌نشده است.');
            }
        }
    }

    /** @return array{status:int,data:array<string,mixed>} */
    private static function resolveAdminMessageIdempotencyReplay(array $existing, string $requestHash): array
    {
        $priorHash = (string) ($existing['request_hash'] ?? '');
        if ($priorHash === '' || !hash_equals($priorHash, $requestHash)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_KEY_REUSED', 'Conflict', 'این کلید قبلاً برای محتوای متفاوتی استفاده شده است؛ برای پاسخ جدید کلید تازه لازم است.');
        }
        if (($existing['state'] ?? '') !== 'completed' || !is_string($existing['response_json'] ?? null)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_IN_PROGRESS', 'Conflict', 'نتیجهٔ درخواست قبلی هنوز قطعی نیست؛ با همان متن و همان کلید دوباره تلاش کنید.');
        }
        try {
            $stored = json_decode((string) $existing['response_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت پاسخ قابل بازیابی نیست؛ تاریخچهٔ پروژه را بررسی کنید.');
        }
        $data = is_array($stored) ? ($stored['data'] ?? null) : null;
        $status = filter_var($existing['response_status'] ?? null, FILTER_VALIDATE_INT);
        if (!is_array($data) || ($data['recorded'] ?? null) !== true || ($data['deliveryStatus'] ?? null) !== 'stored'
            || !is_int($data['id'] ?? null) || $data['id'] < 1 || !is_int($data['projectId'] ?? null) || $data['projectId'] < 1
            || !is_string($data['createdAt'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $data['createdAt'])
            || !is_string($data['message'] ?? null) || !is_int($status) || $status !== 201) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت پاسخ قابل بازیابی نیست؛ تاریخچهٔ پروژه را بررسی کنید.');
        }
        return ['status' => $status, 'data' => [
            'id' => $data['id'], 'projectId' => $data['projectId'], 'createdAt' => $data['createdAt'],
            'recorded' => true, 'deliveryStatus' => 'stored', 'message' => $data['message'],
        ]];
    }

    private static function transaction(PDO $pdo, callable $operation): mixed
    {
        if (!$pdo->beginTransaction()) throw new RuntimeException('Could not begin admin transaction.');
        try {
            $result = $operation();
            if (!$pdo->commit()) throw new RuntimeException('Admin transaction commit was not confirmed.');
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                try { $pdo->rollBack(); } catch (Throwable) { /* retain the original failure */ }
            }
            throw $exception;
        }
    }

    private static function isoUtc(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') return null;
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        } catch (Throwable) {
            return null;
        }
    }
}
