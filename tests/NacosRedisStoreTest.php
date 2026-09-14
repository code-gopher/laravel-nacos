<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests;

use CodeGopher\LaravelNacos\NacosRedisStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Support/MemoryRedis.php';

final class NacosRedisStoreTest extends TestCase
{
    private const NAMESPACE = 'testing';
    private const GROUP = 'WMS';
    private const DATA_ID = 'application.env';

    public function testPutAndGetRoundTripTheFixedArrayPayload(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $store = $this->store($redis);
        $payload = $this->payload();

        $this->assertTrue($store->put(self::NAMESPACE, self::GROUP, self::DATA_ID, $payload));
        $this->assertSame($payload, $store->get(self::NAMESPACE, self::GROUP, self::DATA_ID));
    }

    public function testFreshAndStalePayloadsAreReturnedButExpiredPayloadsAreMisses(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $store = $this->store($redis, ['ttl' => 60, 'stale_ttl' => 30]);

        foreach ([0, 80] as $age) {
            $payload = $this->payload();
            $payload['fetched_at'] = time() - $age;
            $store->put(self::NAMESPACE, self::GROUP, self::DATA_ID, $payload);
            $this->assertSame($payload, $store->get(self::NAMESPACE, self::GROUP, self::DATA_ID));
        }

        $expired = $this->payload();
        $expired['fetched_at'] = time() - 91;
        $store->put(self::NAMESPACE, self::GROUP, self::DATA_ID, $expired);
        $this->assertNull($store->get(self::NAMESPACE, self::GROUP, self::DATA_ID));
    }

    public function testMissingPayloadReturnsNull(): void
    {
        $this->assertNull($this->store(new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis())
            ->get(self::NAMESPACE, self::GROUP, self::DATA_ID));
    }

    public function testRedisErrorsBecomeSafeRuntimeExceptions(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $redis->faults['get'] = 'throw';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('get');
        $this->store($redis)->get(self::NAMESPACE, self::GROUP, self::DATA_ID);
    }

    public function testLockUsesNxAndExAndOnlyOneOwnerCanAcquireIt(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $first = $this->store($redis, ['lock_ttl' => 7]);
        $second = $this->store($redis, ['lock_ttl' => 7]);

        $token = $first->acquireLock(self::NAMESPACE, self::GROUP, self::DATA_ID);
        $this->assertIsString($token);
        $this->assertNull($second->acquireLock(self::NAMESPACE, self::GROUP, self::DATA_ID));
        $this->assertSame($token, $redis->values[$first->lockKey(self::NAMESPACE, self::GROUP, self::DATA_ID)]);
    }

    public function testWrongTokenDoesNotReleaseTheLock(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $store = $this->store($redis);
        $token = $store->acquireLock(self::NAMESPACE, self::GROUP, self::DATA_ID);

        $this->assertFalse($store->releaseLock(self::NAMESPACE, self::GROUP, self::DATA_ID, 'wrong-token'));
        $this->assertSame($token, $redis->values[$store->lockKey(self::NAMESPACE, self::GROUP, self::DATA_ID)]);
    }

    public function testAStaleTokenCannotOverwriteAReplacementValue(): void
    {
        $redis = new \CodeGopher\LaravelNacos\Tests\Support\MemoryRedis();
        $store = $this->store($redis);
        $oldToken = $store->acquireLock(self::NAMESPACE, self::GROUP, self::DATA_ID);
        unset($redis->values[$store->lockKey(self::NAMESPACE, self::GROUP, self::DATA_ID)]);
        $newToken = $store->acquireLock(self::NAMESPACE, self::GROUP, self::DATA_ID);

        $newPayload = $this->payload();
        $newPayload['content'] = 'APP_NAME=new';
        $this->assertTrue($store->put(self::NAMESPACE, self::GROUP, self::DATA_ID, $newPayload, $newToken));
        $oldPayload = $this->payload();
        $oldPayload['content'] = 'APP_NAME=old';
        $this->assertFalse($store->put(self::NAMESPACE, self::GROUP, self::DATA_ID, $oldPayload, $oldToken));
        $this->assertSame($newPayload, $store->get(self::NAMESPACE, self::GROUP, self::DATA_ID));
    }

    /** @return array{content: string, md5: string, last_modified: null, fetched_at: int} */
    private function payload(): array
    {
        return ['content' => 'APP_NAME=demo', 'md5' => 'md5', 'last_modified' => null, 'fetched_at' => time()];
    }

    private function store(object $redis, array $overrides = []): NacosRedisStore
    {
        return new NacosRedisStore(array_merge(['ttl' => 60, 'stale_ttl' => 30, 'lock_ttl' => 10], $overrides), $redis);
    }
}
