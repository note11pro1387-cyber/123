<?php
declare(strict_types=1);

final class SessionMiddleware
{
    public static function start(): void
    {
        SessionManager::start();
    }

    /** @return array<string,mixed> */
    public static function requirePrincipal(string $expectedType): array
    {
        SessionManager::start();
        if (!in_array($expectedType, ['user', 'admin'], true)) {
            throw new LogicException('Unsupported principal policy.');
        }
        if (!isset($_SESSION['principal_type'])) {
            throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'برای ادامه وارد حساب شوید.');
        }
        $principal = SessionManager::currentPrincipal(Database::connection());
        if ($principal === null) {
            throw new ApiProblemException(401, 'AUTH_REQUIRED', 'Unauthorized', 'نشست معتبر نیست؛ دوباره وارد شوید.');
        }
        if (($principal['principalType'] ?? null) !== $expectedType) {
            throw new ApiProblemException(403, 'FORBIDDEN', 'Forbidden', 'برای این عملیات دسترسی ندارید.');
        }
        return $principal;
    }
}
