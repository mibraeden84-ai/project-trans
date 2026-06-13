<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (!canManageFiles()) {
    header('Location: login.php');
    exit;
}

$db = Database::getInstance();
$adminTablePageSize = defined('ADMIN_TABLE_PAGE_SIZE') ? ADMIN_TABLE_PAGE_SIZE : 200;

// Backfill legacy editor permission rows so tab/edit controls work immediately.
try {
    if (DB_DRIVER === 'pgsql') {
        $db->query(
            "UPDATE user_permissions up
             SET can_view_configs = 1,
                 can_view_firmware = 1,
                 can_view_manuals = 1,
                 can_view_software = 1,
                 can_view_brands_models = 1,
                 can_edit_files = 1
             FROM users u
             WHERE u.id = up.user_id
               AND u.role = 'editor'
               AND up.can_view_configs = 0
               AND up.can_view_firmware = 0
               AND up.can_view_manuals = 0
               AND up.can_view_software = 0
               AND up.can_view_brands_models = 0
               AND up.can_edit_files = 0"
        );
    } else {
        $db->query(
            "UPDATE user_permissions up
             JOIN users u ON u.id = up.user_id
             SET up.can_view_configs = 1,
                 up.can_view_firmware = 1,
                 up.can_view_manuals = 1,
                 up.can_view_software = 1,
                 up.can_view_brands_models = 1,
                 up.can_edit_files = 1
             WHERE u.role = 'editor'
               AND up.can_view_configs = 0
               AND up.can_view_firmware = 0
               AND up.can_view_manuals = 0
               AND up.can_view_software = 0
               AND up.can_view_brands_models = 0
               AND up.can_edit_files = 0"
        );
    }
} catch (Throwable $e) {
    // Keep dashboard usable even if migration-like backfill fails.
}

function adminEnsureBrandImageColumn(Database $db): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        if (DB_DRIVER === 'pgsql') {
            $exists = $db->fetchOne(
                "SELECT 1 AS ok
                 FROM information_schema.columns
                 WHERE table_name = 'brands' AND column_name = 'image'
                 LIMIT 1"
            );
            if (!$exists) {
                $db->query("ALTER TABLE brands ADD COLUMN image VARCHAR(255) NULL");
            }
        } else {
            $exists = $db->fetchOne("SHOW COLUMNS FROM brands LIKE 'image'");
            if (!$exists) {
                $db->query("ALTER TABLE brands ADD COLUMN image VARCHAR(255) NULL");
            }
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('Brand image schema check failed: ' . $e->getMessage());
        $ready = false;
    }

    return $ready;
}

$brandImageColumnReady = adminEnsureBrandImageColumn($db);

// Admin can sign in as any active non-admin account
if (isAdmin() && isset($_GET['impersonate_user'])) {
    $targetUserId = (int)($_GET['impersonate_user'] ?? 0);
    $targetUser = $targetUserId > 0
        ? $db->fetchOne("SELECT id, username, role, image, is_active FROM users WHERE id = ?", [$targetUserId])
        : null;

    if ($targetUser && (int)($targetUser['is_active'] ?? 0) === 1 && (int)$targetUser['id'] !== (int)($_SESSION['user_id'] ?? 0)) {
        if (empty($_SESSION['_impersonator_id'])) {
            $_SESSION['_impersonator_id'] = (int)($_SESSION['user_id'] ?? 0);
            $_SESSION['_impersonator_username'] = $_SESSION['user_username'] ?? 'admin';
            $_SESSION['_impersonator_role'] = $_SESSION['user_role'] ?? 'admin';
            $_SESSION['_impersonator_image'] = $_SESSION['user_image'] ?? null;
        }

        $_SESSION['user_id'] = (int)$targetUser['id'];
        $_SESSION['user_username'] = $targetUser['username'];
        $_SESSION['user_role'] = $targetUser['role'];
        $_SESSION['user_image'] = $targetUser['image'];
        unset($_SESSION['_perms'], $_SESSION['_img_refreshed']);
        markUserLoginActivity((int)$targetUser['id']);

        $redirectTo = in_array($targetUser['role'], ['admin', 'editor'], true)
            ? 'dashboard.php'
            : '../index.php';
        header('Location: ' . $redirectTo);
        exit;
    }

    setFlash('Unable to sign in as selected user account', 'error');
    header('Location: dashboard.php#users');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    finalizeUserUsageSession((int)($_SESSION['user_id'] ?? 0));
    session_destroy();
    header('Location: login.php');
    exit;
}

// Handle user edit (admin only)
if (isAdmin() && isset($_POST['edit_user'])) {
    $uid = (int)$_POST['edit_user_id'];
    $editUsername = trim($_POST['edit_username'] ?? '');
    $editEmail = trim($_POST['edit_email'] ?? '');
    $editRole = $_POST['edit_role'] ?? 'user';
    $editPassword = $_POST['edit_password'] ?? '';
    if ($uid && $editUsername) {
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ? AND id != ?", [$editUsername, $uid]);
        if ($existing) {
            $editUserError = 'Username already taken';
        } else {
            $current = $db->fetchOne("SELECT image, role FROM users WHERE id = ?", [$uid]);
            $imagePath = $current['image'] ?? null;
            if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] === UPLOAD_ERR_OK) {
                $imgDir = __DIR__ . '/../uploads/users';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['edit_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (in_array($ext, $allowed)) {
                    $imgName = 'user_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['edit_image']['tmp_name'], $imgDir . '/' . $imgName);
                    $imagePath = 'uploads/users/' . $imgName;
                    if ($current['image'] && file_exists(__DIR__ . '/../' . $current['image'])) {
                        unlink(__DIR__ . '/../' . $current['image']);
                    }
                }
            }
            if ($editPassword) {
                $hash = password_hash($editPassword, PASSWORD_BCRYPT);
                $db->query("UPDATE users SET username=?, email=?, image=?, role=?, password_hash=? WHERE id=?",
                    [$editUsername, $editEmail, $imagePath, $editRole, $hash, $uid]);
            } else {
                $db->query("UPDATE users SET username=?, email=?, image=?, role=? WHERE id=?",
                    [$editUsername, $editEmail, $imagePath, $editRole, $uid]);
            }
            // If editing own profile, update session
            if ($uid === (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['user_username'] = $editUsername;
                $_SESSION['user_image'] = $imagePath;
                $_SESSION['user_role'] = $editRole;
                unset($_SESSION['_perms']);
            }
            // Save permissions (admin sets these for editors)
            if ($editRole === 'editor') {
                $perms = [
                    'can_upload' => 1,
                    'can_delete' => isset($_POST['perm_delete']) ? 1 : 0,
                    'can_manage_brands' => isset($_POST['perm_manage_brands']) ? 1 : 0,
                    'can_manage_models' => isset($_POST['perm_manage_models']) ? 1 : 0,
                    'can_view_configs' => 1,
                    'can_view_firmware' => 1,
                    'can_view_manuals' => 1,
                    'can_view_software' => 1,
                    'can_view_brands_models' => 1,
                    'can_edit_files' => 1,
                ];
                if (DB_DRIVER === 'pgsql') {
                    $db->query(
                        "INSERT INTO user_permissions (user_id, can_upload, can_delete, can_manage_brands, can_manage_models, can_view_configs, can_view_firmware, can_view_manuals, can_view_software, can_view_brands_models, can_edit_files)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON CONFLICT (user_id) DO UPDATE
                         SET can_upload=EXCLUDED.can_upload, can_delete=EXCLUDED.can_delete, can_manage_brands=EXCLUDED.can_manage_brands, can_manage_models=EXCLUDED.can_manage_models",
                        [$uid, $perms['can_upload'], $perms['can_delete'], $perms['can_manage_brands'], $perms['can_manage_models'], $perms['can_view_configs'], $perms['can_view_firmware'], $perms['can_view_manuals'], $perms['can_view_software'], $perms['can_view_brands_models'], $perms['can_edit_files']]
                    );
                } else {
                    $db->query(
                        "INSERT INTO user_permissions (user_id, can_upload, can_delete, can_manage_brands, can_manage_models, can_view_configs, can_view_firmware, can_view_manuals, can_view_software, can_view_brands_models, can_edit_files)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                           can_upload=VALUES(can_upload), can_delete=VALUES(can_delete), can_manage_brands=VALUES(can_manage_brands), can_manage_models=VALUES(can_manage_models)",
                        [$uid, $perms['can_upload'], $perms['can_delete'], $perms['can_manage_brands'], $perms['can_manage_models'], $perms['can_view_configs'], $perms['can_view_firmware'], $perms['can_view_manuals'], $perms['can_view_software'], $perms['can_view_brands_models'], $perms['can_edit_files']]
                    );
                }
            }
            setFlash("User updated");
        }
    } else {
        $editUserError = 'Username is required';
    }
}

// Handle user creation (admin only)
if (isAdmin() && isset($_POST['create_user'])) {
    $newUsername = trim($_POST['new_username'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $newEmail    = trim($_POST['new_email'] ?? '');
    $newRole     = $_POST['new_role'] ?? 'user';
    if ($newUsername && $newPassword) {
        $existing = $db->fetchOne("SELECT id FROM users WHERE username = ?", [$newUsername]);
        if ($existing) {
            $userError = 'Username already taken';
        } else {
            $imagePath = null;
            if (isset($_FILES['user_image']) && $_FILES['user_image']['error'] === UPLOAD_ERR_OK) {
                $imgDir = __DIR__ . '/../uploads/users';
                if (!is_dir($imgDir)) mkdir($imgDir, 0755, true);
                $ext = strtolower(pathinfo($_FILES['user_image']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                if (in_array($ext, $allowed)) {
                    $imgName = 'user_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['user_image']['tmp_name'], $imgDir . '/' . $imgName);
                    $imagePath = 'uploads/users/' . $imgName;
                }
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $newId = $db->insert("INSERT INTO users (username, password_hash, email, image, role, is_active) VALUES (?, ?, ?, ?, ?, 1)",
                [$newUsername, $hash, $newEmail, $imagePath, $newRole]);
            // Create default permissions for editor
            if ($newRole === 'editor') {
                $perms = [
                    'can_upload' => 1,
                    'can_delete' => isset($_POST['perm_delete']) ? 1 : 0,
                    'can_manage_brands' => isset($_POST['perm_manage_brands']) ? 1 : 0,
                    'can_manage_models' => isset($_POST['perm_manage_models']) ? 1 : 0,
                    'can_view_configs' => 1,
                    'can_view_firmware' => 1,
                    'can_view_manuals' => 1,
                    'can_view_software' => 1,
                    'can_view_brands_models' => 1,
                    'can_edit_files' => 1,
                ];
                $db->insert(
                    "INSERT INTO user_permissions (user_id, can_upload, can_delete, can_manage_brands, can_manage_models, can_view_configs, can_view_firmware, can_view_manuals, can_view_software, can_view_brands_models, can_edit_files)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$newId, $perms['can_upload'], $perms['can_delete'], $perms['can_manage_brands'], $perms['can_manage_models'], $perms['can_view_configs'], $perms['can_view_firmware'], $perms['can_view_manuals'], $perms['can_view_software'], $perms['can_view_brands_models'], $perms['can_edit_files']]
                );
            }
            setFlash("User '$newUsername' created");
            header('Location: dashboard.php#users');
            exit;
        }
    } else {
        $userError = 'Username and password are required';
    }
}

// Handle admin-control permission updates for editors
if (isAdmin() && isset($_POST['save_access_control'])) {
    $accessUserId = (int)($_POST['access_user_id'] ?? 0);
    $target = $accessUserId > 0
        ? $db->fetchOne("SELECT id, username, role FROM users WHERE id = ?", [$accessUserId])
        : null;

    if (!$target || ($target['role'] ?? '') !== 'editor') {
        setFlash('Please choose a valid editor account', 'error');
        header('Location: dashboard.php#admin-control');
        exit;
    }

    $perms = [
        'can_upload' => isset($_POST['access_can_upload']) ? 1 : 0,
        'can_edit_files' => isset($_POST['access_can_edit_files']) ? 1 : 0,
        'can_delete' => isset($_POST['access_can_delete']) ? 1 : 0,
        'can_manage_brands' => isset($_POST['access_can_manage_brands']) ? 1 : 0,
        'can_manage_models' => isset($_POST['access_can_manage_models']) ? 1 : 0,
        'can_view_configs' => isset($_POST['access_can_view_configs']) ? 1 : 0,
        'can_view_firmware' => isset($_POST['access_can_view_firmware']) ? 1 : 0,
        'can_view_manuals' => isset($_POST['access_can_view_manuals']) ? 1 : 0,
        'can_view_software' => isset($_POST['access_can_view_software']) ? 1 : 0,
        'can_view_brands_models' => isset($_POST['access_can_view_brands_models']) ? 1 : 0,
    ];

    if ($perms['can_upload'] === 0) {
        $perms['can_edit_files'] = 0;
    }

    if (DB_DRIVER === 'pgsql') {
        $db->query(
            "INSERT INTO user_permissions (user_id, can_upload, can_delete, can_manage_brands, can_manage_models, can_view_configs, can_view_firmware, can_view_manuals, can_view_software, can_view_brands_models, can_edit_files)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE
             SET can_upload=EXCLUDED.can_upload,
                 can_delete=EXCLUDED.can_delete,
                 can_manage_brands=EXCLUDED.can_manage_brands,
                 can_manage_models=EXCLUDED.can_manage_models,
                 can_view_configs=EXCLUDED.can_view_configs,
                 can_view_firmware=EXCLUDED.can_view_firmware,
                 can_view_manuals=EXCLUDED.can_view_manuals,
                 can_view_software=EXCLUDED.can_view_software,
                 can_view_brands_models=EXCLUDED.can_view_brands_models,
                 can_edit_files=EXCLUDED.can_edit_files",
            [$accessUserId, $perms['can_upload'], $perms['can_delete'], $perms['can_manage_brands'], $perms['can_manage_models'], $perms['can_view_configs'], $perms['can_view_firmware'], $perms['can_view_manuals'], $perms['can_view_software'], $perms['can_view_brands_models'], $perms['can_edit_files']]
        );
    } else {
        $db->query(
            "INSERT INTO user_permissions (user_id, can_upload, can_delete, can_manage_brands, can_manage_models, can_view_configs, can_view_firmware, can_view_manuals, can_view_software, can_view_brands_models, can_edit_files)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               can_upload=VALUES(can_upload),
               can_delete=VALUES(can_delete),
               can_manage_brands=VALUES(can_manage_brands),
               can_manage_models=VALUES(can_manage_models),
               can_view_configs=VALUES(can_view_configs),
               can_view_firmware=VALUES(can_view_firmware),
               can_view_manuals=VALUES(can_view_manuals),
               can_view_software=VALUES(can_view_software),
               can_view_brands_models=VALUES(can_view_brands_models),
               can_edit_files=VALUES(can_edit_files)",
            [$accessUserId, $perms['can_upload'], $perms['can_delete'], $perms['can_manage_brands'], $perms['can_manage_models'], $perms['can_view_configs'], $perms['can_view_firmware'], $perms['can_view_manuals'], $perms['can_view_software'], $perms['can_view_brands_models'], $perms['can_edit_files']]
        );
    }

    unset($_SESSION['_perms']);
    setFlash("Access updated for editor '" . ($target['username'] ?? 'user') . "'");
    header('Location: dashboard.php?access_user=' . $accessUserId . '#admin-control');
    exit;
}

// Handle user toggle active (admin only)
if (isAdmin() && isset($_GET['toggle_user'])) {
    $uid = (int)$_GET['toggle_user'];
    $u = $db->fetchOne("SELECT id, is_active FROM users WHERE id = ?", [$uid]);
    if ($u) {
        $newVal = $u['is_active'] ? 0 : 1;
        $db->query("UPDATE users SET is_active = ? WHERE id = ?", [$newVal, $uid]);
    }
    header('Location: dashboard.php#users');
    exit;
}

// Handle user delete (admin only, non-admin users)
if (isAdmin() && isset($_GET['delete_user'])) {
    $uid = (int)$_GET['delete_user'];
    $u = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$uid]);
    if ($u && $u['role'] !== 'admin') {
        $db->query("DELETE FROM users WHERE id = ?", [$uid]);
    }
    header('Location: dashboard.php#users');
    exit;
}

// Handle bulk user management (admin only)
if (isAdmin() && isset($_POST['bulk_user_manage'])) {
    $action = $_POST['bulk_user_action'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [])))));
    $allowedActions = ['activate', 'deactivate', 'delete'];

    if (!in_array($action, $allowedActions, true)) {
        setFlash('Select a valid bulk action', 'error');
        header('Location: dashboard.php#users');
        exit;
    }

    if (empty($ids)) {
        setFlash('Select at least one user', 'error');
        header('Location: dashboard.php#users');
        exit;
    }

    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    $changed = 0;
    $skipped = 0;

    foreach ($ids as $uid) {
        if ($uid <= 0) {
            $skipped++;
            continue;
        }

        $u = $db->fetchOne("SELECT id, role, is_active FROM users WHERE id = ?", [$uid]);
        if (!$u) {
            $skipped++;
            continue;
        }

        if ($uid === $currentUserId) {
            $skipped++;
            continue;
        }

        if ($action === 'activate') {
            if (!(int)$u['is_active']) {
                $db->query("UPDATE users SET is_active = 1 WHERE id = ?", [$uid]);
                $changed++;
            }
            continue;
        }

        if ($action === 'deactivate') {
            if ((int)$u['is_active']) {
                $db->query("UPDATE users SET is_active = 0 WHERE id = ?", [$uid]);
                $changed++;
            }
            continue;
        }

        if ($action === 'delete') {
            if (($u['role'] ?? '') === 'admin') {
                $skipped++;
                continue;
            }
            $db->query("DELETE FROM users WHERE id = ?", [$uid]);
            $changed++;
        }
    }

    $labels = [
        'activate' => 'activated',
        'deactivate' => 'deactivated',
        'delete' => 'deleted',
    ];
    $message = "Bulk action complete: $changed user(s) " . $labels[$action];
    if ($skipped > 0) {
        $message .= ", $skipped skipped";
    }
    setFlash($message, $changed > 0 ? 'success' : 'error');
    header('Location: dashboard.php#users');
    exit;
}

