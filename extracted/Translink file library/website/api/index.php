<?php
// Translink GPS File Library — REST API v1 Entry Point
// All /api/* and /api/v1/* requests are handled here.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';

// API-only overrides
define('API_DEBUG', defined('DEBUG') ? DEBUG : false);
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'translink-enterprise-secret-2026');
define('JWT_TTL', (int)(getenv('JWT_TTL') ?: 86400));

// Autoload
require_once __DIR__ . '/../src/Autoloader.php';
\Translink\Autoloader::register();

use Translink\Router;
use Translink\Middleware\AuthMiddleware;
use Translink\Middleware\CorsMiddleware;
use Translink\Middleware\RateLimitMiddleware;
use Translink\Middleware\AuditMiddleware;

// Determine base path for API routing
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = '/' . trim($path, '/');

// Normalize: strip /api/v1 or /api prefix
$basePath = '';
if (preg_match('#^/(api/v[\d]+|api)(/.*)?$#', $path, $m)) {
    $basePath = $m[1];
    $internalPath = $m[2] ?? '';
    if (empty($internalPath)) $internalPath = '/';
    $_SERVER['REQUEST_URI'] = $internalPath . (str_contains($requestUri, '?') ? '?' . parse_url($requestUri, PHP_URL_QUERY) : '');
}

$router = new Router();

// Global middleware
$router->addMiddleware(function ($req, $res, $next) {
    $res->header('X-API-Version', '1.0');
    $res->header('X-API-Node', gethostname() ?: 'localhost');
    return $next($req, $res);
});
$router->addMiddleware([new CorsMiddleware(), 'handle']);
$router->addMiddleware([new RateLimitMiddleware(120, 60), 'handle']);

// === Auth Routes (no auth required) ===
$router->post('/auth/login', ['Translink\Controllers\AuthController', 'login']);
$router->post('/auth/register', ['Translink\Controllers\AuthController', 'register']);
$router->post('/auth/refresh', ['Translink\Controllers\AuthController', 'refresh']);

// === Auth Routes (auth required) ===
$router->get('/auth/me', ['Translink\Controllers\AuthController', 'me'], [AuthMiddleware::required()]);
$router->put('/auth/me', ['Translink\Controllers\AuthController', 'updateProfile'], [AuthMiddleware::required()]);

// === Brands (public read, admin write) ===
$router->get('/brands', ['Translink\Controllers\BrandController', 'index']);
$router->get('/brands/{slug}', ['Translink\Controllers\BrandController', 'show']);
$router->post('/brands', ['Translink\Controllers\BrandController', 'store'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
$router->put('/brands/{id}', ['Translink\Controllers\BrandController', 'update'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
$router->delete('/brands/{id}', ['Translink\Controllers\BrandController', 'destroy'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);

// === Models (public read, admin write) ===
$router->get('/brands/{brand_slug}/models', ['Translink\Controllers\ModelController', 'index']);
$router->get('/brands/{brand_slug}/models/{model_slug}', ['Translink\Controllers\ModelController', 'show']);
$router->post('/models', ['Translink\Controllers\ModelController', 'store'], [AuthMiddleware::required(), AuthMiddleware::role('admin', 'editor')]);
$router->put('/models/{id}', ['Translink\Controllers\ModelController', 'update'], [AuthMiddleware::required(), AuthMiddleware::role('admin', 'editor')]);
$router->delete('/models/{id}', ['Translink\Controllers\ModelController', 'destroy'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);

// === Files (public read, auth write) ===
$router->get('/files/{type}', ['Translink\Controllers\FileController', 'index']);
$router->get('/files/{type}/{id}', ['Translink\Controllers\FileController', 'show']);
$router->get('/files/{type}/{id}/download', ['Translink\Controllers\FileController', 'download']);
$router->delete('/files/{type}/{id}', ['Translink\Controllers\FileController', 'destroy'], [AuthMiddleware::required(), AuthMiddleware::role('admin', 'editor')]);
$router->post('/files/upload', ['Translink\Controllers\FileController', 'upload'], [AuthMiddleware::required(), AuthMiddleware::role('admin', 'editor')]);

// === Search ===
$router->get('/search', ['Translink\Controllers\SearchController', 'search']);

// === Stats ===
$router->get('/stats', ['Translink\Controllers\StatsController', 'index']);
$router->get('/stats/top-downloads', ['Translink\Controllers\StatsController', 'topDownloads']);

// === Admin Routes ===
$router->group('/admin', function ($r) {
    $r->get('/users', ['Translink\Controllers\AdminController', 'users'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
    $r->post('/users', ['Translink\Controllers\AdminController', 'createUser'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
    $r->put('/users/{id}/toggle', ['Translink\Controllers\AdminController', 'toggleUser'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
    $r->get('/activity', ['Translink\Controllers\AdminController', 'activity'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
    $r->get('/activity/stats', ['Translink\Controllers\AdminController', 'activityStats'], [AuthMiddleware::required(), AuthMiddleware::role('admin')]);
    $r->get('/health', ['Translink\Controllers\AdminController', 'health']);
});

// === Root ===
$router->get('/', function ($req, $res) {
    return $res->success([
        'name' => 'Translink GPS File Library API',
        'version' => '1.0.0',
        'docs' => '/api/v1/docs',
        'endpoints' => [
            'auth' => ['POST /auth/login', 'POST /auth/register', 'GET /auth/me'],
            'brands' => ['GET /brands', 'GET /brands/{slug}', 'POST /brands (admin)'],
            'models' => ['GET /brands/{slug}/models', 'POST /models (admin)'],
            'files' => ['GET /files/{type}', 'GET /files/{type}/{id}', 'GET /files/{type}/{id}/download', 'POST /files/upload (auth)'],
            'search' => ['GET /search?q='],
            'stats' => ['GET /stats', 'GET /stats/top-downloads'],
            'admin' => ['GET /admin/users', 'GET /admin/activity', 'GET /admin/health'],
        ],
    ]);
});

// === Docs ===
$router->get('/docs', ['Translink\Controllers\DocsController', 'openapi']);

// === Catch-all for API routes ===
$router->get('/{path}', function ($req, $res) {
    return $res->error('Endpoint not found', 404);
});

// Dispatch
$router->dispatch();
