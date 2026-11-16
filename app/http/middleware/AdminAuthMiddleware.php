<?php
declare(strict_types=1);

final class AdminAuthMiddleware
{
    public static function requireAdmin(): array
    {
        return SessionMiddleware::requirePrincipal('admin');
    }
}