// Export users CSV (admin only)
if (isAdmin() && isset($_GET['export_users'])) {
    $rows = $db->fetchAll("SELECT id, username, email, role, is_active, created_at FROM users ORDER BY id ASC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Username', 'Email', 'Role', 'Status', 'Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (int)$r['id'],
            $r['username'] ?? '',
            $r['email'] ?? '',
            $r['role'] ?? '',
            ((int)($r['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'),
            $r['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Flash message helper
function setFlash($msg, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function flash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $type = ($f['type'] ?? 'success') === 'success' ? 'success' : 'error';
        $icon = $type === 'success' ? 'check-circle' : 'exclamation-circle';
        echo '<div class="flash-message flash-' . $type . '"><i class="fas fa-' . $icon . '"></i><span>' . escape($f['msg']) . '</span></div>';
    }
}

function normalizeStoredPath(?string $path): string {
    return ltrim(str_replace('\\', '/', trim((string)$path)), '/');
}

function adminImagePathOwned(string $path, array $prefixes): bool {
    foreach ($prefixes as $prefix) {
        if (strpos($path, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

function adminDeleteOwnedImage(?string $storedPath, array $prefixes): void {
    $relative = normalizeStoredPath($storedPath);
    if ($relative === '' || !adminImagePathOwned($relative, $prefixes)) {
        return;
    }
    $abs = __DIR__ . '/../' . $relative;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

function adminDeleteUploadIfUnused(Database $db, ?string $storedPath): void {
    $relative = normalizeStoredPath($storedPath);
    $allowedPrefixes = [
        'uploads/configs/',
        'uploads/firmware/',
        'uploads/manuals/',
        'uploads/software/',
    ];
    if ($relative === '' || !adminImagePathOwned($relative, $allowedPrefixes)) {
        return;
    }

    $activeReferences = 0;
    foreach (['config_files', 'firmware_files', 'manuals', 'software_files'] as $tableName) {
        $row = $db->fetchOne("SELECT COUNT(*) AS c FROM $tableName WHERE file_path = ? AND status = 'active'", [$relative]);
        $activeReferences += (int)($row['c'] ?? 0);
    }

    if ($activeReferences > 0) {
        return;
    }

    $abs = __DIR__ . '/../' . $relative;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

function adminSaveImageUpload(string $field, string $targetDir, string $prefix): array {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return ['ok' => true, 'path' => null];
    }

    $file = $_FILES[$field];
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Image upload failed. Please try again.'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'message' => 'Only JPG, PNG, GIF, WEBP, or SVG images are allowed.'];
    }

    $maxBytes = 6 * 1024 * 1024;
    if ((int)($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'message' => 'Image is too large. Maximum size is 6 MB.'];
    }

    $relativeDir = trim(str_replace('\\', '/', $targetDir), '/');
    $absDir = __DIR__ . '/../' . $relativeDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true)) {
        return ['ok' => false, 'message' => 'Unable to create image folder.'];
    }

    $name = $prefix . uniqid('', true) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['ok' => false, 'message' => 'Unable to save uploaded image.'];
    }

    return ['ok' => true, 'path' => $relativeDir . '/' . $name];
}

// Handle bulk file deletion (AJAX) — soft delete
if (isset($_POST['bulk_delete']) && isset($_POST['type']) && isset($_POST['ids'])) {
    if (!canDelete()) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
    $type = $_POST['type'];
    $ids = array_map('intval', (array)$_POST['ids']);
    $tableMap = ['config' => 'config_files', 'firmware' => 'firmware_files', 'manual' => 'manuals', 'software' => 'software_files'];
    if (!isset($tableMap[$type])) { echo json_encode(['success'=>false,'message'=>'Invalid type']); exit; }
    $table = $tableMap[$type];
    $deleted = 0;
    foreach ($ids as $id) {
        $file = $db->fetchOne("SELECT id, file_path FROM $table WHERE id = ?", [$id]);
        if ($file) {
            $db->query("UPDATE $table SET status = 'deleted' WHERE id = ?", [$id]);
            adminDeleteUploadIfUnused($db, $file['file_path'] ?? null);
            $deleted++;
        }
    }
    echo json_encode(['success'=>true,'deleted'=>$deleted]);
    exit;
}

// Handle file deletion (AJAX + regular) — soft delete
if (isset($_GET['delete']) && isset($_GET['type'])) {
    if (!canDelete()) { 
        if (isset($_GET['ajax'])) { echo json_encode(['success'=>false]); exit; }
        header('Location: dashboard.php'); exit;
    }
    $id = (int)$_GET['delete'];
    $type = $_GET['type'];
    $tableMap = ['config' => 'config_files', 'firmware' => 'firmware_files', 'manual' => 'manuals', 'software' => 'software_files'];
    $deleted = false;
    if (isset($tableMap[$type])) {
        $file = $db->fetchOne("SELECT name, file_path FROM {$tableMap[$type]} WHERE id = ?", [$id]);
        if ($file) {
            $db->query("UPDATE {$tableMap[$type]} SET status = 'deleted' WHERE id = ?", [$id]);
            adminDeleteUploadIfUnused($db, $file['file_path'] ?? null);
            $deleted = true;
            setFlash("Deleted " . $file['name']);
        }
    }
    if (isset($_GET['ajax'])) {
        echo json_encode(['success' => $deleted]);
        exit;
    }
    $tabMap = ['config' => 'configs', 'firmware' => 'firmware', 'manual' => 'manuals', 'software' => 'software'];
    header('Location: dashboard.php#' . ($tabMap[$type] ?? 'add-file'));
    exit;
}

// Handle file rename (AJAX)
if (isset($_POST['rename_file']) && isset($_POST['type']) && isset($_POST['file_id'])) {
    if (!canEditFiles()) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }
    $type = $_POST['type'];
    $id = (int)$_POST['file_id'];
    $newName = trim($_POST['name'] ?? '');
    $newVersion = trim($_POST['version'] ?? '');
    $newDescription = trim($_POST['description'] ?? '');
    $tableMap = ['config' => 'config_files', 'firmware' => 'firmware_files', 'manual' => 'manuals', 'software' => 'software_files'];
    if (!isset($tableMap[$type])) { echo json_encode(['success'=>false,'message'=>'Invalid type']); exit; }
    $table = $tableMap[$type];
    if (empty($newName)) { echo json_encode(['success'=>false,'message'=>'Name is required']); exit; }
    if ($type === 'firmware') {
        $changelog = trim($_POST['changelog'] ?? '');
        $db->query("UPDATE $table SET name = ?, version = ?, changelog = ? WHERE id = ?", [$newName, $newVersion, $changelog, $id]);
    } elseif ($type === 'manual') {
        $db->query("UPDATE $table SET name = ?, description = ? WHERE id = ?", [$newName, $newDescription, $id]);
    } else {
        $db->query("UPDATE $table SET name = ?, version = ?, description = ? WHERE id = ?", [$newName, $newVersion, $newDescription, $id]);
    }
    echo json_encode(['success'=>true]);
    exit;
}

// Handle brand rename/color update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_brand'])) {
    if (!canManageBrands()) { setFlash('You don\'t have permission to edit brands', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $brandName = trim($_POST['brand_name'] ?? '');
    $brandColor = trim($_POST['brand_color'] ?? '#005aa0');
    $currentBrand = $brandId > 0
        ? $db->fetchOne($brandImageColumnReady ? "SELECT id, image FROM brands WHERE id = ?" : "SELECT id FROM brands WHERE id = ?", [$brandId])
        : null;
    $brandImagePath = $currentBrand['image'] ?? null;

    if (!$brandId || $brandName === '') {
        setFlash('Brand name is required', 'error');
    } else {
        $existing = $db->fetchOne("SELECT id FROM brands WHERE LOWER(name) = LOWER(?) AND id != ?", [$brandName, $brandId]);
        if ($existing) {
            setFlash('Another brand already uses that name', 'error');
        } else {
            $slug = slugify($brandName);
            $slugOwner = $db->fetchOne("SELECT id FROM brands WHERE slug = ? AND id != ?", [$slug, $brandId]);
            if ($slugOwner) {
                setFlash('Another brand already uses that URL slug', 'error');
            } else {
                $upload = adminSaveImageUpload('brand_image', 'uploads/brands', 'brand_');
                if (!($upload['ok'] ?? false)) {
                    setFlash($upload['message'] ?? 'Unable to upload brand image', 'error');
                } else {
                    $uploadedPath = $upload['path'] ?? null;
                    if ($uploadedPath) {
                        if ($brandImageColumnReady) {
                            adminDeleteOwnedImage($brandImagePath, ['uploads/brands/']);
                            $brandImagePath = $uploadedPath;
                        } else {
                            adminDeleteOwnedImage($uploadedPath, ['uploads/brands/']);
                        }
                    }
                    if ($brandImageColumnReady) {
                        $db->query("UPDATE brands SET name = ?, slug = ?, color = ?, image = ? WHERE id = ?", [$brandName, $slug, $brandColor, $brandImagePath, $brandId]);
                    } else {
                        $db->query("UPDATE brands SET name = ?, slug = ?, color = ? WHERE id = ?", [$brandName, $slug, $brandColor, $brandId]);
                    }
                    setFlash("Brand updated");
                }
            }
        }
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle brand image delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_brand_image'])) {
    if (!canManageBrands()) { setFlash('You don\'t have permission to edit brands', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $brand = $brandId > 0
        ? $db->fetchOne($brandImageColumnReady ? "SELECT id, image FROM brands WHERE id = ?" : "SELECT id FROM brands WHERE id = ?", [$brandId])
        : null;
    if ($brand) {
        if ($brandImageColumnReady) {
            $stored = normalizeStoredPath((string)($brand['image'] ?? ''));
            if ($stored !== '' && $stored !== '__none__') {
                adminDeleteOwnedImage($stored, ['uploads/brands/']);
            }
            // Use sentinel so even fallback/logo-by-slug image is hidden for this brand.
            $db->query("UPDATE brands SET image = ? WHERE id = ?", ['__none__', $brandId]);
            setFlash('Brand image removed');
        } else {
            setFlash('Brand image column is not available', 'error');
        }
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle brand delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_brand'])) {
    if (!canManageBrands()) { setFlash('You don\'t have permission to delete brands', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $brand = $db->fetchOne($brandImageColumnReady ? "SELECT id, name, image FROM brands WHERE id = ?" : "SELECT id, name FROM brands WHERE id = ?", [$brandId]);
    if ($brand) {
        $brandModels = $db->fetchAll("SELECT image_url FROM device_models WHERE brand_id = ?", [$brandId]);
        if ($brandImageColumnReady) {
            adminDeleteOwnedImage($brand['image'] ?? null, ['uploads/brands/']);
        }
        foreach ($brandModels as $modelImageRow) {
            adminDeleteOwnedImage($modelImageRow['image_url'] ?? null, ['uploads/models/']);
        }
        $db->query("DELETE FROM brands WHERE id = ?", [$brandId]);
        setFlash("Deleted brand " . $brand['name']);
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle model rename/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_model'])) {
    if (!canManageModels()) { setFlash('You don\'t have permission to edit models', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $modelId = (int)($_POST['model_id'] ?? 0);
    $modelBrandId = (int)($_POST['model_brand_id'] ?? 0);
    $modelName = trim($_POST['model_name'] ?? '');
    $modelSystemType = $_POST['model_system_type'] ?? null;
    $currentModel = $modelId > 0 ? $db->fetchOne("SELECT id, image_url FROM device_models WHERE id = ?", [$modelId]) : null;
    $modelImagePath = $currentModel['image_url'] ?? null;
    if ($modelSystemType === '') $modelSystemType = null;

    if (!$modelId || !$modelBrandId || $modelName === '') {
        setFlash('Model name and brand are required', 'error');
    } else {
        $existing = $db->fetchOne(
            "SELECT id FROM device_models WHERE brand_id = ? AND LOWER(name) = LOWER(?) AND id != ?",
            [$modelBrandId, $modelName, $modelId]
        );
        if ($existing) {
            setFlash('That brand already has a model with this name', 'error');
        } else {
            $slug = slugify($modelName);
            $slugOwner = $db->fetchOne("SELECT id FROM device_models WHERE brand_id = ? AND slug = ? AND id != ?", [$modelBrandId, $slug, $modelId]);
            if ($slugOwner) {
                $slug = slugify($modelName . '-' . $modelBrandId);
            }
            $upload = adminSaveImageUpload('model_image', 'uploads/models', 'model_');
            if (!($upload['ok'] ?? false)) {
                setFlash($upload['message'] ?? 'Unable to upload model image', 'error');
            } else {
                $uploadedPath = $upload['path'] ?? null;
                if ($uploadedPath) {
                    adminDeleteOwnedImage($modelImagePath, ['uploads/models/']);
                    $modelImagePath = $uploadedPath;
                }
                $db->query("UPDATE device_models SET brand_id = ?, name = ?, slug = ?, system_type = ?, image_url = ? WHERE id = ?",
                    [$modelBrandId, $modelName, $slug, $modelSystemType, $modelImagePath, $modelId]);
                setFlash("Model updated");
            }
        }
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle model image delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_model_image'])) {
    if (!canManageModels()) { setFlash('You don\'t have permission to edit models', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $modelId = (int)($_POST['model_id'] ?? 0);
    $model = $modelId > 0 ? $db->fetchOne("SELECT id, image_url FROM device_models WHERE id = ?", [$modelId]) : null;
    if ($model) {
        adminDeleteOwnedImage($model['image_url'] ?? null, ['uploads/models/']);
        $db->query("UPDATE device_models SET image_url = NULL WHERE id = ?", [$modelId]);
        setFlash('Model image deleted');
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle model delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_model'])) {
    if (!canManageModels()) { setFlash('You don\'t have permission to delete models', 'error'); header('Location: dashboard.php#brands-models'); exit; }
    $modelId = (int)($_POST['model_id'] ?? 0);
    $model = $db->fetchOne("SELECT id, name, image_url FROM device_models WHERE id = ?", [$modelId]);
    if ($model) {
        adminDeleteOwnedImage($model['image_url'] ?? null, ['uploads/models/']);
        $db->query("DELETE FROM device_models WHERE id = ?", [$modelId]);
        setFlash("Deleted model " . $model['name']);
    }
    header('Location: dashboard.php#brands-models');
    exit;
}

// Handle inline brand creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_brand']) && isset($_POST['brand_name']) && $_POST['brand_name'] !== '') {
    if (!canManageBrands()) { setFlash('You don\'t have permission to create brands', 'error'); header('Location: dashboard.php#add-file'); exit; }
    $brandName = trim($_POST['brand_name']);
    $brandColor = trim($_POST['brand_color'] ?? '#005aa0');
    if ($brandName) {
        $slug = slugify($brandName);
        $existing = $db->fetchOne("SELECT id FROM brands WHERE slug = ?", [$slug]);
        $return = $_POST['return_to'] ?? 'dashboard.php#add-file';
        $anchor = '';
        $hashPos = strpos($return, '#');
        if ($hashPos !== false) {
            $anchor = substr($return, $hashPos);
            $return = substr($return, 0, $hashPos);
        }
        $sep = strpos($return, '?') === false ? '?' : '&';
        if (!$existing) {
            $upload = adminSaveImageUpload('brand_image', 'uploads/brands', 'brand_');
            if (!($upload['ok'] ?? false)) {
                setFlash($upload['message'] ?? 'Unable to upload brand image', 'error');
                header("Location: $return$anchor");
                exit;
            }
            if ($brandImageColumnReady) {
                $newId = $db->insert("INSERT INTO brands (name, slug, color, image) VALUES (?, ?, ?, ?)", [$brandName, $slug, $brandColor, $upload['path'] ?? null]);
            } else {
                if (!empty($upload['path'])) {
                    adminDeleteOwnedImage($upload['path'], ['uploads/brands/']);
                }
                $newId = $db->insert("INSERT INTO brands (name, slug, color) VALUES (?, ?, ?)", [$brandName, $slug, $brandColor]);
            }
            setFlash("Brand '$brandName' created");
            header("Location: $return{$sep}created_brand=$newId$anchor");
            exit;
        }
        setFlash("Brand '$brandName' already exists; selected existing brand", 'error');
        header("Location: $return{$sep}created_brand=" . (int)$existing['id'] . $anchor);
        exit;
    }
    header('Location: dashboard.php#add-file');
    exit;
}

// Handle inline model creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_model']) && isset($_POST['model_name']) && $_POST['model_name'] !== '') {
    if (!canManageModels()) { setFlash('You don\'t have permission to create models', 'error'); header('Location: dashboard.php#add-file'); exit; }
    $modelName = trim($_POST['model_name']);
    $modelBrandId = (int)($_POST['model_brand_id'] ?? 0);
    $modelSystemType = $_POST['model_system_type'] ?? null;
    if ($modelSystemType === '') $modelSystemType = null;
    if ($modelName && $modelBrandId) {
        $slug = slugify($modelName . '-' . $modelBrandId);
        $existing = $db->fetchOne("SELECT id FROM device_models WHERE brand_id = ? AND slug = ?", [$modelBrandId, $slug]);
        if (!$existing) {
            $upload = adminSaveImageUpload('model_image', 'uploads/models', 'model_');
            if (!($upload['ok'] ?? false)) {
                setFlash($upload['message'] ?? 'Unable to upload model image', 'error');
                header('Location: dashboard.php#add-file');
                exit;
            }
            $newId = $db->insert("INSERT INTO device_models (brand_id, name, slug, system_type, image_url) VALUES (?, ?, ?, ?, ?)", [$modelBrandId, $modelName, $slug, $modelSystemType, $upload['path'] ?? null]);
            $return = $_POST['return_to'] ?? 'dashboard.php#add-file';
            $anchor = '';
            $hashPos = strpos($return, '#');
            if ($hashPos !== false) {
                $anchor = substr($return, $hashPos);
                $return = substr($return, 0, $hashPos);
            }
            $sep = strpos($return, '?') === false ? '?' : '&';
            header("Location: $return{$sep}created_model=$newId$anchor");
            exit;
        }
    }
    header('Location: dashboard.php#add-file');
    exit;
}

// File upload handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $isAjaxUpload = isset($_POST['ajax']) && (string)$_POST['ajax'] === '1';
    $uploadRespond = function(bool $success, string $message, string $tab = 'add-file') use ($isAjaxUpload) {
        if ($isAjaxUpload) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $success, 'message' => $message, 'tab' => $tab]);
            exit;
        }
        setFlash($message, $success ? 'success' : 'error');
        header('Location: dashboard.php#' . $tab);
        exit;
    };

    if (!canUpload()) { $uploadRespond(false, 'You don\'t have upload permission'); }
    $uploadType  = $_POST['upload_type'] ?? '';
    $displayName = trim($_POST['display_name'] ?? '');
    $brandId     = (int)($_POST['brand_id'] ?? 0);
    $modelIdRaw  = $_POST['model_id'] ?? '';
    $modelId     = $modelIdRaw === '' ? null : (int)$modelIdRaw;
    $version     = $_POST['version'] ?? '1.0';
    $description = $_POST['description'] ?? '';
    $changelog   = $_POST['changelog'] ?? '';
    $systemType  = $_POST['system_type'] ?? null;
    if ($systemType === '') $systemType = null;

    if (empty($displayName)) { $uploadRespond(false, 'Display name is required'); }

    $uploadSubdirs = ['config' => 'configs', 'firmware' => 'firmware', 'manual' => 'manuals', 'software' => 'software'];
    $tableMap = ['config' => 'config_files', 'firmware' => 'firmware_files', 'manual' => 'manuals', 'software' => 'software_files'];
    $allowedExtsByType = [
        'config' => ALLOWED_CONFIG_EXT,
        'firmware' => ALLOWED_FIRMWARE_EXT,
        'manual' => ALLOWED_MANUAL_EXT,
        'software' => ALLOWED_SOFTWARE_EXT,
    ];

    if (isset($uploadSubdirs[$uploadType]) && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = $allowedExtsByType[$uploadType] ?? [];

        if (!in_array($ext, $allowedExts, true)) {
            $uploadRespond(false, "File type .$ext not allowed for " . $uploadType);
        }
        if ($file['size'] > MAX_FILE_SIZE) { $uploadRespond(false, 'File exceeds max size'); }

        $subdir = $uploadSubdirs[$uploadType];

        // Validate required fields per type
        if (!$brandId) { $uploadRespond(false, 'Brand is required'); }
        if ($uploadType === 'config' && !$modelId) { $uploadRespond(false, 'Model is required for config files'); }
        if ($uploadType !== 'config' && !$modelId) {
            $modelId = null;
        }
        if ($modelId) {
            $modelRow = $db->fetchOne("SELECT id, brand_id FROM device_models WHERE id = ?", [$modelId]);
            if (!$modelRow) {
                $uploadRespond(false, 'Selected model does not exist');
            }
            if ((int)$modelRow['brand_id'] !== (int)$brandId) {
                $uploadRespond(false, 'Selected model does not belong to selected brand');
            }
        }

        $destDir = __DIR__ . '/../uploads/' . $subdir;
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        // Use display name for the stored filename — strip any extension user may have typed
        $cleanName = pathinfo($displayName, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $cleanName) . '.' . $ext;
        $uniqueName = uniqid() . '_' . $safeName;
        $destPath = $destDir . '/' . $uniqueName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $uploadRespond(false, 'Failed to save file');
        }

        $relativePath = 'uploads/' . $subdir . '/' . $uniqueName;
        $fileSize = (int)($file['size'] ?? 0);
        $dbName = $cleanName . '.' . $ext;

        try {
            if ($uploadType === 'config') {
                $db->insert("INSERT INTO config_files (category, status, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['config', 'active', $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $description]);
            } elseif ($uploadType === 'firmware') {
                $db->insert("INSERT INTO firmware_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, changelog) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['firmware', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $changelog]);
            } elseif ($uploadType === 'manual') {
                $db->insert("INSERT INTO manuals (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['manual', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $description]);
            } elseif ($uploadType === 'software') {
                $db->insert("INSERT INTO software_files (category, status, brand_id, device_model_id, name, system_type, file_path, file_size, version, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    ['software', 'active', $brandId, $modelId, $dbName, $systemType, $relativePath, $fileSize, $version, $description]);
            }
            $tabMap = ['config' => 'configs', 'firmware' => 'firmware', 'manual' => 'manuals', 'software' => 'software'];
            $tab = $tabMap[$uploadType] ?? 'add-file';
            $uploadRespond(true, "$displayName uploaded successfully and is now visible on user side.", $tab);
        } catch (Exception $e) {
            if (file_exists($destPath)) unlink($destPath);
            $uploadRespond(false, 'Upload failed: ' . $e->getMessage());
        }
    } else {
        $uploadRespond(false, 'No file selected or upload error');
    }
}

function systemTypeBadge($type) {
    if (!$type) return '<span class="badge badge-none">None</span>';
    $isAdvanced = $type === 'advanced';
    $label = $isAdvanced ? 'Advanced' : 'Standard';
    $class = $isAdvanced ? 'badge-advanced' : 'badge-standard';
    return '<span class="badge ' . $class . '">' . $label . '</span>';
}

function resolveAdminBrandImageSrc(array $brandRow) {
    $stored = trim((string)($brandRow['image'] ?? ''));
    if ($stored === '__none__') {
        return null;
    }
    if ($stored !== '') {
        $relative = ltrim(str_replace('\\', '/', $stored), '/');
        if (file_exists(__DIR__ . '/../' . $relative)) {
            return '../' . $relative;
        }
    }

    $slug = strtolower(trim((string)($brandRow['slug'] ?? '')));
    if ($slug === '') return null;

    $candidates = [
        "assets/images/{$slug}_logo.svg",
        "assets/images/{$slug}_logo.png",
        "assets/images/{$slug}.png",
        "assets/images/{$slug}.webp",
        "assets/images/{$slug}.jpg",
        "assets/images/{$slug}.jpeg",
    ];

    foreach ($candidates as $relative) {
        if (file_exists(__DIR__ . '/../' . $relative)) {
            return '../' . $relative;
        }
    }

    return null;
}

function resolveAdminModelImageSrc(array $modelRow) {
    $stored = trim((string)($modelRow['image_url'] ?? ''));
    if ($stored === '') return null;
    $relative = ltrim(str_replace('\\', '/', $stored), '/');
    if (file_exists(__DIR__ . '/../' . $relative)) {
        return '../' . $relative;
    }
    return null;
}

function formatUsEtDateTime(?string $value, string $format = 'M d, Y h:i A'): string {
    $raw = trim((string)$value);
    if ($raw === '') return '';
    try {
        $dt = new DateTime($raw);
        $dt->setTimezone(new DateTimeZone('America/New_York'));
        return $dt->format($format) . ' ET';
    } catch (Throwable $e) {
        return '';
    }
}

function nowUsEtLabel(string $format = 'h:i:s A'): string {
    try {
        $tz = defined('DASH_TIMEZONE') ? DASH_TIMEZONE : 'America/New_York';
        $label = defined('DASH_TIMEZONE_LABEL') ? DASH_TIMEZONE_LABEL : 'ET';
        $dt = new DateTime('now', new DateTimeZone($tz));
        return $dt->format($format) . ' ' . $label;
    } catch (Throwable $e) {
        return '';
    }
}

function normalizeDashboardDateRange(string $reportFromInput, string $reportToInput): array {
    $reportDefaultFrom = date('Y-m-01');
    $reportDefaultTo = date('Y-m-d');
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';

    $reportFrom = trim($reportFromInput);
    $reportTo = trim($reportToInput);
    if ($reportFrom === '') $reportFrom = $reportDefaultFrom;
    if ($reportTo === '') $reportTo = $reportDefaultTo;

    if (!preg_match($datePattern, $reportFrom) || strtotime($reportFrom) === false) {
        $reportFrom = $reportDefaultFrom;
    }
    if (!preg_match($datePattern, $reportTo) || strtotime($reportTo) === false) {
        $reportTo = $reportDefaultTo;
    }
    if (strtotime($reportFrom) > strtotime($reportTo)) {
        $tmp = $reportFrom;
        $reportFrom = $reportTo;
        $reportTo = $tmp;
    }

    return [
        'from' => $reportFrom,
        'to' => $reportTo,
        'start' => $reportFrom . ' 00:00:00',
        'end' => $reportTo . ' 23:59:59',
        'label' => date('M d, Y', strtotime($reportFrom)) . ' - ' . date('M d, Y', strtotime($reportTo)),
    ];
}

function fetchDashboardDownloadRangeData(Database $db, string $rangeStart, string $rangeEnd): array {
    $ready = ensureUserDownloadEventsTable();
    $stats = ['total' => 0, 'users' => 0];
    $byUser = [];
    $events = [];

    if ($ready) {
        $stats = $db->fetchOne(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS users
             FROM user_downloads
             WHERE downloaded_at >= ? AND downloaded_at <= ?",
            [$rangeStart, $rangeEnd]
        ) ?: $stats;

        $downloadRows = $db->fetchAll(
            "SELECT user_id, COUNT(*) AS c
             FROM user_downloads
             WHERE downloaded_at >= ? AND downloaded_at <= ?
             GROUP BY user_id",
            [$rangeStart, $rangeEnd]
        );
        foreach ($downloadRows as $downloadRow) {
            $byUser[(int)$downloadRow['user_id']] = (int)($downloadRow['c'] ?? 0);
        }

        $events = $db->fetchAll(
            "SELECT ud.user_id, ud.file_type, ud.file_name, ud.downloaded_at, u.username
             FROM user_downloads ud
             JOIN users u ON u.id = ud.user_id
             WHERE ud.downloaded_at >= ? AND ud.downloaded_at <= ?
             ORDER BY ud.downloaded_at DESC
             LIMIT 6",
            [$rangeStart, $rangeEnd]
        );
    }

    return [
        'ready' => $ready,
        'stats' => $stats,
        'by_user' => $byUser,
        'events' => $events,
    ];
}

$dashboardRange = normalizeDashboardDateRange((string)($_GET['report_from'] ?? ''), (string)($_GET['report_to'] ?? ''));
$reportFrom = $dashboardRange['from'];
$reportTo = $dashboardRange['to'];
$reportRangeStart = $dashboardRange['start'];
$reportRangeEnd = $dashboardRange['end'];
$reportRangeLabel = $dashboardRange['label'];

$dashboardDownloadData = fetchDashboardDownloadRangeData($db, $reportRangeStart, $reportRangeEnd);
$downloadEventsReady = (bool)$dashboardDownloadData['ready'];
$downloadRangeStats = $dashboardDownloadData['stats'];
$downloadRangeByUser = $dashboardDownloadData['by_user'];
$recentDownloadEvents = $dashboardDownloadData['events'];

if (isset($_GET['ajax']) && (string)($_GET['ajax'] ?? '') === 'dashboard_live') {
    header('Content-Type: application/json; charset=utf-8');

    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }

    $ajaxRangeFrom = trim((string)($_GET['range_from'] ?? ''));
    $ajaxRangeTo = trim((string)($_GET['range_to'] ?? ''));
    $ajaxDashboardRange = normalizeDashboardDateRange($ajaxRangeFrom, $ajaxRangeTo);
    $ajaxRangeStart = $ajaxDashboardRange['start'];
    $ajaxRangeEnd = $ajaxDashboardRange['end'];
    $ajaxRangeLabel = $ajaxDashboardRange['label'];

    $liveStats = [
        'brands' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM brands")['c'] ?? 0),
        'models' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM device_models")['c'] ?? 0),
        'configs' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM config_files WHERE status = 'active'")['c'] ?? 0),
        'firmware' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM firmware_files WHERE status = 'active'")['c'] ?? 0),
        'manuals' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM manuals WHERE status = 'active'")['c'] ?? 0),
        'software' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM software_files WHERE status = 'active'")['c'] ?? 0),
        'users' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM users")['c'] ?? 0),
    ];
    $liveStats['total_files'] = $liveStats['configs'] + $liveStats['firmware'] + $liveStats['manuals'] + $liveStats['software'];
    $liveRangeStats = [
        'configs' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM config_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$ajaxRangeStart, $ajaxRangeEnd])['c'] ?? 0),
        'firmware' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM firmware_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$ajaxRangeStart, $ajaxRangeEnd])['c'] ?? 0),
        'manuals' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM manuals WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$ajaxRangeStart, $ajaxRangeEnd])['c'] ?? 0),
        'software' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM software_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$ajaxRangeStart, $ajaxRangeEnd])['c'] ?? 0),
    ];
    $liveRangeTotal = $liveRangeStats['configs'] + $liveRangeStats['firmware'] + $liveRangeStats['manuals'] + $liveRangeStats['software'];
    $liveRangeSafe = max(1, $liveRangeTotal);
    $liveMix = [
        ['label' => 'Configs', 'count' => $liveRangeStats['configs'], 'percent' => round(($liveRangeStats['configs'] / $liveRangeSafe) * 100)],
        ['label' => 'Firmware', 'count' => $liveRangeStats['firmware'], 'percent' => round(($liveRangeStats['firmware'] / $liveRangeSafe) * 100)],
        ['label' => 'Manuals', 'count' => $liveRangeStats['manuals'], 'percent' => round(($liveRangeStats['manuals'] / $liveRangeSafe) * 100)],
        ['label' => 'Software', 'count' => $liveRangeStats['software'], 'percent' => round(($liveRangeStats['software'] / $liveRangeSafe) * 100)],
        ['label' => 'Total In Range', 'count' => $liveRangeTotal, 'percent' => 100],
    ];

    $usageColumnsReady = ensureUserUsageColumns();
    if ($usageColumnsReady) {
        $liveUsers = $db->fetchAll("SELECT id, last_seen_at, total_active_seconds, total_downloads FROM users ORDER BY id ASC LIMIT ?", [$adminTablePageSize]);
    } else {
        $liveUsers = $db->fetchAll("SELECT id, NULL AS last_seen_at, 0 AS total_active_seconds, 0 AS total_downloads FROM users ORDER BY id ASC LIMIT ?", [$adminTablePageSize]);
    }

    $liveUserPayload = [];
    foreach ($liveUsers as $liveUser) {
        $liveUserId = (int)($liveUser['id'] ?? 0);
        $liveUserPayload[] = [
            'id' => $liveUserId,
            'online' => isUserOnlineNow($liveUser['last_seen_at'] ?? null, 120),
            'usage_text' => formatUsageDuration((int)($liveUser['total_active_seconds'] ?? 0)),
            'downloads_total' => (int)($liveUser['total_downloads'] ?? 0),
            'downloads_range' => (int)($downloadRangeByUser[$liveUserId] ?? 0),
            'last_seen_text' => !empty($liveUser['last_seen_at']) ? timeAgo($liveUser['last_seen_at']) : 'never',
        ];
    }

    $recentDownloadPayload = [];
    foreach ($recentDownloadEvents as $downloadEvent) {
        $when = (string)($downloadEvent['downloaded_at'] ?? '');
        $recentDownloadPayload[] = [
            'user_id' => (int)($downloadEvent['user_id'] ?? 0),
            'username' => (string)($downloadEvent['username'] ?? 'User'),
            'file_type' => ucfirst((string)($downloadEvent['file_type'] ?? 'file')),
            'file_name' => (string)($downloadEvent['file_name'] ?? 'File'),
            'downloaded_at' => $when,
            'downloaded_label' => formatUsEtDateTime($when, 'M d, h:i A'),
        ];
    }

    echo json_encode([
        'success' => true,
        'generated_at' => date('c'),
        'generated_label' => nowUsEtLabel('h:i:s A'),
        'range' => [
            'from' => $ajaxDashboardRange['from'],
            'to' => $ajaxDashboardRange['to'],
            'label' => $ajaxRangeLabel,
        ],
        'downloads' => [
            'total' => (int)($downloadRangeStats['total'] ?? 0),
            'users' => (int)($downloadRangeStats['users'] ?? 0),
        ],
        'stats' => $liveStats,
        'range_mix' => $liveMix,
        'range_total' => $liveRangeTotal,
        'events_ready' => $downloadEventsReady,
        'recent_downloads' => $recentDownloadPayload,
        'users' => $liveUserPayload,
    ]);
    exit;
}

$brands = $db->getBrands();
$managedBrands = $db->fetchAll(
    "SELECT b.*,
        (SELECT COUNT(*) FROM device_models dm WHERE dm.brand_id = b.id) as model_count,
        (
            (SELECT COUNT(*) FROM firmware_files f WHERE f.brand_id = b.id AND f.status = 'active') +
            (SELECT COUNT(*) FROM manuals m WHERE m.brand_id = b.id AND m.status = 'active') +
            (SELECT COUNT(*) FROM software_files s WHERE s.brand_id = b.id AND s.status = 'active')
        ) as file_count
     FROM brands b
     ORDER BY b.name
     LIMIT ?",
    [$adminTablePageSize]
);
$managedModels = $db->fetchAll(
    "SELECT dm.*, b.name as brand_name,
        (SELECT COUNT(*) FROM config_files c WHERE c.device_model_id = dm.id AND c.status = 'active') as config_count,
        (
            (SELECT COUNT(*) FROM firmware_files f WHERE f.device_model_id = dm.id AND f.status = 'active') +
            (SELECT COUNT(*) FROM manuals m WHERE m.device_model_id = dm.id AND m.status = 'active') +
            (SELECT COUNT(*) FROM software_files s WHERE s.device_model_id = dm.id AND s.status = 'active')
        ) as file_count
     FROM device_models dm
     JOIN brands b ON dm.brand_id = b.id
     ORDER BY b.name, dm.name
     LIMIT ?",
    [$adminTablePageSize]
);
$stats = [
    'brands' => $db->fetchOne("SELECT COUNT(*) as c FROM brands")['c'],
    'models' => $db->fetchOne("SELECT COUNT(*) as c FROM device_models")['c'],
    'configs' => $db->fetchOne("SELECT COUNT(*) as c FROM config_files WHERE status = 'active'")['c'],
    'firmware' => $db->fetchOne("SELECT COUNT(*) as c FROM firmware_files WHERE status = 'active'")['c'],
    'manuals' => $db->fetchOne("SELECT COUNT(*) as c FROM manuals WHERE status = 'active'")['c'],
    'software' => $db->fetchOne("SELECT COUNT(*) as c FROM software_files WHERE status = 'active'")['c'],
    'users' => $db->fetchOne("SELECT COUNT(*) as c FROM users")['c'],
];
$totalFilesCount = (int)$stats['configs'] + (int)$stats['firmware'] + (int)$stats['manuals'] + (int)$stats['software'];
$totalFilesSafe = max(1, $totalFilesCount);
$fileMix = [
    ['label' => 'Configs', 'count' => (int)$stats['configs'], 'percent' => round(((int)$stats['configs'] / $totalFilesSafe) * 100)],
    ['label' => 'Firmware', 'count' => (int)$stats['firmware'], 'percent' => round(((int)$stats['firmware'] / $totalFilesSafe) * 100)],
    ['label' => 'Manuals', 'count' => (int)$stats['manuals'], 'percent' => round(((int)$stats['manuals'] / $totalFilesSafe) * 100)],
    ['label' => 'Software', 'count' => (int)$stats['software'], 'percent' => round(((int)$stats['software'] / $totalFilesSafe) * 100)],
    ['label' => 'Total Files', 'count' => $totalFilesCount, 'percent' => 100],
];
$todayStart = date('Y-m-d') . ' 00:00:00';
$todayEnd = date('Y-m-d') . ' 23:59:59';
$todayStats = [
    'configs' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM config_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$todayStart, $todayEnd])['c'] ?? 0),
    'firmware' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM firmware_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$todayStart, $todayEnd])['c'] ?? 0),
    'manuals' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM manuals WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$todayStart, $todayEnd])['c'] ?? 0),
    'software' => (int)($db->fetchOne("SELECT COUNT(*) as c FROM software_files WHERE status = 'active' AND created_at >= ? AND created_at <= ?", [$todayStart, $todayEnd])['c'] ?? 0),
];
$todayTotal = $todayStats['configs'] + $todayStats['firmware'] + $todayStats['manuals'] + $todayStats['software'];
$todayFilesSafe = max(1, $todayTotal);
$todayMix = [
    ['label' => 'Configs', 'count' => $todayStats['configs'], 'percent' => round(($todayStats['configs'] / $todayFilesSafe) * 100)],
    ['label' => 'Firmware', 'count' => $todayStats['firmware'], 'percent' => round(($todayStats['firmware'] / $todayFilesSafe) * 100)],
    ['label' => 'Manuals', 'count' => $todayStats['manuals'], 'percent' => round(($todayStats['manuals'] / $todayFilesSafe) * 100)],
    ['label' => 'Software', 'count' => $todayStats['software'], 'percent' => round(($todayStats['software'] / $todayFilesSafe) * 100)],
    ['label' => "Today's Total", 'count' => $todayTotal, 'percent' => 100],
];

