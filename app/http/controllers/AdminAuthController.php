<?php
declare(strict_types=1);

final class AdminAuthController
{
    public function dispatch(): never
    {
        if (ApiRequest::method() !== 'POST') {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای ورود مدیر باید POST باشد.', ['Allow' => 'POST']);
        }
        try {
            $body = ApiRequest::jsonBody();
            ApiResponse::success((new AuthService())->adminLogin($body));
        } catch (ApiProblemException $exception) {
            $title = match ($exception->httpStatus) {
                400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 413 => 'Content too large',
                415 => 'Unsupported media type', 422 => 'Unprocessable content', 429 => 'Too many requests',
                default => 'Service unavailable',
            };
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON admin auth failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'ADMIN_AUTH_UNAVAILABLE', 'Service unavailable', 'سرویس ورود مدیر موقتاً در دسترس نیست.');
        }
    }
}
