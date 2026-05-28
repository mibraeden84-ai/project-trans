<?php
namespace Translink;

class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $server;
    private array $headers;
    private array $routeParams = [];
    private ?array $jsonPayload = null;

    public function __construct()
    {
        $this->query = $_GET;
        $this->files = $_FILES;
        $this->server = $_SERVER;
        $this->body = $_POST;
        $this->headers = $this->parseHeaders();

        $contentType = $this->header('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw) {
                $this->jsonPayload = json_decode($raw, true);
                $this->body = array_merge($this->body, $this->jsonPayload ?? []);
            }
        }
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($this->server as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $header = str_replace('_', '-', strtolower(substr($k, 5)));
                $headers[$header] = $v;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return '/' . trim($uri, '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        if (isset($this->headers[$name])) return $this->headers[$name];

        $alt = 'http-' . str_replace('_', '-', $name);
        return $this->headers[$alt] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function ip(): string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $k) {
            if (!empty($this->server[$k])) {
                $ips = explode(',', $this->server[$k]);
                return trim($ips[0]);
            }
        }
        return '127.0.0.1';
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('Accept', '');
        return str_contains($accept, 'application/json')
            || str_contains($this->header('Content-Type', ''), 'application/json')
            || $this->query('format') === 'json';
    }
}
