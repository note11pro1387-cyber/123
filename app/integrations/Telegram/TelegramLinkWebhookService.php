<?php
declare(strict_types=1);

/** Processes Telegram updates with transactional idempotency and one-time Attempt nonce consumption. */
final class TelegramLinkWebhookService
{
    public function __construct(private readonly PDO $pdo, private readonly string $botUsername)
    {
        if (!preg_match('/^[A-Za-z0-9_]{5,32}$/D', $botUsername)) {
            throw new InvalidArgumentException('Telegram bot username configuration is invalid.');
        }
    }

    /** @return array{state:string,linked:bool} */
    public function handleUpdate(array $update): array
    {
        $updateId = $update['update_id'] ?? null;
        if (!is_int($updateId) || $updateId < 0) {
            throw new InvalidArgumentException('Telegram update identifier is invalid.');
        }
        $canonical = self::canonicalize($update);
        $encoded = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $requestHash = hash('sha256', $encoded);
        $scopeId = strtolower($this->botUsername);
        $rawUpdateKey = (string) $updateId;
        $idempotency = new IdempotencyKeyRepository($this->pdo);
        $attempts = new RegistrationAttemptRepository($this->pdo);
        $heldLocks = [];
        $this->beginTransaction();
        try {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 2592000);
            $reserved = $idempotency->reserve('telegram_update', $scopeId, 'webhook', $rawUpdateKey, $requestHash, $expiresAt);
            if (!$reserved) {
                $existing = $idempotency->find('telegram_update', $scopeId, 'webhook', $rawUpdateKey);
                if (is_array($existing) && hash_equals((string) ($existing['request_hash'] ?? ''), $requestHash)
                    && ($existing['state'] ?? null) === 'completed') {
                    $this->pdo->commit();
                    return ['state' => 'duplicate', 'linked' => false];
                }
                // Same update_id with a different body must never overwrite or re-run the original update.
                $this->pdo->commit();
                return ['state' => 'duplicate_mismatch', 'linked' => false];
            }

            $candidate = TelegramUpdateParser::linkCandidate($update, $this->botUsername);
            if ($candidate === null) {
                $this->completeIdempotency($idempotency, $scopeId, $rawUpdateKey, $requestHash, ['state' => 'ignored']);
                $this->pdo->commit();
                return ['state' => 'ignored', 'linked' => false];
            }

            $nonceHash = hash('sha256', $candidate['token']);
            $attempt = $attempts->findTelegramAttemptByNonceHashForUpdate($nonceHash);
            if (!is_array($attempt) || ($attempt['selected_channel'] ?? null) !== 'telegram') {
                $this->completeIdempotency($idempotency, $scopeId, $rawUpdateKey, $requestHash, ['state' => 'ignored']);
                $this->pdo->commit();
                return ['state' => 'ignored', 'linked' => false];
            }

            $identityLockName = 'mrntg_' . substr(hash('sha256', $candidate['telegramUserId']), 0, 58);
            $heldLocks = RegistrationIdentityLockManager::acquire($this->pdo, [$identityLockName], 3);
            if ($attempts->telegramIdentityLinkedToOtherOpenAttempt((string) $attempt['id'], $candidate['telegramUserId'], $candidate['telegramChatId'])) {
                $this->completeIdempotency($idempotency, $scopeId, $rawUpdateKey, $requestHash, ['state' => 'identity_conflict']);
                $this->pdo->commit();
                return ['state' => 'identity_conflict', 'linked' => false];
            }
            $linked = $attempts->linkTelegramIdentity(
                (string) $attempt['id'],
                $candidate['telegramUserId'],
                $candidate['telegramChatId'],
                $nonceHash
            );
            if (!$linked) {
                $this->completeIdempotency($idempotency, $scopeId, $rawUpdateKey, $requestHash, ['state' => 'ignored']);
                $this->pdo->commit();
                return ['state' => 'ignored', 'linked' => false];
            }

            (new AuditLogRepository($this->pdo))->append(
                'anonymous', null, 'registration.telegram_linked', 'registration_attempt', (string) $attempt['id'],
                ApiResponse::requestId(), null, null,
                ['channel' => 'telegram', 'result' => 'linked', 'source' => 'telegram_webhook']
            );
            $this->completeIdempotency($idempotency, $scopeId, $rawUpdateKey, $requestHash, ['state' => 'linked']);
            $this->pdo->commit();
            return ['state' => 'linked', 'linked' => true];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        } finally {
            if ($heldLocks !== []) RegistrationIdentityLockManager::release($this->pdo, $heldLocks);
        }
    }

    private function completeIdempotency(IdempotencyKeyRepository $repository, string $scopeId, string $rawKey, string $requestHash, array $safeResponse): void
    {
        if (!$repository->complete('telegram_update', $scopeId, 'webhook', $rawKey, $requestHash, 200, $safeResponse)) {
            throw new RuntimeException('Telegram update idempotency could not be committed.');
        }
    }

    private function beginTransaction(): void
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw new RuntimeException('Telegram webhook transaction could not be started.');
        }
    }

    private static function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) $value[$key] = self::canonicalize($item);
        }
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }
}
