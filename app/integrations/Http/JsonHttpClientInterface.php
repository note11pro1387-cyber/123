<?php
declare(strict_types=1);

interface JsonHttpClientInterface
{
    /** Request and response bodies are ephemeral; callers must never log them wholesale. */
    public function post(string $url, array $headers, string $body, int $timeoutSeconds = 10): JsonHttpResponse;
}
