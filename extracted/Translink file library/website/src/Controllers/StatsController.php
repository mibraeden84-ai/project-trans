<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Services\FileService;

class StatsController
{
    private FileService $fileService;

    public function __construct()
    {
        $this->fileService = new FileService();
    }

    public function index(Request $req, Response $res): Response
    {
        return $res->success($this->fileService->stats());
    }

    public function topDownloads(Request $req, Response $res): Response
    {
        $limit = min(100, max(1, (int)$req->query('limit', 20)));
        return $res->success($this->fileService->topDownloads($limit));
    }
}
