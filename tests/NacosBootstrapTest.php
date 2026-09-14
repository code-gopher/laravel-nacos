<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests;

use CodeGopher\LaravelNacos\Bootstrapper;
use PHPUnit\Framework\TestCase;

/** @runTestsInSeparateProcesses */
final class NacosBootstrapTest extends TestCase
{
    private $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/nacos-bootstrap-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/bootstrap/cache', 0777, true);
        $_SERVER['argv'] = ['artisan'];
        foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $key) {
            if (strpos($key, 'NACOS_') === 0 || in_array($key, ['APP_ENV', 'APP_CONFIG_CACHE', 'APP_BASE_PATH', 'APP_NAME'], true)) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
        file_put_contents($this->directory.'/.env', "NACOS_ENABLED=true\nAPP_ENV=testing\nNACOS_REDIS_TTL=60\nNACOS_REDIS_STALE_TTL=60\n");
        putenv('APP_CONFIG_CACHE='.$this->directory.'/bootstrap/cache/config.php');
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->directory);
        unset($GLOBALS['nacos.bootstrap.factories']);
        foreach (['NACOS_ENABLED', 'NACOS_SERVER_ADDR', 'NACOS_NAMESPACE', 'NACOS_GROUP', 'NACOS_DATA_ID', 'APP_ENV', 'APP_CONFIG_CACHE', 'ARTISAN_COMMAND'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        parent::tearDown();
    }

    public function testFreshCacheIsInjectedWithoutRequestingNacos(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=fresh', time()));
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame('fresh', getenv('APP_NAME'));
        $this->assertSame(0, $client->fetches);
    }

    public function testMissAllowsOnlyLockHolderToFetchAndWriteRemotePayload(): void
    {
        $store = new BootstrapStoreFake(null, 'owner');
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame('remote', getenv('APP_NAME'));
        $this->assertSame(1, $client->fetches);
        $this->assertSame('APP_NAME=remote', $store->value['content'] ?? null);
        $this->assertSame('owner', $store->writtenToken);
        $this->assertSame(['get', 'lock', 'get', 'put', 'release'], $store->operations);
    }

    public function testInvalidRemoteDotenvIsNotWrittenToCache(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=last-valid', time() - 80), 'owner');
        $client = new BootstrapClientFake($this->payload("APP_NAME=partial\nDB_PASSWORD=unquoted value", time()));
        $this->configure($store, $client);

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame('APP_NAME=last-valid', $store->value['content']);
        $this->assertSame('last-valid', getenv('APP_NAME'));
        $this->assertSame(0, $store->puts);
    }

    public function testProductionDoubleFailureThrowsWithoutLeakingDetails(): void
    {
        $store = new BootstrapStoreFake(null, null, true);
        $client = new BootstrapClientFake(null, true);
        $this->configure($store, $client, 'production');

        try {
            Bootstrapper::bootstrap($this->basePath());
            $this->fail('Expected production bootstrap to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Nacos configuration', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-secret', $exception->getMessage());
        }
    }

    public function testNonProductionUsesAValidStalePayloadWhenRemoteIsUnavailable(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=stale', time() - 80), 'owner');
        $client = new BootstrapClientFake(null, true);
        $this->configure($store, $client, 'testing');

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame('stale', getenv('APP_NAME'));
    }

    public function testStalePayloadTriggersARefreshAndUsesTheRemoteValueWhenItSucceeds(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=stale', time() - 80), 'owner');
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client, 'testing');

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame(1, $client->fetches);
        $this->assertSame('remote', getenv('APP_NAME'));
        $this->assertSame('APP_NAME=remote', $store->value['content']);
    }

    public function testLockContentionUsesExistingStalePayloadWithoutFetchingOrWriting(): void
    {
        $stale = $this->payload('APP_NAME=stale', time() - 80);
        $store = new BootstrapStoreFake($stale, null);
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client, 'testing');

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame(0, $client->fetches);
        $this->assertSame(0, $store->puts);
        $this->assertSame('stale', getenv('APP_NAME'));
        $this->assertSame($stale, $store->value);
    }

    public function testPullForcesRemoteRefreshAndReturnsItsArrayPayload(): void
    {
        $remote = $this->payload('APP_NAME=remote', time());
        $store = new BootstrapStoreFake($this->payload('APP_NAME=fresh', time()), 'owner');
        $client = new BootstrapClientFake($remote);
        $this->configure($store, $client);

        $this->assertSame($remote, Bootstrapper::pull($this->basePath()));
        $this->assertSame(1, $client->fetches);
        $this->assertSame(1, $store->puts);
    }

    public function testPullFailsWhenTheRefreshLockCannotBeAcquired(): void
    {
        $store = new BootstrapStoreFake(null);
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        $this->expectException(\RuntimeException::class);
        Bootstrapper::pull($this->basePath());
    }

    public function testPullStillConfirmsARemoteWriteWhenSecondReadFindsFreshCache(): void
    {
        $store = new BootstrapStoreFake(null, 'owner');
        $store->latest = $this->payload('APP_NAME=other-worker', time());
        $remote = $this->payload('APP_NAME=remote', time());
        $client = new BootstrapClientFake($remote);
        $this->configure($store, $client);

        $this->assertSame($remote, Bootstrapper::pull($this->basePath()));
        $this->assertSame($remote, $store->value);
        $this->assertSame('owner', $store->writtenToken);
        $this->assertSame(['get', 'lock', 'get', 'put', 'release'], $store->operations);
        $this->assertFalse(getenv('APP_NAME'));
    }

    public function testForcePullFetchesNacosAfterLockWhenSecondReadFindsAnotherFreshPayload(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=before-lock', time() - 80), 'owner');
        $store->latest = $this->payload('APP_NAME=other-worker', time());
        $remote = $this->payload('APP_NAME=remote-authoritative', time());
        $client = new BootstrapClientFake($remote);
        $this->configure($store, $client);

        $result = Bootstrapper::pull($this->basePath());

        $this->assertSame($remote, $result);
        $this->assertSame(1, $client->fetches);
        $this->assertSame(1, $store->puts);
        $this->assertSame($remote, $store->value);
        $this->assertSame(['get', 'lock', 'get', 'put', 'release'], $store->operations);
    }

    public function testPullDoesNotReportSuccessWithoutRedisEvenIfNacosIsAvailable(): void
    {
        $store = new BootstrapStoreFake(null, null, true);
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        $this->expectException(\RuntimeException::class);
        Bootstrapper::pull($this->basePath());
    }

    public function testPullRejectsInvalidDotenvWithoutReturningOrOverwritingStale(): void
    {
        $stale = $this->payload('APP_NAME=stale', time() - 80);
        $store = new BootstrapStoreFake($stale, 'owner');
        $this->configure($store, new BootstrapClientFake($this->payload("APP_NAME=partial\nPASSWORD=invalid value", time())));

        try {
            Bootstrapper::pull($this->basePath());
            $this->fail('Expected invalid dotenv to fail pull.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($stale, $store->value);
            $this->assertSame(['get', 'lock', 'get', 'release'], $store->operations);
            $this->assertFalse(getenv('APP_NAME'));
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testPullFailsWhenTheCacheWriteIsNotConfirmed(): void
    {
        $store = new BootstrapStoreFake(null, 'owner', false, false);
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        $this->expectException(\RuntimeException::class);
        Bootstrapper::pull($this->basePath());
    }

    public function testPullFailsWhenTheRefreshLockReleaseIsNotConfirmed(): void
    {
        $store = new BootstrapStoreFake(null, 'owner', false, true, false);
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $this->configure($store, $client);

        $this->expectException(\RuntimeException::class);
        Bootstrapper::pull($this->basePath());
    }

    public function testRemoteCannotOverrideBootstrapKeysOrCreateASnapshot(): void
    {
        $store = new BootstrapStoreFake(null, 'owner');
        $client = new BootstrapClientFake($this->payload("NACOS_SERVER_ADDR=evil\nAPP_CONFIG_CACHE=evil.php\nAPP_BASE_PATH=evil\nAPP_NAME=safe", time()));
        $this->configure($store, $client);

        Bootstrapper::bootstrap($this->basePath());

        $this->assertSame('http://nacos.test:8848', getenv('NACOS_SERVER_ADDR'));
        $this->assertSame($this->basePath().'/bootstrap/cache/config.php', getenv('APP_CONFIG_CACHE'));
        $this->assertFalse(getenv('APP_BASE_PATH'));
        $this->assertFileDoesNotExist($this->basePath().'/bootstrap/cache/.env.nacos');
    }

    public function testConfigClearCanRunWithoutNacosOrRedis(): void
    {
        $path = $this->basePath().'/bootstrap/cache/config.php';
        file_put_contents($path, '<?php return [];');
        $_SERVER['argv'] = ['artisan', '--env', 'testing', 'config:clear'];
        $GLOBALS['nacos.bootstrap.factories'] = [
            'store' => static function () { throw new \LogicException('Redis must not connect.'); },
            'client' => static function () { throw new \LogicException('Nacos must not connect.'); },
        ];

        $this->assertSame(0, Bootstrapper::bootstrap($this->basePath()));
        $this->assertFileDoesNotExist($path);
    }

    /** @dataProvider guardedCommands */
    public function testConfigCacheCommandsAreRejected(string $command): void
    {
        $_SERVER['argv'] = ['artisan', '--env=testing', $command];
        $this->expectException(\RuntimeException::class);
        Bootstrapper::bootstrap($this->basePath());
    }

    public function guardedCommands(): array
    {
        return [['config:cache'], ['optimize']];
    }

    public function testExistingConfigurationCacheIsRejectedBeforeConnecting(): void
    {
        file_put_contents($this->basePath().'/bootstrap/cache/config.php', '<?php throw new \\LogicException();');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nacos dynamic configuration cannot run with Laravel config cache.');
        Bootstrapper::bootstrap($this->basePath());
    }

    public function testFreshPayloadFromSecondReadAvoidsAnotherRemoteFetch(): void
    {
        $store = new BootstrapStoreFake(null, 'owner');
        $store->latest = $this->payload('APP_NAME=other-worker', time());
        $client = new BootstrapClientFake(null, true);
        $this->configure($store, $client);
        Bootstrapper::bootstrap($this->basePath());
        $this->assertSame('other-worker', getenv('APP_NAME'));
        $this->assertSame(0, $client->fetches);
        $this->assertSame(['get', 'lock', 'get', 'release'], $store->operations);
    }

    public function testExpiredTokenCannotWriteSharedCacheDuringPull(): void
    {
        $store = new BootstrapStoreFake(null, 'owner');
        $client = new BootstrapClientFake($this->payload('APP_NAME=remote', time()));
        $client->beforeFetch = static function () use ($store) { $store->token = 'new-owner'; };
        $this->configure($store, $client);
        try {
            Bootstrapper::pull($this->basePath());
            $this->fail('Expected token loss to fail pull.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($store->value);
            $this->assertSame('owner', $store->writtenToken);
        }
    }

    public function testProductionDoesNotSilentlyFallBackToStale(): void
    {
        $this->configure(new BootstrapStoreFake($this->payload('APP_NAME=stale', time() - 80), 'owner'), new BootstrapClientFake(null, true), 'production');
        $this->expectException(\RuntimeException::class);
        Bootstrapper::bootstrap($this->basePath());
    }

    public function testInvalidOrExpiredCacheCannotBeInjected(): void
    {
        $store = new BootstrapStoreFake($this->payload('APP_NAME=expired', time() - 150), null);
        $this->configure($store, new BootstrapClientFake(null, true));
        Bootstrapper::bootstrap($this->basePath());
        $this->assertFalse(getenv('APP_NAME'));
    }

    public function testRedisFailureCanUseValidatedRemoteWithoutWriting(): void
    {
        $store = new BootstrapStoreFake(null, null, true);
        $this->configure($store, new BootstrapClientFake($this->payload('APP_NAME=remote', time())), 'production');
        Bootstrapper::bootstrap($this->basePath());
        $this->assertSame('remote', getenv('APP_NAME'));
        $this->assertSame(0, $store->puts);
    }

    public function testApplicationKeepsCliSelectedLocalFileAfterRemoteAppEnvChanges(): void
    {
        file_put_contents($this->basePath().'/.env.chosen', "NACOS_ENABLED=(true)\nNACOS_GROUP=selected\nLOCAL_ONLY=chosen\n");
        file_put_contents($this->basePath().'/.env.chosen.remote', 'LOCAL_ONLY=wrong');
        $_SERVER['argv'] = ['artisan', '--env=chosen'];
        $store = new BootstrapStoreFake($this->payload('APP_ENV=remote', time()));
        $GLOBALS['nacos.bootstrap.factories'] = ['store' => static function (array $config) use ($store) {
            TestCase::assertTrue($config['enabled']);
            TestCase::assertSame('selected', $config['group']);
            return $store;
        }];
        Bootstrapper::bootstrap($this->basePath());
        $this->assertFalse(getenv('LOCAL_ONLY'));
        $app = new \Illuminate\Foundation\Application($this->basePath());
        Bootstrapper::configureApplication($app);
        $app->make(\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class)->bootstrap($app);
        $this->assertSame('.env.chosen', $app->environmentFile());
        $this->assertSame('chosen', getenv('LOCAL_ONLY'));
        $this->assertSame('remote', getenv('APP_ENV'));
        $this->assertSame('selected', $app->make('nacos.bootstrap.config')['group']);
    }

    public function testApplicationUsesTheSameWindowsAbsoluteConfigCachePathAsBootstrap(): void
    {
        $path = 'Z:/nacos-synthetic/bootstrap/cache/config.php';
        putenv('APP_CONFIG_CACHE='.$path);
        $this->configure(new BootstrapStoreFake($this->payload('APP_NAME=fresh', time())), new BootstrapClientFake(null, true));
        Bootstrapper::bootstrap($this->basePath());
        $app = new \Illuminate\Foundation\Application($this->basePath());

        Bootstrapper::configureApplication($app);

        $this->assertSame($path, $app->getCachedConfigPath());
    }

    private function configure(BootstrapStoreFake $store, BootstrapClientFake $client, string $environment = 'testing'): void
    {
        foreach (['NACOS_ENABLED' => 'true', 'NACOS_SERVER_ADDR' => 'http://nacos.test:8848', 'NACOS_NAMESPACE' => 'testing', 'NACOS_GROUP' => 'WMS', 'NACOS_DATA_ID' => 'application.env', 'APP_ENV' => $environment] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $GLOBALS['nacos.bootstrap.factories'] = [
            'store' => static function () use ($store) { return $store; },
            'client' => static function () use ($client) { return $client; },
        ];
    }

    private function payload(string $content, int $fetchedAt): array
    {
        return ['content' => $content, 'md5' => md5($content), 'last_modified' => null, 'fetched_at' => $fetchedAt];
    }

    private function basePath(): string
    {
        return $this->directory;
    }
}

final class BootstrapStoreFake
{
    public $value;
    public $token;
    public $puts = 0;
    public $writtenToken;
    public $operations = [];
    public $latest;
    private $fails;
    private $putResult;
    private $releaseResult;

    public function __construct(?array $value, ?string $token = null, bool $fails = false, bool $putResult = true, bool $releaseResult = true)
    {
        $this->value = $value;
        $this->token = $token;
        $this->fails = $fails;
        $this->putResult = $putResult;
        $this->releaseResult = $releaseResult;
    }
    public function get(...$args) { $this->operations[] = 'get'; if ($this->fails) { throw new \RuntimeException('synthetic-secret'); } return count($this->operations) > 1 && $this->latest !== null ? $this->latest : $this->value; }
    public function acquireLock(...$args) { $this->operations[] = 'lock'; if ($this->fails) { throw new \RuntimeException('synthetic-secret'); } return $this->token; }
    public function put($namespace, $group, $dataId, array $value, ?string $token = null) { $this->operations[] = 'put'; $this->writtenToken = $token; $this->puts++; if (!$this->putResult || $token !== $this->token) { return false; } $this->value = $value; return true; }
    public function releaseLock(...$args) { $this->operations[] = 'release'; return $this->releaseResult && $args[3] === $this->token; }
}

final class BootstrapClientFake
{
    public $fetches = 0;
    public $beforeFetch;
    private $value;
    private $fails;
    public function __construct(?array $value, bool $fails = false) { $this->value = $value; $this->fails = $fails; }
    public function fetch(...$args) { $this->fetches++; if ($this->beforeFetch !== null) { ($this->beforeFetch)(); } if ($this->fails) { throw new \RuntimeException('synthetic-secret'); } return $this->value; }
}
