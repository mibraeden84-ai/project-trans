<?php
namespace Translink\Models;

class FileRecord
{
    private static array $typeMap = [
        'config' => 'config_files',
        'firmware' => 'firmware_files',
        'manual' => 'manuals',
        'software' => 'software_files',
    ];

    public static function tableName(string $type): ?string
    {
        return self::$typeMap[$type] ?? null;
    }

    public static function normalizeRow(string $type, array $row): array
    {
        $base = [
            'id' => (int)$row['id'],
            'type' => $type,
            'name' => $row['name'],
            'file_path' => $row['file_path'],
            'file_size' => (int)$row['file_size'],
            'version' => $row['version'] ?? null,
            'download_count' => (int)($row['download_count'] ?? 0),
            'status' => $row['status'] ?? 'active',
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'] ?? null,
        ];

        if (isset($row['brand_name'])) $base['brand_name'] = $row['brand_name'];
        if (isset($row['model_name'])) $base['model_name'] = $row['model_name'];
        if (isset($row['brand_slug'])) $base['brand_slug'] = $row['brand_slug'];

        if ($type === 'config' && isset($row['description'])) {
            $base['description'] = $row['description'];
        }
        if ($type === 'firmware' && isset($row['changelog'])) {
            $base['changelog'] = $row['changelog'];
        }

        return $base;
    }
}
