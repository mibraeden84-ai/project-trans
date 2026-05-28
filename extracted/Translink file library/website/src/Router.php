<?php
namespace Translink;

class Router
{
    private array $routes = [];
    private array $globalMiddleware = [];
    private Request $request;
    private Response $response;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    public function addMiddleware(callable|object $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $router = new Router();
        $callback($router);

        foreach ($router->getRoutes() as $route) {
            $route['path'] = $prefix . $route['path'];
            $route['middleware'] = array_merge($middleware, $route['middleware']);
            $this->routes[] = $route;
        }
    }

    private function addRoute(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    public function dispatch(): void
    {
        try {
            $method = $this->request->method();
            $path = $this->request->path();

            $matched = false;
            foreach ($this->routes as $route) {
                if ($route['method'] !== $method && $route['method'] !== 'ANY') continue;

                $params = $this->matchPath($route['path'], $path);
                if ($params === false) continue;

                $matched = true;
                $this->request->setRouteParams($params);

                $middlewareChain = array_merge($this->globalMiddleware, $route['middleware']);
                $handler = $route['handler'];

                $next = function (Request $req, Response $res) use ($handler) {
                    if (is_array($handler)) {
                        [$class, $method] = $handler;
                        $controller = is_string($class) ? new $class() : $class;
                        return $controller->$method($req, $res);
                    }
                    return $handler($req, $res);
                };

                for ($i = count($middlewareChain) - 1; $i >= 0; $i--) {
                    $mw = $middlewareChain[$i];
                    $currentNext = $next;
                    $next = function (Request $req, Response $res) use ($mw, $currentNext) {
                        if (is_callable($mw)) {
                            return $mw($req, $res, $currentNext);
                        }
                        if (is_string($mw)) {
                            $instance = new $mw();
                            return $instance->handle($req, $res, $currentNext);
                        }
                        return $currentNext($req, $res);
                    };
                }

                $result = $next($this->request, $this->response);

                if ($result instanceof Response) {
                    $result->send();
                } elseif ($result !== null) {
                    echo $result;
                }

                break;
            }

            if (!$matched) {
                $this->response->error("Route not found: {$method} {$path}", 404)->send();
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    private function matchPath(string $routePath, string $requestPath): array|false
    {
        $routeParts = explode('/', trim($routePath, '/'));
        $requestParts = explode('/', trim($requestPath, '/'));

        if (count($routeParts) !== count($requestParts)) return false;

        $params = [];
        foreach ($routeParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $key = trim($part, '{}');
                $params[$key] = $requestParts[$i];
            } elseif ($part !== $requestParts[$i]) {
                return false;
            }
        }

        return $params;
    }

    private function handleException(\Throwable $e): void
    {
        $code = 500;
        $msg = 'Internal Server Error';

        if ($e instanceof \PDOException) {
            $code = 503;
            $msg = 'Database error';
        }

        if ($e instanceof \InvalidArgumentException) {
            $code = 422;
            $msg = $e->getMessage();
        }

        if (method_exists($e, 'getStatusCode')) {
            $code = $e->getStatusCode();
            $msg = $e->getMessage();
        }

        error_log("API Error: [{$e->getMessage()}] in {$e->getFile()}:{$e->getLine()}");

        $this->response
            ->json([
                'success' => false,
                'message' => $msg,
                'error' => defined('API_DEBUG') && API_DEBUG ? $e->getMessage() : null,
                'timestamp' => gmdate('c'),
            ], $code)
            ->send();
    }
}
