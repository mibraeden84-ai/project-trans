<?php
namespace Translink\Repositories;

use Translink\Database;

class ActivityRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findAll(int $limit = 50, int $offset = 0, ?string $action = null): array
    {
        $where = '';
        $params = [];
        if ($action) {
            $where = 'WHERE action = ?';
            $params[] = $action;
        }

        $rows = $this->db->fetchAll(
            "SELECT a.*, u.username as user_name
             FROM activity_log a
             LEFT JOIN users u ON a.user_id = u.id
             $where
             ORDER BY a.created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return $rows;
    }

    public function count(?string $action = null): int
    {
        if ($action) {
            return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM activity_log WHERE action = ?", [$action]);
        }
        return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM activity_log");
    }

    public function stats(int $days = 30): array
    {
        $driver = DB_DRIVER;
        $dateFilter = $driver === 'pgsql'
            ? "created_at >= CURRENT_TIMESTAMP - (? * INTERVAL '1 day')"
            : "created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";

        return $this->db->fetchAll(
            "SELECT action, entity_type, COUNT(*) as count
             FROM activity_log WHERE $dateFilter
             GROUP BY action, entity_type ORDER BY count DESC",
            [$days]
        );
    }

    public function cleanup(int $keepDays = 90): int
    {
        $driver = DB_DRIVER;
        $dateFilter = $driver === 'pgsql'
            ? "created_at < CURRENT_TIMESTAMP - (? * INTERVAL '1 day')"
            : "created_at < DATE_SUB(NOW(), INTERVAL ? DAY)";

        $this->db->execute("DELETE FROM activity_log WHERE $dateFilter", [$keepDays]);
        return 0;
    }
}
