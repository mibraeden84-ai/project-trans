<?php
namespace Translink\Services;

class StorageService
{
    private string $localPath;
    private ?string $s3Bucket = null;
    private ?string $s3Region = null;
    private ?string $s3Key = null;
    private ?string $s3Secret = null;
    private ?string $s3Endpoint = null;
    private string $driver;

    private const STORAGE_DRIVERS = ['local', 's3', 'dual'];
    private const UPLOAD_SUBDIRS = [
        'config' => 'configs',
        'firmware' => 'firmware',
        'manual' => 'manuals',
        'software' => 'software',
    ];

    public function __construct()
    {
        $this->localPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : (__DIR__ . '/../../uploads');
        $this->driver = defined('STORAGE_DRIVER') ? STORAGE_DRIVER : 'local';

        if (in_array($this->driver, ['s3', 'dual'])) {
            $this->s3Bucket = defined('S3_BUCKET') ? S3_BUCKET : (getenv('S3_BUCKET') ?: null);
            $this->s3Region = defined('S3_REGION') ? S3_REGION : (getenv('S3_REGION') ?: 'us-east-1');
            $this->s3Key = defined('S3_KEY') ? S3_KEY : (getenv('S3_KEY') ?: null);
            $this->s3Secret = defined('S3_SECRET') ? S3_SECRET : (getenv('S3_SECRET') ?: null);
            $this->s3Endpoint = defined('S3_ENDPOINT') ? S3_ENDPOINT : (getenv('S3_ENDPOINT') ?: null);
        }
    }

    public function store(string $type, string $sourcePath, string $destinationName): string
    {
        $subdir = self::UPLOAD_SUBDIRS[$type] ?? 'configs';
        $relativePath = "{$subdir}/{$destinationName}";

        if ($this->driver === 'local' || $this->driver === 'dual') {
            $this->storeLocal($relativePath, $sourcePath);
        }

        if (in_array($this->driver, ['s3', 'dual']) && $this->s3Bucket) {
            $this->storeS3($relativePath, $sourcePath);
        }

        return "uploads/{$relativePath}";
    }

    public function delete(string $path): bool
    {
        $localDeleted = true;
        $s3Deleted = true;

        if (in_array($this->driver, ['local', 'dual'])) {
            $localDeleted = $this->deleteLocal($path);
        }

        if (in_array($this->driver, ['s3', 'dual']) && $this->s3Bucket) {
            $s3Deleted = $this->deleteS3($path);
        }

        return $localDeleted && $s3Deleted;
    }

    public function exists(string $path): bool
    {
        if ($this->driver === 's3' && $this->s3Bucket) {
            return $this->existsS3($path);
        }
        return file_exists($this->localPath . '/' . ltrim($path, '/'));
    }

