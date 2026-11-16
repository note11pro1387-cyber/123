<?php
declare(strict_types=1);

/** API boundary for start/resend/verify registration challenge actions only. Recovery remains closed. */
final class VerificationController
{
    public function __construct(private readonly ?VerificationService $service = null)
    {
    }

    public function dispatch(string $action): never
    {
        if (!in_array($action, ['sendVerificationCode', 'resendVerificationCode', 'verifyRegisterCode'], true)) {
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'عملیات تأیید ثبت‌نام شناخته نشد.');
        }
        if (ApiRequest::method() !== 'POST') {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای تأیید ثبت‌نام مجاز نیست.', ['Allow' => 'POST']);
        }

        try {
            // Public registration endpoints use the existing anonymous-session CSRF contract.
            SessionManager::start();
            Csrf::assertValid();
            $pdo = Database::connection();
            $input = ApiRequest::jsonBody(8192);
            $service = $this->service ?? new VerificationService($pdo, VerificationTransportFactory::fromEnvironment());
            if ($action === 'verifyRegisterCode') {
                ApiResponse::success($service->verifyRegistration($input, ApiRequest::clientIp()), 201);
            }
            $data = $service->requestDelivery($input, ApiRequest::clientIp(), $action === 'resendVerificationCode');
            ApiResponse::success($data, !empty($data['reusedActiveChallenge']) ? 200 : 202);
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON verification failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'VERIFICATION_UNAVAILABLE', 'Service unavailable', 'عملیات تأیید در حال حاضر در دسترس نیست؛ وضعیت ثبت‌نام تکمیل‌شده فرض نشد.');
        }
    }
}
