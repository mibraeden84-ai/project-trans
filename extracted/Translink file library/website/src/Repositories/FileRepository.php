<?php
namespace Translink\Repositories;

use Translink\Database;
use Translink\Models\FileRecord;

class FileRepository
{
    private Database $db;
    private string $driver;

    private const FILE_TABLES = [
        'config' => 'config_files',
        'firmware' => 'firmware_files',
        'manual' => 'manuals',
        'software' => 'software_files',
    ];

    private const FILE_SEARCH_COLUMNS = [
        'config' => ['description', 'name'],
        'firmware' => ['changelog', 'name'],
        'manual' => ['description', 'name'],
        'software' => ['description', 'name'],
    ];

    private const CACHE_TTL_QUERY = 60;
    private const CACHE_TTL_STATS = 300;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->driver = DB_DRIVER;
    }

    public function findByType(string $type, array $filters = [], int $limit = 20, int $offset = 0, string $sort = 'created_at', string $order = 'DESC'): array
    {
        $table = self::FILE_TABLES[$type] ?? null;
        if (!$table) return ['items' => [], 'total' => 0];

        $where = ["f.status = 'active'"];
        $params = [];

        if (!empty($filters['brand_id'])) {
            $where[] = 'f.brand_id = ?';
            $params[] = (int)$filters['brand_id'];
        }
        if (!empty($filters['model_id'])) {
            $where[] = 'f.device_model_id = ?';
            $params[] = (int)$filters['model_id'];
        }
        if (!empty($filters['system_type'])) {
            $where[] = 'f.system_type = ?';
            $params[] = $filters['system_type'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $filters['search'] . '%';
            $cols = self::FILE_SEARCH_COLUMNS[$type] ?? ['name'];
            $conditions = [];
            foreach ($cols as $col) {
                if ($this->driver === 'pgsql') {
                    $conditions[] = "to_tsvector('simple', coalesce(f.{$col}, '')) @@ plainto_tsquery('simple', ?)";
                } else {
                    $conditions[] = "f.{$col} LIKE ?";
                }
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $conditions) . ')';
        }

        $whereClause = implode(' AND ', $where);
        $allowedSorts = ['name', 'version', 'download_count', 'created_at', 'file_size', 'updated_at'];
        $orderClause = in_array($sort, $allowedSorts)
            ? "$sort $order" : "created_at DESC";

        // config_files has no brand_id; join via device_models
        if ($type === 'config') {
            $joins = "LEFT JOIN device_models dm ON f.device_model_id = dm.id
                      LEFT JOIN brands b ON dm.brand_id = b.id";
        } else {
            $joins = "LEFT JOIN brands b ON f.brand_id = b.id
                      LEFT JOIN device_models dm ON f.device_model_id = dm.id";
        }

        $select = "f.id, f.name, f.version, f.file_path, f.file_size, f.download_count, f.status, f.system_type, f.created_at, f.updated_at,
                   b.name as brand_name, b.slug as brand_slug, dm.name as model_name";

        // Check cache for count queries with no search
        $cacheKey = null;
        if (empty($filters['search'])) {
            $cacheKey = "count:{$type}:" . md5($whereClause . implode(',', $params));
            $count = $this->db->cacheGet($cacheKey);
            if ($count === null) {
                $count = (int)$this->db->fetchColumn(
                    "SELECT COUNT(*) FROM $table f $joins WHERE $whereClause",
                    $params
                );
                $this->db->cacheSet($cacheKey, $count, self::CACHE_TTL_QUERY);
            }
        } else {
            $count = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM $table f $joins WHERE $whereClause",
                $params
            );
        }

        $rows = $this->db->fetchAll(
            "SELECT $select FROM $table f $joins WHERE $whereClause ORDER BY $orderClause LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return [
            'items' => array_map(fn($r) => FileRecord::normalizeRow($type, $r), $rows),
            'total' => $count,
        ];
    }

    public function findById(string $type, int $id): ?array
    {
        $table = self::FILE_TABLES[$type] ?? null;
        if (!$table) return null;

        $cacheKey = "file:{$type}:{$id}";
        $cached = $this->db->cacheGet($cacheKey);
        if ($cached !== null) return $cached;

        if ($type === 'config') {
            $brandJoins = "LEFT JOIN device_models dm ON f.device_model_id = dm.id
                           LEFT JOIN brands b ON dm.brand_id = b.id";
        } else {
            $brandJoins = "LEFT JOIN brands b ON f.brand_id = b.id
                           LEFT JOIN device_models dm ON f.device_model_id = dm.id";
        }

        $row = $this->db->fetchOne(
            "SELECT f.*, b.name as brand_name, b.slug as brand_slug, dm.name as model_name
             FROM $table f
             $brandJoins
             WHERE f.id = ? AND f.status = 'active'",
            [$id]
        );

        $result = $row ? FileRecord::normalizeRow($type, $row) : null;
        if ($result) {
            $this->db->cacheSet($cacheKey, $result, self::CACHE_TTL_QUERY);
        }
        return $result;
    }

    public function incrementDownload(string $type, int $id): void
    {
        $table = self::FILE_TABLES[$type] ?? null;
        if (!$table) return;
        $this->db->execute(
            "UPDATE $table SET download_count = download_count + 1 WHERE id = ?",
            [$id]
        );
        // Bust cache
        $this->db->cacheDelete("file:{$type}:{$id}");
    }

    public function delete(string $type, int $id): bool
    {
        $table = self::FILE_TABLES[$type] ?? null;
        if (!$table) return false;
        $this->db->execute(
            "UPDATE $table SET status = 'deleted' WHERE id = ?",
            [$id]
        );
        $this->db->cacheDelete("file:{$type}:{$id}");
        return true;
    }

    public function getTopDownloads(int $limit = 20): array
    {
        $cacheKey = "top_downloads:{$limit}";
        $cached = $this->db->cacheGet($cacheKey);
        if ($cached !== null) return $cached;

        $sql = "SELECT * FROM (
            SELECT 'config' as type, id, name, download_count, created_at FROM config_files WHERE status = 'active'
            UNION ALL SELECT 'firmware', id, name, download_count, created_at FROM firmware_files WHERE status = 'active'
            UNION ALL SELECT 'manual', id, name, download_count, created_at FROM manuals WHERE status = 'active'
            UNION ALL SELECT 'software', id, name, download_count, created_at FROM software_files WHERE status = 'active'
        ) t ORDER BY download_count DESC LIMIT ?";

        $result = $this->db->fetchAll($sql, [$limit]);
        $this->db->cacheSet($cacheKey, $result, self::CACHE_TTL_QUERY);
        return $result;
    }

    public function search(string $term, int $limit = 20, int $offset = 0): array
    {
        $results = [];
        $like = "%$term%";
        $total = 0;

        foreach (self::FILE_TABLES as $type => $table) {
            if ($type === 'config') {
                $sBrandJoin = "LEFT JOIN device_models dm ON f.device_model_id = dm.id
                               LEFT JOIN brands b ON dm.brand_id = b.id";
            } else {
                $sBrandJoin = "LEFT JOIN brands b ON f.brand_id = b.id
                               LEFT JOIN device_models dm ON f.device_model_id = dm.id";
            }

            $textCols = ['name'];
            if ($type === 'firmware') {
                $textCols[] = 'changelog';
            } else {
                $textCols[] = 'description';
            }

            if ($this->driver === 'pgsql') {
                $tsvectorParts = [];
                foreach ($textCols as $c) {
                    $tsvectorParts[] = "coalesce(f.{$c}, '')";
                }
                $tsvectorExpr = implode(" || ' ' || ", $tsvectorParts);

                $rows = $this->db->fetchAll(
                    "SELECT f.*, b.name as brand_name, b.slug as brand_slug, dm.name as model_name, '$type' as file_type
                     FROM $table f
                     $sBrandJoin
                     WHERE (
                        to_tsvector('simple', $tsvectorExpr) @@ plainto_tsquery('simple', ?)
                        OR f.name ILIKE ?
                     ) AND f.status = 'active'
                     ORDER BY f.download_count DESC LIMIT ? OFFSET ?",
                    [$term, $like, $limit, $offset]
                );
            } else {
                $likeParts = [];
                $mySqlParams = [];
                foreach ($textCols as $c) {
                    $likeParts[] = "f.{$c} LIKE ?";
                    $mySqlParams[] = $like;
                }
                $rows = $this->db->fetchAll(
                    "SELECT f.*, b.name as brand_name, b.slug as brand_slug, dm.name as model_name, '$type' as file_type
                     FROM $table f
                     $sBrandJoin
                     WHERE (" . implode(' OR ', $likeParts) . ") AND f.status = 'active'
                     ORDER BY f.download_count DESC LIMIT ? OFFSET ?",
                    array_merge($mySqlParams, [$limit, $offset])
                );
            }

            // FIXED: Count matching rows, not ALL rows
            if ($this->driver === 'pgsql') {
                $tsvectorParts = [];
                foreach ($textCols as $c) {
                    $tsvectorParts[] = "coalesce(f.{$c}, '')";
                }
                $tsvectorExpr = implode(" || ' ' || ", $tsvectorParts);
                $count = (int)$this->db->fetchColumn(
                    "SELECT COUNT(*) FROM $table f WHERE (
                        to_tsvector('simple', $tsvectorExpr) @@ plainto_tsquery('simple', ?)
                        OR f.name ILIKE ?
                    ) AND f.status = 'active'",
                    [$term, $like]
                );
            } else {
                $likeParts = [];
                $mySqlParams = [];
                foreach ($textCols as $c) {
                    $likeParts[] = "f.{$c} LIKE ?";
                    $mySqlParams[] = $like;
                }
                $count = (int)$this->db->fetchColumn(
                    "SELECT COUNT(*) FROM $table f WHERE (" . implode(' OR ', $likeParts) . ") AND f.status = 'active'",
                    $mySqlParams
                );
            }

            foreach ($rows as &$r) {
                $results[] = FileRecord::normalizeRow($type, $r);
            }
            $total += $count;
        }

        return ['items' => $results, 'total' => $total];
    }

    public function stats(): array
    {
        // Cache stats for 5 minutes
        $cacheKey = 'stats:overview';
        $cached = $this->db->cacheGet($cacheKey);
        if ($cached !== null) return $cached;

        // Single query for all counts using UNION
        $driver = $this->driver;
        $statsSql = "SELECT
            (SELECT COUNT(*) FROM brands) as brands,
            (SELECT COUNT(*) FROM device_models) as models,
            (SELECT COUNT(*) FROM config_files WHERE status = 'active') as configs,
            (SELECT COUNT(*) FROM firmware_files WHERE status = 'active') as firmware,
            (SELECT COUNT(*) FROM manuals WHERE status = 'active') as manuals,
            (SELECT COUNT(*) FROM software_files WHERE status = 'active') as software";

        $stats = $this->db->fetchOne($statsSql);

        $sumSubquery = "SELECT COALESCE(SUM(download_count), 0) as c FROM (
            SELECT download_count FROM config_files WHERE status = 'active'
            UNION ALL SELECT download_count FROM firmware_files WHERE status = 'active'
            UNION ALL SELECT download_count FROM manuals WHERE status = 'active'
            UNION ALL SELECT download_count FROM software_files WHERE status = 'active'
        ) t";

        $result = [
            'brands' => (int)($stats['brands'] ?? 0),
            'models' => (int)($stats['models'] ?? 0),
            'configs' => (int)($stats['configs'] ?? 0),
            'firmware' => (int)($stats['firmware'] ?? 0),
            'manuals' => (int)($stats['manuals'] ?? 0),
            'software' => (int)($stats['software'] ?? 0),
            'total_downloads' => (int)$this->db->fetchColumn($sumSubquery),
        ];

        $this->db->cacheSet($cacheKey, $result, self::CACHE_TTL_STATS);
        return $result;
    }
}
