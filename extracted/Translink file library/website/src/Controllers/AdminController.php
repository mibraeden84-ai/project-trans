<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Repositories\ActivityRepository;
use Translink\Services\AuthService;
use Translink\Utils\Validator;

class AdminController
{
    private AuthService $auth;
    private ActivityRepository $activity;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->activity = new ActivityRepository();
    }

    public function users(Request $req, Response $res): Response
    {
        $page = max(1, (int)$req->query('page', 1));
        $perPage = min(100, max(1, (int)$req->query('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        $result = $this->auth->listUsers($perPage, $offset);
        return $res->paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function createUser(Request $req, Response $res): Response
    {
        $validator = new Validator();
        if (!$validator->validate($req->all(), [
            'username' => 'required|min:3|max:50',
            'password' => 'required|min:6',
            'email' => 'email',
            'role' => 'in:viewer,user,editor,admin',
        ])) {
            return $res->error('Validation failed', 422, $validator->errors());
        }

        try {
            $user = $this->auth->createUser($req->all());
            return $res->success($user, 'User created', 201);
        } catch (\Throwable $e) {
            return $res->error($e->getMessage(), 409);
        }
    }

    public function toggleUser(Request $req, Response $res): Response
    {
        $id = (int)$req->routeParam('id');
        $user = $this->auth->toggleUserActive($id);
        if (!$user) {
            return $res->error('User not found', 404);
        }
        return $res->success($user, 'User status toggled');
    }

    public function activity(Request $req, Response $res): Response
    {
        $page = max(1, (int)$req->query('page', 1));
        $perPage = min(100, max(1, (int)$req->query('per_page', 50)));
        $offset = ($page - 1) * $perPage;
        $action = $req->query('action');

        $items = $this->activity->findAll($perPage, $offset, $action);
        $total = $this->activity->count($action);

        return $res->paginated($items, $total, $page, $perPage);
    }

    public function activityStats(Request $req, Response $res): Response
    {
        $days = min(365, max(1, (int)$req->query('days', 30)));
        return $res->success($this->activity->stats($days));
    }

    public function health(Request $req, Response $res): Response
    {
        $checks = [
            'php_version' => ['status' => 'pass', 'value' => PHP_VERSION],
            'database' => ['status' => 'pass', 'value' => 'connected'],
        ];

        try {
            \Translink\Database::getInstance()->query('SELECT 1');
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'fail', 'value' => $e->getMessage()];
        }

        $uploadDirs = ['configs', 'firmware', 'manuals'];
        foreach ($uploadDirs as $dir) {
            $path = UPLOAD_PATH . '/' . $dir;
            $checks["upload_$dir"] = [
                'status' => is_dir($path) ? 'pass' : 'fail',
                'value' => is_dir($path) ? 'writable' : 'missing',
            ];
        }

        return $res->success([
            'status' => count(array_filter($checks, fn($c) => $c['status'] === 'fail')) === 0 ? 'healthy' : 'degraded',
            'checks' => $checks,
        ]);
    }
}
