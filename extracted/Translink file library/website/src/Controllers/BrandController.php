<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Repositories\BrandRepository;
use Translink\Utils\Validator;

class BrandController
{
    private BrandRepository $brands;

    public function __construct()
    {
        $this->brands = new BrandRepository();
    }

    public function index(Request $req, Response $res): Response
    {
        return $res->success($this->brands->findAll());
    }

    public function show(Request $req, Response $res): Response
    {
        $slug = $req->routeParam('slug');
        $brand = $this->brands->findBySlug($slug);
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }
        return $res->success($brand);
    }

    public function store(Request $req, Response $res): Response
    {
        $validator = new Validator();
        if (!$validator->validate($req->all(), [
            'name' => 'required|min:2|max:100',
            'slug' => 'required|min:2|max:100|slug',
        ])) {
            return $res->error('Validation failed', 422, $validator->errors());
        }

        $id = $this->brands->create($req->all());
        $brand = $this->brands->findById($id);
        return $res->success($brand, 'Brand created', 201);
    }

    public function update(Request $req, Response $res): Response
    {
        $id = (int)$req->routeParam('id');
        $brand = $this->brands->findById($id);
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }

        $data = [];
        foreach (['name', 'slug', 'description', 'icon', 'color'] as $f) {
            if ($req->input($f) !== null) $data[$f] = $req->input($f);
        }

        if (empty($data)) {
            return $res->error('No data to update', 422);
        }

        $this->brands->update($id, $data);
        return $res->success($this->brands->findById($id), 'Brand updated');
    }

    public function destroy(Request $req, Response $res): Response
    {
        $id = (int)$req->routeParam('id');
        $brand = $this->brands->findById($id);
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }

        $this->brands->delete($id);
        return $res->success(null, 'Brand deleted');
    }
}
