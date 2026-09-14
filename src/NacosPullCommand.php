<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos;

use Illuminate\Console\Command;
use Throwable;

/**
 * 主动从 Nacos 拉取配置并刷新 Redis 共享缓存。
 */
final class NacosPullCommand extends Command
{
    /** @var string */
    protected $signature = 'nacos:pull';

    /** @var string */
    protected $description = 'Fetch the current Nacos configuration and refresh its Redis cache.';

    /** @var Bootstrapper */
    private $bootstrapper;

    /**
     * 通过容器注入启动协调器，确保命令复用与 FPM、队列一致的刷新流程。
     */
    public function __construct(Bootstrapper $bootstrapper)
    {
        parent::__construct();

        $this->bootstrapper = $bootstrapper;
    }

    /**
     * 强制刷新共享缓存；任一环节失败都只输出固定提示，避免泄露配置内容或凭据。
     */
    public function handle(): int
    {
        $startedAt = microtime(true);

        try {
            $payload = $this->bootstrapper->pull($this->laravel->basePath());
        } catch (Throwable $exception) {
            $this->error('Unable to pull Nacos configuration.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'dataId=%s version=%s elapsed=%dms',
            (string) $this->laravel['config']->get('nacos.data_id', ''),
            (string) ($payload['md5'] ?? ''),
            (int) round((microtime(true) - $startedAt) * 1000)
        ));

        return self::SUCCESS;
    }
}
