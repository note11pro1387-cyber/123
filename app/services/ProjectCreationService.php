<?php
declare(strict_types=1);

/** Canonical project creation; caller has authenticated the session and validated CSRF + DTO. */
final class ProjectCreationService
{
    private const IDEMPOTENCY_SCOPE_TYPE = 'user';
    private const IDEMPOTENCY_ROUTE = 'POST:api.php?action=saveNeo';
    private const ACTIVE_PROJECT_LIMIT = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{status:int,data:array<string,mixed>,replayed:bool} */
    public function create(int $ownerUserId, string $ownerPublicId, ProjectCreationDTO $dto, string $rawKey, string $requestId, ?string $clientIp = null, ?string $userAgent = null): array
    {
        if ($ownerUserId < 1 || $ownerPublicId === '' || !preg_match('/^[a-f0-9]{32}$/D', $ownerPublicId)) {
            throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست معتبر نیست؛ دوباره وارد حساب شوید.');
        }
        if ($rawKey === '' || strlen($rawKey) > 256 || trim($rawKey) === '' || preg_match('/[\r\n\0]/', $rawKey)) {
            throw new ApiProblemException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Bad request', 'برای ثبت ایمن پروژه، کلید Idempotency-Key معتبر لازم است.');
        }
        if ($requestId === '' || strlen($requestId) > 64) {
            throw new ApiProblemException(500, 'REQUEST_CONTEXT_UNAVAILABLE', 'Internal error', 'شناسهٔ درخواست در دسترس نیست.');
        }

        $scopeId = (string) $ownerUserId;
        $requestHash = $dto->requestHash();
        $projects = new ProjectRepository($this->pdo);
        $users = new UserRepository($this->pdo);
        $milestones = new ProjectMilestoneRepository($this->pdo);
        $events = new ProjectEventRepository($this->pdo);
        $audit = new AuditLogRepository($this->pdo);
        $outbox = new OutboxRepository($this->pdo);
        $idempotency = new IdempotencyKeyRepository($this->pdo);

        if (!$this->pdo->beginTransaction()) {
            throw new RuntimeException('Project creation transaction could not be started.');
        }
        try {
            // Locks the authoritative owner row, serializing active-count + creation for this user.
            if (!$users->lockActiveForProjectCreation($ownerUserId)) {
                throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'حساب فعال نیست یا نشست معتبر نیست.');
            }

            $existing = $idempotency->find(self::IDEMPOTENCY_SCOPE_TYPE, $scopeId, self::IDEMPOTENCY_ROUTE, $rawKey);
            if ($existing !== null) {
                $replay = self::resolveExisting($existing, $requestHash);
                if (!$this->pdo->commit()) {
                    throw new RuntimeException('Idempotent replay transaction could not be committed.');
                }
                return $replay;
            }

            $reserved = $idempotency->reserve(
                self::IDEMPOTENCY_SCOPE_TYPE,
                $scopeId,
                self::IDEMPOTENCY_ROUTE,
                $rawKey,
                $requestHash,
                gmdate('Y-m-d H:i:s', time() + 86400)
            );
            if (!$reserved) {
                // A concurrent request may have inserted the unique scoped key. Roll back before re-reading.
                $this->pdo->rollBack();
                return $this->resolveAfterReservationRace($idempotency, $scopeId, $rawKey, $requestHash);
            }

            if ($projects->countActiveForOwner($ownerUserId) >= self::ACTIVE_PROJECT_LIMIT) {
                throw new ApiProblemException(429, 'PROJECT_LIMIT_REACHED', 'Project limit reached', 'حداکثر سه پروژهٔ فعال قابل ثبت است. ابتدا وضعیت پروژه‌های قبلی را بررسی کنید.');
            }

            $project = $projects->create($ownerUserId, $dto->title, $dto->projectType, $dto->brief());
            $projectId = (int) $project['id'];
            $milestoneCount = $milestones->createDefaults($projectId);
            if ($milestoneCount !== 6) throw new RuntimeException('Default project milestone set is incomplete.');

            $events->append(
                $projectId,
                'user',
                $ownerPublicId,
                'project.submitted',
                null,
                'submitted',
                'درخواست پروژه ثبت شد.',
                ['projectType' => $dto->projectType, 'trackingCode' => $project['tracking_code']],
                $requestId
            );
            $ipHash = ($clientIp !== null && $clientIp !== '' && $clientIp !== 'unknown') ? hash('sha256', $clientIp) : null;
            $agentHash = ($userAgent !== null && $userAgent !== '') ? hash('sha256', $userAgent) : null;
            $audit->append('user', $ownerPublicId, 'project.created', 'project', (string) $project['public_id'], $requestId, $ipHash, $agentHash, [
                'projectType' => $dto->projectType,
                'milestonesCreated' => $milestoneCount,
            ]);
            // In-app admin notice uses only the canonical server-configured admin principal.
            // If that identity is not configured, no synthetic recipient is invented.
            (new NotificationRepository($this->pdo))->createForConfiguredAdmin(
                $projectId,
                'project_created',
                'درخواست پروژه جدید',
                'درخواست جدید با کد پیگیری ' . (string) $project['tracking_code'] . ' ثبت شد.',
                '/#admin-panel'
            );

            // Queue only. No provider adapter/worker is invoked by P1.
            $outbox->enqueue('project_created', 'project', (string) $projectId, 'telegram', [
                'projectPublicId' => $project['public_id'],
                'trackingCode' => $project['tracking_code'],
                'projectType' => $dto->projectType,
            ], 'project-created:' . $project['public_id']);

            $responseData = [
                'projectId' => $project['public_id'],
                'trackingCode' => $project['tracking_code'],
                'title' => $dto->title,
                'projectType' => $dto->projectType,
                'status' => 'submitted',
                'progress' => 0,
                'createdAt' => self::isoUtc((string) $project['created_at']),
            ];
            $safeResponse = ['data' => $responseData];
            if (!$idempotency->complete(self::IDEMPOTENCY_SCOPE_TYPE, $scopeId, self::IDEMPOTENCY_ROUTE, $rawKey, $requestHash, 201, $safeResponse)) {
                throw new RuntimeException('Project idempotency record could not be completed.');
            }
            if (!$this->pdo->commit()) {
                throw new RuntimeException('Project creation transaction could not be committed.');
            }
            return ['status' => 201, 'data' => $responseData, 'replayed' => false];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable) { /* preserve the original safe failure */ }
            }
            throw $exception;
        }
    }

    private function resolveAfterReservationRace(IdempotencyKeyRepository $idempotency, string $scopeId, string $rawKey, string $requestHash): array
    {
        $existing = $idempotency->find(self::IDEMPOTENCY_SCOPE_TYPE, $scopeId, self::IDEMPOTENCY_ROUTE, $rawKey);
        if ($existing === null) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'وضعیت ثبت درخواست قابل تأیید نیست؛ با همان کلید دوباره تلاش کنید.');
        }
        return self::resolveExisting($existing, $requestHash);
    }

    /** @param array<string,mixed> $existing @return array{status:int,data:array<string,mixed>,replayed:bool} */
    private static function resolveExisting(array $existing, string $requestHash): array
    {
        $priorHash = (string) ($existing['request_hash'] ?? '');
        if (!hash_equals($priorHash, $requestHash)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_KEY_REUSED', 'Conflict', 'این کلید قبلاً برای محتوای متفاوتی استفاده شده است؛ برای درخواست جدید پس از تعیین تکلیف درخواست قبلی کلید تازه‌ای بسازید.');
        }
        if (($existing['state'] ?? '') !== 'completed' || !is_string($existing['response_json'] ?? null)) {
            throw new ApiProblemException(409, 'IDEMPOTENCY_IN_PROGRESS', 'Conflict', 'نتیجهٔ درخواست قبلی هنوز قطعی نیست؛ با همان محتوا و همان کلید دوباره تلاش کنید.');
        }
        try {
            $body = json_decode($existing['response_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت قبلی قابل بازیابی نیست؛ شناسهٔ درخواست را برای پشتیبانی نگه دارید.');
        }
        if (!is_array($body) || !is_array($body['data'] ?? null)) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت قبلی قابل بازیابی نیست؛ شناسهٔ درخواست را برای پشتیبانی نگه دارید.');
        }
        $status = filter_var($existing['response_status'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($status) || $status < 200 || $status >= 300) {
            throw new ApiProblemException(503, 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Service unavailable', 'نتیجهٔ ثبت قبلی قابل بازیابی نیست؛ شناسهٔ درخواست را برای پشتیبانی نگه دارید.');
        }
        return ['status' => $status, 'data' => $body['data'], 'replayed' => true];
    }

    private static function isoUtc(string $databaseTimestamp): string
    {
        try {
            return (new DateTimeImmutable($databaseTimestamp, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            throw new RuntimeException('Canonical project timestamp is invalid.');
        }
    }
}
