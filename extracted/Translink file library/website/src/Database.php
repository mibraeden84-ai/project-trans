<?php
namespace Translink;

use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $writeConn;
    private ?PDO $readConn = null;
    private array $config;
    private array $cache = [];
    private int $cacheHit = 0;
    private int $cacheMiss = 0;

    private function __construct()
    {
        $this->config = [
            'driver' => DB_DRIVER,
            'host' => DB_HOST,
            'port' => DB_PORT,
            'name' => DB_NAME,
            'user' => DB_USER,
            'pass' => DB_PASS,
            'persistent' => defined('DB_PERSISTENT') ? DB_PERSISTENT : false,
            'timeout' => defined('DB_CONNECT_TIMEOUT') ? DB_CONNECT_TIMEOUT : 5,
        ];
        $this->writeConn = $this->connect(DB_HOST);

        $readHost = defined('DB_READ_HOST') ? DB_READ_HOST : '';
        if ($readHost) {
            $readPort = defined('DB_READ_PORT') ? DB_READ_PORT : DB_PORT;
            $this->readConn = $this->connect($readHost, $readPort);
        }
    }

    public static function configureReadReplica(string $host, string $port = null): void
    {
        $inst = self::getInstance();
        $inst->readConn = $inst->connect($host, $port);
    }

    private function connect(string $host, ?string $port = null): PDO
    {
        $cfg = $this->config;
        $port = $port ?? $cfg['port'];
        $retries = defined('DB_RETRY_ATTEMPTS') ? DB_RETRY_ATTEMPTS : 1;
        $delayMs = defined('DB_RETRY_DELAY_MS') ? DB_RETRY_DELAY_MS : 100;
        $lastException = null;

        $dsn = $cfg['driver'] === 'pgsql'
            ? "pgsql:host={$host};port={$port};dbname={$cfg['name']};sslmode=prefer"
            : "mysql:host={$host};port={$port};dbname={$cfg['name']};charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        if ($cfg['persistent']) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        // Add connect timeout for MySQL
        if ($cfg['driver'] !== 'pgsql') {
            $options[PDO::ATTR_TIMEOUT] = $cfg['timeout'];
        }

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            try {
                return new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
            } catch (PDOException $e) {
                $lastException = $e;
                if ($attempt < $retries) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new \RuntimeException(
            "Database connection failed after {$retries} attempts: " . $lastException->getMessage(),
            (int)$lastException->getCode(),
            $lastException
        );
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function write(): PDO { return $this->writeConn; }

    public function read(): PDO
    {
        return $this->readConn ?? $this->writeConn;
    }

    public function isReadReplicaEnabled(): bool
    {
        return $this->readConn !== null;
    }

    public function query(string $sql, array $params = [], bool $write = false): \PDOStatement
    {
        $conn = $write ? $this->write() : $this->read();
        $stmt = $conn->prepare($sql);
        foreach (array_values($params) as $idx => $value) {
            $stmt->bindValue($idx + 1, $value, $this->pdoType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $sql, array $params = []): ?string
    {
        $stmt = $this->query($sql, $params, true);

        if (DB_DRIVER === 'pgsql') {
            if (preg_match('/\sRETURNING\s+/i', $sql)) {
                return $stmt->fetchColumn() ?: null;
            }
        }

        return $this->write()->lastInsertId() ?: null;
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        return $this->query($sql, $params, true);
    }

    public function transaction(callable $fn): mixed
    {
        $this->write()->beginTransaction();
        try {
            $result = $fn($this);
            $this->write()->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->write()->rollBack();
            throw $e;
        }
    }

    private function pdoType(mixed $value): int
    {
        if (is_int($value)) return PDO::PARAM_INT;
        if (is_bool($value)) return PDO::PARAM_BOOL;
        if ($value === null) return PDO::PARAM_NULL;
        return PDO::PARAM_STR;
    }

    public static function raw(string $expr): object
    {
        return new class($expr) {
            public function __construct(public string $value) {}
        };
    }

    // ========== CACHING LAYER ==========

    public function cacheGet(string $key): mixed
    {
        $fullKey = $this->cacheKey($key);

        if (CACHE_DRIVER === 'redis') {
            return $this->redisGet($fullKey);
        }

        // In-memory fallback
        if (isset($this->cache[$fullKey])) {
            $this->cacheHit++;
            $entry = $this->cache[$fullKey];
            if ($entry['expires'] === 0 || $entry['expires'] > microtime(true)) {
                return $entry['data'];
            }
            unset($this->cache[$fullKey]);
        }
        $this->cacheMiss++;
        return null;
    }

    public function cacheSet(string $key, mixed $data, int $ttl = null): void
    {
        $fullKey = $this->cacheKey($key);
        $ttl = $ttl ?? CACHE_TTL;

        if (CACHE_DRIVER === 'redis') {
            $this->redisSet($fullKey, $data, $ttl);
            return;
        }

        $this->cache[$fullKey] = [
            'data' => $data,
            'expires' => $ttl > 0 ? microtime(true) + $ttl : 0,
        ];
    }

    public function cacheDelete(string $key): void
    {
        $fullKey = $this->cacheKey($key);
        unset($this->cache[$fullKey]);

        if (CACHE_DRIVER === 'redis') {
            $this->redisDel($fullKey);
        }
    }

    public function cacheClear(): void
    {
        $this->cache = [];
    }

    public function cacheStats(): array
    {
        return [
            'driver' => CACHE_DRIVER,
            'hit' => $this->cacheHit,
            'miss' => $this->cacheMiss,
            'ratio' => $this->cacheHit + $this->cacheMiss > 0
                ? round($this->cacheHit / ($this->cacheHit + $this->cacheMiss) * 100, 1) . '%'
                : '0%',
            'entries' => count($this->cache),
        ];
    }

    private function cacheKey(string $key): string
    {
        return CACHE_PREFIX . $key;
    }

    private function redisGet(string $key): mixed
    {
        try {
            $redis = $this->redisConnect();
            if (!$redis) return null;
            $val = $redis->get($key);
            return $val !== false ? unserialize($val) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function redisSet(string $key, mixed $data, int $ttl): void
    {
        try {
            $redis = $this->redisConnect();
            if (!$redis) return;
            $redis->setex($key, max(1, $ttl), serialize($data));
        } catch (\Throwable) {}
    }

    private function redisDel(string $key): void
    {
        try {
            $redis = $this->redisConnect();
            if (!$redis) return;
            $redis->del($key);
        } catch (\Throwable) {}
    }

    private function redisConnect(): ?object
    {
        if (!class_exists('\Redis')) return null;
        static $redis = null;
        if ($redis === null) {
            try {
                $redis = new \Redis();
                $redis->connect(CACHE_HOST, CACHE_PORT, 1);
            } catch (\Throwable) {
                $redis = false;
            }
        }
        return $redis ?: null;
    }
}
