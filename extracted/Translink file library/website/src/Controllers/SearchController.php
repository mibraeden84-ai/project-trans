<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Services\FileService;

class SearchController
{
    private FileService $fileService;

    public function __construct()
    {
        $this->fileService = new FileService();
    }

    public function search(Request $req, Response $res): Response
    {
        $term = trim($req->query('q', ''));
        if (empty($term)) {
            return $res->error('Search term required (q parameter)', 422);
        }

        $page = max(1, (int)$req->query('page', 1));
        $perPage = min(50, max(1, (int)$req->query('per_page', 20)));

        $result = $this->fileService->search($term, $page, $perPage);
        return $res->paginated($result['items'], $result['total'], $page, $perPage);
    }
}
