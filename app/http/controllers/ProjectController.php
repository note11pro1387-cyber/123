<?php
declare(strict_types=1);

/** Narrow Wave 5/P1 activation of the canonical NEO creation and capacity actions. */
final class ProjectController
{
    public function dispatch(string $action): never
    {
        try {
            if ($action === 'getActiveProjectsCount') {
                $principal = SessionMiddleware::requirePrincipal('user');
                $ownerId = filter_var($principal['user']['id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($ownerId) || $ownerId < 1 || !hash_equals((string) ($principal['principalId'] ?? ''), (string) ($principal['user']['public_id'] ?? ''))) {
                    throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست معتبر نیست؛ دوباره وارد حساب شوید.');
                }
                $count = (new ProjectRepository(Database::connection()))->countActiveForOwner($ownerId);
                ApiResponse::success(['count' => $count, 'limit' => 3, 'canCreate' => $count < 3]);
            }
            if ($action !== 'saveNeo') {
                ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
            }

            SessionMiddleware::start();
            Csrf::assertValid();
            $input = ApiRequest::jsonBody(16384);
            // Full payload validation is completed before querying the auth/session registry or writing data.
            $dto = ProjectCreationDTO::fromArray($input);
            $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
            if (!is_string($key) || $key === '' || strlen($key) > 256 || trim($key) === '' || preg_match('/[\r\n\0]/', $key)) {
                throw new ApiProblemException(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Bad request', 'برای ثبت ایمن پروژه، کلید Idempotency-Key معتبر لازم است.');
            }
            $principal = SessionMiddleware::requirePrincipal('user');
            $ownerId = filter_var($principal['user']['id'] ?? null, FILTER_VALIDATE_INT);
            $ownerPublicId = $principal['principalId'] ?? null;
            if (!is_int($ownerId) || $ownerId < 1 || !is_string($ownerPublicId) || !hash_equals($ownerPublicId, (string) ($principal['user']['public_id'] ?? ''))) {
                throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست معتبر نیست؛ دوباره وارد حساب شوید.');
            }
            $service = new ProjectCreationService(Database::connection());
            $result = $service->create(
                $ownerId,
                $ownerPublicId,
                $dto,
                $key,
                ApiResponse::requestId(),
                ApiRequest::clientIp(),
                isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null
            );
            if ($result['replayed']) header('Idempotency-Replayed: true');
            ApiResponse::success($result['data'], $result['status']);
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON project route failure: requestId=' . ApiResponse::requestId() . ' action=' . $action . ' type=' . get_class($exception));
            if ($action === 'getActiveProjectsCount') {
                ApiResponse::problem(503, 'PROJECT_CAPACITY_UNAVAILABLE', 'Service unavailable', 'بررسی ظرفیت پروژه در دسترس نیست؛ بعداً دوباره تلاش کنید.');
            }
            ApiResponse::problem(503, 'PROJECT_CREATION_UNAVAILABLE', 'Service unavailable', 'ثبت پروژه در حال حاضر در دسترس نیست. اطلاعات پیش‌نویس حفظ می‌شود؛ بعداً با همان درخواست دوباره تلاش کنید.');
        }
    }
}
