<?php
namespace Translink\Models;

class DeviceModel
{
    public static function fromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'brand_id' => (int)$row['brand_id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'image_url' => $row['image_url'],
            'system_type' => $row['system_type'],
            'created_at' => $row['created_at'],
            'config_count' => isset($row['config_count']) ? (int)$row['config_count'] : null,
        ];
    }
}
