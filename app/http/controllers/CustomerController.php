<?php
declare(strict_types=1);

/** Customer-scoped reads and communication for the canonical DB-backed project model. */
final class CustomerController
{
    private const PUBLIC_EVENT_TYPES = ['project.submitted', 'project.status_changed', 'project.milestone_changed'];
    private const PROJECT_STATUSES = ['submitted', 'under_review', 'needs_information', 'in_progress', 'waiting_for_client', 'completed', 'rejected', 'cancelled'];

    public function dispatch(string $action): never
    {
        try {
            if (in_array($action, ['sendMessage', 'markNotificationRead', 'markCustomerMessagesRead'], true)) {
                SessionMiddleware::start();
                Csrf::assertValid();
            }
            $principal = SessionMiddleware::requirePrincipal('user');
            [$ownerId, $ownerPublicId] = self::ownerContext($principal);
            $pdo = Database::connection();
            RateLimitMiddleware::enforceCustomerAction($pdo, $action, ApiRequest::clientIp(), $ownerPublicId);

            switch ($action) {
                case 'getProjects':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 20, 1, 50);
                    $status = $_GET['status'] ?? null;
                    if ($status !== null && (!is_string($status) || !in_array($status, self::PROJECT_STATUSES, true))) {
                        throw new ApiProblemException(400, 'INVALID_PROJECT_FILTER', 'Bad request', 'فیلتر وضعیت پروژه معتبر نیست.');
                    }
                    $projects = new ProjectRepository($pdo);
                    $result = $projects->listForOwner($ownerId, $page, $perPage, $status);
                    $items = array_map(static fn (array $row): array => self::projectListDTO($row), $result['items']);
                    ApiResponse::success([
                        'projects' => $items,
                        'pagination' => [
                            'page' => $result['page'],
                            'perPage' => $result['per_page'],
                            'total' => $result['total'],
                            'hasMore' => ($result['page'] * $result['per_page']) < $result['total'],
                        ],
                        'stats' => [
                            'total' => $projects->countForOwner($ownerId),
                            'active' => $projects->countActiveForOwner($ownerId),
                            'completed' => $projects->countForOwnerByStatus($ownerId, 'completed'),
                        ],
                    ]);

                case 'getProjectById':
                    $publicId = $_GET['projectId'] ?? $_GET['publicId'] ?? '';
                    if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد یا دسترسی به آن مجاز نیست.');
                    }
                    $projects = new ProjectRepository($pdo);
                    $row = $projects->findByPublicIdForOwner($publicId, $ownerId);
                    if ($row === null) {
                        throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد یا دسترسی به آن مجاز نیست.');
                    }
                    $projectId = (int) $row['id'];
                    // Keep history requests bounded and independently pageable. The project itself
                    // was resolved through findByPublicIdForOwner() above; repositories also repeat
                    // the owner constraint for defense in depth.
                    $timelinePage = self::queryInt('timelinePage', 1, 1, 1000000);
                    $messagesPage = self::queryInt('messagesPage', 1, 1, 1000000);
                    $timelinePerPage = 50;
                    $messagesPerPage = 50;
                    $eventRepository = new ProjectEventRepository($pdo);
                    $messageRepository = new ProjectMessageRepository($pdo);
                    $events = $eventRepository->listForOwnerProject($projectId, $ownerId, $timelinePage, $timelinePerPage);
                    $eventTotal = $eventRepository->countForOwnerProject($projectId, $ownerId);
                    $milestones = (new ProjectMilestoneRepository($pdo))->listForOwnerProject($projectId, $ownerId);
                    $messages = $messageRepository->listForOwnerProject($projectId, $ownerId, $messagesPage, $messagesPerPage);
                    $messageTotal = $messageRepository->countForOwnerProject($projectId, $ownerId);
                    $timelineDTO = [];
                    foreach (array_reverse($events) as $event) {
                        $type = (string) ($event['event_type'] ?? '');
                        if (!in_array($type, self::PUBLIC_EVENT_TYPES, true)) continue;
                        $fromStatus = is_string($event['from_status'] ?? null) ? $event['from_status'] : null;
                        $toStatus = is_string($event['to_status'] ?? null) ? $event['to_status'] : null;
                        $timelineDTO[] = [
                            // Stable display key only; this derived hash is not an authorization token.
                            'itemKey' => hash('sha256', $publicId . ':timeline:' . (string) ($event['id'] ?? '')),
                            'type' => $type,
                            'label' => self::publicEventLabel($type, $toStatus),
                            'fromStatus' => $fromStatus,
                            'toStatus' => $toStatus,
                            'message' => is_string($event['customer_message'] ?? null) ? $event['customer_message'] : null,
                            'createdAt' => self::isoUtc($event['created_at'] ?? null),
                        ];
                    }
                    $milestoneDTO = [];
                    foreach ($milestones as $milestone) {
                        $milestoneDTO[] = [
                            'code' => (string) $milestone['code'],
                            'name' => self::milestoneName((string) ($milestone['code'] ?? ''), (string) $milestone['name']),
                            'status' => (string) $milestone['status'],
                            'statusLabel' => self::milestoneStatusLabel((string) $milestone['status']),
                            'weight' => max(0, min(100, (int) $milestone['weight'])),
                            'startedAt' => self::isoUtcOrNull($milestone['started_at'] ?? null),
                            'completedAt' => self::isoUtcOrNull($milestone['completed_at'] ?? null),
                        ];
                    }
                    $messageDTO = [];
                    foreach (array_reverse($messages) as $message) {
                        $senderType = (string) ($message['sender_type'] ?? 'system');
                        if (!in_array($senderType, ['user', 'admin', 'system'], true)) $senderType = 'system';
                        $messageDTO[] = [
                            // Stable display key only; do not expose the internal message row ID.
                            'itemKey' => hash('sha256', $publicId . ':message:' . (string) ($message['id'] ?? '')),
                            'senderType' => $senderType,
                            'senderLabel' => self::senderLabel($senderType),
                            'body' => (string) $message['body'],
                            'createdAt' => self::isoUtc($message['created_at'] ?? null),
                            'read' => is_string($message['read_at'] ?? null) && $message['read_at'] !== '',
                        ];
                    }
                    $dto = self::projectDetailDTO($row);
                    $dto['timeline'] = $timelineDTO;
                    $dto['milestones'] = $milestoneDTO;
                    $dto['messages'] = $messageDTO;
                    $dto['timelinePagination'] = [
                        'page' => $timelinePage,
                        'perPage' => $timelinePerPage,
                        'total' => $eventTotal,
                        'hasMore' => ($timelinePage * $timelinePerPage) < $eventTotal,
                    ];
                    $dto['messagesPagination'] = [
                        'page' => $messagesPage,
                        'perPage' => $messagesPerPage,
                        'total' => $messageTotal,
                        'hasMore' => ($messagesPage * $messagesPerPage) < $messageTotal,
                    ];
                    ApiResponse::success(['project' => $dto]);

                case 'sendMessage':
                    $body = ApiRequest::jsonBody(8192);
                    self::assertAllowedKeys($body, ['projectId', 'text']);
                    $publicId = $body['projectId'] ?? null;
                    $text = $body['text'] ?? null;
                    $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
                    if (!is_string($idempotencyKey)
                        || strlen($idempotencyKey) < 16
                        || strlen($idempotencyKey) > 128
                        || !preg_match('/^[A-Za-z0-9._:-]{16,128}$/D', $idempotencyKey)) {
                        throw new ApiProblemException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Bad request', 'برای ثبت ایمن پیام، کلید Idempotency-Key معتبر لازم است.');
                    }
                    if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(422, 'INVALID_PROJECT_REFERENCE', 'Unprocessable content', 'پروژهٔ انتخاب‌شده معتبر نیست.');
                    }
                    if (!is_string($text)) {
                        throw new ApiProblemException(422, 'INVALID_MESSAGE', 'Unprocessable content', 'متن پیام معتبر نیست.');
                    }
                    $text = trim($text);
                    $length = preg_match_all('/./us', $text);
                    if ($text === '' || $length === false || $length > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
                        throw new ApiProblemException(422, 'INVALID_MESSAGE', 'Unprocessable content', 'پیام باید بین ۱ تا ۲۰۰۰ نویسه و فاقد نویسه‌های کنترلی باشد.');
                    }
                    $project = (new ProjectRepository($pdo))->findByPublicIdForOwner($publicId, $ownerId);
                    if ($project === null) {
                        throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد یا دسترسی به آن مجاز نیست.');
                    }