$initialTab = isAdmin() ? 'dashboard' : (canUpload() ? 'add-file' : 'admin-control');

// Get recent uploads
$recentConfigs = $db->fetchAll("SELECT c.*, dm.name as model_name, b.name as brand_name FROM config_files c JOIN device_models dm ON c.device_model_id = dm.id JOIN brands b ON dm.brand_id = b.id WHERE c.status = 'active' ORDER BY c.created_at DESC LIMIT 5");
$recentFirmware = $db->fetchAll("SELECT f.*, b.name as brand_name FROM firmware_files f JOIN brands b ON f.brand_id = b.id WHERE f.status = 'active' ORDER BY f.created_at DESC LIMIT 5");

$editorAccounts = $db->fetchAll("SELECT id, username, is_active FROM users WHERE role = 'editor' ORDER BY username ASC LIMIT ?", [$adminTablePageSize]);
$selectedAccessUserId = (int)($_GET['access_user'] ?? 0);
if ($selectedAccessUserId <= 0 && !empty($editorAccounts)) {
    $selectedAccessUserId = (int)$editorAccounts[0]['id'];
}
$selectedAccessUser = null;
foreach ($editorAccounts as $editorAccount) {
    if ((int)$editorAccount['id'] === $selectedAccessUserId) {
        $selectedAccessUser = $editorAccount;
        break;
    }
}
$selectedAccessPerms = $selectedAccessUser ? getUserPermissions((int)$selectedAccessUser['id']) : [];
$adminProfileEditJson = '';
if (isAdmin()) {
    $adminProfileRow = $db->fetchOne("SELECT id, username, email, role FROM users WHERE id = ?", [(int)($_SESSION['user_id'] ?? 0)]);
    if ($adminProfileRow) {
        $adminProfilePerms = getUserPermissions((int)$adminProfileRow['id']);
        $adminProfileEditJson = htmlspecialchars(json_encode([
            'id' => (int)$adminProfileRow['id'],
            'username' => (string)($adminProfileRow['username'] ?? ''),
            'email' => (string)($adminProfileRow['email'] ?? ''),
            'role' => (string)($adminProfileRow['role'] ?? 'admin'),
            'perms' => [
                'can_upload' => (int)($adminProfilePerms['can_upload'] ?? 0),
                'can_delete' => (int)($adminProfilePerms['can_delete'] ?? 0),
                'can_manage_brands' => (int)($adminProfilePerms['can_manage_brands'] ?? 0),
                'can_manage_models' => (int)($adminProfilePerms['can_manage_models'] ?? 0),
            ],
        ]), ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="shortcut icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #5f6b7a;
            --line: #d6e1ef;
            --panel: #fff;
            --page: #edf3f9;
            --nav: linear-gradient(180deg, #0b1b39 0%, #112b56 58%, #0f2a54 100%);
            --primary: #005aa0;
            --primary-dark: #003d73;
            --success: #008a5b;
            --danger: #d92d20;
            --shadow: 0 14px 34px rgba(15, 23, 42, 0.09);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: radial-gradient(circle at 8% 4%, rgba(59, 130, 246, 0.09), transparent 26%), radial-gradient(circle at 92% 2%, rgba(16, 185, 129, 0.08), transparent 24%), var(--page);
            color: var(--ink);
        }
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0; width: 258px;
            background: var(--nav); color: #fff; padding: 20px 0; overflow-y: auto;
            box-shadow: 10px 0 30px rgba(15, 23, 42, 0.24);
            border-right: 1px solid rgba(148, 163, 184, 0.2);
            transition: transform 0.22s ease;
        }
        .sidebar-brand { padding: 0 20px; margin-bottom: 18px; }
        .sidebar-logo { height: 34px; width: auto; display: block; }
        .sidebar-subtitle { margin-top: 8px; display: block; font-size: 0.75rem; color: rgba(226, 232, 240, 0.82); }
        .sidebar h2 { padding: 0 20px; font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.62; margin-bottom: 10px; }
        .sidebar a {
            position: relative; display: flex; align-items: center; margin: 3px 10px; padding: 11px 12px; color: rgba(255,255,255,0.86);
            text-decoration: none; font-size: 0.91rem; font-weight: 650; border-radius: 10px; border: 1px solid transparent; transition: background 0.18s, color 0.18s, transform 0.18s, border-color 0.18s;
        }
        .sidebar a:hover, .sidebar a.active {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.24), rgba(59, 130, 246, 0.12));
            color: #fff;
            border-color: rgba(147, 197, 253, 0.42);
            transform: translateX(2px);
        }
        .sidebar a.active::before {
            content: '';
            position: absolute;
            left: 2px;
            top: 8px;
            bottom: 8px;
            width: 3px;
            border-radius: 999px;
            background: #93c5fd;
        }
        .sidebar a i { width: 20px; margin-right: 10px; }
        .sidebar .logout { margin-top: 24px; color: #fecaca; }
        .sidebar-divider { border: none; border-top: 1px solid rgba(148, 163, 184, 0.24); margin: 16px 20px; }
        .mobile-nav-toggle,
        .mobile-nav-backdrop { display: none; }
        .mobile-nav-toggle {
            position: fixed;
            top: 10px;
            left: 10px;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.4);
            background: rgba(11, 27, 57, 0.95);
            color: #fff;
            align-items: center;
            justify-content: center;
            z-index: 1700;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.32);
        }
        .mobile-nav-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.55);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 1600;
        }
        .main { margin-left: 258px; padding: 24px 28px 36px; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(0, 90, 160, 0.12);
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            position: sticky;
            top: 10px;
            z-index: 12;
        }
        .header h1 { font-size: 1.52rem; font-weight: 800; letter-spacing: 0; }
        .header .user { font-size: 0.88rem; color: #1d2939; padding: 5px 10px 5px 5px; border-radius: 999px; background: #fff; border: 1px solid #d8e7f6; box-shadow: 0 5px 14px rgba(15, 23, 42, 0.06); }
        .header-user-pill { display: flex; align-items: center; gap: 8px; }
        .header-right { display: flex; align-items: center; gap: 10px; }
        .profile-edit-btn { border-radius: 999px; padding: 8px 13px; font-size: 0.8rem; }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid #dbeafe; }
        .user-avatar-empty { display: inline-flex; align-items: center; justify-content: center; background: #e8f1fb; color: #3b82f6; font-size: 0.9rem; }
        .header-user-name { font-weight: 700; color: #0f172a; }
        .role-chip { background: #0f4879 !important; color: #fff !important; padding: 3px 9px !important; border-radius: 999px !important; font-size: 0.72rem !important; text-transform: uppercase; letter-spacing: 0.05em; }
        .presence-pill { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:0.75rem; font-weight:700; }
        .presence-pill::before { content:''; width:7px; height:7px; border-radius:999px; display:inline-block; }
        .presence-online { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .presence-online::before { background:#16a34a; box-shadow:0 0 0 4px rgba(34,197,94,0.18); }
        .presence-offline { background:#f3f4f6; color:#475467; border:1px solid #e4e7ec; }
        .presence-offline::before { background:#98a2b3; }
        .usage-stat { font-weight:700; color:#0f172a; }
        .last-seen-text { color:#475467; font-size:0.8rem; white-space:nowrap; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 22px; }
        .stat-card { background: var(--panel); border: 1px solid rgba(0,90,160,0.08); border-radius: 8px; padding: 18px; box-shadow: var(--shadow); }
        .stat-card .number { font-size: 1.85rem; font-weight: 750; color: var(--primary); line-height: 1; }
        .stat-card .label { font-size: 0.78rem; color: var(--muted); margin-top: 8px; text-transform: uppercase; letter-spacing: 0.06em; }
        .section { background: var(--panel); border: 1px solid rgba(15, 23, 42, 0.07); border-radius: 14px; padding: 22px; margin-bottom: 20px; box-shadow: var(--shadow); overflow-x: auto; }
        .section h2 { font-size: 1.04rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; color: var(--ink); }
        table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 780px; }
        th, td { padding: 12px 12px; text-align: left; border-bottom: 1px solid #e8edf4; font-size: 0.85rem; vertical-align: middle; }
        th { font-weight: 700; color: var(--muted); background: linear-gradient(180deg, #f8fbff 0%, #f4f8fc 100%); position: sticky; top: 0; z-index: 1; }
        tbody tr { transition: background 0.15s; }
        tbody tr:hover { background: #f3f8ff; }
        table input[type="text"], table select { width: 100%; min-width: 120px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 0.85rem; outline: none; background: #fff; }
        table input[type="text"]:focus, table select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,90,160,0.12); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 10px; font-size: 0.84rem; font-weight: 700; text-decoration: none; border: 1px solid transparent; cursor: pointer; transition: background 0.18s, box-shadow 0.18s, transform 0.18s, border-color 0.18s; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary { background: linear-gradient(135deg, #005aa0 0%, #0a6ebd 100%); color: #fff; box-shadow: 0 7px 18px rgba(0,90,160,0.18); }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-danger { background: var(--danger); color: #fff; box-shadow: 0 4px 12px rgba(217,45,32,0.16); }
        .btn-danger:hover { background: #b42318; }
        .btn-success { background: linear-gradient(135deg, #0f9a66 0%, #0b7f56 100%); color: #fff; box-shadow: 0 7px 18px rgba(15, 154, 102, 0.2); }
        .btn-success:hover { background: #0b7f56; }
        .btn:not(.btn-primary):not(.btn-danger):not(.btn-success) { background: #fff; border-color: #cad9ea; color: #1f2a37; }
        .btn:not(.btn-primary):not(.btn-danger):not(.btn-success):hover { background: #f2f8ff; border-color: #95bcdf; }
        .btn-sm { padding: 4px 10px; font-size: 0.8rem; }
        .badge { display:inline-flex; align-items:center; justify-content:center; padding:3px 9px; border-radius:999px; font-size:0.74rem; font-weight:700; letter-spacing:0.01em; }
        .badge-none { background:#e4e7ec; color:#344054; }
        .badge-advanced { background:#dbeafe; color:#0a4f8f; }
        .badge-standard { background:#e8eef7; color:#425466; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 0.82rem; font-weight: 700; margin-bottom: 6px; color: #344054; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px 12px; border: 1px solid var(--line);
            border-radius: 10px; font-size: 0.9rem; outline: none; background: #fbfdff;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,90,160,0.12); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .admin-search { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid rgba(0,90,160,0.12); border-radius: 12px; padding: 12px 16px; margin-bottom: 14px; box-shadow: var(--shadow); position: sticky; top: 82px; z-index: 11; }
        .admin-search:focus-within { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,90,160,0.10), var(--shadow); }
        .admin-search i { color: var(--muted); }
        .admin-search input { flex: 1 1 auto; min-width: 0; border: none; outline: none; font-size: 0.95rem; }
        .admin-search button { border: none; background: transparent; color: var(--muted); cursor: pointer; width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; }
        .admin-search button[hidden] { display: none; }
        .admin-search button:hover { background: #eef4fa; color: var(--ink); }
        .admin-search-meta { color: var(--muted); font-size: 0.8rem; white-space: nowrap; }
        .tab-bar { display: none; }
        .tab-separator { width: 1px; height: 28px; background: #d5e2ef; margin: 0 2px; }
        .tab-btn { padding: 9px 15px; border: 1px solid #d7e2ef; background: #fff; color: #344054; border-radius: 10px; cursor: pointer; font-size: 0.84rem; font-weight: 700; transition: all 0.18s; }
        .tab-btn:hover { border-color: rgba(0,90,160,0.38); background: #f6fbff; color: #124574; }
        .tab-btn.active { background: linear-gradient(135deg, #005aa0 0%, #0a6dbb 100%); border-color: #005aa0; color: #fff; box-shadow: 0 8px 18px rgba(0,90,160,0.2); }
        .dashboard-hero { display:flex; align-items:center; justify-content:space-between; gap:10px; border:1px solid #dbe8f5; border-radius:12px; background:#ffffff; padding:10px 12px; margin-bottom:14px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }
        .dashboard-hero strong { font-size:0.9rem; color:#123a63; }
        .dashboard-hero span { color:#64748b; font-size:0.8rem; }
        .dashboard-date-filter { margin-bottom:12px; padding:10px 12px; border:1px solid #dbe8f5; border-radius:12px; background:#fff; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }
        .dashboard-date-quick { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:6px; }
        .date-quick-btn { padding:5px 14px; border:1px solid #cbdcec; border-radius:999px; background:#fff; color:#475467; font-size:0.78rem; cursor:pointer; transition:all 0.15s; font-family:inherit; }
        .date-quick-btn:hover { background:#f0f6ff; border-color:#9fc0e1; }
        .date-quick-btn.active { background:#005aa0; color:#fff; border-color:#005aa0; }
        .dashboard-date-custom { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px; padding:8px 0; border-top:1px solid #e4edf7; }
        .dashboard-date-custom label { font-size:0.78rem; color:#475467; display:flex; align-items:center; gap:6px; }
        .dashboard-date-custom input[type="date"] { border:1px solid #cbdcec; padding:5px 8px; border-radius:6px; font-size:0.82rem; font-family:inherit; color:#1f2a37; background:#fff; }
        .dashboard-date-custom .btn { padding:5px 12px; font-size:0.76rem; }
        .dashboard-date-meta-row { display:flex; align-items:center; gap:12px; flex-wrap:wrap; font-size:0.75rem; color:#64748b; }
        .dashboard-date-meta-row .date-meta + .date-meta { margin-left:auto; }
        .dashboard-live-status { font-weight:700; color:#0b63aa; background:#eef6ff; border:1px solid #cfe1f7; border-radius:999px; padding:4px 10px; }
        .widget-loading { opacity:0.5; transition:opacity 0.2s; pointer-events:none; }
        .widget-refreshing > .mix-row, .widget-refreshing > .download-feed-wrap { opacity:0.3; transition:opacity 0.2s; }
        .dash-empty-state { text-align:center; padding:20px 12px; color:#94a3b8; font-size:0.82rem; }
        .dash-empty-state i { font-size:1.5rem; display:block; margin-bottom:6px; color:#cbd5e1; }
        .dash-fade-up { animation:dashFadeUp 0.4s ease; }
        @keyframes dashFadeUp { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
        .dashboard-range-kpis { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:10px; margin-bottom:14px; }
        .dashboard-range-kpi { border:1px solid #d6e4f3; border-radius:12px; background:#fff; padding:11px 12px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05); }
        .dashboard-range-kpi strong { font-size:1.4rem; color:#0b3f70; line-height:1; display:block; }
        .dashboard-range-kpi span { margin-top:5px; display:block; font-size:0.77rem; color:#64748b; text-transform:uppercase; letter-spacing:0.04em; font-weight:700; }
        .dashboard-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .dashboard-kpi { --kpi-accent: #0b63aa; border: 1px solid #d3e2f1; border-radius: 14px; background:#ffffff; padding: 14px 16px 14px; position: relative; overflow: hidden; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.07); min-height: 108px; transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease; }
        .dashboard-kpi::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--kpi-accent); border-radius:4px; }
        .dashboard-kpi::after { content:none; }
        .dashboard-kpi:hover { transform: translateY(-3px); border-color:#b8cfe8; box-shadow: 0 16px 30px rgba(15, 23, 42, 0.12); }
        .dashboard-kpi .kpi-icon { position:absolute; top:10px; right:10px; width:30px; height:30px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; font-size:0.86rem; background:#f8fbff; border:1px solid #dce8f5; color:var(--kpi-accent); box-shadow: inset 0 -2px 0 rgba(15,23,42,0.03); }
        .dashboard-kpi strong { display: block; font-size: 2rem; color: #0b3f70; line-height: 1; font-weight: 800; letter-spacing: 0; margin-top: 14px; }
        .dashboard-kpi span { display: block; margin-top: 10px; font-size: 0.8rem; color: #54657a; text-transform: uppercase; letter-spacing: 0.045em; font-weight: 700; }
        .dashboard-kpi-total { --kpi-accent:#0a6dbb; background:#ffffff; border-color: #cbe0f4; }
        .dashboard-kpi-brands { --kpi-accent:#0f9a66; }
        .dashboard-kpi-models { --kpi-accent:#2563eb; }
        .dashboard-kpi-configs { --kpi-accent:#0b63aa; }
        .dashboard-kpi-firmware { --kpi-accent:#7c3aed; }
        .dashboard-kpi-manuals { --kpi-accent:#c2410c; }
        .dashboard-kpi-software { --kpi-accent:#be185d; }
        .dashboard-kpi-users { --kpi-accent:#0369a1; }
        .dashboard-split { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .dashboard-list { border: 1px solid #e8eef5; border-radius: 12px; background: #fff; overflow: hidden; box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05); }
        .dashboard-list-head { display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #edf1f5;font-size:0.82rem;font-weight:700;color:#344054; }
        .dashboard-list table { min-width: 0; }
        .dashboard-list th, .dashboard-list td { font-size: 0.8rem; padding: 9px 10px; }
        .dashboard-actions { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .dashboard-actions .btn { border:1px solid #c8dbef; background:#fff; color:#1f2a37; }
        .dashboard-actions .btn:hover { background:#f4f9ff; border-color:#9fc0e1; }
        .dashboard-insights { display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-top:14px; }
        .mix-card { border: 1px solid #d9e6f3; border-radius: 12px; background: #fff; padding: 12px; box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04); }
        .mix-card h3 { margin:0 0 10px; font-size:0.86rem; color:#344054; display:flex; align-items:center; gap:6px; }
        .download-feed-wrap { min-height: 40px; }
        .download-feed { display:grid; gap:8px; }
        .download-feed-item { border:1px solid #e4edf7; border-radius:9px; padding:8px 10px; background:#fbfdff; display:flex; align-items:center; justify-content:space-between; gap:8px; }
        .download-feed-item strong { font-size:0.8rem; color:#123a63; display:block; }
        .download-feed-item small { color:#64748b; font-size:0.73rem; }
        .mix-row { margin-bottom: 10px; }
        .mix-row:last-child { margin-bottom: 0; }
        .mix-row-meta { display:flex; justify-content:space-between; align-items:center; font-size:0.78rem; color:#475467; margin-bottom:4px; }
        .mix-track { width:100%; height:8px; border-radius:999px; background:#edf2f7; overflow:hidden; }
        .mix-fill { height:100%; border-radius:999px; background:linear-gradient(90deg, #005aa0 0%, #1c7ed6 100%); }
        .mix-toggle-btn { padding:4px 12px; border:1px solid #d9e6f3; border-radius:999px; background:#fff; color:#475467; font-size:0.75rem; cursor:pointer; transition:all 0.15s; }
        .mix-toggle-btn.active { background:#005aa0; color:#fff; border-color:#005aa0; }
        .mix-toggle-btn:hover:not(.active) { background:#f0f6ff; }
        .upload-dropzone { border:1.5px dashed #9fc0e1; border-radius:12px; background:linear-gradient(180deg, #fbfdff 0%, #f4f9ff 100%); padding:14px; display:flex; align-items:center; justify-content:space-between; gap:12px; min-height:72px; cursor:pointer; transition:border-color 0.18s, background 0.18s, box-shadow 0.18s, transform 0.18s; }
        .upload-dropzone-main { display:flex; align-items:center; gap:11px; min-width:0; }
        .upload-dropzone-main i { color:#005aa0; font-size:1.05rem; width:32px; height:32px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; background:#fff; border:1px solid #d7e6f6; flex:0 0 auto; }
        .upload-dropzone-title { display:block; color:#102a43; font-weight:700; font-size:0.99rem; line-height:1.2; }
        .upload-dropzone-meta { display:block; color:#64748b; font-size:0.79rem; margin-top:2px; }
        .upload-dropzone-browse { border:1px solid #c3d8ec; background:#fff; color:#0f4678; border-radius:8px; padding:7px 11px; font-size:0.77rem; font-weight:700; white-space:nowrap; }
        .upload-dropzone:hover { border-color:#6ea4d4; background:linear-gradient(180deg, #f8fcff 0%, #edf6ff 100%); transform:translateY(-1px); }
        .upload-dropzone:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(0,90,160,0.18); }
        .upload-dropzone.is-dragover { border-color:#005aa0; background:#eaf4ff; box-shadow: 0 0 0 3px rgba(0,90,160,0.14); }
        .upload-file-chip { margin-top:9px; display:none; align-items:center; justify-content:space-between; border:1px solid #d8e6f4; background:#fff; border-radius:10px; padding:9px 11px; font-size:0.8rem; color:#344054; }
        .upload-file-chip strong { font-weight:700; }
        .upload-file-chip span { color:#667085; font-size:0.75rem; }
        .upload-section { border-color: #cfe0f2; background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%); }
        .upload-hero-strip { display:flex; align-items:center; gap:12px; border:1px solid #d2e2f3; background:linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%); border-radius:10px; padding:12px 14px; margin-bottom:14px; }
        .upload-hero-strip i { font-size:1.1rem; color:#005aa0; width:34px; height:34px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; background:#fff; border:1px solid #d9e8f8; }
        .upload-hero-strip strong { display:block; color:#1f2a37; font-size:0.9rem; line-height:1.2; }
        .upload-hero-strip span { display:block; color:#64748b; font-size:0.8rem; margin-top:2px; }
        .upload-form .form-row { gap: 14px; }
        .upload-form .form-group { margin-bottom: 14px; }
        .upload-native-input { position:absolute !important; left:-9999px !important; width:1px !important; height:1px !important; opacity:0 !important; }
        .upload-progress-wrap { display:none; margin-top:12px; border:1px solid #d8e6f4; background:#fff; border-radius:12px; padding:12px 13px; }
        .upload-progress-wrap.active { display:block; }
        .upload-progress-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:8px; }
        .upload-progress-head strong { color:#0f172a; font-size:0.9rem; }
        .upload-progress-head span { color:#64748b; font-size:0.8rem; text-align:right; }
        .upload-progress-track { width:100%; height:10px; border-radius:999px; background:#e8f1fb; overflow:hidden; }
        .upload-progress-fill { width:0; height:100%; border-radius:999px; background:linear-gradient(90deg, #0ea5e9 0%, #2563eb 100%); transition:width 0.18s ease; }
        .upload-status-note { display:none; margin-top:10px; font-size:0.8rem; font-weight:700; color:#0f4678; }
        .upload-status-note.active { display:block; }
        .upload-status-note.error { color:#b42318; }
        .btn-upload-loader { display:none; }
        .upload-submit-row { padding-top: 4px; border-top: 1px solid #e6eef7; margin-top: 10px; }
        .upload-submit-row .btn { width: 100%; justify-content: center; font-size: 0.95rem; padding: 11px 16px; border-radius: 10px; }
        .plus-icon-btn { width: 30px; height: 30px; padding: 0; margin-left: 8px; font-size: 0.8rem; border-radius: 9px; }
        .upload-inline-modal { display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.5); z-index:12000; align-items:center; justify-content:center; padding:18px; }
        .upload-inline-modal.active { display:flex; }
        .upload-inline-form { width:min(460px, 94vw); background:#fff; border:1px solid #d7e2ee; border-radius:12px; box-shadow:0 18px 45px rgba(15,23,42,0.28); padding:16px; }
        .upload-inline-form h3 { margin:0 0 12px; font-size:0.98rem; color:#182230; display:flex; align-items:center; gap:8px; }
        .upload-inline-row { display:flex; gap:8px; flex-wrap:wrap; }
        .upload-inline-row > * { flex:1 1 160px; }
        .upload-inline-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:12px; }
        .entity-image-cell { display:flex; align-items:center; gap:8px; min-width: 190px; }
        .entity-image-preview { width:42px; height:42px; border-radius:8px; border:1px solid #dbe6f2; background:#f8fbff; object-fit:contain; padding:4px; }
        .entity-image-empty { width:42px; height:42px; border-radius:8px; border:1px dashed #c6d6e8; display:inline-flex; align-items:center; justify-content:center; color:#98a2b3; background:#f8fbff; }
        .entity-image-tools { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .entity-image-tools input[type="file"] { max-width: 175px; font-size: 0.75rem; }
        .icon-only-btn { width:30px; height:30px; padding:0; border-radius:8px; }
        .tab-search-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; margin-left: 6px; padding: 0 6px; border-radius: 999px; background: rgba(0,0,0,0.12); font-size: 0.75rem; line-height: 1; }
        .tab-btn.active .tab-search-count { background: rgba(255,255,255,0.22); color: #fff; }
        .flash-message { display:flex; align-items:center; gap:8px; padding:11px 13px; border-radius:10px; margin-bottom:12px; font-weight:700; border:1px solid transparent; }
        .flash-success { color:#067647; background:#ecfdf3; border-color:#abefc6; }
        .flash-error { color:#b42318; background:#fef3f2; border-color:#fecdca; }
        .inline-note { color:#475467; font-weight:400; font-size:0.8rem; }
        .control-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:12px; margin-top:12px; }
        .control-card { border:1px solid #d8e4f1; border-radius:12px; background:linear-gradient(180deg,#fff 0%,#f8fbff 100%); padding:12px; }
        .control-card strong { display:block; font-size:0.92rem; color:#0f172a; margin-bottom:5px; }
        .control-card span { display:block; font-size:0.8rem; color:#64748b; }
        .perm-list { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-top:12px; }
        .perm-item { display:flex; align-items:center; justify-content:space-between; gap:8px; border:1px solid #d9e6f3; border-radius:10px; padding:9px 10px; background:#fff; font-size:0.84rem; }
        .perm-item strong { font-size:0.83rem; color:#1f2a37; }
        .perm-pill { display:inline-flex; align-items:center; justify-content:center; min-width:56px; padding:3px 8px; border-radius:999px; font-size:0.74rem; font-weight:700; }
        .perm-on { background:#dcfce7; color:#166534; }
        .perm-off { background:#fee2e2; color:#991b1b; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: tabFadeIn 0.24s ease; }
        @keyframes tabFadeIn {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .empty { text-align: center; padding: 32px; color: var(--muted); font-size: 0.9rem; }
        .back-link { margin-bottom: 16px; display: inline-block; color: var(--primary); text-decoration: none; font-size: 0.9rem; }
        .required { color: var(--danger); margin-left: 2px; }
        @media (max-width: 900px) {
            body.admin-nav-open { overflow: hidden; }
            .mobile-nav-toggle { display: inline-flex; }
            .mobile-nav-backdrop { display: block; }
            body.admin-nav-open .mobile-nav-backdrop {
                opacity: 1;
                pointer-events: auto;
            }
            .sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                width: min(84vw, 280px);
                padding: 16px 0 20px;
                z-index: 1650;
                transform: translateX(-105%);
            }
            body.admin-nav-open .sidebar {
                transform: translateX(0);
            }
            .main { margin-left: 0; padding: 66px 12px 20px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
            .header { align-items: flex-start; gap: 10px; flex-direction: column; top: 8px; padding: 12px; }
            .header h1 { font-size: 1.22rem; }
            .header-right { width: 100%; justify-content: space-between; flex-wrap: wrap; }
            .header-user-pill { max-width: 100%; }
            .admin-search { top: 70px; flex-wrap: nowrap; padding: 10px 12px; }
            .admin-search-meta { display: none; }
            .section { padding: 14px; }
            table { min-width: 640px; }
            .dashboard-split { grid-template-columns: 1fr; }
            .dashboard-insights { grid-template-columns: 1fr; }
            .tab-separator { display: none; }
            .dashboard-actions .btn { flex: 1; justify-content: center; }
            .dashboard-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dashboard-range-kpis { grid-template-columns: 1fr; }
            .dashboard-date-meta-row { flex-direction:column; align-items:flex-start; gap:4px; }
            .dashboard-date-meta-row .date-meta + .date-meta { margin-left:0; }
            .upload-dropzone { align-items:flex-start; flex-direction:column; }
            .upload-dropzone-browse { width:100%; text-align:center; }
        }
        @media (max-width: 620px) {
            .dashboard-kpis { grid-template-columns: 1fr; }
            .header-right { flex-direction: column; align-items: stretch; }
            .profile-edit-btn { width: 100%; justify-content: center; }
            .header-user-pill { width: 100%; justify-content: flex-start; }
            .dashboard-date-filter label,
            .dashboard-date-filter input[type="date"],
            .dashboard-date-filter .btn {
                width: 100%;
            }
            .dashboard-date-filter {
                align-items: stretch;
            }
            .entity-image-cell { min-width: 0; }
        }
        @media (min-width: 1240px) {
            .dashboard-kpis { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        }

    </style>

</head>
<body class="<?= isAdmin() ? 'role-admin' : 'role-editor' ?>">

    <button type="button" class="mobile-nav-toggle" id="adminMobileNavToggle" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fas fa-bars"></i>
    </button>
    <div class="mobile-nav-backdrop" id="adminMobileNavBackdrop"></div>

    <div class="sidebar" id="adminSidebar">
        <div class="sidebar-brand">
            <img src="../assets/images/translink_logo.svg" alt="Translink" class="sidebar-logo">
            <span class="sidebar-subtitle"><?= isAdmin() ? 'Admin Workspace' : 'Editor Workspace' ?></span>
        </div>
        <h2>Navigation</h2>
        <?php if (isAdmin()): ?><a href="#" onclick="return showTab('dashboard')" class="<?= $initialTab === 'dashboard' ? 'active' : '' ?>" id="nav-dashboard"><i class="fas fa-chart-bar"></i> Dashboard</a><?php endif; ?>
        <a href="#" onclick="return showTab('admin-control')"><i class="fas fa-user-shield"></i> Admin Control</a>
        <?php if (isAdmin() || canUpload()): ?><a href="#" onclick="return showTab('add-file')" class="<?= $initialTab === 'add-file' ? 'active' : '' ?>"><i class="fas fa-upload"></i> Upload File</a><?php endif; ?>
        <?php if (isAdmin() || canViewTab('configs')): ?><a href="#" onclick="return showTab('configs')"><i class="fas fa-file-code"></i> Config Files</a><?php endif; ?>
        <?php if (isAdmin() || canViewTab('firmware')): ?><a href="#" onclick="return showTab('firmware')"><i class="fas fa-microchip"></i> Firmware</a><?php endif; ?>
        <?php if (isAdmin() || canViewTab('manuals')): ?><a href="#" onclick="return showTab('manuals')"><i class="fas fa-book"></i> Manuals</a><?php endif; ?>
        <?php if (isAdmin() || canViewTab('software')): ?><a href="#" onclick="return showTab('software')"><i class="fas fa-gear"></i> Software</a><?php endif; ?>
        <?php if (isAdmin() || canViewTab('brands-models') || canManageBrands() || canManageModels()): ?><a href="#" onclick="return showTab('brands-models')"><i class="fas fa-sitemap"></i> Brands &amp; Models</a><?php endif; ?>
        <?php if (isAdmin()): ?><a href="#" onclick="return showTab('users')"><i class="fas fa-users"></i> Users</a><?php endif; ?>
        <hr class="sidebar-divider">
        <?php if (!empty($_SESSION['_impersonator_id'])): ?>
        <a href="../logout.php?switch_back=1"><i class="fas fa-rotate-left"></i> Back to Admin</a>
        <?php endif; ?>
        <a href="?logout=1" class="logout"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
    </div>
    <div class="main">
            <div class="header">
                <h1 id="adminPageTitle" data-default-title="<?= escape(isAdmin() ? 'Dashboard' : 'Editor Workspace') ?>"><?= isAdmin() ? 'Dashboard' : 'Editor Workspace' ?></h1>
                <div class="header-right">
                    <?php if (isAdmin() && $adminProfileEditJson !== ''): ?>
                    <button type="button" class="btn profile-edit-btn" onclick="openEditModalFromJson('<?= $adminProfileEditJson ?>')" title="Edit my profile">
                        <i class="fas fa-user-cog"></i> Edit Profile
                    </button>
                    <?php endif; ?>
                    <div class="user header-user-pill">
                        <?php if (!empty($_SESSION['user_image'])): ?>
                        <img src="../<?= escape($_SESSION['user_image']) ?>" alt="" class="user-avatar">
                        <?php else: ?>
                        <span class="user-avatar user-avatar-empty"><i class="fas fa-user"></i></span>
                        <?php endif; ?>
                        <span class="header-user-name"><?= escape($_SESSION['user_username']) ?></span>
                        <span class="role-chip"><?= escape($_SESSION['user_role'] ?? 'admin') ?></span>
                    </div>
                </div>
            </div>
            <?php flash(); ?>

        <div class="admin-search">
            <i class="fas fa-search"></i>
            <input type="search" id="adminSearchInput" placeholder="Search admin records..." autocomplete="off">
            <span id="adminSearchMeta" class="admin-search-meta"></span>
            <button type="button" id="adminSearchClear" onclick="clearAdminSearch()" title="Clear search" hidden><i class="fas fa-times"></i></button>
        </div>

        <div class="tab-bar">
            <?php if (isAdmin()): ?>
            <button class="tab-btn <?= $initialTab === 'dashboard' ? 'active' : '' ?>" onclick="showTab('dashboard')">Dashboard</button>
            <span class="tab-separator" aria-hidden="true"></span>
            <?php endif; ?>
            <button class="tab-btn" onclick="showTab('admin-control')">Admin Control</button>
            <?php if (isAdmin() || canUpload()): ?><button class="tab-btn <?= $initialTab === 'add-file' ? 'active' : '' ?>" onclick="showTab('add-file')">Upload File</button><?php endif; ?>
            <?php if (isAdmin() || canViewTab('configs')): ?><button class="tab-btn" onclick="showTab('configs')">Configs</button><?php endif; ?>
            <?php if (isAdmin() || canViewTab('firmware')): ?><button class="tab-btn" onclick="showTab('firmware')">Firmware</button><?php endif; ?>
            <?php if (isAdmin() || canViewTab('manuals')): ?><button class="tab-btn" onclick="showTab('manuals')">Manuals</button><?php endif; ?>
            <?php if (isAdmin() || canViewTab('software')): ?><button class="tab-btn" onclick="showTab('software')">Software</button><?php endif; ?>
            <?php if (isAdmin() || canViewTab('brands-models') || canManageBrands() || canManageModels()): ?><button class="tab-btn" onclick="showTab('brands-models')">Brands &amp; Models</button><?php endif; ?>
            <?php if (isAdmin()): ?><button class="tab-btn" onclick="showTab('users')">Users</button><?php endif; ?>
        </div>

        <div id="tab-admin-control" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-user-shield"></i> Admin Control</h2>
                <?php if (isAdmin()): ?>
                    <div class="dashboard-actions">
                        <button class="btn" type="button" onclick="showTab('users')"><i class="fas fa-users"></i> Manage Users</button>
                        <button class="btn" type="button" onclick="showTab('brands-models')"><i class="fas fa-sitemap"></i> Manage Brands &amp; Models</button>
                        <button class="btn" type="button" onclick="showTab('add-file')"><i class="fas fa-upload"></i> Upload Center</button>
                        <button class="btn" type="button" onclick="showTab('dashboard')"><i class="fas fa-chart-line"></i> Overview</button>
                    </div>
                    <div class="control-grid">
                        <div class="control-card"><strong>Full Access</strong><span>Admins can manage users, roles, brand/model data, and file visibility.</span></div>
                        <div class="control-card"><strong>User Impersonation</strong><span>Use Users tab to sign in as editor/viewer for testing experience.</span></div>
                        <div class="control-card"><strong>Publishing</strong><span>Uploads are published directly to user library after successful save.</span></div>
                    </div>
                    <div style="margin-top:14px">
                        <form method="GET" action="dashboard.php" class="form-row">
                            <div class="form-group">
                                <label>Choose Editor Account</label>
                                <select name="access_user" onchange="this.form.submit()">
                                    <?php if (empty($editorAccounts)): ?>
                                    <option value="">No editor accounts</option>
                                    <?php else: foreach ($editorAccounts as $editorAccount): ?>
                                    <option value="<?= (int)$editorAccount['id'] ?>" <?= (int)$editorAccount['id'] === (int)$selectedAccessUserId ? 'selected' : '' ?>>
                                        <?= escape($editorAccount['username']) ?><?= (int)$editorAccount['is_active'] === 1 ? '' : ' (inactive)' ?>
                                    </option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </div>
                        </form>

                        <?php if (!empty($selectedAccessUser)): ?>
                        <form method="POST" action="dashboard.php#admin-control">
                            <input type="hidden" name="save_access_control" value="1">
                            <input type="hidden" name="access_user_id" value="<?= (int)$selectedAccessUser['id'] ?>">
                            <div class="perm-list">
                                <label class="perm-item"><strong>Upload Access</strong><input type="checkbox" name="access_can_upload" value="1" <?= !empty($selectedAccessPerms['can_upload']) ? 'checked' : '' ?>></label>
                                <label class="perm-item"><strong>Edit / Rename Access</strong><input type="checkbox" name="access_can_edit_files" value="1" <?= !empty($selectedAccessPerms['can_edit_files']) ? 'checked' : '' ?>></label>
                                <label class="perm-item"><strong>Delete Access</strong><input type="checkbox" name="access_can_delete" value="1" <?= !empty($selectedAccessPerms['can_delete']) ? 'checked' : '' ?>></label>
                                <label class="perm-item"><strong>Manage Brands Access</strong><input type="checkbox" name="access_can_manage_brands" value="1" <?= !empty($selectedAccessPerms['can_manage_brands']) ? 'checked' : '' ?>></label>
                                <label class="perm-item"><strong>Manage Models Access</strong><input type="checkbox" name="access_can_manage_models" value="1" <?= !empty($selectedAccessPerms['can_manage_models']) ? 'checked' : '' ?>></label>
                            </div>
                            <div style="margin-top:12px">
                                <strong style="font-size:0.84rem;color:#334155">Editor Tab Access</strong>
                                <div class="perm-list" style="margin-top:8px">
                                    <label class="perm-item"><strong>Configs Tab</strong><input type="checkbox" name="access_can_view_configs" value="1" <?= !empty($selectedAccessPerms['can_view_configs']) ? 'checked' : '' ?>></label>
                                    <label class="perm-item"><strong>Firmware Tab</strong><input type="checkbox" name="access_can_view_firmware" value="1" <?= !empty($selectedAccessPerms['can_view_firmware']) ? 'checked' : '' ?>></label>
                                    <label class="perm-item"><strong>Manuals Tab</strong><input type="checkbox" name="access_can_view_manuals" value="1" <?= !empty($selectedAccessPerms['can_view_manuals']) ? 'checked' : '' ?>></label>
                                    <label class="perm-item"><strong>Software Tab</strong><input type="checkbox" name="access_can_view_software" value="1" <?= !empty($selectedAccessPerms['can_view_software']) ? 'checked' : '' ?>></label>
                                    <label class="perm-item"><strong>Brands &amp; Models Tab</strong><input type="checkbox" name="access_can_view_brands_models" value="1" <?= !empty($selectedAccessPerms['can_view_brands_models']) ? 'checked' : '' ?>></label>
                                </div>
                            </div>
                            <div class="upload-inline-actions" style="margin-top:14px">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Access Control</button>
                            </div>
                        </form>
                        <?php else: ?>
                        <p class="inline-note" style="margin-top:10px">Create an editor user first, then configure access here.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <p class="inline-note">Editor can see this control tab. Management actions are granted by admin permissions.</p>
                    <div class="perm-list">
                        <div class="perm-item"><strong>Upload Files</strong><span class="perm-pill <?= canUpload() ? 'perm-on' : 'perm-off' ?>"><?= canUpload() ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Edit / Rename Files</strong><span class="perm-pill <?= canEditFiles() ? 'perm-on' : 'perm-off' ?>"><?= canEditFiles() ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Delete Files</strong><span class="perm-pill <?= canDelete() ? 'perm-on' : 'perm-off' ?>"><?= canDelete() ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Manage Brands</strong><span class="perm-pill <?= canManageBrands() ? 'perm-on' : 'perm-off' ?>"><?= canManageBrands() ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Manage Models</strong><span class="perm-pill <?= canManageModels() ? 'perm-on' : 'perm-off' ?>"><?= canManageModels() ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Configs Tab</strong><span class="perm-pill <?= canViewTab('configs') ? 'perm-on' : 'perm-off' ?>"><?= canViewTab('configs') ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Firmware Tab</strong><span class="perm-pill <?= canViewTab('firmware') ? 'perm-on' : 'perm-off' ?>"><?= canViewTab('firmware') ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Manuals Tab</strong><span class="perm-pill <?= canViewTab('manuals') ? 'perm-on' : 'perm-off' ?>"><?= canViewTab('manuals') ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Software Tab</strong><span class="perm-pill <?= canViewTab('software') ? 'perm-on' : 'perm-off' ?>"><?= canViewTab('software') ? 'Enabled' : 'Disabled' ?></span></div>
                        <div class="perm-item"><strong>Brands &amp; Models Tab</strong><span class="perm-pill <?= canViewTab('brands-models') ? 'perm-on' : 'perm-off' ?>"><?= canViewTab('brands-models') ? 'Enabled' : 'Disabled' ?></span></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isAdmin()): ?>
        <div id="tab-dashboard" class="tab-content <?= $initialTab === 'dashboard' ? 'active' : '' ?>">
            <div class="section">
                <h2><i class="fas fa-chart-line"></i> Dashboard Overview</h2>
                <div class="dashboard-hero">
                    <div>
                        <strong>Operational Snapshot</strong>
                        <span>Live totals across files, brands, models, and users</span>
                    </div>
                </div>
                <div class="dashboard-date-filter" id="dashboardDateFilterWrap">
                    <div class="dashboard-date-quick">
                        <button type="button" class="date-quick-btn active" data-range="today" onclick="setDateRange('today')">Today</button>
                        <button type="button" class="date-quick-btn" data-range="7d" onclick="setDateRange('7d')">Last 7 Days</button>
                        <button type="button" class="date-quick-btn" data-range="30d" onclick="setDateRange('30d')">Last 30 Days</button>
                        <button type="button" class="date-quick-btn" data-range="custom" onclick="toggleCustomDate()">Custom</button>
                    </div>
                    <div class="dashboard-date-custom" id="dashboardDateCustom" style="display:none">
                        <label>From <input type="date" id="customFrom" value="<?= escape($reportFrom) ?>"></label>
                        <label>To <input type="date" id="customTo" value="<?= escape($reportTo) ?>"></label>
                        <button type="button" class="btn btn-primary" onclick="applyCustomDate()"><i class="fas fa-calendar-check"></i> Apply</button>
                    </div>
                    <div class="dashboard-date-meta-row">
                        <span class="date-meta" id="dashboardRangeLabel" data-range-label-prefix="Range: ">Range: <?= escape($reportRangeLabel) ?></span>
                        <span class="date-meta dashboard-live-status" id="dashboardLiveStatus">Live every 30s <span id="dashTimeLabel">(EAT)</span></span>
                    </div>
                </div>
                <div class="dashboard-range-kpis">
                    <div class="dashboard-range-kpi">
                        <strong id="rangeDownloadsValue"><?= (int)($downloadRangeStats['total'] ?? 0) ?></strong>
                        <span>Downloads In Range</span>
                    </div>
                    <div class="dashboard-range-kpi">
                        <strong id="rangeUsersValue"><?= (int)($downloadRangeStats['users'] ?? 0) ?></strong>
                        <span>Users Downloaded</span>
                    </div>
                </div>
                <div class="dashboard-actions">
                    <button class="btn" type="button" onclick="showTab('add-file')"><i class="fas fa-upload"></i> Go To Upload</button>
                    <button class="btn" type="button" onclick="showTab('brands-models')"><i class="fas fa-sitemap"></i> Manage Brands &amp; Models</button>
                    <button class="btn" type="button" onclick="showTab('users')"><i class="fas fa-users"></i> Manage Users</button>
                </div>
                <div class="dashboard-kpis">
                    <div class="dashboard-kpi dashboard-kpi-total"><i class="fas fa-layer-group kpi-icon"></i><strong id="kpiTotalFiles"><?= (int)$totalFilesCount ?></strong><span>Total Files</span></div>
                    <div class="dashboard-kpi dashboard-kpi-brands"><i class="fas fa-sitemap kpi-icon"></i><strong id="kpiBrands"><?= (int)$stats['brands'] ?></strong><span>Brands</span></div>
                    <div class="dashboard-kpi dashboard-kpi-models"><i class="fas fa-microchip kpi-icon"></i><strong id="kpiModels"><?= (int)$stats['models'] ?></strong><span>Models</span></div>
                    <div class="dashboard-kpi dashboard-kpi-configs"><i class="fas fa-file-code kpi-icon"></i><strong id="kpiConfigs"><?= (int)$stats['configs'] ?></strong><span>Configs</span></div>
                    <div class="dashboard-kpi dashboard-kpi-firmware"><i class="fas fa-memory kpi-icon"></i><strong id="kpiFirmware"><?= (int)$stats['firmware'] ?></strong><span>Firmware</span></div>
                    <div class="dashboard-kpi dashboard-kpi-manuals"><i class="fas fa-book kpi-icon"></i><strong id="kpiManuals"><?= (int)$stats['manuals'] ?></strong><span>Manuals</span></div>
                    <div class="dashboard-kpi dashboard-kpi-software"><i class="fas fa-gear kpi-icon"></i><strong id="kpiSoftware"><?= (int)$stats['software'] ?></strong><span>Software</span></div>
                    <div class="dashboard-kpi dashboard-kpi-users"><i class="fas fa-users kpi-icon"></i><strong id="kpiUsers"><?= (int)$stats['users'] ?></strong><span>Users</span></div>
                </div>
                <div class="dashboard-split">
                    <div class="dashboard-list">
                        <div class="dashboard-list-head"><span>Recent Config Uploads</span><span><?= count($recentConfigs) ?></span></div>
                        <table>
                            <thead><tr><th>Name</th><th>Model</th><th>Brand</th></tr></thead>
                            <tbody>
                            <?php if (empty($recentConfigs)): ?>
                                <tr><td colspan="3" class="empty">No uploads yet.</td></tr>
                            <?php else: foreach ($recentConfigs as $rc): ?>
                                <tr><td><?= escape($rc['name']) ?></td><td><?= escape($rc['model_name']) ?></td><td><?= escape($rc['brand_name']) ?></td></tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="dashboard-list">
                        <div class="dashboard-list-head"><span>Recent Firmware Uploads</span><span><?= count($recentFirmware) ?></span></div>
                        <table>
                            <thead><tr><th>Name</th><th>Brand</th><th>Version</th></tr></thead>
                            <tbody>
                            <?php if (empty($recentFirmware)): ?>
                                <tr><td colspan="3" class="empty">No uploads yet.</td></tr>
                            <?php else: foreach ($recentFirmware as $rf): ?>
                                <tr><td><?= escape($rf['name']) ?></td><td><?= escape($rf['brand_name']) ?></td><td><?= escape($rf['version']) ?></td></tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="dashboard-insights">
                    <div class="mix-card" id="fileDistributionCard">
                        <h3><i class="fas fa-chart-pie"></i> File Distribution <span style="font-size:0.75rem;font-weight:400;color:#64748b;margin-left:8px" id="rangeMixLabel">(<?= escape($reportRangeLabel) ?>)</span></h3>
                        <div id="rangeMixView">
                        <?php $mixEmpty = array_sum(array_column($todayMix, 'count')) === 0; ?>
                        <?php if ($mixEmpty): ?>
                        <div class="dash-empty-state"><i class="fas fa-inbox"></i>No files uploaded in this period.</div>
                        <?php else: ?>
                        <?php foreach ($todayMix as $mix): ?>
                        <div class="mix-row">
                            <div class="mix-row-meta">
                                <span><?= escape($mix['label']) ?></span>
                                <span><?= (int)$mix['count'] ?> (<?= (int)$mix['percent'] ?>%)</span>
                            </div>
                            <div class="mix-track"><div class="mix-fill" style="width: <?= (int)$mix['percent'] ?>%"></div></div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                    <div class="mix-card">
                        <h3><i class="fas fa-bolt"></i> Admin Activity Snapshot</h3>
                        <div class="mix-row">
                            <div class="mix-row-meta"><span>Recent Config Uploads</span><span><?= count($recentConfigs) ?></span></div>
                            <div class="mix-track"><div class="mix-fill" style="width: <?= min(100, count($recentConfigs) * 20) ?>%"></div></div>
                        </div>
                        <div class="mix-row">
                            <div class="mix-row-meta"><span>Recent Firmware Uploads</span><span><?= count($recentFirmware) ?></span></div>
                            <div class="mix-track"><div class="mix-fill" style="width: <?= min(100, count($recentFirmware) * 20) ?>%"></div></div>
                        </div>
                        <div class="mix-row">
                            <div class="mix-row-meta"><span>Library Coverage</span><span><?= (int)$stats['brands'] ?> brands / <?= (int)$stats['models'] ?> models</span></div>
                            <div class="mix-track"><div class="mix-fill" style="width: <?= min(100, (int)$stats['brands'] * 8) ?>%"></div></div>
                        </div>
                        <div class="mix-row" style="margin-top:10px">
                            <div class="mix-row-meta"><span>Downloads In Selected Range</span><span id="rangeDownloadsMixValue"><?= (int)($downloadRangeStats['total'] ?? 0) ?></span></div>
                            <div class="mix-track"><div class="mix-fill" id="rangeDownloadsMixFill" style="width: <?= min(100, (int)($downloadRangeStats['total'] ?? 0)) ?>%"></div></div>
                        </div>
                    </div>
                    <div class="mix-card" id="recentDownloadsCard">
                        <h3><i class="fas fa-download"></i> Recent User Downloads <span style="font-size:0.7rem;font-weight:400;color:#94a3b8" id="downloadsLastUpdated"></span></h3>
                        <div id="adminRecentDownloadsFeed" class="download-feed-wrap" data-events-ready="<?= $downloadEventsReady ? '1' : '0' ?>">
                        <?php if (!$downloadEventsReady): ?>
                            <div class="dash-empty-state"><i class="fas fa-database"></i>Download events table unavailable.</div>
                        <?php elseif (empty($recentDownloadEvents)): ?>
                            <div class="dash-empty-state"><i class="fas fa-download"></i>No downloads found in selected range.</div>
                        <?php else: ?>
                            <div class="download-feed">
                                <?php foreach ($recentDownloadEvents as $downloadEvent): ?>
                                <div class="download-feed-item">
                                    <div>
                                        <strong><?= escape($downloadEvent['username'] ?? 'User') ?> - <?= escape(ucfirst((string)($downloadEvent['file_type'] ?? 'file'))) ?></strong>
                                        <small><?= escape($downloadEvent['file_name'] ?? 'File') ?></small>
                                    </div>
                                    <small><?= escape(formatUsEtDateTime((string)($downloadEvent['downloaded_at'] ?? ''), 'M d, h:i A')) ?></small>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canUpload()): ?>
        <div id="tab-add-file" class="tab-content <?= $initialTab === 'add-file' ? 'active' : '' ?>">
            <div class="section upload-section">
                <h2><i class="fas fa-upload"></i> Upload New File</h2>
                <div class="upload-hero-strip">
                    <i class="fas fa-cloud-arrow-up"></i>
                    <div>
                        <strong>Fast Publish</strong>
                        <span>Upload once and it appears in the user library automatically.</span>
                    </div>
                </div>
                <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="upload-form" id="uploadForm">
                    <input type="hidden" name="upload" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label>File Type<span class="required">*</span></label>
                            <select name="upload_type" id="uploadType" onchange="toggleUploadFields()" required>
                                <option value="config">Configuration File</option>
                                <option value="firmware">Firmware</option>
                                <option value="manual">Manual / Guide</option>
                                <option value="software">Configurator Software</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>File<span class="required">*</span></label>
                            <div class="upload-dropzone" id="uploadDropzone" role="button" tabindex="0" aria-controls="uploadFileInput" aria-label="Drop file here or click to browse">
                                <div class="upload-dropzone-main">
                                    <i class="fas fa-file-arrow-up"></i>
                                    <div>
                                        <span class="upload-dropzone-title">Drop file here or click to browse</span>
                                        <span class="upload-dropzone-meta">Configs, firmware, manuals, or software package</span>
                                    </div>
                                </div>
                                <span class="upload-dropzone-browse"><i class="fas fa-folder-open"></i> Browse Files</span>
                            </div>
                            <input type="file" id="uploadFileInput" class="upload-native-input" name="file" onchange="updateUploadFileChip()" required>
                            <div id="uploadFileChip" class="upload-file-chip">
                                <strong id="uploadFileName">No file selected</strong>
                                <span id="uploadFileSize"></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>System Type<span class="required">*</span></label>
                            <select name="system_type">
                                <option value="">— None —</option>
                                <option value="advanced">Advanced Tracking System</option>
                                <option value="standard">Standard Tracking System</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Display Name <span class="required">*</span></label>
                            <input type="text" id="displayNameInput" name="display_name" placeholder="e.g. FMB920_Translink_v2" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" id="brandField">
                            <label>
                                Brand<span class="required">*</span>
                                <?php if (canManageBrands()): ?><button type="button" class="btn btn-sm btn-primary plus-icon-btn" title="Create Brand" onclick="showInlineForm('brandInlineForm')"><i class="fas fa-plus"></i></button><?php endif; ?>
                            </label>
                            <select name="brand_id" id="brandSelect" required>
                                <option value="">— Select Brand —</option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= escape($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" id="modelField">
                            <label>
                                Device Model<span class="required">*</span>
                                <?php if (canManageModels()): ?><button type="button" class="btn btn-sm btn-primary plus-icon-btn" title="Create Model" onclick="showInlineForm('modelInlineForm')"><i class="fas fa-plus"></i></button><?php endif; ?>
                            </label>
                            <select name="model_id" id="modelSelect" required>
                                <option value="">— Select Model —</option>
                                <?php foreach ($brands as $b):
                                    $models = $db->getModelsByBrand($b['id']);
                                    foreach ($models as $m): ?>
                                <option value="<?= $m['id'] ?>" data-brand="<?= $b['id'] ?>"><?= escape($b['name']) ?> — <?= escape($m['name']) ?></option>
                                <?php endforeach; endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Version</label>
                        <input type="text" name="version" placeholder="1.0">
                    </div>
                    <div class="form-group">
                        <label>Description / Changelog</label>
                        <textarea name="description" rows="2" placeholder="Brief description of this file..."></textarea>
                    </div>
                    <div class="form-group" id="changelogField" style="display:none;">
                        <label>Changelog</label>
                        <textarea name="changelog" rows="2" placeholder="What's new in this firmware version..."></textarea>
                    </div>
                    <div id="uploadProgressWrap" class="upload-progress-wrap" aria-live="polite">
                        <div class="upload-progress-head">
                            <strong id="uploadProgressPct">0%</strong>
                            <span id="uploadProgressText">Preparing upload...</span>
                        </div>
                        <div class="upload-progress-track">
                            <div id="uploadProgressFill" class="upload-progress-fill"></div>
                        </div>
                        <div id="uploadStatusNote" class="upload-status-note"></div>
                    </div>
                    <div class="upload-submit-row">
                        <button type="submit" class="btn btn-primary" id="uploadSubmitBtn">
                            <i class="fas fa-upload btn-upload-icon"></i>
                            <i class="fas fa-spinner fa-spin btn-upload-loader"></i>
                            <span class="btn-upload-text">Upload File</span>
                        </button>
                    </div>
                </form>

                <?php if (canManageBrands()): ?>
                <div id="brandInlineModal" class="upload-inline-modal" onclick="if(event.target===this)hideInlineForm('brandInlineForm')">
                    <div id="brandInlineForm" class="upload-inline-form">
                        <h3><i class="fas fa-layer-group"></i> Create New Brand</h3>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="create_brand" value="1">
                            <input type="hidden" name="return_to" value="dashboard.php#add-file">
                            <div class="form-group">
                                <label>Brand Name<span class="required">*</span></label>
                                <input type="text" name="brand_name" required>
                            </div>
                            <div class="upload-inline-row">
                                <div class="form-group">
                                    <label>Brand Color</label>
                                    <input type="color" name="brand_color" value="#1a73e8" style="height:38px;padding:4px">
                                </div>
                                <div class="form-group">
                                    <label>Brand Image</label>
                                    <input type="file" name="brand_image" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml">
                                </div>
                            </div>
                            <div class="upload-inline-actions">
                                <button type="button" class="btn" style="background:#e0e0e0;color:#333" onclick="hideInlineForm('brandInlineForm')">Cancel</button>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Create</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Standalone inline model creation form -->
                <?php if (canManageModels()): ?>
                <div id="modelInlineModal" class="upload-inline-modal" onclick="if(event.target===this)hideInlineForm('modelInlineForm')">
                    <div id="modelInlineForm" class="upload-inline-form">
                    <h3><i class="fas fa-microchip"></i> Create New Model</h3>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="create_model" value="1">
                        <input type="hidden" name="return_to" value="dashboard.php#add-file">
                        <div class="upload-inline-row">
                            <select name="model_brand_id" style="flex:1;min-width:120px;padding:6px 10px;border:2px solid #e0e0e0;border-radius:6px;font-size:0.85rem" required>
                                <option value="">— Brand —</option>
                                <?php foreach ($brands as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= escape($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="model_name" placeholder="Model name" style="flex:1;padding:6px 10px;border:2px solid #e0e0e0;border-radius:6px;font-size:0.85rem" required>
                            <select name="model_system_type" style="padding:6px 10px;border:2px solid #e0e0e0;border-radius:6px;font-size:0.85rem">
                                <option value="">System</option>
                                <option value="advanced">Advanced</option>
                                <option value="standard">Standard</option>
                            </select>
                            <input type="file" name="model_image" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="flex:1;min-width:180px;padding:6px 10px;border:1px dashed #b8d0e8;border-radius:6px;font-size:0.8rem;background:#fff">
                            <button type="submit" name="create_model" class="btn btn-sm btn-primary" style="white-space:nowrap"><i class="fas fa-plus"></i> Create</button>
                            <button type="button" class="btn btn-sm" style="background:#e0e0e0;color:#333" onclick="hideInlineForm('modelInlineForm')">Cancel</button>
                        </div>
                    </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <script>
                var uploadTypeExtMap = <?= json_encode([
                    'config' => array_values(ALLOWED_CONFIG_EXT),
                    'firmware' => array_values(ALLOWED_FIRMWARE_EXT),
                    'manual' => array_values(ALLOWED_MANUAL_EXT),
                    'software' => array_values(ALLOWED_SOFTWARE_EXT),
                ], JSON_UNESCAPED_SLASHES) ?>;
                var uploadTypeLabelMap = {
                    config: 'Configuration File',
                    firmware: 'Firmware',
                    manual: 'Manual / Guide',
                    software: 'Configurator Software'
                };

                function toggleUploadFields() {
                    const type = document.getElementById('uploadType').value;
                    document.getElementById('changelogField').style.display = (type === 'firmware') ? 'block' : 'none';
                }
                function updateUploadFileChip() {
                    var input = document.getElementById('uploadFileInput');
                    var chip = document.getElementById('uploadFileChip');
                    var nameEl = document.getElementById('uploadFileName');
                    var sizeEl = document.getElementById('uploadFileSize');
                    if (!input || !chip || !nameEl || !sizeEl) return;
                    if (!input.files || !input.files.length) {
                        chip.style.display = 'none';
                        return;
                    }
                    var file = input.files[0];
                    var sizeKb = (file.size / 1024);
                    var sizeText = sizeKb < 1024 ? (sizeKb.toFixed(1) + ' KB') : ((sizeKb / 1024).toFixed(2) + ' MB');
                    nameEl.textContent = file.name;
                    sizeEl.textContent = sizeText;
                    chip.style.display = 'flex';

                    var displayName = document.getElementById('displayNameInput');
                    if (displayName && !displayName.value.trim()) {
                        displayName.value = file.name.replace(/\.[^.]+$/, '');
                    }
                }
                function detectUploadTypeByExt(ext) {
                    var normalized = String(ext || '').toLowerCase();
                    if (!normalized) return '';
                    var types = Object.keys(uploadTypeExtMap || {});
                    for (var i = 0; i < types.length; i++) {
                        var type = types[i];
                        var list = uploadTypeExtMap[type] || [];
                        if (list.indexOf(normalized) !== -1) {
                            return type;
                        }
                    }
                    return '';
                }
                function initUploadDropzone() {
                    var zone = document.getElementById('uploadDropzone');
                    var input = document.getElementById('uploadFileInput');
                    if (!zone || !input) return;

                    function preventDefaults(e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    function highlight() { zone.classList.add('is-dragover'); }
                    function unhighlight() { zone.classList.remove('is-dragover'); }
                    function assignDroppedFile(files) {
                        if (!files || !files.length) return false;
                        try {
                            if (typeof DataTransfer !== 'undefined') {
                                var dt = new DataTransfer();
                                dt.items.add(files[0]);
                                input.files = dt.files;
                                return true;
                            }
                        } catch (err) {}
                        try {
                            input.files = files;
                            return !!(input.files && input.files.length);
                        } catch (err2) {
                            return false;
                        }
                    }

                    ['dragenter', 'dragover'].forEach(function(evt) {
                        zone.addEventListener(evt, function(e) { preventDefaults(e); highlight(); });
                    });
                    ['dragleave', 'dragend', 'drop'].forEach(function(evt) {
                        zone.addEventListener(evt, function(e) { preventDefaults(e); unhighlight(); });
                    });

                    zone.addEventListener('drop', function(e) {
                        var files = e.dataTransfer ? e.dataTransfer.files : null;
                        if (!assignDroppedFile(files)) {
                            if (typeof showToast === 'function') showToast('Could not attach dropped file. Please use browse.', 'error', 3800);
                            return;
                        }
                        updateUploadFileChip();
                        if (typeof showToast === 'function') showToast('File attached. Ready to upload.', 'success', 2200);
                    });

                    zone.addEventListener('click', function() { input.click(); });
                    zone.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            input.click();
                        }
                    });
                }

                function initUploadAsync() {
                    var uploadForm = document.getElementById('uploadForm');
                    var uploadBtn = document.getElementById('uploadSubmitBtn');
                    var uploadText = uploadBtn ? uploadBtn.querySelector('.btn-upload-text') : null;
                    var uploadIcon = uploadBtn ? uploadBtn.querySelector('.btn-upload-icon') : null;
                    var uploadLoader = uploadBtn ? uploadBtn.querySelector('.btn-upload-loader') : null;
                    var progressWrap = document.getElementById('uploadProgressWrap');
                    var progressFill = document.getElementById('uploadProgressFill');
                    var progressPct = document.getElementById('uploadProgressPct');
                    var progressText = document.getElementById('uploadProgressText');
                    var statusNote = document.getElementById('uploadStatusNote');

                    if (!uploadForm || !uploadBtn || uploadForm.dataset.asyncBound === '1') return;
                    uploadForm.dataset.asyncBound = '1';

                    function setUploadBusy(isBusy) {
                        uploadBtn.disabled = !!isBusy;
                        if (uploadText) uploadText.textContent = isBusy ? 'Uploading File' : 'Upload File';
                        if (uploadIcon) uploadIcon.style.display = isBusy ? 'none' : 'inline-flex';
                        if (uploadLoader) uploadLoader.style.display = isBusy ? 'inline-flex' : 'none';
                    }

                    function setUploadProgress(percent, label) {
                        var safe = Math.max(0, Math.min(100, percent || 0));
                        if (progressWrap) progressWrap.classList.add('active');
                        if (progressFill) progressFill.style.width = safe + '%';
                        if (progressPct) progressPct.textContent = Math.round(safe) + '%';
                        if (progressText && label) progressText.textContent = label;
                    }

                    function setUploadStatus(message, isError) {
                        if (!statusNote) return;
                        statusNote.textContent = message || '';
                        statusNote.classList.toggle('active', !!message);
                        statusNote.classList.toggle('error', !!isError);
                    }

                    function fallbackSubmit() {
                        uploadForm.submit();
                    }

                    uploadForm.addEventListener('submit', function(e) {
                        e.preventDefault();

                        var typeSelect = document.getElementById('uploadType');
                        var type = typeSelect ? typeSelect.value : 'config';
                        var brand = document.getElementById('brandSelect');
                        var model = document.getElementById('modelSelect');
                        var fileInput = document.getElementById('uploadFileInput');

                        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                            if (typeof showToast === 'function') showToast('Choose a file first.', 'error', 4200);
                            return;
                        }

                        var ext = (fileInput.files[0].name.split('.').pop() || '').toLowerCase();
                        var allowedForType = uploadTypeExtMap[type] || [];
                        if (allowedForType.length && allowedForType.indexOf(ext) === -1) {
                            var detectedType = detectUploadTypeByExt(ext);
                            if (detectedType && detectedType !== type && typeSelect) {
                                typeSelect.value = detectedType;
                                toggleUploadFields();
                                type = detectedType;
                                allowedForType = uploadTypeExtMap[type] || [];
                                if (typeof showToast === 'function') {
                                    showToast('Upload type changed to ' + (uploadTypeLabelMap[type] || type) + ' for .' + ext + ' files.', 'success', 2800);
                                }
                            } else {
                                if (typeof showToast === 'function') showToast('Selected file .' + ext + ' is not supported.', 'error', 4200);
                                return;
                            }
                        }

                        if (!brand || !brand.value) {
                            if (typeof showToast === 'function') showToast('Please select a brand.', 'error', 4200);
                            return;
                        }
                        if (type === 'config' && (!model || !model.value)) {
                            if (typeof showToast === 'function') showToast('Please select a device model for configuration files.', 'error', 4200);
                            return;
                        }

                        var formData = new FormData(uploadForm);
                        if (typeof formData.set === 'function') {
                            formData.set('ajax', '1');
                        } else {
                            formData.append('ajax', '1');
                        }

                        var xhr = null;
                        try {
                            xhr = new XMLHttpRequest();
                        } catch (err) {
                            fallbackSubmit();
                            return;
                        }

                        setUploadBusy(true);
                        setUploadStatus('', false);
                        setUploadProgress(0, 'Preparing upload...');

                        xhr.open('POST', 'dashboard.php', true);
                        xhr.upload.onprogress = function(ev) {
                            if (!ev.lengthComputable) return;
                            var pct = Math.min(98, Math.round((ev.loaded / ev.total) * 100));
                            setUploadProgress(pct, pct < 95 ? 'Uploading file...' : 'Finalizing publish...');
                        };
                        xhr.onerror = function() {
                            setUploadBusy(false);
                            setUploadProgress(0, 'Preparing upload...');
                            setUploadStatus('Upload failed. Please try again.', true);
                            if (typeof showToast === 'function') showToast('Upload failed. Please try again.', 'error', 4200);
                        };
                        xhr.onload = function() {
                            var data = null;
                            try {
                                data = JSON.parse(xhr.responseText || '{}');
                            } catch (err) {
                                fallbackSubmit();
                                return;
                            }

                            if (xhr.status >= 200 && xhr.status < 300 && data && data.success) {
                                setUploadProgress(100, 'Published to user library');
                                setUploadStatus(data.message || 'Upload complete.', false);
                                if (typeof showToast === 'function') showToast(data.message || 'Upload complete.', 'success', 3200);
                                var nextTab = data.tab || 'add-file';
                                window.setTimeout(function() {
                                    window.location.href = 'dashboard.php#' + nextTab;
                                }, 900);
                                return;
                            }

                            setUploadBusy(false);
                            setUploadProgress(0, 'Preparing upload...');
                            var msg = (data && data.message) ? data.message : 'Upload failed. Please try again.';
                            setUploadStatus(msg, true);
                            if (typeof showToast === 'function') showToast(msg, 'error', 4200);
                        };
                        xhr.send(formData);
                    });
                }

                initUploadDropzone();
                initUploadAsync();
                toggleUploadFields();
            </script>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canViewTab('configs')): ?>
        <div id="tab-configs" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-file-code"></i> Configuration Files</h2>
                <?php
                $allConfigs = $db->fetchAll("SELECT c.*, dm.name as model_name, b.name as brand_name FROM config_files c JOIN device_models dm ON c.device_model_id = dm.id JOIN brands b ON dm.brand_id = b.id WHERE c.status = 'active' ORDER BY c.created_at DESC LIMIT ?", [$adminTablePageSize]);
                if (empty($allConfigs)): echo '<div class="empty">No config files uploaded yet.</div>';
                else: ?>
                <p class="inline-note">Showing latest <?= (int)$adminTablePageSize ?> config files for fast admin performance.</p>
                <table>
                    <thead><tr><th><input type="checkbox" id="selAllConfig" onchange="toggleAll('config',this.checked)"></th><th>Name</th><th>Brand/Model</th><th>System</th><th>Version</th><th>Size</th><th>Downloads</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allConfigs as $c): ?>
                        <tr>
                            <td><input type="checkbox" class="sel-config" value="<?= $c['id'] ?>" onchange="checkBulkBtn('config')"></td>
                            <td><?= escape($c['name']) ?></td>
                            <td><?= escape($c['brand_name']) ?> — <?= escape($c['model_name']) ?></td>
                            <td><?= systemTypeBadge($c['system_type'] ?? '') ?></td>
                            <td>v<?= escape($c['version']) ?></td>
                            <td><?= formatFileSize($c['file_size']) ?></td>
                            <td><?= (int)$c['download_count'] ?></td>
                            <td><?php if (canEditFiles()): ?><a href="#" class="btn btn-primary btn-sm" data-edit-type="config" data-edit-id="<?= $c['id'] ?>" data-edit-name="<?= escape($c['name']) ?>" data-edit-version="<?= escape($c['version']) ?>" data-edit-desc="<?= escape($c['description'] ?? '') ?>" onclick="return openRenameModal(this)"><i class="fas fa-edit"></i></a><?php endif; ?><?php if (canDelete()): ?><a href="#" class="btn btn-danger btn-sm" data-del-id="<?= $c['id'] ?>" data-del-type="config" onclick="return deleteFile(<?= $c['id'] ?>,'config')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (canDelete()): ?><div id="bulkBarConfig" style="display:none;margin-top:8px"><button class="btn btn-danger btn-sm" onclick="bulkDelete('config')"><i class="fas fa-trash"></i> Delete Selected</button></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canViewTab('firmware')): ?>
        <div id="tab-firmware" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-microchip"></i> Firmware Files</h2>
                <?php
                $allFirmware = $db->fetchAll("SELECT f.*, b.name as brand_name, dm.name as model_name
                    FROM firmware_files f
                    JOIN brands b ON f.brand_id = b.id
                    LEFT JOIN device_models dm ON f.device_model_id = dm.id
                    WHERE f.status = 'active'
                    ORDER BY f.created_at DESC
                    LIMIT ?", [$adminTablePageSize]);
                if (empty($allFirmware)): echo '<div class="empty">No firmware files uploaded yet.</div>';
                else: ?>
                <p class="inline-note">Showing latest <?= (int)$adminTablePageSize ?> firmware files for fast admin performance.</p>
                <table>
                    <thead><tr><th><input type="checkbox" id="selAllFirmware" onchange="toggleAll('firmware',this.checked)"></th><th>Name</th><th>Brand/Model</th><th>System</th><th>Version</th><th>Size</th><th>Downloads</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($allFirmware as $f): ?>
                        <tr>
                            <td><input type="checkbox" class="sel-firmware" value="<?= $f['id'] ?>" onchange="checkBulkBtn('firmware')"></td>
                            <td><?= escape($f['name']) ?></td>
                            <td><?= escape($f['brand_name']) ?> â€” <?= escape($f['model_name'] ?? 'All') ?></td>
                            <td><?= systemTypeBadge($f['system_type'] ?? '') ?></td>
                            <td>v<?= escape($f['version']) ?></td>
                            <td><?= formatFileSize($f['file_size']) ?></td>
                            <td><?= (int)$f['download_count'] ?></td>
                            <td><?php if (canEditFiles()): ?><a href="#" class="btn btn-primary btn-sm" data-edit-type="firmware" data-edit-id="<?= $f['id'] ?>" data-edit-name="<?= escape($f['name']) ?>" data-edit-version="<?= escape($f['version']) ?>" data-edit-desc="<?= escape($f['changelog'] ?? '') ?>" onclick="return openRenameModal(this)"><i class="fas fa-edit"></i></a><?php endif; ?><?php if (canDelete()): ?><a href="#" class="btn btn-danger btn-sm" data-del-id="<?= $f['id'] ?>" data-del-type="firmware" onclick="return deleteFile(<?= $f['id'] ?>,'firmware')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (canDelete()): ?><div id="bulkBarFirmware" style="display:none;margin-top:8px"><button class="btn btn-danger btn-sm" onclick="bulkDelete('firmware')"><i class="fas fa-trash"></i> Delete Selected</button></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canViewTab('manuals')): ?>
        <div id="tab-manuals" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-book"></i> Manuals</h2>
                <?php
                $allManuals = $db->fetchAll("SELECT m.*, b.name as brand_name, dm.name as model_name
                    FROM manuals m
                    JOIN brands b ON m.brand_id = b.id
                    LEFT JOIN device_models dm ON m.device_model_id = dm.id
                    WHERE m.status = 'active'
                    ORDER BY m.created_at DESC
                    LIMIT ?", [$adminTablePageSize]);
                if (empty($allManuals)): echo '<div class="empty">No manuals uploaded yet.</div>';
                else: ?>
                <p class="inline-note">Showing latest <?= (int)$adminTablePageSize ?> manuals for fast admin performance.</p>
                <table>
                    <thead><tr><th><input type="checkbox" id="selAllManual" onchange="toggleAll('manual',this.checked)"></th><th>Name</th><th>Brand/Model</th><th>System</th><th>Size</th><th>Downloads</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($allManuals as $m): ?>
                        <tr>
                            <td><input type="checkbox" class="sel-manual" value="<?= $m['id'] ?>" onchange="checkBulkBtn('manual')"></td>
                            <td><?= escape($m['name']) ?></td>
                            <td><?= escape($m['brand_name']) ?> â€” <?= escape($m['model_name'] ?? 'General') ?></td>
                            <td><?= systemTypeBadge($m['system_type'] ?? '') ?></td>
                            <td><?= formatFileSize($m['file_size']) ?></td>
                            <td><?= (int)$m['download_count'] ?></td>
                            <td><?php if (canEditFiles()): ?><a href="#" class="btn btn-primary btn-sm" data-edit-type="manual" data-edit-id="<?= $m['id'] ?>" data-edit-name="<?= escape($m['name']) ?>" data-edit-version="" data-edit-desc="<?= escape($m['description'] ?? '') ?>" onclick="return openRenameModal(this)"><i class="fas fa-edit"></i></a><?php endif; ?><?php if (canDelete()): ?><a href="#" class="btn btn-danger btn-sm" data-del-id="<?= $m['id'] ?>" data-del-type="manual" onclick="return deleteFile(<?= $m['id'] ?>,'manual')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (canDelete()): ?><div id="bulkBarManual" style="display:none;margin-top:8px"><button class="btn btn-danger btn-sm" onclick="bulkDelete('manual')"><i class="fas fa-trash"></i> Delete Selected</button></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canViewTab('software')): ?>
        <div id="tab-software" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-gear"></i> Configurator Software</h2>
                <?php
                $allSoftware = $db->fetchAll("SELECT s.*, b.name as brand_name, dm.name as model_name
                    FROM software_files s
                    JOIN brands b ON s.brand_id = b.id
                    LEFT JOIN device_models dm ON s.device_model_id = dm.id
                    WHERE s.status = 'active'
                    ORDER BY s.created_at DESC
                    LIMIT ?", [$adminTablePageSize]);
                if (empty($allSoftware)): echo '<div class="empty">No software uploaded yet.</div>';
                else: ?>
                <p class="inline-note">Showing latest <?= (int)$adminTablePageSize ?> software files for fast admin performance.</p>
                <table>
                    <thead><tr><th><input type="checkbox" id="selAllSoftware" onchange="toggleAll('software',this.checked)"></th><th>Name</th><th>Brand/Model</th><th>System</th><th>Version</th><th>Size</th><th>Downloads</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($allSoftware as $s): ?>
                        <tr>
                            <td><input type="checkbox" class="sel-software" value="<?= $s['id'] ?>" onchange="checkBulkBtn('software')"></td>
                            <td><?= escape($s['name']) ?></td>
                            <td><?= escape($s['brand_name']) ?> â€” <?= escape($s['model_name'] ?? 'All') ?></td>
                            <td><?= systemTypeBadge($s['system_type'] ?? '') ?></td>
                            <td>v<?= escape($s['version']) ?></td>
                            <td><?= formatFileSize($s['file_size']) ?></td>
                            <td><?= (int)$s['download_count'] ?></td>
                            <td><?php if (canEditFiles()): ?><a href="#" class="btn btn-primary btn-sm" data-edit-type="software" data-edit-id="<?= $s['id'] ?>" data-edit-name="<?= escape($s['name']) ?>" data-edit-version="<?= escape($s['version']) ?>" data-edit-desc="<?= escape($s['description'] ?? '') ?>" onclick="return openRenameModal(this)"><i class="fas fa-edit"></i></a><?php endif; ?><?php if (canDelete()): ?><a href="#" class="btn btn-danger btn-sm" data-del-id="<?= $s['id'] ?>" data-del-type="software" onclick="return deleteFile(<?= $s['id'] ?>,'software')"><i class="fas fa-trash"></i></a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (canDelete()): ?><div id="bulkBarSoftware" style="display:none;margin-top:8px"><button class="btn btn-danger btn-sm" onclick="bulkDelete('software')"><i class="fas fa-trash"></i> Delete Selected</button></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() || canViewTab('brands-models') || canManageBrands() || canManageModels()): ?>
        <div id="tab-brands-models" class="tab-content">
            <?php if (canManageBrands()): ?>
            <div class="section">
                <h2><i class="fas fa-sitemap"></i> Brands</h2>
                <?php if (empty($managedBrands)): echo '<div class="empty">No brands found.</div>'; else: ?>
                <table>
                    <thead><tr><th>Name</th><th>Image</th><th>Color</th><th>Models</th><th>Files</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($managedBrands as $brandRow): ?>
                        <?php
                        $brandFormId = 'brandForm' . (int)$brandRow['id'];
                        $brandImageSrc = resolveAdminBrandImageSrc($brandRow);
                        $canDeleteVisibleBrandImage = $brandImageColumnReady && !empty($brandImageSrc);
                        $deleteBrandImageConfirm = htmlspecialchars(json_encode('Delete brand image for "' . $brandRow['name'] . '"?'), ENT_QUOTES, 'UTF-8');
                        $brandColor = preg_match('/^#[0-9a-fA-F]{6}$/', $brandRow['color'] ?? '') ? $brandRow['color'] : '#005aa0';
                        $brandConfirm = htmlspecialchars(json_encode('Delete brand "' . $brandRow['name'] . '"? This also removes related models and linked files.'), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr class="user-row" data-user-role="<?= escape((string)$u['role']) ?>" data-user-status="<?= (int)$u['is_active'] === 1 ? 'active' : 'inactive' ?>">
                            <td>
                                <input type="text" name="brand_name" value="<?= escape($brandRow['name']) ?>" form="<?= $brandFormId ?>" required>
                            </td>
                            <td>
                                <div class="entity-image-cell">
                                    <?php if ($brandImageSrc): ?>
                                        <img src="<?= escape($brandImageSrc) ?>" alt="<?= escape($brandRow['name']) ?> image" class="entity-image-preview">
                                    <?php else: ?>
                                        <span class="entity-image-empty" title="No image"><i class="fas fa-image"></i></span>
                                    <?php endif; ?>
                                    <div class="entity-image-tools">
                                        <input type="file" name="brand_image" form="<?= $brandFormId ?>" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml">
                                        <?php if ($canDeleteVisibleBrandImage): ?>
                                        <form method="POST" style="display:inline" onsubmit='return confirm(<?= $deleteBrandImageConfirm ?>)'>
                                            <input type="hidden" name="delete_brand_image" value="1">
                                            <input type="hidden" name="brand_id" value="<?= (int)$brandRow['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm icon-only-btn" title="Delete image">
                                                <i class="fas fa-xmark"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <input type="color" name="brand_color" value="<?= escape($brandColor) ?>" form="<?= $brandFormId ?>" style="width:44px;height:34px;padding:2px">
                            </td>
                            <td><?= (int)$brandRow['model_count'] ?></td>
                            <td><?= (int)$brandRow['file_count'] ?></td>
                            <td>
                                <form id="<?= $brandFormId ?>" method="POST" enctype="multipart/form-data" style="display:inline">
                                    <input type="hidden" name="update_brand" value="1">
                                    <input type="hidden" name="brand_id" value="<?= (int)$brandRow['id'] ?>">
                                </form>
                                <button type="submit" form="<?= $brandFormId ?>" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
                                <form method="POST" style="display:inline" onsubmit='return confirm(<?= $brandConfirm ?>)'>
                                    <input type="hidden" name="delete_brand" value="1">
                                    <input type="hidden" name="brand_id" value="<?= (int)$brandRow['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (canManageModels()): ?>
            <div class="section">
                <h2><i class="fas fa-microchip"></i> Models</h2>
                <?php if (empty($managedModels)): echo '<div class="empty">No models found.</div>'; else: ?>
                <table>
                    <thead><tr><th>Name</th><th>Brand</th><th>System</th><th>Configs</th><th>Files</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($managedModels as $modelRow): ?>
                        <?php
                        $modelFormId = 'modelForm' . (int)$modelRow['id'];
                        $modelConfirm = htmlspecialchars(json_encode('Delete model "' . $modelRow['name'] . '"? This also removes linked configuration files.'), ENT_QUOTES, 'UTF-8');
                        ?>
                        <tr>
                            <td>
                                <input type="text" name="model_name" value="<?= escape($modelRow['name']) ?>" form="<?= $modelFormId ?>" required>
                            </td>
                            <td>
                                <select name="model_brand_id" form="<?= $modelFormId ?>" required>
                                    <?php foreach ($brands as $brandOption): ?>
                                    <option value="<?= (int)$brandOption['id'] ?>" <?= (int)$brandOption['id'] === (int)$modelRow['brand_id'] ? 'selected' : '' ?>><?= escape($brandOption['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="model_system_type" form="<?= $modelFormId ?>">
                                    <option value="" <?= empty($modelRow['system_type']) ? 'selected' : '' ?>>None</option>
                                    <option value="advanced" <?= ($modelRow['system_type'] ?? '') === 'advanced' ? 'selected' : '' ?>>Advanced</option>
                                    <option value="standard" <?= ($modelRow['system_type'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option>
                                </select>
                            </td>
                            <td><?= (int)$modelRow['config_count'] ?></td>
                            <td><?= (int)$modelRow['file_count'] ?></td>
                            <td>
                                <form id="<?= $modelFormId ?>" method="POST" enctype="multipart/form-data" style="display:inline">
                                    <input type="hidden" name="update_model" value="1">
                                    <input type="hidden" name="model_id" value="<?= (int)$modelRow['id'] ?>">
                                </form>
                                <button type="submit" form="<?= $modelFormId ?>" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save</button>
                                <form method="POST" style="display:inline" onsubmit='return confirm(<?= $modelConfirm ?>)'>
                                    <input type="hidden" name="delete_model" value="1">
                                    <input type="hidden" name="model_id" value="<?= (int)$modelRow['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    <?php if (isAdmin()): ?>
        <div id="tab-users" class="tab-content">
            <div class="section">
                <h2><i class="fas fa-user-plus"></i> Create New User</h2>
                <?php if (isset($userError)): ?>
                <div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;margin-bottom:16px"><?= escape($userError) ?></div>
                <?php endif; ?>
                <?php flash(); ?>
                <form method="POST" action="dashboard.php" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username<span class="required">*</span></label>
                            <input type="text" name="new_username" required>
                        </div>
                        <div class="form-group">
                            <label>Password<span class="required">*</span></label>
                            <div style="display:flex;gap:8px">
                                <input type="text" name="new_password" id="createPassword" required style="flex:1">
                                <button type="button" class="btn btn-sm" style="background:#e0e0e0;color:#333" onclick="generateCreatePassword()" title="Generate password">
                                    <i class="fas fa-key"></i>
                                </button>
                                <button type="button" class="btn btn-sm" style="background:#e0e0e0;color:#333" onclick="copyCreateCredentials()" title="Copy username and password">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="new_email" placeholder="optional">
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <select name="new_role" id="createRole" onchange="toggleCreatePerms()">
                                <option value="viewer">Viewer (view only)</option>
                                <option value="user">User (browse &amp; download)</option>
                                <option value="editor">Editor (upload/manage files)</option>
                                <option value="admin">Admin (full access)</option>
                            </select>
                        </div>
                    </div>
                    <div id="createPerms" style="display:none;background:#f0f7f0;padding:16px;border-radius:8px;margin-bottom:16px">
                        <label style="font-weight:600;font-size:0.9rem;display:block;margin-bottom:8px">Editor Permissions</label>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_upload" checked disabled> Upload files (always on)</label>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_delete" value="1"> Delete files</label>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_manage_brands" value="1"> Manage brands</label>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_manage_models" value="1"> Manage models</label>
                    </div>
                    <div class="form-group">
                        <label>Profile Image</label>
                        <input type="file" name="user_image" accept="image/jpeg,image/png,image/gif,image/webp">
                    </div>
                    <button type="submit" name="create_user" class="btn btn-primary"><i class="fas fa-plus"></i> Create User</button>
                </form>
            </div>
            <div class="section">
                <h2><i class="fas fa-users"></i> All Users</h2>
                <form method="GET" action="dashboard.php#users" class="dashboard-date-filter" style="margin-bottom:14px">
                    <label>From
                        <input type="date" name="report_from" value="<?= escape($reportFrom) ?>">
                    </label>
                    <label>To
                        <input type="date" name="report_to" value="<?= escape($reportTo) ?>">
                    </label>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-calendar-check"></i> Filter</button>
                    <a href="dashboard.php#users" class="btn"><i class="fas fa-rotate-left"></i> Reset</a>
                    <span class="date-meta" id="usersRangeLabel" data-range-label-prefix="Showing range: ">Showing range: <?= escape($reportRangeLabel) ?></span>
                </form>
                <div class="dashboard-actions" style="align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px">
                    <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap">
                        <label style="display:flex;flex-direction:column;gap:4px;font-size:0.74rem;font-weight:700;color:#475467">
                            Role
                            <select id="userRoleFilter" onchange="applyAdminSearch()" style="min-width:140px;padding:8px 10px;border:1px solid #cfe0f1;border-radius:8px;font-size:0.82rem;background:#fff">
                                <option value="">All Roles</option>
                                <option value="admin">Admin</option>
                                <option value="editor">Editor</option>
                                <option value="user">User</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </label>
                        <label style="display:flex;flex-direction:column;gap:4px;font-size:0.74rem;font-weight:700;color:#475467">
                            Status
                            <select id="userStatusFilter" onchange="applyAdminSearch()" style="min-width:140px;padding:8px 10px;border:1px solid #cfe0f1;border-radius:8px;font-size:0.82rem;background:#fff">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </label>
                    </div>
                    <button type="button" class="btn" onclick="resetUserFilters()"><i class="fas fa-rotate-left"></i> Clear Filters</button>
                </div>
                <?php
                $usageColumnsReady = ensureUserUsageColumns();
                if ($usageColumnsReady) {
                    $allUsers = $db->fetchAll("SELECT id, username, email, image, role, is_active, created_at, last_login_at, last_seen_at, total_active_seconds, total_downloads FROM users ORDER BY id ASC LIMIT ?", [$adminTablePageSize]);
                } else {
                    $allUsers = $db->fetchAll("SELECT id, username, email, image, role, is_active, created_at, NULL AS last_login_at, NULL AS last_seen_at, 0 AS total_active_seconds, 0 AS total_downloads FROM users ORDER BY id ASC LIMIT ?", [$adminTablePageSize]);
                }
                $allPerms = $db->fetchAll("SELECT * FROM user_permissions");
                $permMap = [];
                foreach ($allPerms as $p) $permMap[$p['user_id']] = $p;
                if (empty($allUsers)): echo '<div class="empty">No users found.</div>';
                else: ?>
                <p class="inline-note">Showing first <?= (int)$adminTablePageSize ?> users in the dashboard to keep large workspaces responsive.</p>
                <form method="POST" action="dashboard.php#users" onsubmit="return submitBulkUsersAction()">
                    <div id="bulkUserBar" style="display:none;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;border:1px solid #dbe7f5;background:#f8fbff;padding:10px 12px;border-radius:10px;margin-bottom:10px">
                        <strong id="bulkUserCount" style="font-size:0.85rem;color:#123a63">0 selected</strong>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                            <select id="bulkUserAction" name="bulk_user_action" style="min-width:180px;padding:8px 10px;border:1px solid #cfe0f1;border-radius:8px;font-size:0.82rem;background:#fff">
                                <option value="">Choose bulk action</option>
                                <option value="activate">Activate selected users</option>
                                <option value="deactivate">Deactivate selected users</option>
                                <option value="delete">Delete selected users</option>
                            </select>
                            <button type="submit" name="bulk_user_manage" value="1" class="btn btn-primary"><i class="fas fa-check"></i> Apply</button>
                        </div>
                    </div>
                <table>
                    <thead><tr><th style="width:42px"><input type="checkbox" id="selAllUsers" onchange="toggleAllUsers(this.checked)" title="Select all visible users"></th><th>ID</th><th>Image</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Online</th><th>Usage Time</th><th>Downloads</th><th>Last Seen</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($allUsers as $u): ?>
                        <?php $roleColor = $u['role']==='admin'?'#005aa0':($u['role']==='editor'?'#00a86b':'#6c757d'); ?>
                        <?php $onlineNow = isUserOnlineNow($u['last_seen_at'] ?? null, 120); ?>
                        <?php $usageText = formatUsageDuration((int)($u['total_active_seconds'] ?? 0)); ?>
                        <?php $downloadTotal = (int)($u['total_downloads'] ?? 0); ?>
                        <?php $lastSeenText = !empty($u['last_seen_at']) ? timeAgo($u['last_seen_at']) : 'never'; ?>
                        <?php $up = $permMap[$u['id']] ?? []; ?>
                        <?php $editPerms = ['can_upload'=>$up['can_upload']??0,'can_delete'=>$up['can_delete']??0,'can_manage_brands'=>$up['can_manage_brands']??0,'can_manage_models'=>$up['can_manage_models']??0]; ?>
                        <?php $editJson = htmlspecialchars(json_encode(['id'=>$u['id'],'username'=>$u['username'],'email'=>$u['email'] ?? '','role'=>$u['role'],'perms'=>$editPerms]), ENT_QUOTES, 'UTF-8'); ?>
                        <tr class="user-row" data-user-role="<?= escape((string)$u['role']) ?>" data-user-status="<?= (int)$u['is_active'] === 1 ? 'active' : 'inactive' ?>">
                            <td><input type="checkbox" class="sel-user" name="user_ids[]" value="<?= (int)$u['id'] ?>" onchange="checkBulkUsersBar()" <?= (int)$u['id'] === (int)($_SESSION['user_id'] ?? 0) ? 'disabled' : '' ?>></td>
                            <td><?= $u['id'] ?></td>
                            <td><?php if ($u['image']): ?><img src="../<?= escape($u['image']) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover"><?php else: ?><span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:#e0e0e0;color:#999;font-size:0.8rem"><i class="fas fa-user"></i></span><?php endif; ?></td>
                            <td><strong><?= escape($u['username']) ?></strong></td>
                            <td><?= escape($u['email'] ?: '—') ?></td>
                            <td><span style="background:<?= $roleColor ?>;color:#fff;padding:2px 10px;border-radius:4px;font-size:0.8rem"><?= $u['role'] ?></span></td>
                            <td><?= $u['is_active'] ? '<span style="color:#00a86b">Active</span>' : '<span style="color:#e74c3c">Inactive</span>' ?></td>
                            <td><span class="presence-pill <?= $onlineNow ? 'presence-online' : 'presence-offline' ?>" data-user-online="<?= (int)$u['id'] ?>"><?= $onlineNow ? 'Online' : 'Offline' ?></span></td>
                            <td><span class="usage-stat" data-user-usage="<?= (int)$u['id'] ?>"><?= escape($usageText) ?></span></td>
                            <td><span class="usage-stat" data-user-download-total="<?= (int)$u['id'] ?>"><?= $downloadTotal ?></span></td>
                            <td><span class="last-seen-text" data-user-last-seen="<?= (int)$u['id'] ?>"><?= escape($lastSeenText) ?></span></td>
                            <td><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="openEditModalFromJson('<?= $editJson ?>')" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0) && (int)$u['is_active'] === 1): ?>
                                <a href="?impersonate_user=<?= $u['id'] ?>" class="btn btn-sm btn-success" onclick="return confirm('Sign in as this user account?')" title="Sign in as this user">
                                    <i class="fas fa-user-secret"></i> Login As
                                </a>
                                <?php endif; ?>
                                <a href="?toggle_user=<?= $u['id'] ?>" class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-primary' ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fas fa-<?= $u['is_active'] ? 'ban' : 'check' ?>"></i>
                                </a>
                                <?php if ($u['role'] !== 'admin'): ?>
                                <a href="?delete_user=<?= $u['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete user <?= escape($u['username']) ?>?')" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Edit User Modal -->
    <div id="editUserModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;align-items:center;justify-content:center">
        <div style="background:#fff;border-radius:12px;padding:32px;width:480px;max-width:90vw;max-height:90vh;overflow-y:auto">
            <h2 style="margin-bottom:16px"><i class="fas fa-user-edit"></i> Edit User</h2>
            <?php if (isset($editUserError)): ?>
            <div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;margin-bottom:16px"><?= escape($editUserError) ?></div>
            <?php endif; ?>
            <?php flash(); ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="edit_user_id" id="editUserId">
                <div class="form-group">
                    <label>Username<span class="required">*</span></label>
                    <input type="text" name="edit_username" id="editUsername" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="edit_email" id="editEmail" placeholder="optional">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="edit_role" id="editRole" onchange="toggleEditPerms()">
                        <option value="viewer">Viewer (view only)</option>
                        <option value="user">User (browse &amp; download)</option>
                        <option value="editor">Editor (upload/manage files)</option>
                        <option value="admin">Admin (full access)</option>
                    </select>
                </div>
                <div id="editPerms" style="display:none;background:#f0f7f0;padding:16px;border-radius:8px;margin-bottom:16px">
                    <label style="font-weight:600;font-size:0.9rem;display:block;margin-bottom:8px">Editor Permissions</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_upload" id="editPermUpload" checked disabled> Upload files (always on)</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_delete" id="editPermDelete" value="1"> Delete files</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_manage_brands" id="editPermBrands" value="1"> Manage brands</label>
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-weight:400;font-size:0.85rem"><input type="checkbox" name="perm_manage_models" id="editPermModels" value="1"> Manage models</label>
                </div>
                <div class="form-group">
                    <label>Password <span style="color:#6c757d;font-weight:400;font-size:0.8rem">(leave blank to keep current — cannot view existing, only overwrite)</span></label>
                    <div style="display:flex;gap:8px">
                        <input type="password" name="edit_password" id="editPassword" placeholder="Enter new password" style="flex:1">
                        <button type="button" class="btn btn-sm" id="pwToggle" style="background:#e0e0e0;color:#333" onclick="togglePw()" title="Show/hide password"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Profile Image</label>
                    <input type="file" name="edit_image" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
                    <button type="button" class="btn" style="background:#e0e0e0;color:#333" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEditModalFromJson(json) {
            var data = JSON.parse(json);
            document.getElementById('editUserId').value = data.id;
            document.getElementById('editUsername').value = data.username;
            document.getElementById('editEmail').value = data.email || '';
            document.getElementById('editRole').value = data.role;
            // Set permission checkboxes
            var perms = data.perms || {};
            document.getElementById('editPermDelete').checked = perms.can_delete == 1;
            document.getElementById('editPermBrands').checked = perms.can_manage_brands == 1;
            document.getElementById('editPermModels').checked = perms.can_manage_models == 1;
            toggleEditPerms();
            document.getElementById('editUserModal').style.display = 'flex';
        }
        function closeEditModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }
        function togglePw() {
            var p = document.getElementById('editPassword');
            var t = document.getElementById('pwToggle');
            if (p.type === 'password') {
                p.type = 'text';
                t.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                p.type = 'password';
                t.innerHTML = '<i class="fas fa-eye"></i>';
            }
        }

        function showToast(message, type, timeoutMs) {
            var stack = document.getElementById('toastStack');
            if (!stack) return;
            var toast = document.createElement('div');
            var toastType = type === 'error' ? 'error' : 'success';
            toast.className = 'app-toast ' + toastType;
            var icon = toastType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
            toast.innerHTML = '<i class="fas ' + icon + '"></i><span>' + String(message || '') + '</span>';
            stack.appendChild(toast);
            var ttl = typeof timeoutMs === 'number' ? timeoutMs : 3200;
            setTimeout(function() {
                toast.classList.add('hide');
                setTimeout(function() {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 220);
            }, ttl);
        }

        function deleteFile(id, type) {
            if (!confirm('Delete this file?')) return false;
            fetch('dashboard.php?delete=' + id + '&type=' + type + '&ajax=1')
                .then(function(r) {
                    if (!r.ok) throw new Error('Request failed');
                    return r.json();
                })
                .then(function(d) {
                    if (d.success) {
                        var btn = document.querySelector('[data-del-id="' + id + '"][data-del-type="' + type + '"]');
                        if (btn) { var tr = btn.closest('tr'); if (tr) tr.remove(); }
                        applyAdminSearch();
                        showToast('File deleted from admin and user library.', 'success');
                    } else {
                        showToast('Delete failed.', 'error');
                    }
                })
                .catch(function() { showToast('Delete failed. Please refresh and try again.', 'error', 4200); });
            return false;
        }
        function toggleAll(type, checked) {
            document.querySelectorAll('.sel-' + type).forEach(function(cb) { cb.checked = checked; });
            checkBulkBtn(type);
        }
        function checkBulkBtn(type) {
            var bar = document.getElementById('bulkBar' + type.charAt(0).toUpperCase() + type.slice(1));
            if (!bar) return;
            var checked = document.querySelectorAll('.sel-' + type + ':checked');
            bar.style.display = checked.length > 0 ? 'block' : 'none';
        }
        function bulkDelete(type) {
            var checked = document.querySelectorAll('.sel-' + type + ':checked');
            var ids = [];
            checked.forEach(function(cb) { ids.push(cb.value); });
            if (ids.length === 0) { showToast('Select files to delete.', 'error'); return; }
            if (!confirm('Delete ' + ids.length + ' selected file(s)?')) return;
            var form = new FormData();
            form.append('bulk_delete', '1');
            form.append('type', type);
            ids.forEach(function(id) { form.append('ids[]', id); });
            fetch('dashboard.php', { method: 'POST', body: form })
                .then(function(r) {
                    if (!r.ok) throw new Error('Request failed');
                    return r.json();
                })
                .then(function(d) {
                    if (d.success) {
                        checked.forEach(function(cb) { var tr = cb.closest('tr'); if (tr) tr.remove(); });
                        applyAdminSearch();
                        showToast((d.deleted || ids.length) + ' file(s) deleted and removed from user library.', 'success');
                    } else {
                        showToast('Delete failed: ' + (d.message || 'Unknown error'), 'error', 4200);
                    }
                })
                .catch(function() { showToast('Bulk delete failed. Please refresh and try again.', 'error', 4200); });
        }
        function toggleCreatePerms() {
            var sel = document.getElementById('createRole');
            var perms = document.getElementById('createPerms');
            perms.style.display = sel.value === 'editor' ? 'block' : 'none';
        }
        function toggleEditPerms() {
            var sel = document.getElementById('editRole');
            var perms = document.getElementById('editPerms');
            perms.style.display = sel.value === 'editor' ? 'block' : 'none';
        }
        function generateCreatePassword() {
            var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
            var pwd = '';
            for (var i = 0; i < 14; i++) {
                pwd += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            var input = document.getElementById('createPassword');
            if (input) input.value = pwd;
        }
        function copyCreateCredentials() {
            var user = document.querySelector('input[name=\"new_username\"]');
            var pass = document.getElementById('createPassword');
            var username = user ? user.value.trim() : '';
            var password = pass ? pass.value.trim() : '';
            if (!username || !password) {
                alert('Enter username and password first.');
                return;
            }
            var text = 'Username: ' + username + '\\nPassword: ' + password;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    alert('Credentials copied.');
                }).catch(function() {
                    window.prompt('Copy credentials:', text);
                });
            } else {
                window.prompt('Copy credentials:', text);
            }
        }
        function toggleAllUsers(checked) {
            var rows = document.querySelectorAll('#tab-users .user-row');
            rows.forEach(function(row) {
                var cb = row.querySelector('.sel-user');
                if (!cb || cb.disabled) return;
                if (row.style.display === 'none') return;
                cb.checked = checked;
            });
            checkBulkUsersBar();
        }
        function checkBulkUsersBar() {
            var bar = document.getElementById('bulkUserBar');
            if (!bar) return;
            var checked = document.querySelectorAll('.sel-user:checked');
            var count = checked.length;
            var countText = document.getElementById('bulkUserCount');
            if (countText) countText.textContent = count + ' selected';
            bar.style.display = count > 0 ? 'flex' : 'none';
            var master = document.getElementById('selAllUsers');
            if (master) master.checked = false;
        }
        function submitBulkUsersAction() {
            var action = document.getElementById('bulkUserAction');
            var checked = document.querySelectorAll('.sel-user:checked');
            if (!action || !action.value) {
                alert('Choose a bulk action.');
                return false;
            }
            if (checked.length === 0) {
                alert('Select at least one user.');
                return false;
            }
            var msg = 'Apply "' + action.options[action.selectedIndex].text + '" to ' + checked.length + ' user(s)?';
            return confirm(msg);
        }
        function resetUserFilters() {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            if (role) role.value = '';
            if (status) status.value = '';
            applyAdminSearch();
        }
        // Close modal on backdrop click
        document.getElementById('editUserModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
    </script>
    <script>
        function adminRowSearchText(row) {
            var parts = [row.innerText || row.textContent || ''];
            row.querySelectorAll('input, textarea').forEach(function(el) {
                parts.push(el.value || '');
            });
            row.querySelectorAll('select').forEach(function(el) {
                parts.push(el.value || '');
                if (el.selectedIndex >= 0 && el.options[el.selectedIndex]) {
                    parts.push(el.options[el.selectedIndex].text || '');
                }
            });
            return parts.join(' ').toLowerCase();
        }

        function ensureAdminSearchEmptyRow(table) {
            var tbody = table.tBodies[0];
            if (!tbody) return null;
            var row = tbody.querySelector('.admin-search-empty-row');
            if (!row) {
                row = document.createElement('tr');
                row.className = 'admin-search-empty-row';
                var cell = document.createElement('td');
                cell.colSpan = table.tHead && table.tHead.rows[0] ? table.tHead.rows[0].cells.length : 1;
                cell.className = 'empty';
                cell.textContent = 'No matching records';
                row.appendChild(cell);
                tbody.appendChild(row);
            }
            return row;
        }

        function adminTabNameFromControl(control) {
            var onclick = control.getAttribute('onclick') || '';
            var match = onclick.match(/showTab\('([^']+)'\)/);
            return match ? match[1] : '';
        }

        function hasActiveUserFilters() {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            return !!((role && role.value) || (status && status.value));
        }

        function userRowMatchesFilters(row) {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            var roleFilter = role ? role.value : '';
            var statusFilter = status ? status.value : '';
            var rowRole = row.getAttribute('data-user-role') || '';
            var rowStatus = row.getAttribute('data-user-status') || '';
            if (roleFilter && rowRole !== roleFilter) return false;
            if (statusFilter && rowStatus !== statusFilter) return false;
            return true;
        }

        function hasActiveUserFilters() {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            return !!((role && role.value) || (status && status.value));
        }

        function userRowMatchesFilters(row) {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            var roleFilter = role ? role.value : '';
            var statusFilter = status ? status.value : '';
            var rowRole = row.getAttribute('data-user-role') || '';
            var rowStatus = row.getAttribute('data-user-status') || '';
            if (roleFilter && rowRole !== roleFilter) return false;
            if (statusFilter && rowStatus !== statusFilter) return false;
            return true;
        }

        function hasActiveUserFilters() {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            return !!((role && role.value) || (status && status.value));
        }

        function userRowMatchesFilters(row) {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            var roleFilter = role ? role.value : '';
            var statusFilter = status ? status.value : '';
            var rowRole = row.getAttribute('data-user-role') || '';
            var rowStatus = row.getAttribute('data-user-status') || '';
            if (roleFilter && rowRole !== roleFilter) return false;
            if (statusFilter && rowStatus !== statusFilter) return false;
            return true;
        }

        function hasActiveUserFilters() {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            return !!((role && role.value) || (status && status.value));
        }

        function userRowMatchesFilters(row) {
            var role = document.getElementById('userRoleFilter');
            var status = document.getElementById('userStatusFilter');
            var roleFilter = role ? role.value : '';
            var statusFilter = status ? status.value : '';
            var rowRole = row.getAttribute('data-user-role') || '';
            var rowStatus = row.getAttribute('data-user-status') || '';
            if (roleFilter && rowRole !== roleFilter) return false;
            if (statusFilter && rowStatus !== statusFilter) return false;
            return true;
        }

        function updateAdminSearchBadges(tabStats, query) {
            document.querySelectorAll('.tab-btn').forEach(function(btn) {
                var tabName = adminTabNameFromControl(btn);
                var badge = btn.querySelector('.tab-search-count');
                if (!query || !tabName || !tabStats[tabName] || tabStats[tabName].total === 0) {
                    if (badge) badge.remove();
                    return;
                }

                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'tab-search-count';
                    btn.appendChild(badge);
                }
                badge.textContent = tabStats[tabName].visible;
            });
        }

        function applyAdminSearch() {
            var input = document.getElementById('adminSearchInput');
            var meta = document.getElementById('adminSearchMeta');
            var clear = document.getElementById('adminSearchClear');
            var query = input ? input.value.trim().toLowerCase() : '';
            var activeTab = document.querySelector('.tab-content.active');
            var activeVisible = 0;
            var activeTotal = 0;
            var tabStats = {};

            document.querySelectorAll('.tab-content').forEach(function(tabContent) {
                var tabName = tabContent.id.replace(/^tab-/, '');
                tabStats[tabName] = { visible: 0, total: 0 };

                tabContent.querySelectorAll('table').forEach(function(table) {
                    var tbody = table.tBodies[0];
                    if (!tbody) return;
                    var rows = Array.prototype.filter.call(tbody.rows, function(row) {
                        return !row.classList.contains('admin-search-empty-row');
                    });
                    var visible = 0;

                    rows.forEach(function(row) {
                        var textMatch = query === '' || adminRowSearchText(row).indexOf(query) !== -1;
                        var userMatch = true;
                        if (tabName === 'users' && row.classList.contains('user-row')) {
                            userMatch = userRowMatchesFilters(row);
                        }
                        var match = textMatch && userMatch;
                        row.style.display = match ? '' : 'none';
                        if (match) visible++;
                    });

                    var emptyRow = ensureAdminSearchEmptyRow(table);
                    var shouldShowEmpty = visible === 0 && (query !== '' || (tabName === 'users' && hasActiveUserFilters()));
                    if (emptyRow) emptyRow.style.display = shouldShowEmpty ? '' : 'none';

                    tabStats[tabName].visible += visible;
                    tabStats[tabName].total += rows.length;
                });

                if (activeTab && activeTab === tabContent) {
                    activeVisible = tabStats[tabName].visible;
                    activeTotal = tabStats[tabName].total;
                }
            });

            updateAdminSearchBadges(tabStats, query);

            if (meta) {
                var usersTabActive = activeTab && activeTab.id === 'tab-users';
                meta.textContent = (query || (usersTabActive && hasActiveUserFilters())) ? activeVisible + '/' + activeTotal : '';
            }
            if (clear) {
                clear.hidden = query === '';
            }
            checkBulkUsersBar();
        }

        function clearAdminSearch() {
            var input = document.getElementById('adminSearchInput');
            if (input) input.value = '';
            applyAdminSearch();
            if (input) input.focus();
        }

        function updateAdminPageTitle(tabName) {
            var titleEl = document.getElementById('adminPageTitle');
            if (!titleEl) return;
            var map = {
                'dashboard': 'Dashboard',
                'admin-control': 'Admin Control',
                'add-file': 'Upload File',
                'configs': 'Config Files',
                'firmware': 'Firmware',
                'manuals': 'Manuals',
                'software': 'Software',
                'brands-models': 'Brands & Models',
                'users': 'Users'
            };
            titleEl.textContent = map[tabName] || titleEl.getAttribute('data-default-title') || 'Dashboard';
        }

        function setAdminMobileNavOpen(isOpen) {
            var body = document.body;
            var toggleBtn = document.getElementById('adminMobileNavToggle');
            if (!body) return;
            if (isOpen) {
                body.classList.add('admin-nav-open');
            } else {
                body.classList.remove('admin-nav-open');
            }
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
        }

        function closeAdminMobileNav() {
            setAdminMobileNavOpen(false);
        }

        function showTab(tabName) {
            setTimeout(function() {
                var tabs = document.querySelectorAll('.tab-content');
                var btns = document.querySelectorAll('.tab-btn');
                var navs = document.querySelectorAll('.sidebar a');
                var i, el, oc;
                for (i = 0; i < tabs.length; i++) { tabs[i].style.display = 'none'; }
                for (i = 0; i < btns.length; i++) { btns[i].classList.remove('active'); }
                for (i = 0; i < navs.length; i++) { navs[i].classList.remove('active'); }
                el = document.getElementById('tab-' + tabName);
                if (el) { el.style.display = 'block'; }
                for (i = 0; i < btns.length; i++) {
                    oc = btns[i].getAttribute('onclick') || '';
                    if (oc.indexOf("'" + tabName + "'") !== -1) btns[i].classList.add('active');
                }
                for (i = 0; i < navs.length; i++) {
                    oc = navs[i].getAttribute('onclick') || '';
                    if (oc.indexOf("'" + tabName + "'") !== -1) navs[i].classList.add('active');
                }
                try { updateAdminPageTitle(tabName); } catch(e) {}
                try { applyAdminSearch(); } catch(e) {}
                try { closeAdminMobileNav(); } catch(e) {}
            }, 10);
            return false;
        }

        function showInlineForm(id) {
            var el = document.getElementById(id);
            if (!el) return;
            document.querySelectorAll('.upload-inline-modal').forEach(function(modal) {
                modal.classList.remove('active');
            });
            var modalId = id === 'brandInlineForm' ? 'brandInlineModal' : (id === 'modelInlineForm' ? 'modelInlineModal' : '');
            var modal = modalId ? document.getElementById(modalId) : null;
            if (modal) modal.classList.add('active');
            if (id === 'modelInlineForm') {
                var brandSelect = document.getElementById('brandSelect');
                var inlineBrand = el.querySelector('select[name="model_brand_id"]');
                if (inlineBrand && inlineBrand.options && inlineBrand.options.length) {
                    inlineBrand.options[0].value = '';
                    inlineBrand.options[0].text = '- Brand -';
                }
                if (brandSelect && inlineBrand && brandSelect.value) {
                    inlineBrand.value = brandSelect.value;
                }
            }
            var firstInput = el.querySelector('input[type="text"], select');
            if (firstInput) firstInput.focus();
        }
        function hideInlineForm(id) {
            var modalId = id === 'brandInlineForm' ? 'brandInlineModal' : (id === 'modelInlineForm' ? 'modelInlineModal' : '');
            var modal = modalId ? document.getElementById(modalId) : null;
            if (modal) modal.classList.remove('active');
        }

        // Auto-select newly created items from URL params
        (function() {
            const params = new URLSearchParams(location.search);
            const cb = params.get('created_brand');
            const cm = params.get('created_model');
            if (cb) {
                const sel = document.getElementById('brandSelect');
                if (sel) { sel.value = cb; showTab('add-file'); }
                if (typeof syncUploadBrandModel === 'function') syncUploadBrandModel();
                const url = new URL(location);
                url.searchParams.delete('created_brand');
                history.replaceState(null, '', url);
            }
            if (cm) {
                const sel = document.getElementById('modelSelect');
                if (sel) {
                    sel.value = cm;
                    const selectedModel = sel.options[sel.selectedIndex];
                    const modelBrand = selectedModel ? selectedModel.getAttribute('data-brand') : '';
                    const brandSel = document.getElementById('brandSelect');
                    if (brandSel && modelBrand) brandSel.value = modelBrand;
                    showTab('add-file');
                }
                if (typeof syncUploadBrandModel === 'function') syncUploadBrandModel();
                const url = new URL(location);
                url.searchParams.delete('created_model');
                history.replaceState(null, '', url);
            }
        })();

        // Hash-based initial tab on page load
        (function() {
            var navToggle = document.getElementById('adminMobileNavToggle');
            var navBackdrop = document.getElementById('adminMobileNavBackdrop');
            var mq = window.matchMedia('(max-width: 900px)');
            if (navToggle) {
                navToggle.addEventListener('click', function() {
                    setAdminMobileNavOpen(!document.body.classList.contains('admin-nav-open'));
                });
            }
            if (navBackdrop) {
                navBackdrop.addEventListener('click', closeAdminMobileNav);
            }
            document.querySelectorAll('.sidebar a').forEach(function(link) {
                link.addEventListener('click', closeAdminMobileNav);
            });
            window.addEventListener('resize', function() {
                if (!mq.matches) {
                    closeAdminMobileNav();
                }
            });

            var adminSearchInput = document.getElementById('adminSearchInput');
            if (adminSearchInput) {
                adminSearchInput.addEventListener('input', applyAdminSearch);
                adminSearchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') clearAdminSearch();
                });
            }
            if (location.hash) {
                const tab = location.hash.replace('#', '');
                if (document.getElementById('tab-' + tab)) {
                    showTab(tab);
                }
            }
            var activeTabEl = document.querySelector('.tab-content.active');
            if (activeTabEl) {
                updateAdminPageTitle(activeTabEl.id.replace(/^tab-/, ''));
            }
            applyAdminSearch();
        })();

        // Make showTab globally accessible for onclick handlers
        window.showTab = showTab;

        // === FILE RENAME MODAL ===
        function openRenameModal(btn) {
            var type = btn.getAttribute('data-edit-type');
            var id = btn.getAttribute('data-edit-id');
            var name = btn.getAttribute('data-edit-name');
            var version = btn.getAttribute('data-edit-version');
            var desc = btn.getAttribute('data-edit-desc');
            document.getElementById('renameType').value = type;
            document.getElementById('renameId').value = id;
            document.getElementById('renameName').value = name;
            document.getElementById('renameVersion').value = version;
            document.getElementById('renameDesc').value = desc;
            document.getElementById('changelog').value = desc;
            var isFirmware = type === 'firmware';
            document.getElementById('renameDescGroup').style.display = isFirmware ? 'none' : 'block';
            document.getElementById('renameChangelogGroup').style.display = isFirmware ? 'block' : 'none';
            document.getElementById('renameModal').style.display = 'flex';
        }
        function closeRenameModal() {
            document.getElementById('renameModal').style.display = 'none';
        }
        function submitRename() {
            var form = new FormData();
            form.append('rename_file', '1');
            form.append('type', document.getElementById('renameType').value);
            form.append('file_id', document.getElementById('renameId').value);
            form.append('name', document.getElementById('renameName').value);
            form.append('version', document.getElementById('renameVersion').value);
            var type = document.getElementById('renameType').value;
            if (type === 'firmware') {
                form.append('changelog', document.getElementById('changelog').value);
            } else {
                form.append('description', document.getElementById('renameDesc').value);
            }
            fetch('dashboard.php', { method: 'POST', body: form })
                .then(function(r) {
                    if (!r.ok) throw new Error('Request failed');
                    return r.json();
                })
                .then(function(d) {
                    if (d.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (d.message || 'Unknown error'));
                    }
                })
                .catch(function() { alert('Save failed. Please refresh and try again.'); });
        }

        var dashRangeFrom = '<?= escape($reportFrom) ?>';
        var dashRangeTo = '<?= escape($reportTo) ?>';

        (function initDashboardLiveRangeRefresh() {
            var rangeTotalEl = document.getElementById('rangeDownloadsValue');
            if (!rangeTotalEl) { window.setDateRange = function(){}; window.toggleCustomDate = function(){}; window.applyCustomDate = function(){}; return; }

            // Initialize button state and range label on page load
            function initDateRangeState() {
                var now = new Date();
                var todayStr = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');

                // Determine which preset matches the current range
                var activePreset = 'custom';
                if (dashRangeFrom === todayStr && dashRangeTo === todayStr) {
                    activePreset = 'today';
                } else {
                    var d7 = new Date(now); d7.setDate(d7.getDate() - 6);
                    var d7Str = d7.getFullYear() + '-' + String(d7.getMonth()+1).padStart(2,'0') + '-' + String(d7.getDate()).padStart(2,'0');
                    if (dashRangeFrom === d7Str && dashRangeTo === todayStr) {
                        activePreset = '7d';
                    } else {
                        var d30 = new Date(now); d30.setDate(d30.getDate() - 29);
                        var d30Str = d30.getFullYear() + '-' + String(d30.getMonth()+1).padStart(2,'0') + '-' + String(d30.getDate()).padStart(2,'0');
                        if (dashRangeFrom === d30Str && dashRangeTo === todayStr) {
                            activePreset = '30d';
                        }
                    }
                }

                // Highlight the correct button
                document.querySelectorAll('.date-quick-btn').forEach(function(b){
                    b.classList.toggle('active', b.getAttribute('data-range') === activePreset);
                });

                // Update range label
                var rangeEl = document.getElementById('dashboardRangeLabel');
                if (rangeEl) {
                    var labels = { 'today':'Today', '7d':'Last 7 Days', '30d':'Last 30 Days' };
                    if (activePreset !== 'custom') {
                        rangeEl.textContent = 'Range: ' + (labels[activePreset] || activePreset);
                    } else {
                        rangeEl.textContent = 'Range: ' + (dashRangeFrom || '?') + ' - ' + (dashRangeTo || '?');
                    }
                }
            }
            initDateRangeState();

            var pollMs = 30000;
            var inFlight = false;
            var timerId = null;
            var statusEl = document.getElementById('dashboardLiveStatus');

            function safeText(value) {
                return value == null ? '' : String(value);
            }

            function escapeHtml(value) {
                return safeText(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            }

            function setStatus(message, isError) {
                if (!statusEl) return;
                statusEl.textContent = message;
                if (isError) {
                    statusEl.style.color = '#b42318';
                    statusEl.style.borderColor = '#f5c2c7';
                    statusEl.style.background = '#fff5f5';
                } else {
                    statusEl.style.color = '#0b63aa';
                    statusEl.style.borderColor = '#cfe1f7';
                    statusEl.style.background = '#eef6ff';
                }
            }

            function setCountById(id, value) {
                var el = document.getElementById(id);
                if (!el) return;
                var safeNumber = parseInt(value, 10);
                el.textContent = Number.isFinite(safeNumber) ? String(Math.max(0, safeNumber)) : '0';
            }

            function buildRequestParams() {
                var params = new URLSearchParams();
                params.set('ajax', 'dashboard_live');
                if (dashRangeFrom) params.set('range_from', dashRangeFrom);
                if (dashRangeTo) params.set('range_to', dashRangeTo);
                return params;
            }

            function setDateRange(preset) {
                document.querySelectorAll('.date-quick-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-range') === preset); });
                document.getElementById('dashboardDateCustom').style.display = 'none';
                var now = new Date();
                function fmt(d) {
                    var y = d.getFullYear();
                    var m = String(d.getMonth()+1).padStart(2,'0');
                    var day = String(d.getDate()).padStart(2,'0');
                    return y + '-' + m + '-' + day;
                }
                if (preset === 'today') {
                    dashRangeFrom = fmt(now);
                    dashRangeTo = fmt(now);
                } else if (preset === '7d') {
                    var d7 = new Date(now); d7.setDate(d7.getDate() - 6);
                    dashRangeFrom = fmt(d7);
                    dashRangeTo = fmt(now);
                } else if (preset === '30d') {
                    var d30 = new Date(now); d30.setDate(d30.getDate() - 29);
                    dashRangeFrom = fmt(d30);
                    dashRangeTo = fmt(now);
                }
                refreshDashboardLive();
                var rangeEl = document.getElementById('dashboardRangeLabel');
                if (rangeEl) {
                    var labels = { 'today':'Today', '7d':'Last 7 Days', '30d':'Last 30 Days' };
                    rangeEl.textContent = 'Range: ' + (labels[preset] || preset);
                }
            }

            function toggleCustomDate() {
                var el = document.getElementById('dashboardDateCustom');
                el.style.display = el.style.display === 'none' ? 'flex' : 'none';
                if (el.style.display === 'flex') {
                    document.querySelectorAll('.date-quick-btn').forEach(function(b){ b.classList.remove('active'); });
                }
            }

            function applyCustomDate() {
                var f = document.getElementById('customFrom');
                var t = document.getElementById('customTo');
                if (f && f.value) dashRangeFrom = f.value;
                if (t && t.value) dashRangeTo = t.value;
                document.getElementById('dashboardDateCustom').style.display = 'none';
                var rangeEl = document.getElementById('dashboardRangeLabel');
                if (rangeEl) rangeEl.textContent = 'Range: ' + (dashRangeFrom || '?') + ' - ' + (dashRangeTo || '?');
                refreshDashboardLive();
            }

            function updateUsers(users) {
                if (!Array.isArray(users)) return;
                users.forEach(function(userRow) {
                    var userId = parseInt(userRow.id, 10);
                    if (!Number.isFinite(userId) || userId <= 0) return;

                    var onlineEl = document.querySelector('[data-user-online="' + userId + '"]');
                    if (onlineEl) {
                        var online = !!userRow.online;
                        onlineEl.textContent = online ? 'Online' : 'Offline';
                        onlineEl.classList.toggle('presence-online', online);
                        onlineEl.classList.toggle('presence-offline', !online);
                    }

                    var usageEl = document.querySelector('[data-user-usage="' + userId + '"]');
                    if (usageEl) usageEl.textContent = safeText(userRow.usage_text);

                    var totalEl = document.querySelector('[data-user-download-total="' + userId + '"]');
                    if (totalEl) totalEl.textContent = safeText(userRow.downloads_total);

                    var seenEl = document.querySelector('[data-user-last-seen="' + userId + '"]');
                    if (seenEl) seenEl.textContent = safeText(userRow.last_seen_text);
                });
            }

            function renderRecentDownloads(eventsReady, events) {
                var feedWrap = document.getElementById('adminRecentDownloadsFeed');
                if (!feedWrap) return;

                if (!eventsReady) {
                    feedWrap.innerHTML = '<div class="empty">Download events are unavailable.</div>';
                    return;
                }

                if (!Array.isArray(events) || events.length === 0) {
                    feedWrap.innerHTML = '<div class="empty">No downloads found in selected range.</div>';
                    return;
                }

                var html = '<div class="download-feed">';
                events.forEach(function(item) {
                    html += '<div class="download-feed-item">';
                    html += '<div>';
                    html += '<strong>' + escapeHtml(item.username) + ' - ' + escapeHtml(item.file_type) + '</strong>';
                    html += '<small>' + escapeHtml(item.file_name) + '</small>';
                    html += '</div>';
                    html += '<small>' + escapeHtml(item.downloaded_label) + '</small>';
                    html += '</div>';
                });
                html += '</div>';
                feedWrap.innerHTML = html;
            }

            function applyLiveData(data) {
                if (!data || data.success !== true) return;

                if (data.downloads) {
                    var rangeTotal = parseInt(data.downloads.total, 10) || 0;
                    setCountById('rangeDownloadsValue', rangeTotal);
                    setCountById('rangeUsersValue', data.downloads.users);
                    setCountById('rangeDownloadsMixValue', rangeTotal);

                    var mixFill = document.getElementById('rangeDownloadsMixFill');
                    if (mixFill) mixFill.style.width = Math.min(100, Math.max(0, rangeTotal)) + '%';
                }

                if (data.stats) {
                    setCountById('kpiTotalFiles', data.stats.total_files);
                    setCountById('kpiBrands', data.stats.brands);
                    setCountById('kpiModels', data.stats.models);
                    setCountById('kpiConfigs', data.stats.configs);
                    setCountById('kpiFirmware', data.stats.firmware);
                    setCountById('kpiManuals', data.stats.manuals);
                    setCountById('kpiSoftware', data.stats.software);
                    setCountById('kpiUsers', data.stats.users);
                }

                updateUsers(data.users || []);
                renderRecentDownloads(!!data.events_ready, data.recent_downloads || []);
                if (typeof applyAdminSearch === 'function') applyAdminSearch();
                if (data.range_mix) {
                    var mixView = document.getElementById('rangeMixView');
                    var mixLabel = document.getElementById('rangeMixLabel');
                    if (mixView) {
                        var hasData = data.range_mix.some(function(m){ return m.count > 0; });
                        var mixHtml;
                        if (!hasData) {
                            mixHtml = '<div class="dash-empty-state"><i class="fas fa-inbox"></i>No files uploaded in this period.</div>';
                        } else {
                            mixHtml = '';
                            data.range_mix.forEach(function(item) {
                                mixHtml += '<div class="mix-row"><div class="mix-row-meta"><span>' + safeText(item.label) + '</span><span>' + item.count + ' (' + item.percent + '%)</span></div><div class="mix-track"><div class="mix-fill" style="width:' + item.percent + '%"></div></div></div>';
                            });
                        }
                        mixView.innerHTML = mixHtml;
                        mixView.classList.add('dash-fade-up');
                        setTimeout(function(){ mixView.classList.remove('dash-fade-up'); }, 500);
                    }
                    if (mixLabel && data.range && data.range.label) mixLabel.textContent = '(' + safeText(data.range.label) + ')';
                }
                var dlUpdated = document.getElementById('downloadsLastUpdated');
                if (dlUpdated && data.generated_label) dlUpdated.textContent = 'Updated ' + safeText(data.generated_label);
                setStatus('Live updated ' + safeText(data.generated_label), false);
            }

            function refreshDashboardLive() {
                if (inFlight) return;
                inFlight = true;

                var params = buildRequestParams();
                fetch('dashboard.php?' + params.toString(), {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                })
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.json();
                })
                .then(function(data) {
                    applyLiveData(data);
                })
                .catch(function() {
                    setStatus('Live update paused. Retrying...', true);
                })
                .finally(function() {
                    inFlight = false;
                });
            }

            function startTimer() {
                if (timerId) return;
                timerId = window.setInterval(refreshDashboardLive, pollMs);
            }

            function stopTimer() {
                if (!timerId) return;
                window.clearInterval(timerId);
                timerId = null;
            }

            document.addEventListener('visibilitychange', function() {
                if (document.hidden) {
                    stopTimer();
                    return;
                }
                refreshDashboardLive();
                startTimer();
            });
            window.addEventListener('beforeunload', stopTimer);

            refreshDashboardLive();
            startTimer();

            // Make functions globally accessible for onclick handlers
            window.setDateRange = setDateRange;
            window.toggleCustomDate = toggleCustomDate;
            window.applyCustomDate = applyCustomDate;
        })();
    </script>

    <!-- File Rename Modal -->
    <div id="renameModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;align-items:center;justify-content:center" onclick="if(event.target===this)closeRenameModal()">
        <div style="background:#fff;border-radius:12px;padding:32px;width:90%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,0.3)">
            <h2 style="margin-bottom:20px;font-size:1.2rem"><i class="fas fa-edit"></i> Edit File</h2>
            <input type="hidden" id="renameType">
            <input type="hidden" id="renameId">
            <div class="form-group">
                <label>File Name <span class="required">*</span></label>
                <input type="text" id="renameName" required>
            </div>
            <div class="form-group">
                <label>Version</label>
                <input type="text" id="renameVersion" placeholder="1.0">
            </div>
            <div class="form-group" id="renameDescGroup">
                <label>Description</label>
                <textarea id="renameDesc" rows="3"></textarea>
            </div>
            <div class="form-group" id="renameChangelogGroup" style="display:none">
                <label>Changelog</label>
                <textarea id="changelog" rows="3"></textarea>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:24px">
                <button class="btn" style="background:#e0e0e0;color:#333" onclick="closeRenameModal()">Cancel</button>
                <button class="btn btn-primary" onclick="submitRename()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>

</body>
</html>
