<?php
declare(strict_types=1);

final class ApiRequest
{
    public static function action(): string
    {
        $action = $_GET['action'] ?? '';
        if (!is_string($action) || strlen($action) > 64 || !preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $action)) {
            throw new ApiProblemException(404, 'UNKNOWN_ACTION', 'Not found', 'درخواست API شناخته نشد.');
        }
        return $action;
    }

    public static function method(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new ApiProblemException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست پشتیبانی نمی‌شود.');
        }
        return $method;
    }

    /** @return array<string,mixed> */
    public static function jsonBody(int $maxBytes = 16384): array
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            throw new ApiProblemException(415, 'UNSUPPORTED_MEDIA_TYPE', 'Unsupported media type', 'نوع محتوای درخواست باید application/json باشد.');
        }
        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($contentLength) && $contentLength > $maxBytes) {
            throw new ApiProblemException(413, 'REQUEST_TOO_LARGE', 'Content too large', 'حجم درخواست بیش از حد مجاز است.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw)) {
            throw new ApiProblemException(400, 'REQUEST_READ_FAILED', 'Bad request', 'خواندن درخواست ممکن نشد.');
        }
        if (strlen($raw) > $maxBytes) {
            throw new ApiProblemException(413, 'REQUEST_TOO_LARGE', 'Content too large', 'حجم درخواست بیش از حد مجاز است.');
        }
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApiProblemException(400, 'INVALID_JSON', 'Bad request', 'ساختار JSON درخواست معتبر نیست.');
        }
        if (!$decoded instanceof stdClass) {
            throw new ApiProblemException(400, 'INVALID_REQUEST_SHAPE', 'Bad request', 'بدنهٔ درخواست باید یک شیء JSON باشد.');
        }
        return (array) $decoded;
    }

    public static function clientIp(): string
    {
        // Deliberately ignore X-Forwarded-For until trusted proxies are explicitly configured.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : 'unknown';
    }
}
