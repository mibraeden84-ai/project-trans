<?php
namespace Translink;

class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $data = null;
    private bool $sent = false;

    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function json(mixed $data, int $status = null): self
    {
        if ($status !== null) $this->statusCode = $status;
        $this->data = $data;
        $this->header('Content-Type', 'application/json; charset=utf-8');
        return $this;
    }

    public function success(mixed $data = null, string $message = 'OK', int $status = 200): self
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => gmdate('c'),
        ], $status);
    }

    public function error(string $message, int $status = 400, mixed $errors = null): self
    {
        $payload = [
            'success' => false,
            'message' => $message,
            'timestamp' => gmdate('c'),
        ];
        if ($errors !== null) $payload['errors'] = $errors;
        return $this->json($payload, $status);
    }

    public function paginated(array $items, int $total, int $page, int $perPage): self
    {
        $lastPage = max(1, (int)ceil($total / $perPage));
        $pagination = [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ];

        return $this->success([
            'items' => $items,
            'pagination' => $pagination,
        ]);
    }

    public function cursorPaginated(array $items, ?string $nextCursor, int $perPage, string $cursorColumn = 'created_at'): self
    {
        return $this->success([
            'items' => $items,
            'pagination' => [
                'per_page' => $perPage,
                'next_cursor' => $nextCursor,
                'cursor_column' => $cursorColumn,
                'has_more' => $nextCursor !== null && count($items) >= $perPage,
            ],
        ]);
    }

    public function send(): void
    {
        if ($this->sent) return;
        $this->sent = true;

        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        if ($this->data !== null) {
            echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function __destruct()
    {
        if (!$this->sent) $this->send();
    }

    public static function redirect(string $url): never
    {
        header("Location: $url");
        exit;
    }
}
