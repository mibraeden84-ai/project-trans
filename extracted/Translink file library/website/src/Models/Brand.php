<?php
namespace Translink\Models;

class Brand
{
    public static function fromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'description' => $row['description'],
            'icon' => $row['icon'],
            'color' => $row['color'],
            'created_at' => $row['created_at'],
            'model_count' => isset($row['model_count']) ? (int)$row['model_count'] : null,
        ];
    }
}
