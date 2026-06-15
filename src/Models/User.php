<?php
namespace Translink\Models;

class User
{
    public static function fromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'] ?? null,
            'role' => $row['role'],
            'image' => $row['image'] ?? null,
            'is_active' => (bool)($row['is_active'] ?? true),
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    public static function safe(array $row): array
    {
        unset($row['password_hash']);
        return $row;
    }
}
