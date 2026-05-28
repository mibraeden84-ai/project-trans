<?php
namespace Translink\Middleware;

use Translink\Request;
use Translink\Response;

class CorsMiddleware
{
    private array $allowedOrigins;
    private array $allowedMethods;
    private array $allowedHeaders;

    public function __construct()
    {
        $origins = defined('CORS_ALLOWED_ORIGINS') ? CORS_ALLOWED_ORIGINS : '*';
        $this->allowedOrigins = is_array($origins) ? $origins : [$origins];
        $this->allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        $this->allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'];
    }

    public function handle(Request $req, Response $res, callable $next): Response
    {
        $origin = $req->header('Origin', '*');

        if (in_array('*', $this->allowedOrigins)) {
            $res->header('Access-Control-Allow-Origin', '*');
        } elseif (in_array($origin, $this->allowedOrigins)) {
            $res->header('Access-Control-Allow-Origin', $origin);
            $res->header('Vary', 'Origin');
        }

        $res->header('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        $res->header('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        $res->header('Access-Control-Max-Age', '86400');
        $res->header('Access-Control-Allow-Credentials', 'true');

        if ($req->method() === 'OPTIONS') {
            return $res->status(204);
        }

        return $next($req, $res);
    }
}
