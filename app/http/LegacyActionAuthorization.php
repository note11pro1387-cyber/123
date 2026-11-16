<?php
declare(strict_types=1);

final class LegacyActionAuthorization
{
    private const ADMIN_ONLY = ['adminUpdate', 'getAll', 'getAllUsers', 'deleteRequest'];
    private const USER_ONLY = ['getProjects', 'getProjectById', 'updateProject', 'deleteProject', 'getNotifications', 'markNotificationRead', 'changeUsername', 'changePassword', 'sendMessage', 'delete'];
    private const STATE_CHANGING = ['register', 'forgotPassword', 'verifyResetCode', 'resetPassword', 'verifyPasswordResetCode', 'sendVerificationCode', 'verifyRegisterCode', 'resendVerificationCode', 'updateProject', 'deleteProject', 'adminUpdate', 'markNotificationRead', 'deleteRequest', 'changeUsername', 'changePassword', 'sendMessage', 'delete'];

    public static function enforce(string $action, string $method): void
    {
        if (in_array($action, self::ADMIN_ONLY, true)) {
            AdminAuthMiddleware::requireAdmin();
        } elseif (in_array($action, self::USER_ONLY, true)) {
            SessionMiddleware::requirePrincipal('user');
        }
        if ($method !== 'GET' && $method !== 'HEAD' && in_array($action, self::STATE_CHANGING, true)) {
            CsrfMiddleware::assertMutationAllowed();
        }
    }
}
