<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Repositories\BrandRepository;
use Translink\Repositories\ModelRepository;
use Translink\Utils\Validator;

class ModelController
{
    private ModelRepository $models;
    private BrandRepository $brands;

    public function __construct()
    {
        $this->models = new ModelRepository();
        $this->brands = new BrandRepository();
    }

    public function index(Request $req, Response $res): Response
    {
        $brandSlug = $req->routeParam('brand_slug');
        $brand = $this->brands->findBySlug($brandSlug);
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }
        return $res->success($this->models->findByBrand($brand['id']));
    }

    public function show(Request $req, Response $res): Response
    {
        $brandSlug = $req->routeParam('brand_slug');
        $modelSlug = $req->routeParam('model_slug');

        $brand = $this->brands->findBySlug($brandSlug);
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }

        $model = $this->models->findByBrandAndSlug($brand['id'], $modelSlug);
        if (!$model) {
            return $res->error('Model not found', 404);
        }
        return $res->success($model);
    }

    public function store(Request $req, Response $res): Response
    {
        $validator = new Validator();
        if (!$validator->validate($req->all(), [
            'brand_id' => 'required|integer',
            'name' => 'required|min:2|max:100',
            'slug' => 'required|slug',
        ])) {
            return $res->error('Validation failed', 422, $validator->errors());
        }

        $brand = $this->brands->findById((int)$req->input('brand_id'));
        if (!$brand) {
            return $res->error('Brand not found', 404);
        }

        $id = $this->models->create($req->all());
        return $res->success($this->models->findById($id), 'Model created', 201);
    }

    public function update(Request $req, Response $res): Response
    {
        $id = (int)$req->routeParam('id');
        $model = $this->models->findById($id);
        if (!$model) {
            return $res->error('Model not found', 404);
        }

        $data = [];
        foreach (['brand_id', 'name', 'slug', 'description', 'image_url', 'system_type'] as $f) {
            if ($req->input($f) !== null) $data[$f] = $req->input($f);
        }

        if (empty($data)) {
            return $res->error('No data to update', 422);
        }

        $this->models->update($id, $data);
        return $res->success($this->models->findById($id), 'Model updated');
    }

    public function destroy(Request $req, Response $res): Response
    {
        $id = (int)$req->routeParam('id');
        $model = $this->models->findById($id);
        if (!$model) {
            return $res->error('Model not found', 404);
        }

        $this->models->delete($id);
        return $res->success(null, 'Model deleted');
    }
}
