<?php
// Translink File Library — AJAX Upload Handler
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json');
$db = Database::getInstance();

// GET: return models for a brand (AJAX cascade)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'models') {
    $brandId = (int)($_GET['brand_id'] ?? 0);
    if ($brandId) {
        $models = $db->getModelsByBrand($brandId);
        echo json_encode(['success' => true, 'models' => $models]);
    } else {
        echo json_encode(['success' => false, 'models' => []]);
    }
    exit;
}

// POST: upload file (admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    requireFileManager();
    $uploadType  = $_POST['upload_type'] ?? 'config';
    $displayName = trim($_POST['display_name'] ?? '');
    $brandId     = $_POST['brand_id'] ?? null;
    $modelId     = $_POST['model_id'] ?? null;
    $version     = trim($_POST['version'] ?? '1.0');
    $description = trim($_POST['description'] ?? '');
    $changelog   = trim($_POST['changelog'] ?? '');
    $systemType  = $_POST['system_type'] ?? null;
    if ($systemType === '') $systemType = null;
    $category    = trim($_POST['category'] ?? 'General');

    if (empty($displayName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Display name is required']);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errMsg = 'File upload failed';
        if (!empty($_FILES['file'])) {
            $errors = [
                UPLOAD_INI_SIZE   => 'File exceeds PHP max upload size',
                UPLOAD_FORM_SIZE  => 'File exceeds form max size',
                UPLOAD_PARTIAL    => 'File was partially uploaded',
                UPLOAD_NO_FILE    => 'No file selected',
            ];
            $errMsg = $errors[$_FILES['file']['error']] ?? $errMsg;
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $errMsg]);
        exit;
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = array_merge(ALLOWED_CONFIG_EXT, ALLOWED_FIRMWARE_EXT, ALLOWED_MANUAL_EXT, ALLOWED_SOFTWARE_EXT);

    if (!in_array($ext, $allowedExts)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "File type .$ext is not allowed"]);
        exit;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File exceeds maximum size of ' . formatFileSize(MAX_FILE_SIZE)]);
        exit;
    }

    // Determine upload subdirectory
    $subdirMap = ['config' => 'configs', 'firmware' => 'firmware', 'manual' => 'manuals', 'software' => 'software'];
    $subdir = $subdirMap[$uploadType] ?? 'configs';

    $destDir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    // Use display name for the stored filename — strip any extension user may have typed
    $cleanName = pathinfo($displayName, PATHINFO_FILENAME);
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $cleanName) . '.' . $ext;
    $uniqueName = uniqid() . '_' . $safeName;
    $destPath = $destDir . '/' . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    $relativePath = 'uploads/' . $subdir . '/' . $uniqueName;
    $fileSize = (int)($file['size'] ?? 0);

    try {
        switch ($uploadType) {
            case 'config':
                if (!$modelId) throw new Exception('Model is required for config files');
                $db->insert("INSERT INTO config_files (category, status, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['config', 'active', $modelId, $cleanName . '.' . $ext, $systemType, $relativePath, $fileSize, $version, $description]);
                break;

            case 'firmware':
                if (!$brandId) throw new Exception('Brand is required for firmware');
                $db->insert("INSERT INTO firmware_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['firmware', 'active', $brandId, $modelId, $cleanName . '.' . $ext, $systemType, $relativePath, $fileSize, $version, $changelog]);
                break;

            case 'manual':
                if (!$brandId) throw new Exception('Brand is required for manuals');
                $db->insert("INSERT INTO manuals (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['manual', 'active', $brandId, $modelId, $cleanName . '.' . $ext, $systemType, $relativePath, $fileSize, $description]);
                break;

            case 'software':
                if (!$brandId) throw new Exception('Brand is required for software');
                $db->insert("INSERT INTO software_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['software', 'active', $brandId, $modelId, $cleanName . '.' . $ext, $systemType, $relativePath, $fileSize, $version, $description]);
                break;

            default:
                throw new Exception('Invalid upload type');
        }

        echo json_encode(['success' => true, 'message' => "$displayName uploaded successfully"]);
    } catch (Exception $e) {
        // Clean up file on DB failure
        if (file_exists($destPath)) unlink($destPath);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Invalid request']);
