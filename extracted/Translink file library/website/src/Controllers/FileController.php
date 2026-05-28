<?php
namespace Translink\Controllers;

use Translink\Request;
use Translink\Response;
use Translink\Services\FileService;
use Translink\Services\StorageService;
use Translink\Database;

class FileController
{
    private FileService $fileService;

    public function __construct()
    {
        $this->fileService = new FileService();
    }

    public function index(Request $req, Response $res): Response
    {
        $type = $req->routeParam('type');
        $validTypes = ['config', 'firmware', 'manual', 'software'];
        if (!in_array($type, $validTypes)) {
            return $res->error("Invalid type: $type", 422);
        }

        $page = max(1, (int)$req->query('page', 1));
        $perPage = min(100, max(1, (int)$req->query('per_page', 20)));

        $filters = [];
        foreach (['brand_id', 'model_id', 'system_type', 'search'] as $f) {
            if ($req->query($f) !== null) $filters[$f] = $req->query($f);
        }
        $filters['sort'] = $req->query('sort', 'created_at');
        $filters['order'] = $req->query('order', 'DESC');

        $result = $this->fileService->list($type, $filters, $page, $perPage);
        return $res->paginated($result['items'], $result['total'], $page, $perPage);
    }

    public function show(Request $req, Response $res): Response
    {
        $type = $req->routeParam('type');
        $id = (int)$req->routeParam('id');

        $file = $this->fileService->get($type, $id);
        if (!$file) {
            return $res->error('File not found', 404);
        }

        return $res->success($file);
    }

    public function download(Request $req, Response $res): Response
    {
        $type = $req->routeParam('type');
        $id = (int)$req->routeParam('id');

        $download = $this->fileService->download($type, $id);
        if (!$download) {
            return $res->error('File not found', 404);
        }

        if (!empty($download['local'])) {
            $res->send(); // flush headers
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($download['name']) . '"');
            header('Content-Length: ' . $download['size']);
            readfile($download['path']);
            exit;
        }

        return $res->success(['url' => $download['url'], 'name' => $download['name']]);
    }

    public function destroy(Request $req, Response $res): Response
    {
        $type = $req->routeParam('type');
        $id = (int)$req->routeParam('id');

        $deleted = $this->fileService->delete($type, $id);
        if (!$deleted) {
            return $res->error('File not found', 404);
        }

        return $res->success(null, 'File deleted');
    }

    public function upload(Request $req, Response $res): Response
    {
        $type = $req->input('upload_type', 'config');
        $validTypes = ['config', 'firmware', 'manual', 'software'];
        if (!in_array($type, $validTypes)) {
            return $res->error("Invalid type: $type", 422);
        }

        $name = trim($req->input('name', ''));
        if (empty($name)) {
            return $res->error('Name is required', 422);
        }

        if (!$req->hasFile('file')) {
            return $res->error('File upload is required', 422);
        }

        $file = $req->file('file');
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        $allowedConfig = defined('ALLOWED_CONFIG_EXT') ? ALLOWED_CONFIG_EXT : ['cfg', 'txt', 'conf', 'ini', 'csv', 'xls', 'xlsx'];
        $allowedFirmware = defined('ALLOWED_FIRMWARE_EXT') ? ALLOWED_FIRMWARE_EXT : ['fw', 'bin', 'hex', 'dfu', 'xim', 'cfw'];
        $allowedManual = defined('ALLOWED_MANUAL_EXT') ? ALLOWED_MANUAL_EXT : ['pdf', 'doc', 'docx', 'txt'];
        $allowedSoftware = defined('ALLOWED_SOFTWARE_EXT') ? ALLOWED_SOFTWARE_EXT : ['exe', 'msi', 'zip', 'rar', '7z', 'gz', 'xim', 'cif'];

        $allowedMap = [
            'config' => $allowedConfig,
            'firmware' => $allowedFirmware,
            'manual' => $allowedManual,
            'software' => $allowedSoftware,
        ];

        if (!in_array($ext, $allowedMap[$type])) {
            return $res->error("File type .$ext is not allowed for $type files", 422);
        }

        $maxSize = defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 104857600;
        if ($file['size'] > $maxSize) {
            return $res->error('File exceeds maximum size', 413);
        }

        $storage = new StorageService();
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $uniqueName = uniqid('', true) . '_' . $cleanName . '.' . $ext;

        $relativePath = $storage->store($type, $file['tmp_name'], $uniqueName);

        $db = Database::getInstance();

        try {
            switch ($type) {
                case 'config':
                    $db->insert(
                        "INSERT INTO config_files (category, status, device_model_id, name, system_type, file_path, file_size, version, description)
                         VALUES (?, 'active', ?, ?, ?, ?, ?, ?, ?) RETURNING id",
                        ['config', (int)$req->input('model_id'), $name . '.' . $ext, $req->input('system_type'), $relativePath, $file['size'], $req->input('version', '1.0'), $req->input('description')]
                    );
                    break;

                case 'firmware':
                    $db->insert(
                        "INSERT INTO firmware_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, changelog)
                         VALUES ('firmware', 'active', ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
                        [(int)$req->input('brand_id'), $req->input('model_id') ? (int)$req->input('model_id') : null, $name . '.' . $ext, $req->input('system_type'), $relativePath, $file['size'], $req->input('version', '1.0'), $req->input('changelog')]
                    );
                    break;

                case 'manual':
                    $db->insert(
                        "INSERT INTO manuals (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, description)
                         VALUES ('manual', 'active', ?, ?, ?, ?, ?, ?, ?) RETURNING id",
                        [(int)$req->input('brand_id'), $req->input('model_id') ? (int)$req->input('model_id') : null, $name . '.' . $ext, $req->input('system_type'), $relativePath, $file['size'], $req->input('description')]
                    );
                    break;

                case 'software':
                    $db->insert(
                        "INSERT INTO software_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, description)
                         VALUES ('software', 'active', ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id",
                        [(int)$req->input('brand_id'), $req->input('model_id') ? (int)$req->input('model_id') : null, $name . '.' . $ext, $req->input('system_type'), $relativePath, $file['size'], $req->input('version', '1.0'), $req->input('description')]
                    );
                    break;
            }
        } catch (\Throwable $e) {
            $storage->delete($relativePath);
            throw $e;
        }

        return $res->success(['path' => $relativePath, 'size' => $file['size']], 'File uploaded successfully', 201);
    }
}
