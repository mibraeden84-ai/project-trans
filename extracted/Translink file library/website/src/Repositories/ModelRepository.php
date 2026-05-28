<?php
namespace Translink\Repositories;

use Translink\Database;
use Translink\Models\DeviceModel;

class ModelRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findByBrand(int $brandId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT dm.*, COUNT(cf.id) as config_count
             FROM device_models dm
             LEFT JOIN config_files cf ON cf.device_model_id = dm.id AND cf.status = 'active'
             WHERE dm.brand_id = ?
             GROUP BY dm.id
             ORDER BY dm.name",
            [$brandId]
        );
        return array_map([DeviceModel::class, 'fromRow'], $rows);
    }

    public function findByBrandAndSlug(int $brandId, string $slug): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT dm.*, COUNT(cf.id) as config_count
             FROM device_models dm
             LEFT JOIN config_files cf ON cf.device_model_id = dm.id AND cf.status = 'active'
             WHERE dm.brand_id = ? AND dm.slug = ?
             GROUP BY dm.id",
            [$brandId, $slug]
        );
        return $row ? DeviceModel::fromRow($row) : null;
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM device_models WHERE id = ?", [$id]);
        return $row ? DeviceModel::fromRow($row) : null;
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert(
            "INSERT INTO device_models (brand_id, name, slug, description, image_url, system_type)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id",
            [$data['brand_id'], $data['name'], $data['slug'], $data['description'] ?? null, $data['image_url'] ?? null, $data['system_type'] ?? null]
        );
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['brand_id', 'name', 'slug', 'description', 'image_url', 'system_type'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        $this->db->execute(
            "UPDATE device_models SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM device_models WHERE id = ?", [$id]);
    }
}
