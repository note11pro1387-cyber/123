<?php
declare(strict_types=1);

/** Atomically creates the verified account after a registration OTP has been accepted. */
final class RegistrationCompletionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Must be called inside VerificationService's transaction while the attempt and challenge rows
     * are locked. This method never begins, commits, or rolls back a transaction on its own.
     * @return array<string,mixed>
     */
    public function completeWithinTransaction(array $attempt, array $challenge): array
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('Registration completion requires a caller-owned transaction.');
        }
        $attemptId = (string) ($attempt['id'] ?? '');
        $challengeId = (string) ($challenge['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || !preg_match('/^[a-f0-9]{32}$/D', $challengeId)
            || ($attempt['selected_channel'] ?? null) !== ($challenge['channel_type'] ?? null)
            || ($challenge['state'] ?? null) !== 'active'
            || ($challenge['purpose'] ?? null) !== 'registration'
            || ($challenge['registration_attempt_id'] ?? null) !== $attemptId) {
            throw new ApiProblemException(409, 'VERIFICATION_ATTEMPT_INVALID', 'Conflict', 'درخواست تأیید معتبر یا قابل تکمیل نیست.');
        }

        $channel = (string) $attempt['selected_channel'];
        if (!in_array($channel, ['email', 'telegram'], true)) {
            throw new ApiProblemException(409, 'VERIFICATION_ATTEMPT_INVALID', 'Conflict', 'درخواست تأیید معتبر یا قابل تکمیل نیست.');
        }
        $target = $this->selectedTarget($attempt);
        if ($target === null || !hash_equals((string) ($challenge['destination_normalized'] ?? ''), $target['normalized'])) {
            throw new ApiProblemException(409, 'VERIFICATION_ATTEMPT_INVALID', 'Conflict', 'درخواست تأیید معتبر یا قابل تکمیل نیست.');
        }

        $users = new UserRepository($this->pdo);
        $channels = new UserChannelRepository($this->pdo);
        $attempts = new RegistrationAttemptRepository($this->pdo);
        $challenges = new VerificationChallengeRepository($this->pdo);
        $audit = new AuditLogRepository($this->pdo);

        if ($users->hasRegistrationIdentity((string) $attempt['username_normalized'], (string) $attempt['phone_normalized'])) {
            throw self::registrationConflict();
        }
        $email = isset($attempt['email_normalized']) && is_string($attempt['email_normalized']) && $attempt['email_normalized'] !== ''
            ? $attempt['email_normalized'] : null;
        if ($email !== null && $channels->existsByNormalizedValue('email', $email)) {
            throw self::registrationConflict();
        }

        // The only stored password material is the W4-P2 password_hash. No raw password is accepted here.
        $created = $users->create([
            'full_name' => (string) $attempt['full_name'],
            'username' => (string) $attempt['username'],
            'phone' => (string) $attempt['phone_normalized'],
            'password_hash' => (string) $attempt['password_hash'],
            'account_status' => 'active',
            'legacy_verified' => false,
            'terms_version' => $attempt['terms_version'] ?? null,
            'terms_accepted_at' => $attempt['terms_accepted_at'] ?? null,
        ]);
        $userId = (int) $created['id'];

        if ($channel === 'email') {
            if ($email === null || !hash_equals($email, $target['normalized'])) {
                throw new ApiProblemException(409, 'VERIFICATION_ATTEMPT_INVALID', 'Conflict', 'درخواست تأیید معتبر یا قابل تکمیل نیست.');
            }
            $channels->create($userId, 'email', $email, true, null, true);
        } else {
            $telegramUserId = (string) ($attempt['linked_telegram_user_id'] ?? '');
            $telegramChatId = (string) ($attempt['linked_telegram_chat_id'] ?? '');
            if (!preg_match('/^[0-9]{1,128}$/D', $telegramUserId)
                || !preg_match('/^[0-9]{1,128}$/D', $telegramChatId)
                || !hash_equals($telegramChatId, $target['normalized'])) {
                throw new ApiProblemException(409, 'TELEGRAM_DESTINATION_NOT_LINKED', 'Conflict', 'ابتدا باید اتصال امن تلگرام تکمیل شود.');
            }
            // Schema has no username field for a newly linked Telegram identity: the canonical chat ID
            // is the stored channel value and the trusted provider user ID is the unique provider subject.
            $channels->create($userId, 'telegram', $telegramChatId, true, $telegramUserId, true);
            if ($email !== null) {
                // Optional e-mail supplied on a Telegram registration is retained as unverified/non-primary.
                $channels->create($userId, 'email', $email, false, null, false);
            }
        }

        if (!$challenges->consumeOnce($challengeId)) {
            throw new RuntimeException('Challenge could not be consumed atomically with account completion.');
        }
        if (!$attempts->consumeOnce($attemptId, $userId)) {
            throw new RuntimeException('Registration attempt could not be consumed atomically with account completion.');
        }
        $audit->append(
            'user',
            (string) $created['public_id'],
            'registration.completed',
            'user',
            (string) $userId,
            ApiResponse::requestId(),
            null,
            null,
            ['selectedChannel' => $channel, 'result' => 'completed']
        );

        $profile = $users->findById($userId);
        if (!is_array($profile) || ($profile['account_status'] ?? null) !== 'active') {
            throw new RuntimeException('Created account could not be read before transaction commit.');
        }
        return [
            'registered' => true,
            'authenticated' => false,
            'sessionEstablished' => false,
            'user' => UserDTO::fromRepositoryRow($profile),
        ];
    }

    /** @return array{value:string,normalized:string}|null */
    private function selectedTarget(array $attempt): ?array
    {
        if (($attempt['selected_channel'] ?? null) === 'email') {
            $email = $attempt['email_normalized'] ?? null;
            return is_string($email) && $email !== '' ? ['value' => $email, 'normalized' => strtolower($email)] : null;
        }
        if (($attempt['selected_channel'] ?? null) === 'telegram') {
            $chatId = $attempt['linked_telegram_chat_id'] ?? null;
            return is_string($chatId) && preg_match('/^[0-9]{1,128}$/D', $chatId) === 1
                ? ['value' => $chatId, 'normalized' => $chatId]
                : null;
        }
        return null;
    }

    private static function registrationConflict(): ApiProblemException
    {
        return new ApiProblemException(409, 'REGISTRATION_CONFLICT', 'Conflict', 'درخواست ثبت‌نام با اطلاعات واردشده قابل تکمیل نیست.');
    }
}
