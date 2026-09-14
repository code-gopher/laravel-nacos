<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos;

use CodeGopher\LaravelNacos\Console\Commands\NacosPullCommand;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * 注册 Nacos 配置服务和唯一的主动拉取命令。
 */
class NacosServiceProvider extends ServiceProvider
{
    /**
     * 注册配置、HTTP 客户端、Redis 存储和启动协调器。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/nacos.php', 'nacos');

        $this->app->singleton(NacosClient::class, function ($app): NacosClient {
            return new NacosClient((array) $app['config']->get('nacos', []));
        });
        $this->app->singleton(NacosRedisStore::class, function ($app): NacosRedisStore {
            return new NacosRedisStore((array) $app['config']->get('nacos', []));
        });
        $this->app->singleton(Bootstrapper::class);
        $this->app->singleton(NacosPullCommand::class);
    }

    /**
     * 只在控制台环境注册 nacos:pull。
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/nacos.php' => config_path('nacos.php'),
        ], 'nacos-config');

        // Application 已经启动后，嵌套 Artisan 调用不会重新经过 bootstrap/app.php，
        // 因此必须在命令真正执行前拦截动态配置快照命令。
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (($this->app['config']->get('nacos.enabled', false)) !== true) {
                return;
            }

            if (in_array($event->command, ['config:cache', 'optimize'], true)) {
                throw new \RuntimeException('Nacos dynamic configuration forbids config:cache and optimize.');
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([NacosPullCommand::class]);
        }
    }
}
