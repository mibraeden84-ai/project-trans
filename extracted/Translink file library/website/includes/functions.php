<?php
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}

function slugify($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower(empty($text) ? 'n-a' : $text);
}

function isAdmin() {
    return isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';
}

function isEditor() {
    return isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'editor';
}

function canManageFiles() {
    return isAdmin() || isEditor();
}

function requireAdmin() {
    if (!isAdmin()) redirect('admin/login.php');
}

function requireFileManager() {
    if (!canManageFiles()) redirect('admin/login.php');
}

function hasPermission($perm) {
    $role = $_SESSION['user_role'] ?? '';
    if ($role === 'admin') return true;
    $p = getCachedPermissions();
    return $p[$perm] ?? 0;
}

function getCachedPermissions() {
    $now = time();
    $cached = $_SESSION['_perms'] ?? null;
    if ($cached && isset($cached['_ts']) && ($now - $cached['_ts']) < 1) {
        return $cached;
    }
    $p = fetchUserPermissions();
    $p['_ts'] = $now;
    $_SESSION['_perms'] = $p;
    return $p;
}

function fetchUserPermissions($userId = null) {
    if ($userId === null) $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) return [];
    $defaults = ['can_upload'=>0,'can_delete'=>0,'can_manage_brands'=>0,'can_manage_models'=>0,'can_view_configs'=>0,'can_view_firmware'=>0,'can_view_manuals'=>0,'can_view_software'=>0,'can_view_brands_models'=>0,'can_edit_files'=>0];
    try {
        $db = Database::getInstance();
        $p = $db->fetchOne("SELECT * FROM user_permissions WHERE user_id = ?", [$userId]);
        return $p ? array_merge($defaults, $p) : $defaults;
    } catch (Exception $e) {
        return $defaults;
    }
}

function getUserPermissions($userId = null) {
    if ($userId === null) $userId = $_SESSION['user_id'] ?? 0;
    if (!$userId) return [];
    if ($userId === ($_SESSION['user_id'] ?? 0)) {
        return getCachedPermissions();
    }
    return fetchUserPermissions($userId);
}

function canUpload() {
    return hasPermission('can_upload');
}

function canDelete() {
    return hasPermission('can_delete');
}

function canManageBrands() {
    return hasPermission('can_manage_brands');
}

function canManageModels() {
    return hasPermission('can_manage_models');
}

function canViewTab($tab) {
    $map = ['configs'=>'can_view_configs','firmware'=>'can_view_firmware','manuals'=>'can_view_manuals','software'=>'can_view_software','brands-models'=>'can_view_brands_models'];
    $perm = $map[$tab] ?? null;
    return $perm ? hasPermission($perm) : false;
}

