<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');

if (!canManageFiles()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}
session_write_close();

ignore_user_abort(true);

$action = $_GET['action'] ?? '';

if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id'] ?? '');
    $chunkIndex = (int)($_POST['chunk_index'] ?? 0);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);

    if (!$uploadId || !isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Invalid chunk']);
        exit;
    }

    $chunkDir = __DIR__ . '/tmp/chunks/' . $uploadId;
    if (!is_dir($chunkDir)) {
        mkdir($chunkDir, 0755, true);
    }

    $chunkPath = $chunkDir . '/' . $chunkIndex;
    move_uploaded_file($_FILES['chunk']['tmp_name'], $chunkPath);

    if ($chunkIndex === 0) {
        $metadata = [
            'upload_type'   => $_POST['upload_type'] ?? 'config',
            'display_name'  => $_POST['display_name'] ?? '',
            'brand_id'      => $_POST['brand_id'] ?? '',
            'model_id'      => $_POST['model_id'] ?? '',
            'version'       => $_POST['version'] ?? '1.0',
            'description'   => $_POST['description'] ?? '',
            'changelog'     => $_POST['changelog'] ?? '',
            'system_type'   => $_POST['system_type'] ?? null,
            'original_name' => $_FILES['chunk']['name'],
        ];
        file_put_contents($chunkDir . '/metadata.json', json_encode($metadata));
    }

    echo json_encode(['success' => true, 'chunk_index' => $chunkIndex, 'total_chunks' => $totalChunks]);
    exit;
}

if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['upload_id'] ?? '');
    if (!$uploadId) {
        echo json_encode(['success' => false, 'message' => 'Invalid upload ID']);
        exit;
    }

    $chunkDir = __DIR__ . '/tmp/chunks/' . $uploadId;
    $metadataPath = $chunkDir . '/metadata.json';

    if (!file_exists($metadataPath)) {
        echo json_encode(['success' => false, 'message' => 'Metadata not found']);
        exit;
    }

    $metadata = json_decode(file_get_contents($metadataPath), true);
    $totalChunks = (int)($_POST['total_chunks'] ?? 0);

    $chunks = [];
    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $chunkDir . '/' . $i;
        if (!file_exists($chunkPath)) {
            echo json_encode(['success' => false, 'message' => "Missing chunk $i"]);
            exit;
        }
        $chunks[] = $chunkPath;
    }

    $originalName = $metadata['original_name'] ?? 'file.bin';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $uploadType = $metadata['upload_type'] ?? 'config';
    $subdirMap = ['config' => 'configs', 'firmware' => 'firmware', 'manual' => 'manuals', 'software' => 'software'];
    $subdir = $subdirMap[$uploadType] ?? 'configs';

    $destDir = __DIR__ . '/uploads/' . $subdir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $cleanName = pathinfo($metadata['display_name'] ?: $metadata['original_name'], PATHINFO_FILENAME);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $cleanName) . '.' . $ext;
    $uniqueName = uniqid() . '_' . $safeName;
    $destPath = $destDir . '/' . $uniqueName;

    $out = fopen($destPath, 'wb');
    if (!$out) {
        echo json_encode(['success' => false, 'message' => 'Failed to create output file']);
        exit;
    }
    foreach ($chunks as $chunkPath) {
        $in = fopen($chunkPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
    }
    fclose($out);

    array_map('unlink', glob($chunkDir . '/*'));
    rmdir($chunkDir);

    $relativePath = 'uploads/' . $subdir . '/' . $uniqueName;
    $fileSize = filesize($destPath);

    $db = Database::getInstance();
    try {
        $brandId = !empty($metadata['brand_id']) ? (int)$metadata['brand_id'] : null;
        $modelId = !empty($metadata['model_id']) ? (int)$metadata['model_id'] : null;
        $dbName = $cleanName . '.' . $ext;
        $systemType = $metadata['system_type'] ?? null;
        $version = $metadata['version'] ?? '1.0';
        $description = $metadata['description'] ?? '';
        $changelog = $metadata['changelog'] ?? '';
        $displayName = $metadata['display_name'] ?: $originalName;

        switch ($uploadType) {
            case 'config':
                $db->insert("INSERT INTO config_files (category, status, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['config', 'active', $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $description]);
                break;
            case 'firmware':
                $db->insert("INSERT INTO firmware_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['firmware', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $changelog]);
                break;
            case 'manual':
                $db->insert("INSERT INTO manuals (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['manual', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $description]);
                break;
            case 'software':
                $db->insert("INSERT INTO software_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['software', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $description]);
                break;
            default:
                throw new Exception('Invalid upload type');
        }

        echo json_encode(['success' => true, 'message' => "$displayName uploaded successfully"]);
    } catch (Exception $e) {
        if (file_exists($destPath)) {
            unlink($destPath);
        }
        echo json_encode(['success' => false, 'message' => 'Upload failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid request']);
