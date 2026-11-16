<?php
declare(strict_types=1);

final class AuthController
{
    public function __construct(private readonly AuthService $service = new AuthService())
    {
    }

    public function dispatch(string $action): never
    {
        $allowedMethods = [
            'getSession' => 'GET',
            'login' => 'POST',
            'logout' => 'POST',
        ];
        $method = ApiRequest::method();
        $expected = $allowedMethods[$action] ?? null;
        if (!is_string($expected)) {
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
        }
        if ($method !== $expected) {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای این عملیات مجاز نیست.', ['Allow' => $expected]);
        }
        try {
            if ($action === 'getSession') {
                ApiResponse::success($this->service->getSession());
            }
            $body = ApiRequest::jsonBody();
            if ($action === 'login') ApiResponse::success($this->service->login($body));
            if ($action === 'logout') ApiResponse::success($this->service->logout());
            ApiResponse::problem(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON auth failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'AUTH_SERVICE_UNAVAILABLE', 'Service unavailable', 'سرویس احراز هویت موقتاً در دسترس نیست.');
        }
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not found',
            405 => 'Method not allowed', 413 => 'Content too large', 415 => 'Unsupported media type',
            422 => 'Unprocessable content', 429 => 'Too many requests', default => 'Service unavailable',
        };
    }
}
