<?php
namespace Translink\Middleware;

use Translink\Request;
use Translink\Response;

interface MiddlewareInterface
{
    public function handle(Request $req, Response $res, callable $next): Response;
}
