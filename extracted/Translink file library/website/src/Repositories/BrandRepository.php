<?php
namespace Translink\Repositories;

use Translink\Database;
use Translink\Models\Brand;

class BrandRepository
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function findAll(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT b.*, COUNT(dm.id) as model_count
             FROM brands b
             LEFT JOIN device_models dm ON dm.brand_id = b.id
             GROUP BY b.id
             ORDER BY b.name"
        );
        return array_map([Brand::class, 'fromRow'], $rows);
    }

    public function findBySlug(string $slug): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT b.*, COUNT(dm.id) as model_count
             FROM brands b
             LEFT JOIN device_models dm ON dm.brand_id = b.id
             WHERE b.slug = ?
             GROUP BY b.id",
            [$slug]
        );
        return $row ? Brand::fromRow($row) : null;
    }

    public function findById(int $id): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM brands WHERE id = ?", [$id]);
        return $row ? Brand::fromRow($row) : null;
    }

    public function create(array $data): int
    {
        return (int)$this->db->insert(
            "INSERT INTO brands (name, slug, description, icon, color)
             VALUES (?, ?, ?, ?, ?) RETURNING id",
            [$data['name'], $data['slug'], $data['description'] ?? null, $data['icon'] ?? 'GPS', $data['color'] ?? '#1a73e8']
        );
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];
        foreach (['name', 'slug', 'description', 'icon', 'color'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) return;
        $params[] = $id;
        $this->db->execute(
            "UPDATE brands SET " . implode(', ', $fields) . " WHERE id = ?",
            $params
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM brands WHERE id = ?", [$id]);
    }

    public function count(): int
    {
        return (int)$this->db->fetchColumn("SELECT COUNT(*) FROM brands");
    }
}
