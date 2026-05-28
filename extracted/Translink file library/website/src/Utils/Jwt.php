<?php
namespace Translink\Utils;

class Jwt
{
    private string $secret;
    private string $algorithm;
    private int $ttl;

    public function __construct()
    {
        $this->secret = defined('JWT_SECRET') ? JWT_SECRET : 'translink-enterprise-secret-2026';
        $this->algorithm = 'HS256';
        $this->ttl = defined('JWT_TTL') ? JWT_TTL : 86400;
    }

    public function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode([
            'typ' => 'JWT',
            'alg' => $this->algorithm,
        ]));

        $payload['iat'] = $payload['iat'] ?? time();
        $payload['exp'] = $payload['exp'] ?? time() + $this->ttl;
        $payload['jti'] = $payload['jti'] ?? bin2hex(random_bytes(16));

        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));
        $signature = $this->sign("{$header}.{$payloadEncoded}");

        return "{$header}.{$payloadEncoded}.{$signature}";
    }

    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $payload, $signature] = $parts;

        if (!hash_equals($this->sign("{$header}.{$payload}"), $signature)) {
            return null;
        }

        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!$data || !isset($data['exp'])) return null;

        if ($data['exp'] < time()) return null;

        return $data;
    }

    public function refresh(string $token): ?string
    {
        $payload = $this->decode($token);
        if (!$payload) return null;

        unset($payload['iat'], $payload['exp'], $payload['jti']);
        return $this->encode($payload);
    }

    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
