<?php
namespace Translink\Services;

class QueueService
{
    private string $driver;

    public function __construct()
    {
        $this->driver = defined('QUEUE_DRIVER') ? QUEUE_DRIVER : 'sync';
    }

    public function push(string $queue, array $payload): bool
    {
        return match ($this->driver) {
            'redis' => $this->pushRedis($queue, $payload),
            'rabbitmq' => $this->pushRabbitMQ($queue, $payload),
            default => $this->pushSync($queue, $payload),
        };
    }

    public function later(string $queue, array $payload, int $delaySeconds = 0): bool
    {
        if ($delaySeconds > 0) {
            $payload['_delay_until'] = time() + $delaySeconds;
        }
        return $this->push($queue, $payload);
    }

    private function pushSync(string $queue, array $payload): bool
    {
        $handler = $this->resolveHandler($queue);
        if ($handler) {
            try {
                $handler($payload);
                return true;
            } catch (\Throwable $e) {
                error_log("Queue[{$queue}] sync handler failed: " . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    private function pushRedis(string $queue, array $payload): bool
    {
        try {
            $redis = $this->redisConnect();
            if (!$redis) {
                return $this->pushSync($queue, $payload);
            }
            $redis->lPush("queue:{$queue}", serialize($payload));
            return true;
        } catch (\Throwable $e) {
            error_log("Queue[{$queue}] Redis push failed: " . $e->getMessage());
            return $this->pushSync($queue, $payload);
        }
    }

    private function pushRabbitMQ(string $queue, array $payload): bool
    {
        try {
            if (!extension_loaded('amqp')) {
                return $this->pushSync($queue, $payload);
            }
            $conn = new \AMQPConnection([
                'host' => QUEUE_HOST,
                'port' => QUEUE_PORT,
                'login' => 'guest',
                'password' => 'guest',
            ]);
            $conn->connect();
            $channel = new \AMQPChannel($conn);
            $exchange = new \AMQPExchange($channel);
            $exchange->setName('');
            $exchange->publish(serialize($payload), $queue);
            $conn->disconnect();
            return true;
        } catch (\Throwable $e) {
            error_log("Queue[{$queue}] RabbitMQ push failed: " . $e->getMessage());
            return $this->pushSync($queue, $payload);
        }
    }

    private function resolveHandler(string $queue): ?callable
    {
        return match ($queue) {
            'audit_log' => [$this, 'processAuditLog'],
            'increment_download' => [$this, 'processIncrementDownload'],
            default => null,
        };
    }

    private function redisConnect(): ?\Redis
    {
        if (!class_exists('\Redis')) return null;
        static $redis = null;
        if ($redis === null) {
            try {
                $redis = new \Redis();
                $redis->connect(CACHE_HOST, defined('QUEUE_PORT') ? QUEUE_PORT : 6379, 1);
            } catch (\Throwable) {
                $redis = false;
            }
        }
        return $redis ?: null;
    }

    // ========== Queue Handlers ==========

    public function processAuditLog(array $payload): void
    {
        $db = \Translink\Database::getInstance();
        $db->execute(
            "INSERT INTO activity_log (action, entity_type, entity_id, entity_name, details, ip_address, user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $payload['action'] ?? '',
                $payload['entity_type'] ?? '',
                $payload['entity_id'] ?? null,
                $payload['entity_name'] ?? null,
                $payload['details'] ?? null,
                $payload['ip_address'] ?? null,
                $payload['user_id'] ?? null,
            ]
        );
    }

    public function processIncrementDownload(array $payload): void
    {
        $db = \Translink\Database::getInstance();
        $type = $payload['type'] ?? '';
        $id = (int)($payload['file_id'] ?? 0);

        $tableMap = [
            'config' => 'config_files',
            'firmware' => 'firmware_files',
            'manual' => 'manuals',
            'software' => 'software_files',
        ];

        $table = $tableMap[$type] ?? null;
        if ($table && $id) {
            $db->execute(
                "UPDATE $table SET download_count = download_count + 1 WHERE id = ?",
                [$id]
            );
        }
    }
}
