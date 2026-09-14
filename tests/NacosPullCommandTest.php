<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Console\Application as Artisan;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use CodeGopher\LaravelNacos\Bootstrapper;
use CodeGopher\LaravelNacos\NacosClient;
use CodeGopher\LaravelNacos\NacosRedisStore;
use CodeGopher\LaravelNacos\NacosServiceProvider;
use CodeGopher\LaravelNacos\Tests\Support\MemoryRedis;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/Support/MemoryRedis.php';

/**
 * 使用真实 Laravel 容器和命令，只替换 HTTP、Redis 网络边界，不加载宿主 Ignition。
 *
 * @runTestsInSeparateProcesses
 */
final class NacosPullCommandTest extends TestCase
{
    private $app;
    private $artisan;
    private $store;

    protected function setUp(): void
    {
        parent::setUp();
        // 不创建环境文件：已启动命令必须使用容器配置，不能重新读取另一份 .env。
        $this->app = new Application(sys_get_temp_dir().'/nacos-command-'.bin2hex(random_bytes(8)));
        $this->app->instance('config', new Repository(['nacos' => [
            'enabled' => true,
            'server_addr' => 'https://nacos.invalid',
            'namespace' => 'container-space',
            'group' => 'CONTAINER_GROUP',
            'data_id' => 'container.env',
            'cache' => ['ttl' => 300, 'stale_ttl' => 60, 'lock_ttl' => 10],
        ]]));
        $this->app->register(NacosServiceProvider::class);
        $this->app->boot();
        $this->store = new NacosRedisStore($this->app['config']->get('nacos'), new MemoryRedis());
        $store = $this->store;
        $this->app->bind(NacosRedisStore::class, static function () use ($store) { return $store; });
        $this->app->instance(NacosClient::class, $this->client('APP_NAME=container'));
        $this->artisan = new Artisan($this->app, $this->app['events'], 'test');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['nacos.bootstrap.factories']);
        Artisan::forgetBootstrappers();
        Container::setInstance(null);
        parent::tearDown();
    }

    public function testPullCommandUsesReplacedContainerServicesAndConfiguration(): void
    {
        $this->assertSame(0, $this->artisan->call('nacos:pull'));
        $output = $this->artisan->output();
        $this->assertSame('APP_NAME=container', $this->store->get('container-space', 'CONTAINER_GROUP', 'container.env')['content']);
        $this->assertStringContainsString('dataId=container.env', $output);
        $this->assertStringContainsString('version='.md5('APP_NAME=container'), $output);
        $this->assertStringNotContainsString('APP_NAME=', $output);
    }

    public function testProviderPublishesThePackageConfigurationFile(): void
    {
        $paths = NacosServiceProvider::pathsToPublish(NacosServiceProvider::class, 'nacos-config');

        $this->assertCount(1, $paths);
        $this->assertSame($this->app->configPath('nacos.php'), array_values($paths)[0]);
        $this->assertFileExists(array_keys($paths)[0]);
    }

    public function testDirectPullAlsoUsesTheActiveLaravelContainer(): void
    {
        $payload = Bootstrapper::pull($this->app->basePath());

        $this->assertSame('APP_NAME=container', $payload['content']);
        $this->assertSame($payload, $this->store->get('container-space', 'CONTAINER_GROUP', 'container.env'));
    }

    public function testGlobalFactoriesTakePriorityOverContainerBindings(): void
    {
        $factoryStore = new NacosRedisStore($this->app['config']->get('nacos'), new MemoryRedis());
        $factoryClient = $this->client('APP_NAME=factory');
        $GLOBALS['nacos.bootstrap.factories'] = [
            'store' => static function () use ($factoryStore) { return $factoryStore; },
            'client' => static function () use ($factoryClient) { return $factoryClient; },
        ];

        $this->assertSame(0, $this->artisan->call('nacos:pull'));
        $this->assertSame('APP_NAME=factory', $factoryStore->get('container-space', 'CONTAINER_GROUP', 'container.env')['content']);
        $this->assertNull($this->store->get('container-space', 'CONTAINER_GROUP', 'container.env'));
    }

    public function testDisabledContainerConfigurationDoesNotResolveNetworkServices(): void
    {
        $this->app['config']->set('nacos.enabled', false);
        $resolutions = 0;
        foreach ([NacosClient::class, NacosRedisStore::class] as $service) {
            $this->app->bind($service, static function () use (&$resolutions) {
                $resolutions++;
                throw new \RuntimeException('Unexpected service resolution.');
            });
        }

        $this->assertSame(1, $this->artisan->call('nacos:pull'));
        $this->assertSame(0, $resolutions);
        $this->assertSame('Unable to pull Nacos configuration.', trim($this->artisan->output()));
    }

    public function testLockContentionDoesNotResolveTheBoundClient(): void
    {
        $token = $this->store->acquireLock('container-space', 'CONTAINER_GROUP', 'container.env');
        $resolutions = 0;
        $this->app->bind(NacosClient::class, static function () use (&$resolutions) {
            $resolutions++;
            throw new \RuntimeException('Unexpected HTTP request.');
        });

        $this->assertSame(1, $this->artisan->call('nacos:pull'));
        $this->assertSame(0, $resolutions);
        $this->assertTrue($this->store->releaseLock('container-space', 'CONTAINER_GROUP', 'container.env', $token));
    }

    public function testDynamicModeRejectsConfigCacheWhenCalledByCommandClassBeforeWriting(): void
    {
        NacosConfigCacheProbeCommand::$writes = 0;
        $this->artisan->add(new NacosConfigCacheProbeCommand());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nacos dynamic configuration forbids config:cache and optimize.');
        try {
            $this->artisan->call(NacosConfigCacheProbeCommand::class);
        } finally {
            $this->assertSame(0, NacosConfigCacheProbeCommand::$writes);
        }
    }

    public function testDynamicModeRejectsNestedOptimizeBeforeWriting(): void
    {
        NacosOptimizeProbeCommand::$writes = 0;
        $this->artisan->add(new NacosOptimizeProbeCommand());
        $this->artisan->add(new NacosNestedOptimizeProbeCommand());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nacos dynamic configuration forbids config:cache and optimize.');
        try {
            $this->artisan->call('nacos:test-nested-optimize');
        } finally {
            $this->assertSame(0, NacosOptimizeProbeCommand::$writes);
        }
    }

    public function testDisabledModeDoesNotRejectConfigurationCommand(): void
    {
        $this->app['config']->set('nacos.enabled', false);
        NacosConfigCacheProbeCommand::$writes = 0;
        $this->artisan->add(new NacosConfigCacheProbeCommand());

        $this->assertSame(0, $this->artisan->call(NacosConfigCacheProbeCommand::class));
        $this->assertSame(1, NacosConfigCacheProbeCommand::$writes);
    }

    private function client(string $content): NacosClient
    {
        return new NacosClient($this->app['config']->get('nacos'), new Client([
            'handler' => new MockHandler([new Response(200, [], $content)]),
        ]));
    }
}

final class NacosConfigCacheProbeCommand extends Command
{
    public static $writes = 0;

    protected $signature = 'config:cache';

    public function handle(): int
    {
        self::$writes++;

        return self::SUCCESS;
    }
}

final class NacosOptimizeProbeCommand extends Command
{
    public static $writes = 0;

    protected $signature = 'optimize';

    public function handle(): int
    {
        self::$writes++;

        return self::SUCCESS;
    }
}

final class NacosNestedOptimizeProbeCommand extends Command
{
    protected $signature = 'nacos:test-nested-optimize';

    public function handle(): int
    {
        return $this->getApplication()->call('optimize');
    }
}
