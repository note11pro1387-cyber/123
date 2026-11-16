<?php
declare(strict_types=1);

/** Produces the same canonical identity lock names used by W4-P2 RegistrationService. */
final class RegistrationIdentityLockManager
{
    /** @return list<string> */
    public static function namesFromAttempt(array $attempt): array
    {
        $identities = [];
        foreach ([
            'username:' . (string) ($attempt['username_normalized'] ?? ''),
            'phone:' . (string) ($attempt['phone_normalized'] ?? ''),
        ] as $identity) {
            if (substr($identity, strpos($identity, ':') + 1) === '') {
                throw new InvalidArgumentException('Registration identity lock context is incomplete.');
            }
            $identities[] = $identity;
        }
        if (isset($attempt['email_normalized']) && is_string($attempt['email_normalized']) && $attempt['email_normalized'] !== '') {
            $identities[] = 'email:' . $attempt['email_normalized'];
        }
        sort($identities, SORT_STRING);
        return array_map(static fn(string $identity): string => 'mrnreg_' . substr(hash('sha256', $identity), 0, 56), $identities);
    }

    /** @return list<string> */
    public static function acquire(PDO $pdo, array $names, int $waitSeconds = 3): array
    {
        $acquired = [];
        try {
            foreach ($names as $name) {
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name)) {
                    throw new InvalidArgumentException('Registration lock name is invalid.');
                }
                $statement = $pdo->prepare('SELECT GET_LOCK(?, ?)');
                $statement->execute([$name, $waitSeconds]);
                if ((string) $statement->fetchColumn() !== '1') {
                    throw new ApiProblemException(409, 'REGISTRATION_REQUEST_IN_PROGRESS', 'Conflict', 'درخواست ثبت‌نام مشابهی در حال پردازش است؛ کمی بعد دوباره تلاش کنید.');
                }
                $acquired[] = $name;
            }
            return $acquired;
        } catch (Throwable $exception) {
            self::release($pdo, $acquired);
            throw $exception;
        }
    }

    public static function release(PDO $pdo, array $names): void
    {
        foreach (array_reverse($names) as $name) {
            try {
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$name]);
            } catch (Throwable $exception) {
                error_log('MR NEON lock release warning: requestId=' . ApiResponse::requestId() . ' type=' . get_class($exception));
            }
        }
    }
}
