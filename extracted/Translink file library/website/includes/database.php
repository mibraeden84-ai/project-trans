<?php
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $retries = defined('DB_RETRY_ATTEMPTS') ? DB_RETRY_ATTEMPTS : 1;
        $delayMs = defined('DB_RETRY_DELAY_MS') ? DB_RETRY_DELAY_MS : 100;
        $persistent = defined('DB_PERSISTENT') ? DB_PERSISTENT : false;
        $lastException = null;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                $dsn = DB_DRIVER === 'pgsql'
                    ? "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME
                    : "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                if ($persistent) {
                    $options[PDO::ATTR_PERSISTENT] = true;
                }
                $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
                return;
            } catch (PDOException $e) {
                $lastException = $e;
                error_log("DB connection attempt {$attempt}/{$retries} failed: " . $e->getMessage());
                if ($attempt < $retries) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new RuntimeException("Database connection failed after {$retries} attempts: " . $lastException->getMessage());
    }

    public static function getInstance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function getConnection() { return $this->conn; }

    public function query($sql, $params = []) {
        $stmt = $this->conn->prepare($sql);
        foreach (array_values($params) as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, $this->pdoParamType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function insert($sql, $params = []) {
        if (DB_DRIVER !== 'pgsql') {
            $this->query($sql, $params);
            return $this->conn->lastInsertId();
        }

        $table = $this->insertTableName($sql);
        $returningId = $table && $table !== 'user_permissions' && stripos($sql, ' returning ') === false;

        if ($returningId) {
            $stmt = $this->query(rtrim($sql, " \t\n\r;") . " RETURNING id", $params);
            return $stmt->fetchColumn();
        }

        $this->query($sql, $params);
        return null;
    }

    private function pdoParamType($value) {
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if ($value === null) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    private function insertTableName($sql) {
        if (preg_match('/^\s*INSERT\s+INTO\s+"?([a-z_][a-z0-9_]*)"?/i', $sql, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    // === BRANDS & MODELS ===

    public function getBrands() {
        return $this->fetchAll("SELECT * FROM brands ORDER BY name");
    }

    public function getBrandBySlug($slug) {
        return $this->fetchOne("SELECT * FROM brands WHERE slug = ?", [$slug]);
    }

    public function getModelsByBrand($brandId) {
        return $this->fetchAll("SELECT * FROM device_models WHERE brand_id = ? ORDER BY name", [$brandId]);
    }

    public function getModelBySlug($brandId, $slug) {
        return $this->fetchOne("SELECT * FROM device_models WHERE brand_id = ? AND slug = ?", [$brandId, $slug]);
    }

    // === CONFIG FILES ===

    public function getConfigsByModel($modelId) {
        return $this->fetchAll("SELECT * FROM config_files WHERE device_model_id = ? AND status = 'active' ORDER BY version DESC", [$modelId]);
    }

    public function getConfig($id) {
        return $this->fetchOne("SELECT c.*, dm.name as model_name, b.name as brand_name FROM config_files c JOIN device_models dm ON c.device_model_id = dm.id JOIN brands b ON dm.brand_id = b.id WHERE c.id = ? AND c.status = 'active'", [$id]);
    }

    // === FIRMWARE ===

    public function getFirmwareByBrand($brandId) {
        return $this->fetchAll(
            "SELECT f.*, dm.name as model_name FROM firmware_files f
             LEFT JOIN device_models dm ON f.device_model_id = dm.id
             WHERE f.brand_id = ? AND f.status = 'active' ORDER BY f.created_at DESC", [$brandId]);
    }

    public function getFirmwareByModel($modelId) {
        return $this->fetchAll(
            "SELECT f.* FROM firmware_files f
             WHERE (f.device_model_id = ? OR f.device_model_id IS NULL) AND f.status = 'active'
             ORDER BY f.created_at DESC", [$modelId]);
    }

    public function getFirmwareByBrandAndModel($brandId, $modelId) {
        return $this->fetchAll(
            "SELECT f.* FROM firmware_files f
             WHERE f.brand_id = ? AND (f.device_model_id = ? OR f.device_model_id IS NULL) AND f.status = 'active'
             ORDER BY f.created_at DESC", [$brandId, $modelId]);
    }

    public function getLatestFirmware($limit = 6) {
        return $this->fetchAll(
            "SELECT f.*, b.name as brand_name, b.slug as brand_slug, b.color as brand_color, dm.name as model_name
             FROM firmware_files f
             JOIN brands b ON f.brand_id = b.id
             LEFT JOIN device_models dm ON f.device_model_id = dm.id
             WHERE f.status = 'active'
             ORDER BY f.created_at DESC LIMIT ?", [$limit]);
    }

    public function getFirmware($id) {
        return $this->fetchOne("SELECT f.*, b.name as brand_name FROM firmware_files f JOIN brands b ON f.brand_id = b.id WHERE f.id = ? AND f.status = 'active'", [$id]);
    }

    // === SOFTWARE ===

    public function getSoftwareByBrand($brandId) {
        return $this->fetchAll(
            "SELECT s.*, dm.name as model_name FROM software_files s
             LEFT JOIN device_models dm ON s.device_model_id = dm.id
             WHERE s.brand_id = ? AND s.status = 'active' ORDER BY s.created_at DESC", [$brandId]);
    }

    public function getSoftwareByBrandAndModel($brandId, $modelId) {
        return $this->fetchAll(
            "SELECT s.* FROM software_files s
             WHERE s.brand_id = ? AND (s.device_model_id = ? OR s.device_model_id IS NULL) AND s.status = 'active'
             ORDER BY s.created_at DESC", [$brandId, $modelId]);
    }

    public function getSoftwareByBrandAndType($brandId, $systemType) {
        return $this->fetchAll(
            "SELECT s.*, dm.name as model_name FROM software_files s
             LEFT JOIN device_models dm ON s.device_model_id = dm.id
             WHERE s.brand_id = ? AND s.system_type = ? AND s.status = 'active' ORDER BY s.created_at DESC", [$brandId, $systemType]);
    }

    public function getLatestVideoFirmware($limit = 4) {
        return $this->fetchAll(
            "SELECT f.*, b.name as brand_name, b.slug as brand_slug, b.color as brand_color, dm.name as model_name
             FROM firmware_files f
             JOIN brands b ON f.brand_id = b.id
             LEFT JOIN device_models dm ON f.device_model_id = dm.id
             WHERE b.slug = 'dash-cam' AND f.status = 'active'
             ORDER BY f.created_at DESC LIMIT ?", [$limit]);
    }

    public function getLatestVideoSoftware($limit = 4) {
        return $this->fetchAll(
            "SELECT s.*, b.name as brand_name, b.slug as brand_slug, b.color as brand_color, dm.name as model_name
             FROM software_files s
             JOIN brands b ON s.brand_id = b.id
             LEFT JOIN device_models dm ON s.device_model_id = dm.id
             WHERE b.slug = 'dash-cam' AND s.status = 'active'
             ORDER BY s.created_at DESC LIMIT ?", [$limit]);
    }

    public function getLatestSoftware($limit = 6) {
        return $this->fetchAll(
            "SELECT s.*, b.name as brand_name, b.slug as brand_slug, b.color as brand_color, dm.name as model_name
             FROM software_files s
             JOIN brands b ON s.brand_id = b.id
             LEFT JOIN device_models dm ON s.device_model_id = dm.id
             WHERE s.status = 'active'
             ORDER BY s.created_at DESC LIMIT ?", [$limit]);
    }

    // === MANUALS ===

    public function getManualsByBrand($brandId) {
        return $this->fetchAll(
            "SELECT m.*, dm.name as model_name FROM manuals m
             LEFT JOIN device_models dm ON m.device_model_id = dm.id
             WHERE m.brand_id = ? AND m.status = 'active' ORDER BY m.name", [$brandId]);
    }

    public function getManualsByBrandAndModel($brandId, $modelId) {
        return $this->fetchAll(
            "SELECT m.* FROM manuals m
             WHERE m.brand_id = ? AND (m.device_model_id = ? OR m.device_model_id IS NULL) AND m.status = 'active'
             ORDER BY m.name", [$brandId, $modelId]);
    }

    // === ACTIVITY ===

    public function getRecentActivity($limit = 10) {
        return $this->fetchAll("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT ?", [$limit]);
    }

    public function getActivityStats($days = 30) {
        if (DB_DRIVER !== 'pgsql') {
            return $this->fetchAll(
                "SELECT action, entity_type, COUNT(*) as count FROM activity_log
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY action, entity_type ORDER BY count DESC", [$days]);
        }

        return $this->fetchAll(
            "SELECT action, entity_type, COUNT(*) as count FROM activity_log
             WHERE created_at >= (CURRENT_TIMESTAMP - (? * INTERVAL '1 day'))
             GROUP BY action, entity_type ORDER BY count DESC", [$days]);
    }

    public function getTopDownloads($limit = 10) {
        $sql = "SELECT * FROM (
                    SELECT 'config' as type, name, download_count, created_at FROM config_files WHERE status = 'active'
                    UNION ALL SELECT 'firmware' as type, name, download_count, created_at FROM firmware_files WHERE status = 'active'
                    UNION ALL SELECT 'manual' as type, name, download_count, created_at FROM manuals WHERE status = 'active'
                    UNION ALL SELECT 'software' as type, name, download_count, created_at FROM software_files WHERE status = 'active'
                ) downloads
                ORDER BY download_count DESC LIMIT ?";
        return $this->fetchAll($sql, [$limit]);
    }

    // === STATS ===

    public function getStats() {
        return [
            'brands'  => $this->fetchOne("SELECT COUNT(*) as c FROM brands")['c'],
            'models'  => $this->fetchOne("SELECT COUNT(*) as c FROM device_models")['c'],
            'configs' => $this->fetchOne("SELECT COUNT(*) as c FROM config_files WHERE status = 'active'")['c'],
            'firmware'=> $this->fetchOne("SELECT COUNT(*) as c FROM firmware_files WHERE status = 'active'")['c'],
            'manuals' => $this->fetchOne("SELECT COUNT(*) as c FROM manuals WHERE status = 'active'")['c'],
            'software'=> $this->fetchOne("SELECT COUNT(*) as c FROM software_files WHERE status = 'active'")['c'],
            'downloads' => $this->fetchOne("SELECT COALESCE(SUM(download_count), 0) as c FROM (SELECT download_count FROM config_files WHERE status = 'active' UNION ALL SELECT download_count FROM firmware_files WHERE status = 'active' UNION ALL SELECT download_count FROM manuals WHERE status = 'active' UNION ALL SELECT download_count FROM software_files WHERE status = 'active') t")['c'] ?? 0,
            'activity' => $this->fetchOne("SELECT COUNT(*) as c FROM activity_log")['c'],
        ];
    }

    // === DOWNLOAD ===

    public function incrementDownload($table, $id) {
        $allowed = ['config_files', 'firmware_files', 'manuals', 'software_files'];
        if (!in_array($table, $allowed)) return;
        $this->query("UPDATE $table SET download_count = download_count + 1 WHERE id = ?", [$id]);
    }

    public function getFileForDownload($type, $id) {
        $tableMap = [
            'config'   => 'config_files',
            'firmware' => 'firmware_files',
            'manual'   => 'manuals',
            'software' => 'software_files',
        ];
        if (!isset($tableMap[$type])) return null;
        return $this->fetchOne("SELECT * FROM {$tableMap[$type]} WHERE id = ? AND status = 'active'", [$id]);
    }

    // === SEARCH (FULLTEXT) ===

    public function search($term, $limit = null, $offset = 0) {
        $limit = $limit === null ? SEARCH_PAGE_SIZE : max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        $like = "%$term%";
        $normalizedTerm = strtolower(trim((string)$term));
        $typeCounts = ['config' => 0, 'firmware' => 0, 'manual' => 0, 'software' => 0];
        $typeKeywordMatch = [
            'config' => in_array($normalizedTerm, ['config', 'configs', 'configuration', 'configurations', 'cfg'], true) ? 1 : 0,
            'firmware' => in_array($normalizedTerm, ['firmware', 'fw'], true) ? 1 : 0,
            'manual' => in_array($normalizedTerm, ['manual', 'manuals', 'guide', 'guides', 'installation'], true) ? 1 : 0,
            'software' => in_array($normalizedTerm, ['software', 'configurator', 'tool', 'tools', 'utility', 'utilities'], true) ? 1 : 0,
        ];
        if (DB_DRIVER !== 'pgsql') {
            $configWhere = "(MATCH(c.name, c.description) AGAINST(? IN BOOLEAN MODE) OR c.name LIKE ? OR c.description LIKE ? OR ? = 1)";
            $firmwareWhere = "(MATCH(f.name, f.changelog) AGAINST(? IN BOOLEAN MODE) OR f.name LIKE ? OR f.changelog LIKE ? OR ? = 1)";
            $softwareWhere = "(MATCH(s.name, s.description) AGAINST(? IN BOOLEAN MODE) OR s.name LIKE ? OR s.description LIKE ? OR ? = 1)";
            $manualWhere = "(MATCH(m.name, m.description) AGAINST(? IN BOOLEAN MODE) OR m.name LIKE ? OR m.description LIKE ? OR ? = 1)";
            $params = [$term, $like, $like];
        } else {
            $configWhere = "(to_tsvector('simple', coalesce(c.name, '') || ' ' || coalesce(c.description, '')) @@ plainto_tsquery('simple', ?) OR c.name ILIKE ? OR coalesce(c.description, '') ILIKE ? OR ? = 1)";
            $firmwareWhere = "(to_tsvector('simple', coalesce(f.name, '') || ' ' || coalesce(f.changelog, '')) @@ plainto_tsquery('simple', ?) OR f.name ILIKE ? OR coalesce(f.changelog, '') ILIKE ? OR ? = 1)";
            $softwareWhere = "(to_tsvector('simple', coalesce(s.name, '') || ' ' || coalesce(s.description, '')) @@ plainto_tsquery('simple', ?) OR s.name ILIKE ? OR coalesce(s.description, '') ILIKE ? OR ? = 1)";
            $manualWhere = "(to_tsvector('simple', coalesce(m.name, '') || ' ' || coalesce(m.description, '')) @@ plainto_tsquery('simple', ?) OR m.name ILIKE ? OR coalesce(m.description, '') ILIKE ? OR ? = 1)";
            $params = [$term, $like, $like];
        }

        $typeCounts['config'] = (int)($this->fetchOne("SELECT COUNT(*) as c FROM config_files c WHERE $configWhere AND c.status = 'active'", array_merge($params, [$typeKeywordMatch['config']]))['c'] ?? 0);
        $typeCounts['firmware'] = (int)($this->fetchOne("SELECT COUNT(*) as c FROM firmware_files f WHERE $firmwareWhere AND f.status = 'active'", array_merge($params, [$typeKeywordMatch['firmware']]))['c'] ?? 0);
        $typeCounts['software'] = (int)($this->fetchOne("SELECT COUNT(*) as c FROM software_files s WHERE $softwareWhere AND s.status = 'active'", array_merge($params, [$typeKeywordMatch['software']]))['c'] ?? 0);
        $typeCounts['manual'] = (int)($this->fetchOne("SELECT COUNT(*) as c FROM manuals m WHERE $manualWhere AND m.status = 'active'", array_merge($params, [$typeKeywordMatch['manual']]))['c'] ?? 0);

        $unionSql = "
            SELECT * FROM (
                SELECT
                    c.id, c.name, c.version, c.file_path, c.file_size, c.download_count, c.status, c.system_type,
                    c.created_at, NULL AS updated_at, c.description, NULL AS changelog,
                    b.name AS brand_name, b.slug AS brand_slug, dm.name AS model_name, 'config' AS type
                FROM config_files c
                JOIN device_models dm ON c.device_model_id = dm.id
                JOIN brands b ON dm.brand_id = b.id
                WHERE $configWhere AND c.status = 'active'

                UNION ALL

                SELECT
                    f.id, f.name, f.version, f.file_path, f.file_size, f.download_count, f.status, f.system_type,
                    f.created_at, NULL AS updated_at, NULL AS description, f.changelog,
                    b.name AS brand_name, b.slug AS brand_slug, dm.name AS model_name, 'firmware' AS type
                FROM firmware_files f
                LEFT JOIN device_models dm ON f.device_model_id = dm.id
                JOIN brands b ON f.brand_id = b.id
                WHERE $firmwareWhere AND f.status = 'active'

                UNION ALL

                SELECT
                    s.id, s.name, s.version, s.file_path, s.file_size, s.download_count, s.status, s.system_type,
                    s.created_at, NULL AS updated_at, s.description, NULL AS changelog,
                    b.name AS brand_name, b.slug AS brand_slug, dm.name AS model_name, 'software' AS type
                FROM software_files s
                LEFT JOIN device_models dm ON s.device_model_id = dm.id
                JOIN brands b ON s.brand_id = b.id
                WHERE $softwareWhere AND s.status = 'active'

                UNION ALL

                SELECT
                    m.id, m.name, NULL AS version, m.file_path, m.file_size, m.download_count, m.status, m.system_type,
                    m.created_at, NULL AS updated_at, m.description, NULL AS changelog,
                    b.name AS brand_name, b.slug AS brand_slug, dm.name AS model_name, 'manual' AS type
                FROM manuals m
                LEFT JOIN device_models dm ON m.device_model_id = dm.id
                JOIN brands b ON m.brand_id = b.id
                WHERE $manualWhere AND m.status = 'active'
            ) search_results
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?";

        $rows = $this->fetchAll(
            $unionSql,
            array_merge(
                $params, [$typeKeywordMatch['config']],
                $params, [$typeKeywordMatch['firmware']],
                $params, [$typeKeywordMatch['software']],
                $params, [$typeKeywordMatch['manual']],
                [$limit, $offset]
            )
        );

        foreach ($rows as &$row) {
            $row['file_type'] = $row['type'] ?? '';
        }

        return [
            'items' => $rows,
            'total' => array_sum($typeCounts),
            'type_counts' => $typeCounts,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    // === ACTIVITY LOG ===

    public function logActivity($action, $entityType, $entityId, $entityName, $details = null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->insert("INSERT INTO activity_log (action, entity_type, entity_id, entity_name, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)",
            [$action, $entityType, $entityId, $entityName, $details, $ip]);
    }
}