                    // Scope the key to the authenticated account, target project and exact endpoint.
                    // The canonical fingerprint includes the public project reference and normalized text.
                    $idempotencyScopeType = 'user';
                    $idempotencyScopeId = (string) $ownerId;
                    $idempotencyRoute = 'POST:api.php?action=sendMessage:project:' . $publicId;
                    $requestHash = hash('sha256', strlen($publicId) . ':' . $publicId . ':' . strlen($text) . ':' . $text);
                    $idempotency = new IdempotencyKeyRepository($pdo);
                    if (!$pdo->beginTransaction()) {
                        throw new RuntimeException('Could not begin project-message transaction.');
                    }
                    try {
                        $existing = $idempotency->find($idempotencyScopeType, $idempotencyScopeId, $idempotencyRoute, $idempotencyKey);
                        if ($existing !== null) {
                            $replay = self::resolveMessageIdempotencyReplay($existing, $requestHash);
                            if (!$pdo->commit()) {
                                throw new RuntimeException('Idempotent message replay transaction could not be committed.');
                            }
                            header('Idempotency-Replayed: true');
                            ApiResponse::success($replay['data'], $replay['status']);
                        }

                        $reserved = $idempotency->reserve(
                            $idempotencyScopeType,
                            $idempotencyScopeId,
                            $idempotencyRoute,
                            $idempotencyKey,
                            $requestHash,
                            gmdate('Y-m-d H:i:s', time() + 86400)
                        );
                        if (!$reserved) {
                            // A concurrent request won the unique-key race. Roll back before reading its result.
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $existing = $idempotency->find($idempotencyScopeType, $idempotencyScopeId, $idempotencyRoute, $idempotencyKey);
                            if ($existing === null) {
                                throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'وضعیت ثبت پیام قابل تأیید نیست؛ با همان متن و همان کلید دوباره تلاش کنید.');
                            }
                            $replay = self::resolveMessageIdempotencyReplay($existing, $requestHash);
                            header('Idempotency-Replayed: true');
                            ApiResponse::success($replay['data'], $replay['status']);
                        }

