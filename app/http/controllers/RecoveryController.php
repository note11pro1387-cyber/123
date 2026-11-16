<?php
declare(strict_types=1);

/** Safe Recovery API boundary. Reset-only authorization is never a normal Session. */
final class RecoveryController
{
    public function __construct(private readonly ?RecoveryService $service = null)
    {
    }

    public function dispatch(string $action): never
    {
        $allowed = ['forgotPassword', 'verifyResetCode', 'resetPassword', 'verifyPasswordResetCode'];
        if (!in_array($action, $allowed, true)) {
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'عملیات بازیابی شناخته نشد.');
        }
        if (ApiRequest::method() !== 'POST') {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای بازیابی مجاز نیست.', ['Allow' => 'POST']);
        }

        try {
            SessionManager::start();
            Csrf::assertValid();

            // Compatibility alias intentionally remains fail-closed. The new flow separates OTP proof
            // from Reset-only authorization and never accepts OTP + password in one legacy request.
            if ($action === 'verifyPasswordResetCode') {
                ApiResponse::problem(410, 'RECOVERY_FLOW_UPDATED', 'Gone', 'این مسیر قدیمی غیرفعال است؛ بازیابی را از ابتدا آغاز کنید.');
            }

            $pdo = Database::connection();
            $service = $this->service ?? new RecoveryService($pdo, VerificationTransportFactory::fromEnvironment());
            $ip = ApiRequest::clientIp();
            $input = ApiRequest::jsonBody(8192);

            if ($action === 'forgotPassword') {
                ApiResponse::success($service->startRecovery($input, $ip), 202);
            }
            if ($action === 'verifyResetCode') {
                ApiResponse::success($service->verifyCode($input, $ip));
            }

            $sessionPrincipalType = is_string($_SESSION['principal_type'] ?? null) ? $_SESSION['principal_type'] : null;
            $sessionUserId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            $rawToken = $_SERVER['HTTP_X_RESET_AUTHORIZATION'] ?? '';
            if (!is_string($rawToken)) $rawToken = '';
            $result = $service->resetPassword($rawToken, $input, $ip);
            $targetUserId = (int) ($result['_userId'] ?? 0);
            unset($result['_userId'], $result['revokedSessions']);

            // No login is created. If this browser happened to hold a session for the account just reset,
            // destroy its local PHP session too; server-side registry revocation already committed.
            if ($sessionPrincipalType === 'user' && is_int($sessionUserId) && $sessionUserId > 0 && $sessionUserId === $targetUserId) {
                SessionManager::invalidateCurrentLocally();
                $result['csrfToken'] = Csrf::token();
            }
            $result['message'] = 'رمز عبور بازنشانی شد. برای ادامه، با رمز جدید وارد شوید.';
            ApiResponse::success($result);
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON Recovery failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'RECOVERY_UNAVAILABLE', 'Service unavailable', 'عملیات بازیابی در حال حاضر در دسترس نیست؛ نتیجهٔ بازنشانی تکمیل‌شده فرض نشد.');
        }
    }
}
