<?php
declare(strict_types=1);

/** Telegram's external webhook: secret-header authenticated, bounded, DB-idempotent, no user session/CSRF. */
final class TelegramWebhookController
{
    public function dispatch(): never
    {
        if (ApiRequest::method() !== 'POST') $this->respond(405, false);
        $expected = TelegramLinkConfiguration::webhookSecret();
        $provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        if ($expected === '') $this->respond(503, false);
        if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) $this->respond(403, false);

        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') $this->respond(415, false);
        $length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($length) && ($length < 0 || $length > 65536)) $this->respond(413, false);
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (!is_string($raw) || strlen($raw) > 65536) $this->respond(413, false);
        try {
            $update = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->respond(400, false);
        }
        if (!is_array($update) || !is_int($update['update_id'] ?? null) || $update['update_id'] < 0) $this->respond(400, false);
        $botUsername = TelegramLinkConfiguration::botUsername();
        if ($botUsername === '') $this->respond(503, false);

        try {
            (new TelegramLinkWebhookService(Database::connection(), $botUsername))->handleUpdate($update);
            $this->respond(200, true);
        } catch (Throwable $exception) {
            error_log('MR NEON Telegram webhook failed: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            // Non-2xx allows Telegram to retry transient DB failures; idempotency prevents replayed mutation.
            $this->respond(503, false);
        }
    }

    private function respond(int $status, bool $ok): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo $ok ? '{"ok":true}' : '{"ok":false}';
        exit;
    }
}
