<?php
declare(strict_types=1);

final class RequestIdMiddleware
{
    public static function handle(): string
    {
        return ApiResponse::initializeRequestId();
    }
}
