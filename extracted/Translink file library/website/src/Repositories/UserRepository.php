<?php
namespace Translink\Repositories;

use Translink\Database;
use Translink\Models\User;

class UserRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByUsername(string $username): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM users WHERE username = ? AND is_active = 1",
            [$username]
        );
        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT * FROM users WHERE email = ? AND is_active = 1",
            [$email]
        );
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT id, username, email, role, image, is_active, created_at FROM users WHERE id = ?",
            [$id]
        );
        return $row ? User::fromRow($row) : null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT id, username, email, role, image, is_active, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function count(): int
    {
        return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users");
    }

    public function create(array $data): int
    {
        $hash = password_hash($data['password'], PASSWORD_BCRYPT);
        return (int)$this->db->insert(
            "INSERT INTO users (username, password_hash, email, role, is_active)
             VALUES (?, ?, ?, ?, ?) RETURNING id",
            [$data['username'], $hash, $data['email'] ?? null, $data['role'] ?? 'user', $data['is_active'] ?? 1]
        );
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['username', 'email', 'role', 'image', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (!empty($data['password'])) {
            $fields[] = "password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_BCRYPT);
        }
        if (empty($fields)) return;
        $params[] = $id;
        $this->db->execute("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    }

    public function deactivate(int $id): void
    {
        $this->db->execute("UPDATE users SET is_active = 0 WHERE id = ?", [$id]);
    }
}
