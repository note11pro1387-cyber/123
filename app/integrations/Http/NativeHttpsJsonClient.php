<?php
declare(strict_types=1);

/** Small HTTPS-only JSON client. TLS peer/hostname verification is always enabled. */
final class NativeHttpsJsonClient implements JsonHttpClientInterface
{
    public function post(string $url, array $headers, string $body, int $timeoutSeconds = 10): JsonHttpResponse
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || strlen($body) > 131072) {
            throw new InvalidArgumentException('HTTPS request contract is invalid.');
        }
        $timeoutSeconds = max(1, min(15, $timeoutSeconds));
        $host = (string) $parts['host'];
        $headerLines = ['Content-Type: application/json; charset=utf-8', 'Accept: application/json', 'User-Agent: MR-NEON/1.0'];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name)
                || !is_string($value) || preg_match('/[\r\n\0]/', $value) || strlen($value) > 512) {
                throw new InvalidArgumentException('HTTPS request header is invalid.');
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines) . "\r\n",
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        // Suppress runtime warnings because they can contain the full URL (including a Bot API token).
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new RuntimeException('HTTPS_REQUEST_RESULT_UNKNOWN');
        }
        try {
            $responseBody = stream_get_contents($stream, 131073);
            $metadata = stream_get_meta_data($stream);
            if (!is_string($responseBody) || strlen($responseBody) > 131072) {
                throw new RuntimeException('HTTPS_RESPONSE_INVALID');
            }
            $statusCode = 0;
            foreach (($metadata['wrapper_data'] ?? []) as $line) {
                if (is_string($line) && preg_match('~^HTTP/\S+\s+(\d{3})\b~i', $line, $matches)) {
                    $statusCode = (int) $matches[1];
                }
            }
            if ($statusCode < 100 || $statusCode > 599) {
                throw new RuntimeException('HTTPS_RESPONSE_STATUS_UNKNOWN');
            }
            return new JsonHttpResponse($statusCode, $responseBody);
        } finally {
            fclose($stream);
        }
    }
}
