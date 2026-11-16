<?php
declare(strict_types=1);

final class JsonHttpResponse
{
    public function __construct(public readonly int $statusCode, public readonly string $body)
    {
    }
}
