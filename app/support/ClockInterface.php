<?php
declare(strict_types=1);

/** Server-side clock abstraction. Implementations must never read Client-controlled time. */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
