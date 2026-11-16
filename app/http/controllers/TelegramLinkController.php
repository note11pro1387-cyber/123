<?php
declare(strict_types=1);

/** Authenticated-by-session-CSRF status endpoint; exposes no provider identifiers or account data. */
final class TelegramLinkController
{
    public function dispatchStatus(): never
    {
        if (ApiRequest::method() !== 'POST') {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای بررسی اتصال مجاز نیست.', ['Allow' => 'POST']);
        }
        try {
            SessionManager::start();
            Csrf::assertValid();
            $input = ApiRequest::jsonBody(8192);
            if (array_diff(array_keys($input), ['attemptId']) !== []) {
                throw new ApiProblemException(422, 'TELEGRAM_LINK_STATUS_INVALID', 'Unprocessable content', 'درخواست بررسی اتصال معتبر نیست.');
            }
            $attemptId = $input['attemptId'] ?? null;
            if (!is_string($attemptId) || !preg_match('/^[a-f0-9]{32}$/D', $attemptId)) {
                throw new ApiProblemException(422, 'TELEGRAM_LINK_STATUS_INVALID', 'Unprocessable content', 'شناسهٔ درخواست ثبت‌نام معتبر نیست.');
            }
            $pdo = Database::connection();
            RateLimitMiddleware::enforceTelegramLinkStatus($pdo, ApiRequest::clientIp(), $attemptId);
            $status = (new RegistrationAttemptRepository($pdo))->telegramLinkStatus($attemptId);
            if (!is_array($status) || $status['selected_channel'] !== 'telegram') {
                throw new ApiProblemException(404, 'REGISTRATION_ATTEMPT_UNAVAILABLE', 'Not found', 'درخواست ثبت‌نام برای اتصال Telegram در دسترس نیست.');
            }
            ApiResponse::success(['linked' => $status['linked'] === true]);
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON Telegram link status failed: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'TELEGRAM_LINK_STATUS_UNAVAILABLE', 'Service unavailable', 'وضعیت اتصال Telegram در حال حاضر قابل بررسی نیست.');
        }
    }
}
