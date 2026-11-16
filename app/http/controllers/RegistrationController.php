<?php
declare(strict_types=1);

final class RegistrationController
{
    public function __construct(private readonly ?RegistrationService $service = null)
    {
    }

    public function dispatch(): never
    {
        if (ApiRequest::method() !== 'POST') {
            ApiResponse::problem(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای ثبت‌نام مجاز نیست.', ['Allow' => 'POST']);
        }

        try {
            // Registration is public, but remains protected by the existing anonymous session CSRF contract.
            SessionManager::start();
            Csrf::assertValid();
            $pdo = Database::connection();
            $input = ApiRequest::jsonBody(16384);
            $service = $this->service ?? new RegistrationService($pdo);
            $result = $service->start($input, ApiRequest::clientIp());
            // Never return the raw one-time link nonce as a standalone field. It is disclosed only
            // inside the BotFather-configured public deep link, and only when webhook prerequisites exist.
            if (isset($result['telegramLinkNonce']) && is_string($result['telegramLinkNonce'])) {
                $nonce = $result['telegramLinkNonce'];
                unset($result['telegramLinkNonce']);
                $result['telegramLinkUrl'] = TelegramLinkConfiguration::deepLinkForNonce($nonce);
                $result['telegramLinkReady'] = is_string($result['telegramLinkUrl']);
            }
            ApiResponse::success($result, !empty($result['reusedExistingAttempt']) ? 200 : 201);
        } catch (ApiProblemException $exception) {
            ApiResponse::problem($exception->httpStatus, $exception->errorCode, $exception->title, $exception->getMessage(), $exception->responseHeaders);
        } catch (Throwable $exception) {
            error_log('MR NEON registration failure: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            ApiResponse::problem(503, 'REGISTRATION_UNAVAILABLE', 'Service unavailable', 'ثبت موقت اطلاعات در حال حاضر در دسترس نیست. اطلاعات تأیید یا ارسال نشده‌اند.');
        }
    }
}
