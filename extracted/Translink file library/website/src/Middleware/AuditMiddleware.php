<?php
namespace Translink\Middleware;

use Translink\Request;
use Translink\Response;
use Translink\Services\QueueService;

class AuditMiddleware
{
    private QueueService $queue;

    public function __construct()
    {
        $this->queue = new QueueService();
    }

    public function log(string $action, string $entityType): callable
    {
        return function (Request $req, Response $res, callable $next) use ($action, $entityType) {
            $startTime = microtime(true);

            $result = $next($req, $res);

            $duration = (microtime(true) - $startTime) * 1000;

            $user = $req->routeParam('_user');
            $payload = [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => (int)$req->routeParam('id') ?: null,
                'entity_name' => $req->routeParam('slug') ?? $req->query('q'),
                'details' => json_encode([
                    'method' => $req->method(),
                    'path' => $req->path(),
                    'duration_ms' => round($duration, 2),
                    'params' => $req->query('_debug') ? $req->all() : null,
                ]),
                'ip_address' => $req->ip(),
                'user_id' => $user ? (int)($user['id'] ?? 0) : null,
            ];

            $this->queue->push('audit_log', $payload);

            return $result;
        };
    }
}
