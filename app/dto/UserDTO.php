<?php
declare(strict_types=1);

final class UserDTO
{
    /** Explicit allowlist: never serializes a repository row directly. */
    public static function fromRepositoryRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (string) $row['id'] : '',
            'publicId' => isset($row['public_id']) && is_string($row['public_id']) ? $row['public_id'] : null,
            'username' => isset($row['username']) && is_string($row['username']) ? $row['username'] : '',
            'fullName' => isset($row['full_name']) && is_string($row['full_name']) ? $row['full_name'] : '',
            'phone' => isset($row['phone']) && is_string($row['phone']) ? $row['phone'] : '',
            'email' => isset($row['email']) && is_string($row['email']) ? $row['email'] : null,
            'role' => 'user',
        ];
    }
}
