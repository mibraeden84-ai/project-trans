<?php
namespace Translink\Middleware;

use Translink\Database;
use Translink\Request;
use Translink\Response;
use Translink\Utils\Jwt;

class AuthMiddleware
{
    public static function required(): callable
    {
        return function (Request $req, Response $res, callable $next) {
            $token = $req->bearerToken();
            if (!$token) {
                $res->error('Authentication required. Provide a Bearer token', 401)->send();
                return $res;
            }

            $jwt = new Jwt();
            $payload = $jwt->decode($token);
            if (!$payload) {
                $res->error('Invalid or expired token', 401)->send();
                return $res;
            }

            $user = Database::getInstance()->fetchOne(
                "SELECT id, username, email, role, image, is_active FROM users WHERE id = ? AND is_active = 1",
                [$payload['sub'] ?? 0]
            );

            if (!$user) {
                $res->error('User not found or deactivated', 401)->send();
                return $res;
            }

            $req->setRouteParams(array_merge($req->routeParam('_all', []), ['_user' => $user]));
            return $next($req, $res);
        };
    }

    public static function role(string ...$roles): callable
    {
        return function (Request $req, Response $res, callable $next) use ($roles) {
            $user = $req->routeParam('_user');
            if (!$user) {
                $res->error('Authentication required', 401)->send();
                return $res;
            }
            if (!in_array($user['role'], $roles)) {
                $res->error('Insufficient permissions. Required role: ' . implode(', ', $roles), 403)->send();
                return $res;
            }
            return $next($req, $res);
        };
    }

    public static function optional(): callable
    {
        return function (Request $req, Response $res, callable $next) {
            $token = $req->bearerToken();
            if ($token) {
                $jwt = new Jwt();
                $payload = $jwt->decode($token);
                if ($payload) {
                    $user = Database::getInstance()->fetchOne(
                        "SELECT id, username, email, role, image FROM users WHERE id = ? AND is_active = 1",
                        [$payload['sub'] ?? 0]
                    );
                    if ($user) {
                        $params = $req->routeParam('_all', []);
                        $params['_user'] = $user;
                        $req->setRouteParams($params);
                    }
                }
            }
            return $next($req, $res);
        };
    }
}
