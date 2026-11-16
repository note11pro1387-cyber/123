<?php
declare(strict_types=1);

final class CsrfMiddleware
{
    public static function assertMutationAllowed(): void
    {
        Csrf::assertValid();
    }
}
