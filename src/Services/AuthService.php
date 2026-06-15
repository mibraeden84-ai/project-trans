<?php
namespace Translink\Services;

use Translink\Database;
use Translink\Models\User;
use Translink\Repositories\UserRepository;
use Translink\Utils\Jwt;

class AuthService
{
    private UserRepository $users;
    private Jwt $jwt;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->jwt = new Jwt();
    }

    public function authenticate(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername($username);
        if (!$user) return null;
        if (!password_verify($password, $user['password_hash'])) return null;

        $token = $this->jwt->encode([
            'sub' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => defined('JWT_TTL') ? JWT_TTL : 86400,
            'user' => User::fromRow($user),
        ];
    }

    public function register(array $data): array
    {
        if ($this->users->findByUsername($data['username'])) {
            throw new \InvalidArgumentException('Username already taken');
        }
        if (!empty($data['email']) && $this->users->findByEmail($data['email'])) {
            throw new \InvalidArgumentException('Email already registered');
        }

        $data['role'] = 'user';
        $userId = $this->users->create($data);
        $user = $this->users->findById($userId);

        $token = $this->jwt->encode([
            'sub' => $userId,
            'username' => $data['username'],
            'role' => 'user',
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => defined('JWT_TTL') ? JWT_TTL : 86400,
            'user' => $user,
        ];
    }

    public function refreshToken(string $token): ?array
    {
        $newToken = $this->jwt->refresh($token);
        if (!$newToken) return null;

        $payload = $this->jwt->decode($newToken);
        return [
            'token' => $newToken,
            'token_type' => 'Bearer',
            'expires_in' => defined('JWT_TTL') ? JWT_TTL : 86400,
        ];
    }

    public function getUser(int $id): ?array
    {
        return $this->users->findById($id);
    }

    public function updateProfile(int $userId, array $data): ?array
    {
        $this->users->update($userId, $data);
        return $this->users->findById($userId);
    }

    public function listUsers(int $limit, int $offset): array
    {
        return [
            'items' => $this->users->findAll($limit, $offset),
            'total' => $this->users->count(),
        ];
    }

    public function createUser(array $data): array
    {
        $userId = $this->users->create($data);
        return $this->users->findById($userId);
    }

    public function toggleUserActive(int $userId): ?array
    {
        $user = $this->users->findById($userId);
        if (!$user) return null;

        if ($user['is_active']) {
            $this->users->deactivate($userId);
        } else {
            $this->users->update($userId, ['is_active' => 1]);
        }

        return $this->users->findById($userId);
    }
}
