<?php
declare(strict_types=1);

final class ApiProblemException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly string $title,
        string $safeDetail,
        public readonly array $responseHeaders = []
    ) {
        parent::__construct($safeDetail);
    }
}
