<?php
declare(strict_types=1);

/** Strictly extracts private-chat /start payloads for the configured bot; ignores all other updates. */
final class TelegramUpdateParser
{
    /** @return array{updateId:int,token:string,telegramUserId:string,telegramChatId:string}|null */
    public static function linkCandidate(array $update, string $botUsername): ?array
    {
        $updateId = $update['update_id'] ?? null;
        if (!is_int($updateId) || $updateId < 0) return null;
        $message = $update['message'] ?? null;
        if (!is_array($message) || !is_array($message['chat'] ?? null) || !is_array($message['from'] ?? null)) return null;
        if (($message['chat']['type'] ?? null) !== 'private' || !is_string($message['text'] ?? null)) return null;
        $chatId = self::numericProviderId($message['chat']['id'] ?? null);
        $userId = self::numericProviderId($message['from']['id'] ?? null);
        if ($chatId === null || $userId === null || !hash_equals($chatId, $userId)
            || ($message['from']['is_bot'] ?? false) === true || isset($message['sender_chat'])) return null;
        $text = $message['text'];
        if (strlen($text) > 512 || preg_match('/[\r\n\0]/', $text)) return null;
        if (!preg_match('/^\/start(?:@([A-Za-z0-9_]{5,32}))?\s+([A-Za-z0-9_-]{1,64})$/D', trim($text), $matches)) return null;
        if (isset($matches[1]) && $matches[1] !== '' && strcasecmp($matches[1], $botUsername) !== 0) return null;
        $token = $matches[2];
        // Current registration emits 64 lowercase hex characters. Do not accept loose arbitrary tokens.
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        return ['updateId' => $updateId, 'token' => $token, 'telegramUserId' => $userId, 'telegramChatId' => $chatId];
    }

    private static function numericProviderId(mixed $value): ?string
    {
        if (is_int($value) && $value > 0) $value = (string) $value;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,19}$/D', $value)) return null;
        return $value;
    }
}