function canEditFiles() {
    return hasPermission('can_edit_files');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function ensureUserUsageColumns() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $db = Database::getInstance();
        $columns = [
            'last_seen_at' => DB_DRIVER === 'pgsql'
                ? "ALTER TABLE users ADD COLUMN last_seen_at TIMESTAMP NULL"
                : "ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL",
            'last_login_at' => DB_DRIVER === 'pgsql'
                ? "ALTER TABLE users ADD COLUMN last_login_at TIMESTAMP NULL"
                : "ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL",
            'total_active_seconds' => "ALTER TABLE users ADD COLUMN total_active_seconds BIGINT NOT NULL DEFAULT 0",
            'total_downloads' => "ALTER TABLE users ADD COLUMN total_downloads BIGINT NOT NULL DEFAULT 0",
        ];

        foreach ($columns as $columnName => $alterSql) {
            if (DB_DRIVER === 'pgsql') {
                $exists = $db->fetchOne(
                    "SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = ?",
                    [$columnName]
                );
            } else {
                $exists = $db->fetchOne(
                    "SELECT 1 FROM information_schema.columns
                     WHERE table_schema = DATABASE()
                       AND table_name = 'users'
                       AND column_name = ?",
                    [$columnName]
                );
            }
            if (!$exists) {
                $db->query($alterSql);
            }
        }
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function markUserLoginActivity($userId = null) {
    $uid = (int)($userId ?? ($_SESSION['user_id'] ?? 0));
    if ($uid <= 0) {
        return;
    }
    if (!ensureUserUsageColumns()) {
        return;
    }

    try {
        $db = Database::getInstance();
        $db->query("UPDATE users SET last_login_at = CURRENT_TIMESTAMP, last_seen_at = CURRENT_TIMESTAMP WHERE id = ?", [$uid]);
    } catch (Throwable $e) {
        // Tracking must not block login flow.
    }

    $now = time();
    $_SESSION['_usage_last_touch'] = $now;
    $_SESSION['_usage_last_db_ping'] = $now;
}

function finalizeUserUsageSession($userId = null) {
    $uid = (int)($userId ?? ($_SESSION['user_id'] ?? 0));
    if ($uid <= 0) {
        return;
    }

    $now = time();
    $lastTouch = (int)($_SESSION['_usage_last_touch'] ?? $now);
    $delta = max(0, $now - $lastTouch);
    if ($delta > 900) {
        $delta = 0;
    }
    $delta = min($delta, 120);

    if (ensureUserUsageColumns()) {
        try {
            $db = Database::getInstance();
            $db->query(
                "UPDATE users
                 SET last_seen_at = CURRENT_TIMESTAMP,
                     total_active_seconds = COALESCE(total_active_seconds, 0) + ?
                 WHERE id = ?",
                [$delta, $uid]
            );
        } catch (Throwable $e) {
            // Tracking must not block logout flow.
        }
    }

    unset($_SESSION['_usage_last_touch'], $_SESSION['_usage_last_db_ping']);
}

function trackUserActivityPing() {
    if (!isLoggedIn()) {
        return;
    }
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $now = time();
    $lastTouch = (int)($_SESSION['_usage_last_touch'] ?? $now);
    $delta = max(0, $now - $lastTouch);
    if ($delta > 900) {
        $delta = 0;
    }
    $delta = min($delta, 120);
    $_SESSION['_usage_last_touch'] = $now;

    $lastDbPing = (int)($_SESSION['_usage_last_db_ping'] ?? 0);
    $shouldWrite = $lastDbPing === 0 || ($now - $lastDbPing) >= 20;
    if (!$shouldWrite || !ensureUserUsageColumns()) {
        return;
    }

    try {
        $db = Database::getInstance();
        $db->query(
            "UPDATE users
             SET last_seen_at = CURRENT_TIMESTAMP,
                 total_active_seconds = COALESCE(total_active_seconds, 0) + ?
             WHERE id = ?",
            [$delta, $uid]
        );
        $_SESSION['_usage_last_db_ping'] = $now;
    } catch (Throwable $e) {
        // Tracking must never break normal page rendering.
    }
}

function isUserOnlineNow($lastSeenAt, $windowSeconds = 120) {
    if (empty($lastSeenAt)) {
        return false;
    }
    $seenTs = strtotime((string)$lastSeenAt);
    if (!$seenTs) {
        return false;
    }
    return (time() - $seenTs) <= max(15, (int)$windowSeconds);
}

function formatUsageDuration($seconds) {
    $s = max(0, (int)$seconds);
    $days = intdiv($s, 86400);
    $s %= 86400;
    $hours = intdiv($s, 3600);
    $s %= 3600;
    $mins = intdiv($s, 60);

    if ($days > 0) {
        return $days . 'd ' . $hours . 'h';
    }
    if ($hours > 0) {
        return $hours . 'h ' . $mins . 'm';
    }
    if ($mins > 0) {
        return $mins . 'm';
    }
    return '0m';
}

function incrementUserDownloadCount($userId = null, $step = 1) {
    $uid = (int)($userId ?? ($_SESSION['user_id'] ?? 0));
    $inc = max(1, (int)$step);
    if ($uid <= 0) {
        return;
    }
    if (!ensureUserUsageColumns()) {
        return;
    }

    try {
        $db = Database::getInstance();
        $db->query(
            "UPDATE users
             SET total_downloads = COALESCE(total_downloads, 0) + ?,
                 last_seen_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [$inc, $uid]
        );
    } catch (Throwable $e) {
        // Download tracking should not block file delivery.
    }
}

function ensureUserDownloadEventsTable() {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $db = Database::getInstance();
        if (DB_DRIVER === 'pgsql') {
            $db->query(
                "CREATE TABLE IF NOT EXISTS user_downloads (
                    id BIGSERIAL PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    file_type VARCHAR(20) NOT NULL,
                    file_id BIGINT DEFAULT NULL,
                    file_name VARCHAR(255) DEFAULT NULL,
                    downloaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );
            $db->query("CREATE INDEX IF NOT EXISTS idx_user_downloads_user_date ON user_downloads (user_id, downloaded_at)");
            $db->query("CREATE INDEX IF NOT EXISTS idx_user_downloads_date ON user_downloads (downloaded_at)");
        } else {
            $db->query(
                "CREATE TABLE IF NOT EXISTS user_downloads (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    file_type VARCHAR(20) NOT NULL,
                    file_id BIGINT NULL,
                    file_name VARCHAR(255) NULL,
                    downloaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_user_downloads_user_date (user_id, downloaded_at),
                    KEY idx_user_downloads_date (downloaded_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }

    return $ready;
}

function logUserDownloadEvent($userId = null, $fileType = '', $fileId = 0, $fileName = '') {
    $uid = (int)($userId ?? ($_SESSION['user_id'] ?? 0));
    $type = strtolower(trim((string)$fileType));
    $fid = (int)$fileId;
    $name = trim((string)$fileName);
    $allowed = ['config', 'firmware', 'manual', 'software'];

    if ($uid <= 0 || !in_array($type, $allowed, true)) {
        return;
    }
    if (!ensureUserDownloadEventsTable()) {
        return;
    }

    try {
        $db = Database::getInstance();
        $db->insert(
            "INSERT INTO user_downloads (user_id, file_type, file_id, file_name, downloaded_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)",
            [$uid, $type, $fid > 0 ? $fid : null, $name !== '' ? $name : null]
        );
    } catch (Throwable $e) {
        // Event logging should not block file delivery.
    }
}

function requireLogin() {
    if (!isLoggedIn()) redirect('login.php');
}

function redirect($url) {
    header("Location: $url");
    exit;
}

function escape($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function timeAgo($datetime) {
    if (!$datetime) return 'never';
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

function getFileIcon($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'cfg' => 'fa-file-code', 'conf' => 'fa-file-code', 'ini' => 'fa-file-code', 'csv' => 'fa-file-csv', 'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'txt' => 'fa-file-lines',
        'fw'  => 'fa-microchip', 'bin' => 'fa-microchip', 'hex' => 'fa-microchip', 'dfu' => 'fa-microchip', 'cfw' => 'fa-microchip',
        'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'exe' => 'fa-gear', 'msi' => 'fa-gear', 'zip' => 'fa-file-zipper', 'rar' => 'fa-file-zipper', '7z' => 'fa-file-zipper', 'gz' => 'fa-file-zipper', 'xim' => 'fa-microchip', 'cif' => 'fa-file-code',
    ];
    return $icons[$ext] ?? 'fa-file';
}

function getBrandIcon($icon, $slug = null) {
    if ($slug) {
        $imgDir = 'assets/images/';
        $patterns = [
            $imgDir . $slug . '_logo.svg',
            $imgDir . $slug . '_logo.png',
            $imgDir . $slug . '.png',
            $imgDir . $slug . '.webp',
        ];
        foreach ($patterns as $p) {
            if (file_exists(__DIR__ . '/../' . $p)) {
                return '<img src="' . $p . '" alt="' . escape($slug) . '" class="brand-img-icon">';
            }
        }
    }
    return $icon ?? '<i class="fas fa-satellite-dish"></i>';
}

function getFileTypeLabel($type) {
    $labels = ['config' => 'Config', 'firmware' => 'Firmware', 'manual' => 'Manual', 'software' => 'Software'];
    return $labels[$type] ?? ucfirst($type);
}

// Dark mode
function isDarkMode() {
    return isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === '1';
}

function darkModeClass() {
    return isDarkMode() ? 'dark-mode' : '';
}

// Log activity
function logActivity($action, $entityType, $entityId, $entityName, $details = null) {
    try {
        $db = Database::getInstance();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $db->insert("INSERT INTO activity_log (action, entity_type, entity_id, entity_name, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
            [$action, $entityType, $entityId, $entityName, $details, $ip]);
    } catch (Exception $e) {
        // Silently fail — logging should never break the app
    }
}

function parseTags($tagsStr) {
    if (empty($tagsStr)) return [];
    return array_map('trim', explode(',', $tagsStr));
}

function tagBadge($tag) {
    $colors = [
        'firmware' => '#e74c3c', 'config' => '#005aa0', 'can-bus' => '#00a86b',
        '1-wire' => '#f39c12', 'analog' => '#9b59b6', 'obdii' => '#e67e22',
        'default' => '#95a5a6', 'production' => '#2ecc71', 'testing' => '#e74c3c',
    ];
    $color = $colors[strtolower($tag)] ?? '#005aa0';
    return '<span class="tag" style="background:' . $color . '">' . escape($tag) . '</span>';
}

if (PHP_SAPI !== 'cli' && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api') && isLoggedIn()) {
    trackUserActivityPing();
}
