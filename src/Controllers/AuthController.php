<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Services\AuthService;
use Translink\Utils\Validator;

class AuthController
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function login(Request $req, Response $res): Response
    {
        $validator = new Validator();
        if (!$validator->validate($req->all(), [
            'username' => 'required|min:2',
            'password' => 'required|min:4',
        ])) {
            return $res->error('Validation failed', 422, $validator->errors());
        }

        $result = $this->auth->authenticate(
            $req->input('username'),
            $req->input('password')
        );

        if (!$result) {
            return $res->error('Invalid username or password', 401);
        }

        return $res->success($result, 'Login successful');
    }

    public function register(Request $req, Response $res): Response
    {
        $validator = new Validator();
        if (!$validator->validate($req->all(), [
            'username' => 'required|min:3|max:50',
            'password' => 'required|min:6',
            'email' => 'email',
        ])) {
            return $res->error('Validation failed', 422, $validator->errors());
        }

        try {
            $result = $this->auth->register($req->all());
            return $res->success($result, 'Registration successful', 201);
        } catch (\InvalidArgumentException $e) {
            return $res->error($e->getMessage(), 409);
        }
    }

    public function me(Request $req, Response $res): Response
    {
        $user = $req->routeParam('_user');
        return $res->success($user);
    }

    public function refresh(Request $req, Response $res): Response
    {
        $token = $req->bearerToken();
        if (!$token) {
            return $res->error('No token provided', 401);
        }

        $result = $this->auth->refreshToken($token);
        if (!$result) {
            return $res->error('Invalid or expired token', 401);
        }

        return $res->success($result, 'Token refreshed');
    }

    public function updateProfile(Request $req, Response $res): Response
    {
        $user = $req->routeParam('_user');
        $data = [];

        if ($req->input('email') !== null) $data['email'] = $req->input('email');
        if ($req->input('password') !== null) $data['password'] = $req->input('password');

        if (empty($data)) {
            return $res->error('No data to update', 422);
        }

        $updated = $this->auth->updateProfile($user['id'], $data);
        return $res->success($updated, 'Profile updated');
    }
}
