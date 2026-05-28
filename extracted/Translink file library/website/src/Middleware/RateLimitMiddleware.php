<?php
namespace Translink\Middleware;

use Translink\Database;
use Translink\Request;
use Translink\Response;

class RateLimitMiddleware
{
    private int $maxRequests;
    private int $windowSeconds;
    private static bool $tableEnsured = false;

    public function __construct(int $maxRequests = 60, int $windowSeconds = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
    }

    public function handle(Request $req, Response $res, callable $next): Response
    {
        $key = $this->getKey($req);
        $db = Database::getInstance();
        $windowStart = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        try {
            $this->ensureRateLimitTable($db);

            $count = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM api_rate_limits
                 WHERE rate_key = ? AND created_at >= ?",
                [$key, $windowStart]
            );

            if ($count >= $this->maxRequests) {
                $res->header('X-RateLimit-Limit', (string)$this->maxRequests);
                $res->header('X-RateLimit-Remaining', '0');
                $res->header('Retry-After', (string)$this->windowSeconds);
                $res->error('Rate limit exceeded. Try again later.', 429)->send();
                return $res;
            }

            $db->execute(
                "INSERT INTO api_rate_limits (rate_key, created_at) VALUES (?, CURRENT_TIMESTAMP)",
                [$key]
            );

            $res->header('X-RateLimit-Limit', (string)$this->maxRequests);
            $res->header('X-RateLimit-Remaining', (string)($this->maxRequests - $count - 1));
        } catch (\Throwable $e) {
            // Do not block API traffic if rate-limit storage is unavailable.
            return $next($req, $res);
        }

        return $next($req, $res);
    }

    private function ensureRateLimitTable(Database $db): void
    {
        if (self::$tableEnsured) {
            return;
        }

        if (defined('DB_DRIVER') && DB_DRIVER === 'pgsql') {
            $db->execute(
                "CREATE TABLE IF NOT EXISTS api_rate_limits (
                    id BIGSERIAL PRIMARY KEY,
                    rate_key VARCHAR(128) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
        } else {
            $db->execute(
                "CREATE TABLE IF NOT EXISTS api_rate_limits (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    rate_key VARCHAR(128) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_rate_limits_key_time (rate_key, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }

        self::$tableEnsured = true;
    }

    private function getKey(Request $req): string
    {
        $user = $req->routeParam('_user');
        if ($user) return 'user:' . $user['id'];
        return 'ip:' . $req->ip();
    }
}
