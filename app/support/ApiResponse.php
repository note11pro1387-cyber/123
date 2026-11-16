<?php
declare(strict_types=1);

final class ApiResponse
{
    private static string $requestId = '';

    public static function initializeRequestId(): string
    {
        self::$requestId = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            header('X-Request-ID: ' . self::$requestId);
        }
        return self::$requestId;
    }

    public static function requestId(): string
    {
        if (self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(16));
        }
        return self::$requestId;
    }

    public static function successPayload(mixed $data, ?string $requestId = null): array
    {
        return [
            'data' => $data,
            'meta' => ['requestId' => $requestId ?? self::requestId()],
        ];
    }

    public static function problemPayload(int $status, string $code, string $title, string $detail, ?string $requestId = null): array
    {
        $safeCode = preg_match('/^[A-Z][A-Z0-9_]{1,63}$/D', $code) ? $code : 'INTERNAL_ERROR';
        return [
            'type' => 'urn:mr-neon:problem:' . strtolower(str_replace('_', '-', $safeCode)),
            'title' => self::boundedText($title, 120),
            'status' => $status,
            'detail' => self::boundedText($detail, 500),
            'code' => $safeCode,
            'requestId' => $requestId ?? self::requestId(),
        ];
    }

    public static function success(mixed $data = null, int $status = 200): never
    {
        self::emit(self::successPayload($data), $status, 'application/json; charset=utf-8');
    }

    public static function problem(int $status, string $code, string $title, string $detail, array $headers = []): never
    {
        $payload = self::problemPayload($status, $code, $title, $detail);
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value) && preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) && !preg_match('/[\r\n]/', $value)) {
                header($name . ': ' . $value);
            }
        }
        self::emit($payload, $status, 'application/problem+json; charset=utf-8');
    }

    private static function emit(array $payload, int $status, string $contentType): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $contentType);
            header('Cache-Control: no-store, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/problem+json; charset=utf-8');
            }
            echo '{"type":"urn:mr-neon:problem:serialization-error","title":"Internal server error","status":500,"detail":"خطای داخلی سرور رخ داد.","code":"RESPONSE_SERIALIZATION_FAILED"}';
        }
        exit;
    }

    private static function boundedText(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
        return function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);
    }
}
