<?php
declare(strict_types=1);

final class MethodPolicy
{
    /** Explicit HTTP-method allowlist; action dispatch owns each route's activation policy. */
    private const METHODS = [
        'register' => 'POST', 'login' => 'POST', 'adminLogin' => 'POST', 'getSession' => 'GET', 'logout' => 'POST',
        'forgotPassword' => 'POST', 'verifyResetCode' => 'POST', 'resetPassword' => 'POST', 'sendVerificationCode' => 'POST',
        'verifyRegisterCode' => 'POST', 'resendVerificationCode' => 'POST', 'telegramLinkStatus' => 'POST', 'telegramWebhook' => 'POST', 'saveNeo' => 'POST',
        'getProjects' => 'GET', 'getProjectById' => 'GET', 'updateProject' => 'POST', 'deleteProject' => 'POST',
        'adminUpdate' => 'POST', 'getAll' => 'GET', 'getAllUsers' => 'GET', 'getAdminUserDetail' => 'GET', 'getAdminStats' => 'GET', 'getAdminProjectDetail' => 'GET', 'adminUpdateUser' => 'POST', 'getNotifications' => 'GET',
        'getAdminNotifications' => 'GET', 'getAdminMessages' => 'GET', 'getAdminAuditLog' => 'GET', 'getAdminSettingsSummary' => 'GET', 'markAdminNotificationRead' => 'POST', 'exportProjectsCsv' => 'GET',
        'markNotificationRead' => 'POST', 'markCustomerMessagesRead' => 'POST', 'markAdminMessagesRead' => 'POST',
        'getActiveProjectsCount' => 'GET', 'deleteRequest' => 'POST',
        'adminSendMessage' => 'POST',
        'changeUsername' => 'POST', 'changePassword' => 'POST', 'sendMessage' => 'POST', 'delete' => 'POST',
        'verifyPasswordResetCode' => 'POST',
    ];

    public static function enforce(string $action, string $method): void
    {
        $expected = self::METHODS[$action] ?? null;
        if ($expected === null) return;
        if ($method !== $expected) {
            throw new ApiProblemException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed', 'روش درخواست برای این عملیات مجاز نیست.', ['Allow' => $expected]);
        }
    }

    public static function known(string $action): bool
    {
        return isset(self::METHODS[$action]);
    }
}
