<?php
namespace Translink\Services;

use Translink\Database;
use Translink\Repositories\FileRepository;

class FileService
{
    private FileRepository $files;
    private StorageService $storage;

    public function __construct()
    {
        $this->files = new FileRepository();
        $this->storage = new StorageService();
    }

    public function list(string $type, array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $sort = $filters['sort'] ?? 'created_at';
        $order = $filters['order'] ?? 'DESC';
        unset($filters['sort'], $filters['order']);

        return $this->files->findByType($type, $filters, $perPage, $offset, $sort, $order);
    }

    public function get(string $type, int $id): ?array
    {
        return $this->files->findById($type, $id);
    }

    public function download(string $type, int $id): ?array
    {
        $file = $this->files->findById($type, $id);
        if (!$file) return null;

        $this->files->incrementDownload($type, $id);

        $localPath = $this->storage->getLocalPath($file['file_path']);
        if (file_exists($localPath)) {
            return [
                'path' => $localPath,
                'name' => $file['name'],
                'size' => $file['file_size'],
                'local' => true,
            ];
        }

        // Generate presigned URL for S3 storage
        $presignedUrl = $this->storage->getPresignedUrl($file['file_path'], 3600);

        return [
            'url' => $presignedUrl ?? $this->storage->getUrl($file['file_path']),
            'name' => $file['name'],
            'local' => false,
        ];
    }

    public function delete(string $type, int $id): bool
    {
        $file = $this->files->findById($type, $id);
        if (!$file) return false;

        $this->storage->delete($file['file_path']);
        return $this->files->delete($type, $id);
    }

    public function search(string $term, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        return $this->files->search($term, $perPage, $offset);
    }

    public function stats(): array
    {
        return $this->files->stats();
    }

    public function topDownloads(int $limit): array
    {
        return $this->files->getTopDownloads($limit);
    }
}