    public function getUrl(string $path): string
    {
        if ($this->driver === 's3' && $this->s3Bucket) {
            if ($this->s3Endpoint) {
                return "https://{$this->s3Endpoint}/" . ltrim($path, '/');
            }
            return "https://{$this->s3Bucket}.s3.{$this->s3Region}.amazonaws.com/" . ltrim($path, '/');
        }

        $baseUrl = defined('SITE_URL') ? SITE_URL : 'http://localhost:8000';
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function getPresignedUrl(string $path, int $ttlSeconds = 3600): ?string
    {
        if (!in_array($this->driver, ['s3', 'dual']) || !$this->s3Bucket) {
            return $this->getUrl($path);
        }

        $key = ltrim($path, '/');
        $expires = time() + $ttlSeconds;

        return $this->s3PresignedUrl('GET', $key, $expires);
    }

    public function getLocalPath(string $path): string
    {
        return $this->localPath . '/' . ltrim($path, '/');
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    private function storeLocal(string $relative, string $sourcePath): void
    {
        $destDir = dirname($this->localPath . '/' . $relative);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        copy($sourcePath, $this->localPath . '/' . $relative);
    }

    private function deleteLocal(string $path): bool
    {
        $fullPath = $this->localPath . '/' . ltrim($path, '/');
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return true;
    }

    // ========== S3 Signature V4 Implementation ==========

    private function storeS3(string $relative, string $sourcePath): void
    {
        $this->s3PutObjectV4($relative, file_get_contents($sourcePath));
    }

    private function deleteS3(string $path): bool
    {
        return $this->s3DeleteObjectV4(ltrim($path, '/'));
    }

    private function existsS3(string $path): bool
    {
        return $this->s3HeadObjectV4(ltrim($path, '/')) !== null;
    }

    private function s3PutObjectV4(string $key, string $body): bool
    {
        if (!$this->s3Bucket || !$this->s3Key || !$this->s3Secret) return false;

        $contentType = mime_content_type($body) ?: 'application/octet-stream';
        $headers = [
            'x-amz-acl' => 'private',
            'Content-Type' => $contentType,
        ];

        return $this->s3RequestV4('PUT', $key, $headers, $body) !== null;
    }

    private function s3DeleteObjectV4(string $key): ?array
    {
        return $this->s3RequestV4('DELETE', $key);
    }

    private function s3HeadObjectV4(string $key): ?array
    {
        return $this->s3RequestV4('HEAD', $key);
    }

    private function s3PresignedUrl(string $method, string $key, int $expires): string
    {
        $region = $this->s3Region ?: 'us-east-1';
        $service = 's3';
        $algo = 'AWS4-HMAC-SHA256';
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');
        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";

        $signedHeaders = 'host';

        $host = $this->s3Endpoint ?? "{$this->s3Bucket}.s3.{$region}.amazonaws.com";
        $uri = '/' . ltrim($key, '/');

        $canonicalQuery = http_build_query([
            'X-Amz-Algorithm' => $algo,
            'X-Amz-Credential' => urlencode("{$this->s3Key}/{$credentialScope}"),
            'X-Amz-Date' => $dateTime,
            'X-Amz-Expires' => $expires - time(),
            'X-Amz-SignedHeaders' => $signedHeaders,
        ], '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = "{$method}\n{$uri}\n{$canonicalQuery}\nhost:{$host}\n\n{$signedHeaders}\nUNSIGNED-PAYLOAD";

        $stringToSign = "{$algo}\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->s4SigningKey($this->s3Secret, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $scheme = $this->s3Endpoint ? 'https' : 'https';
        return "{$scheme}://{$host}{$uri}?{$canonicalQuery}&X-Amz-Signature={$signature}";
    }

    private function s3RequestV4(string $method, string $key, array $extraHeaders = [], ?string $body = null): ?array
    {
        if (!$this->s3Bucket || !$this->s3Key || !$this->s3Secret) return null;

        $region = $this->s3Region ?: 'us-east-1';
        $service = 's3';
        $algo = 'AWS4-HMAC-SHA256';
        $date = gmdate('Ymd');
        $dateTime = gmdate('Ymd\THis\Z');

        $host = $this->s3Endpoint ?? "{$this->s3Bucket}.s3.{$region}.amazonaws.com";
        $uri = '/' . ltrim($key, '/');
        $payload = $body ?? '';
        $payloadHash = hash('sha256', $payload);

        // Build canonical headers
        $canonicalHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $dateTime,
        ];
        foreach ($extraHeaders as $k => $v) {
            $canonicalHeaders[strtolower($k)] = $v;
        }
        ksort($canonicalHeaders);

        $headerStrings = [];
        $signedHeaderList = [];
        foreach ($canonicalHeaders as $k => $v) {
            $headerStrings[] = "{$k}:{$v}";
            $signedHeaderList[] = $k;
        }
        $signedHeaders = implode(';', $signedHeaderList);

        $canonicalRequest = "{$method}\n{$uri}\n\n"
            . implode("\n", $headerStrings) . "\n\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = "{$algo}\n{$dateTime}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->s4SigningKey($this->s3Secret, $date, $region, $service);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "{$algo} Credential={$this->s3Key}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // Build final headers for request
        $requestHeaders = [
            "Host: {$host}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$dateTime}",
            "Authorization: {$authorization}",
        ];
        foreach ($extraHeaders as $k => $v) {
            $requestHeaders[] = "{$k}: {$v}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaders),
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);

        $url = ($this->s3Endpoint ? "https://{$this->s3Endpoint}" : "https://{$host}") . $uri;
        $result = @file_get_contents($url, false, $context);

        if ($result === false && $http_response_header) {
            $statusCode = 0;
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#', implode("\n", $http_response_header), $m)) {
                $statusCode = (int)$m[1];
            }
            if ($method === 'HEAD' && $statusCode === 200) return ['status' => 200];
            return null;
        }

        return ['status' => 200, 'body' => $result];
    }

    private function s4SigningKey(string $secret, string $date, string $region, string $service): string
    {
        $kDate = hash_hmac('sha256', $date, "AWS4{$secret}", true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