                        $messageId = (new ProjectMessageRepository($pdo))->create((int) $project['id'], 'user', $ownerPublicId, $text);
                        (new AuditLogRepository($pdo))->append(
                            'user', $ownerPublicId, 'project.message_sent', 'project', (string) $project['public_id'],
                            ApiResponse::requestId(), null, null, ['message_id' => $messageId]
                        );
                        (new NotificationRepository($pdo))->createForConfiguredAdmin(
                            (int) $project['id'],
                            'customer_message',
                            'پیام جدید مشتری',
                            'برای پروژه با کد پیگیری ' . (string) ($project['tracking_code'] ?? '') . ' پیام جدید ثبت شد.',
                            '/#admin-panel'
                        );
                        $created = $pdo->prepare('SELECT created_at FROM project_messages WHERE id = ? AND project_id = ? LIMIT 1');
                        $created->execute([$messageId, (int) $project['id']]);
                        $createdAt = $created->fetchColumn();
                        if (!is_string($createdAt) || $createdAt === '') {
                            throw new RuntimeException('The saved message timestamp could not be read.');
                        }
                        $responseData = [
                            'sent' => true,
                            'deliveryStatus' => 'stored',
                            'createdAt' => self::isoUtc($createdAt),
                            'message' => 'پیام در سرور ثبت شد؛ تحویل یا مشاهدهٔ آن توسط مدیریت هنوز تأیید نشده است.',
                        ];
                        if (!$idempotency->complete(
                            $idempotencyScopeType,
                            $idempotencyScopeId,
                            $idempotencyRoute,
                            $idempotencyKey,
                            $requestHash,
                            201,
                            ['data' => $responseData]
                        )) {
                            throw new RuntimeException('Project-message idempotency record could not be completed.');
                        }
                        if (!$pdo->commit()) {
                            throw new RuntimeException('Project-message transaction commit was not confirmed.');
                        }
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            try { $pdo->rollBack(); } catch (Throwable) { /* keep the original failure */ }
                        }
                        throw $exception;
                    }
                    ApiResponse::success($responseData, 201);

                case 'getNotifications':
                    $page = self::queryInt('page', 1, 1, 1000000);
                    $perPage = self::queryInt('perPage', 25, 1, 50);
                    $result = (new NotificationRepository($pdo))->listForUser($ownerId, $page, $perPage);
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
                        'pagination' => [
                            'page' => $result['page'],
                            'perPage' => $result['per_page'],
                            'total' => $result['total'],
                            'hasMore' => ($result['page'] * $result['per_page']) < $result['total'],
                        ],
                        'unreadCount' => (new NotificationRepository($pdo))->countUnreadForUser($ownerId),
                    ]);

                case 'markCustomerMessagesRead':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertAllowedKeys($body, ['projectId']);
                    $publicId = $body['projectId'] ?? null;
                    if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(422, 'INVALID_PROJECT_REFERENCE', 'Unprocessable content', 'پروژهٔ انتخاب‌شده معتبر نیست.');
                    }
                    $project = (new ProjectRepository($pdo))->findByPublicIdForOwner($publicId, $ownerId);
                    if ($project === null) throw new ApiProblemException(404, 'PROJECT_NOT_FOUND', 'Not found', 'پروژه پیدا نشد یا دسترسی به آن مجاز نیست.');
                    (new ProjectMessageRepository($pdo))->markUnreadForOwnerProject((int) $project['id'], $ownerId);
                    ApiResponse::success(['projectId' => $publicId, 'read' => true]);

                case 'markNotificationRead':
                    $body = ApiRequest::jsonBody(4096);
                    self::assertAllowedKeys($body, ['id']);
                    $publicId = $body['id'] ?? null;
                    if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
                        throw new ApiProblemException(422, 'INVALID_NOTIFICATION_REFERENCE', 'Unprocessable content', 'شناسهٔ اعلان معتبر نیست.');
                    }
                    if (!(new NotificationRepository($pdo))->markReadForUser($publicId, $ownerId)) {
                        throw new ApiProblemException(404, 'NOTIFICATION_NOT_FOUND', 'Not found', 'اعلان پیدا نشد یا دسترسی به آن مجاز نیست.');
                    }
                    ApiResponse::success([
                        'id' => $publicId,
                        'read' => true,
                        'unreadCount' => (new NotificationRepository($pdo))->countUnreadForUser($ownerId),
                    ]);
            }
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON customer route failure: requestId=' . ApiResponse::requestId() . ' action=' . $action . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'CUSTOMER_DATA_UNAVAILABLE', 'Service unavailable', 'اطلاعات مشتری در حال حاضر در دسترس نیست؛ بعداً دوباره تلاش کنید.');
        }
    }

    /** @return array{0:int,1:string} */
    private static function ownerContext(array $principal): array
    {
        $ownerId = filter_var($principal['user']['id'] ?? null, FILTER_VALIDATE_INT);
        $principalId = $principal['principalId'] ?? null;
        $userPublicId = $principal['user']['public_id'] ?? null;
        if (!is_int($ownerId) || $ownerId < 1 || !is_string($principalId) || $principalId === ''
            || !is_string($userPublicId) || !hash_equals($principalId, $userPublicId)) {
            throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست معتبر نیست؛ دوباره وارد حساب شوید.');
        }
        return [$ownerId, $principalId];
    }

    private static function queryInt(string $name, int $default, int $min, int $max): int
    {
        $raw = $_GET[$name] ?? null;
        if ($raw === null) return $default;
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر صفحه‌بندی معتبر نیست.');
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new ApiProblemException(400, 'INVALID_QUERY_PARAMETER', 'Bad request', 'پارامتر صفحه‌بندی خارج از محدوده مجاز است.');
        }
        return $value;
    }

    private static function assertAllowedKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new ApiProblemException(422, 'UNEXPECTED_FIELD', 'Unprocessable content', 'درخواست شامل فیلدی است که پذیرفته نمی‌شود.');
            }
        }
    }

    /** @return array{status:int,data:array<string,mixed>} */
    private static function resolveMessageIdempotencyReplay(array $existing, string $requestHash): array
    {
        $priorHash = (string) ($existing['request_hash'] ?? '');
        if ($priorHash === '' || !hash_equals($priorHash, $requestHash)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_KEY_REUSED', 'Conflict', 'این کلید قبلاً برای محتوای متفاوتی استفاده شده است؛ درخواست قبلی را تعیین تکلیف کنید و برای پیام جدید کلید تازه بسازید.');
        }
        if (($existing['state'] ?? '') !== 'completed' || !is_string($existing['response_json'] ?? null)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_IN_PROGRESS', 'Conflict', 'نتیجهٔ درخواست قبلی هنوز قطعی نیست؛ با همان متن و همان کلید دوباره تلاش کنید.');
        }
        try {
            $stored = json_decode((string) $existing['response_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت پیام قابل بازیابی نیست؛ پیش از ارسال دوباره، تاریخچهٔ پروژه را بررسی کنید.');
        }
        $data = is_array($stored) ? ($stored['data'] ?? null) : null;
        $status = filter_var($existing['response_status'] ?? null, FILTER_VALIDATE_INT);
        if (!is_array($data)
            || ($data['sent'] ?? null) !== true
            || ($data['deliveryStatus'] ?? null) !== 'stored'
            || !is_string($data['createdAt'] ?? null)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) ($data['createdAt'] ?? ''))
            || !is_string($data['message'] ?? null)
            || !is_int($status)
            || $status !== 201) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت پیام قابل بازیابی نیست؛ پیش از ارسال دوباره، تاریخچهٔ پروژه را بررسی کنید.');
        }
        // Return only the allowlisted canonical fields; never relay arbitrary stored JSON keys.
        return ['status' => $status, 'data' => [
            'sent' => true,
            'deliveryStatus' => 'stored',
            'createdAt' => $data['createdAt'],
            'message' => $data['message'],
        ]];
    }

    private static function projectListDTO(array $row): array
    {
        $brief = is_array($row['brief'] ?? null) ? $row['brief'] : [];
        $publicId = (string) ($row['public_id'] ?? '');
        $industry = self::briefString($brief, 'industry');
        $purpose = self::briefString($brief, 'purpose');
        $style = self::briefString($brief, 'style');
        $timeline = self::briefString($brief, 'timeline');
        $features = isset($brief['features']) && is_array($brief['features'])
            ? array_values(array_filter($brief['features'], 'is_string'))
            : [];
        $colors = is_array($brief['colors'] ?? null) ? $brief['colors'] : [];
        $palettes = is_array($colors['palettes'] ?? null) ? array_values(array_filter($colors['palettes'], 'is_string')) : [];
        $content = is_array($brief['content'] ?? null) ? $brief['content'] : [];
        $messenger = self::briefString($brief, 'messenger');
        $messengerId = self::briefString($brief, 'messengerId');
        return [
            'id' => $publicId,
            'publicId' => $publicId,
            'trackingCode' => (string) ($row['tracking_code'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'projectType' => (string) ($row['project_type'] ?? ''),
            'industry' => $industry,
            'industryLabel' => NeoCatalog::label('industries', $industry),
            'purpose' => $purpose,
            'purposeLabel' => NeoCatalog::purposeLabel($industry, $purpose),
            'style' => $style,
            'styleLabel' => NeoCatalog::label('styles', $style),
            'timeline' => $timeline,
            'timelineLabel' => NeoCatalog::label('timelines', $timeline),
            'paletteLabels' => array_map(static fn (string $palette): string => NeoCatalog::label('palettes', $palette), $palettes),
            'featuresLabels' => array_map(static fn (string $feature): string => NeoCatalog::label('features', $feature), $features),
            'contentLabels' => NeoCatalog::contentLabels($content),
            'messengerLabel' => $messenger !== '' ? NeoCatalog::label('messengers', $messenger) : '',
            'status' => (string) ($row['status'] ?? 'submitted'),
            'statusLabel' => self::statusLabel((string) ($row['status'] ?? 'submitted')),
            'progress' => max(0, min(100, (int) ($row['progress'] ?? 0))),
            'createdAt' => self::isoUtc($row['created_at'] ?? null),
            'submittedAt' => self::isoUtc($row['submitted_at'] ?? null),
            'updatedAt' => self::isoUtc($row['updated_at'] ?? null),
            'archived' => is_string($row['archived_at'] ?? null) && $row['archived_at'] !== '',
        ];
    }

    private static function projectDetailDTO(array $row): array
    {
        $dto = self::projectListDTO($row);
        $status = (string) ($row['status'] ?? '');
        $reason = $row['status_reason'] ?? null;
        $reasonLength = is_string($reason) ? preg_match_all('/./us', $reason) : false;
        $dto['statusReason'] = in_array($status, ['needs_information', 'rejected', 'cancelled'], true)
            && is_string($reason) && trim($reason) !== '' && $reasonLength !== false && $reasonLength <= 500
            ? trim($reason)
            : null;
        $dto['brief'] = self::publicBrief(is_array($row['brief'] ?? null) ? $row['brief'] : []);
        return $dto;
    }

    private static function publicBrief(array $brief): array
    {
        $result = [];
        foreach (['industry','purpose','style','timeline','fullName','phone','email','messenger','messengerId','userNote'] as $field) {
            if (isset($brief[$field]) && is_string($brief[$field])) {
                $result[$field] = $brief[$field];
            }
        }
        if (isset($brief['features']) && is_array($brief['features'])) {
            $result['features'] = array_values(array_filter($brief['features'], 'is_string'));
        }
        if (isset($brief['colors']) && is_array($brief['colors'])) {
            $colors = $brief['colors'];
            $result['colors'] = [
                'palettes' => isset($colors['palettes']) && is_array($colors['palettes']) ? array_values(array_filter($colors['palettes'], 'is_string')) : [],
                'customColors' => isset($colors['customColors']) && is_array($colors['customColors']) ? array_values(array_filter($colors['customColors'], 'is_string')) : [],
            ];
        }
        if (isset($brief['content']) && is_array($brief['content'])) {
            $content = [];
            foreach (['logo','text','images'] as $field) {
                if (isset($brief['content'][$field]) && is_string($brief['content'][$field])) $content[$field] = $brief['content'][$field];
            }
            $result['content'] = $content;
        }
        return $result;
    }

    private static function briefString(array $brief, string $field): string
    {
        return isset($brief[$field]) && is_string($brief[$field]) ? $brief[$field] : '';
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'submitted' => 'ثبت‌شده',
            'under_review' => 'در حال بررسی',
            'needs_information' => 'نیازمند اطلاعات',
            'in_progress' => 'در حال انجام',
            'waiting_for_client' => 'منتظر پاسخ شما',
            'completed' => 'تکمیل‌شده',
            'rejected' => 'ردشده',
            'cancelled' => 'لغوشده',
            default => 'وضعیت نامشخص',
        };
    }

    private static function milestoneName(string $code, string $storedName): string
    {
        return match ($code) {
            'intake' => 'دریافت نیازمندی‌ها',
            'review' => 'بررسی درخواست',
            'design' => 'طراحی',
            'development' => 'توسعه',
            'qa' => 'کنترل کیفیت',
            'delivery' => 'تحویل پروژه',
            default => $storedName,
        };
    }

    private static function milestoneStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'در انتظار', 'active' => 'در حال انجام', 'completed' => 'تکمیل‌شده', default => 'نامشخص',
        };
    }

    private static function senderLabel(string $senderType): string
    {
        return match ($senderType) {
            'user' => 'شما', 'admin' => 'تیم MR NEON', default => 'سیستم',
        };
    }

    private static function publicEventLabel(string $type, ?string $toStatus): string
    {
        return match ($type) {
            'project.submitted' => 'درخواست پروژه ثبت شد.',
            'project.status_changed' => 'وضعیت پروژه به «' . self::statusLabel($toStatus ?? '') . '» تغییر کرد.',
            'project.milestone_changed' => 'پیشرفت پروژه به‌روزرسانی شد.',
            default => 'رویداد پروژه به‌روزرسانی شد.',
        };
    }

    private static function isoUtc(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('A canonical UTC timestamp is missing.');
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            throw new RuntimeException('A canonical UTC timestamp is invalid.');
        }
    }

    private static function isoUtcOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? self::isoUtc($value) : null;
    }
}
