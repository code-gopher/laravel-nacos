<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos;

/**
 * 使用 Redis 保存 Nacos 配置数组，并提供跨进程刷新锁。
 */
final class NacosRedisStore
{
    private const RELEASE_LOCK_SCRIPT = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    private const PUT_LOCKED_SCRIPT = "if redis.call('get', KEYS[1]) == ARGV[1] then redis.call('set', KEYS[2], ARGV[2], 'EX', ARGV[3]); return 1 else return 0 end";

    /** @var object */
    private $redis;

    /** @var int */
    private $ttl;

    /** @var int */
    private $staleTtl;

    /** @var int */
    private $lockTtl;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, ?object $redis = null)
    {
        if (isset($config['cache']) && is_array($config['cache'])) {
            $config = $config['cache'];
        }

        $this->ttl = $this->positiveInteger($config['ttl'] ?? 300, 'ttl');
        $this->staleTtl = $this->nonNegativeInteger($config['stale_ttl'] ?? 0, 'stale_ttl');
        $this->lockTtl = $this->positiveInteger($config['lock_ttl'] ?? 10, 'lock_ttl');

        $timeout = $this->positiveTimeout($config['timeout'] ?? 1.0);
        $connectTimeout = $this->positiveTimeout($config['connect_timeout'] ?? 1.0);
        $this->redis = $redis ?? new \Redis();
        if ($redis === null) {
            $this->io('connect', [
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 6379),
                $connectTimeout,
                null,
                0,
                $timeout,
            ]);
        }

        // phpredis 的连接超时不能限制认证、选库和读写等待，因此需单独设置读超时。
        $this->io('setOption', [\Redis::OPT_READ_TIMEOUT, $timeout]);

        $password = $config['password'] ?? null;
        if ($password !== null && (string) $password !== '') {
            $this->io('auth', [(string) $password]);
        }

        $database = (int) ($config['database'] ?? $config['db'] ?? 0);
        if ($database !== 0) {
            $this->io('select', [$database]);
        }
    }

    public function key(string $namespace, string $group, string $dataId): string
    {
        return sprintf('wms:nacos:config:%s:%s:%s', $namespace, $group, $dataId);
    }

    public function lockKey(string $namespace, string $group, string $dataId): string
    {
        return sprintf('wms:nacos:lock:%s:%s:%s', $namespace, $group, $dataId);
    }

    public function get(string $namespace, string $group, string $dataId): ?array
    {
        $payload = $this->io('get', [$this->key($namespace, $group, $dataId)], true);
        if ($payload === false || $payload === null || $payload === '') {
            return null;
        }

        $value = json_decode((string) $payload, true);
        if (!is_array($value)) {
            return null;
        }

        $fetchedAt = (int) ($value['fetched_at'] ?? 0);
        if ($fetchedAt <= 0 || time() - $fetchedAt > $this->ttl + $this->staleTtl) {
            return null;
        }

        return $value;
    }

    public function put(string $namespace, string $group, string $dataId, array $value, ?string $token = null): bool
    {
        $payload = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new \RuntimeException('put failed.');
        }

        if ($token !== null) {
            return (int) $this->io('eval', [
                self::PUT_LOCKED_SCRIPT,
                [$this->lockKey($namespace, $group, $dataId), $this->key($namespace, $group, $dataId), $token, $payload, $this->ttl + $this->staleTtl],
                2,
            ]) === 1;
        }

        return (bool) $this->io('set', [
            $this->key($namespace, $group, $dataId),
            $payload,
            ['EX' => $this->ttl + $this->staleTtl],
        ]);
    }

    public function delete(string $namespace, string $group, string $dataId): bool
    {
        return (int) $this->io('del', [$this->key($namespace, $group, $dataId)]) > 0;
    }

    public function acquireLock(string $namespace, string $group, string $dataId): ?string
    {
        $token = bin2hex(random_bytes(16));
        $acquired = $this->io('set', [
            $this->lockKey($namespace, $group, $dataId),
            $token,
            ['NX', 'EX' => $this->lockTtl],
        ], true);

        return $acquired ? $token : null;
    }

    public function releaseLock(string $namespace, string $group, string $dataId, string $token): bool
    {
        return (int) $this->io('eval', [
            self::RELEASE_LOCK_SCRIPT,
            [$this->lockKey($namespace, $group, $dataId), $token],
            1,
        ]) === 1;
    }

    /**
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    private function io(string $operation, array $arguments, bool $allowFalse = false)
    {
        try {
            $this->redis->clearLastError();
            $result = $this->redis->{$operation}(...$arguments);
            if ($this->redis->getLastError() || !$this->redis->isConnected() || ($result === false && !$allowFalse)) {
                throw new \RuntimeException();
            }

            return $result;
        } catch (\Throwable $exception) {
            throw new \RuntimeException($operation.' failed.');
        }
    }

    /** @param mixed $value */
    private function positiveTimeout($value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
            throw new \InvalidArgumentException('Nacos Redis timeouts must be finite and greater than zero.');
        }

        return (float) $value;
    }

    /** @param mixed $value */
    private function positiveInteger($value, string $name): int
    {
        $integer = (int) $value;
        if ($integer < 1) {
            throw new \InvalidArgumentException(sprintf('%s must be greater than zero.', $name));
        }

        return $integer;
    }

    /** @param mixed $value */
    private function nonNegativeInteger($value, string $name): int
    {
        $integer = (int) $value;
        if ($integer < 0) {
            throw new \InvalidArgumentException(sprintf('%s must not be negative.', $name));
        }

        return $integer;
    }
}
