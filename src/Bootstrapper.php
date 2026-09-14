<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos;

use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/**
 * 在 Laravel Application 创建前协调 Nacos 动态配置。
 *
 * 启动参数只读本地白名单；业务正文完整校验后才注入内存，不生成配置快照。
 */
final class Bootstrapper
{
    private const STARTUP_KEYS = [
        'APP_ENV', 'APP_CONFIG_CACHE', 'NACOS_ENABLED', 'NACOS_SERVER_ADDR', 'NACOS_NAMESPACE',
        'NACOS_GROUP', 'NACOS_DATA_ID', 'NACOS_USERNAME', 'NACOS_PASSWORD', 'NACOS_ACCESS_TOKEN',
        'NACOS_TIMEOUT', 'NACOS_CONNECT_TIMEOUT', 'NACOS_FAILURE_STRATEGY', 'NACOS_REDIS_HOST',
        'NACOS_REDIS_PORT', 'NACOS_REDIS_PASSWORD', 'NACOS_REDIS_DB', 'NACOS_REDIS_TTL',
        'NACOS_REDIS_STALE_TTL', 'NACOS_REDIS_LOCK_TTL', 'NACOS_REDIS_TIMEOUT',
        'NACOS_REDIS_CONNECT_TIMEOUT',
    ];

    private const CONFIG_CACHE_ERROR = 'Nacos dynamic configuration cannot run with Laravel config cache.';
    private const GUARDED_COMMAND_ERROR = 'Nacos dynamic configuration forbids config:cache and optimize.';

    /** @var array<string, mixed>|null */
    private static $configuration;

    /** @var string */
    private static $selectedEnvironmentFile = '.env';

    /** @var float */
    private static $startedAt = 0.0;

    /** config:clear 在 Application 创建前退出，其他入口继续正常启动。 */
    public static function run(string $basePath): void
    {
        $status = self::bootstrap($basePath);
        if ($status !== null) {
            fwrite($status === 0 ? STDOUT : STDERR, $status === 0
                ? "Configuration cache cleared.\n" : "Unable to clear configuration cache.\n");
            exit($status);
        }
    }

    /** 固定本次启动的环境文件，避免远端 APP_ENV 让 Laravel 再次选取文件。 */
    public static function configureApplication(Application $app): void
    {
        if (self::$configuration === null) {
            return;
        }

        $app->loadEnvironmentFrom(self::$selectedEnvironmentFile);
        // Laravel 8 默认不识别 Windows 盘符，需与启动前的缓存路径检查保持一致。
        $cachePath = (string) getenv('APP_CONFIG_CACHE');
        if (preg_match('~^[A-Za-z]:[\\\\/]~', $cachePath) === 1) {
            $app->addAbsoluteCachePathPrefix(substr($cachePath, 0, 3));
        }
        $app->bind(LoadEnvironmentVariables::class, static function () {
            return new class extends LoadEnvironmentVariables {
                protected function checkForSpecificEnvironmentFile($app)
                {
                    // Bootstrapper 已固定文件，远端 APP_ENV 不能触发二次选择。
                }
            };
        });
        $app->instance('nacos.bootstrap.config', self::$configuration);
    }

    /** 返回 null 继续启动，返回整数表示恢复命令的退出状态。 */
    public static function bootstrap(string $basePath): ?int
    {
        self::$configuration = null;
        list($config, $nacosEnv) = self::loadConfiguration($basePath);
        if (($config['enabled'] ?? false) !== true) {
            return null;
        }
        self::$configuration = $config;

        $path = self::configurationCachePath($basePath, $nacosEnv);
        $factories = self::factories();
        $files = isset($factories['files']) ? $factories['files']() : new \Illuminate\Filesystem\Filesystem();
        $command = self::command();

        if ($command === 'config:clear') {
            // 恢复动作不能加载 PHP 配置缓存，更不能依赖 Redis 或 Nacos 在线。
            try {
                $files->delete($path);

                return $files->exists($path) ? 1 : 0;
            } catch (\Throwable $exception) {
                return 1;
            }
        }
        if (in_array($command, ['config:cache', 'optimize'], true)) {
            throw new \RuntimeException(self::GUARDED_COMMAND_ERROR);
        }
        if ($files->exists($path)) {
            throw new \RuntimeException(self::CONFIG_CACHE_ERROR);
        }

        $isProduction = strtolower((string) $nacosEnv('APP_ENV', 'production')) === 'production';
        try {
            self::inject(self::refresh($config, false, $isProduction));
        } catch (\Throwable $exception) {
            self::emit($config, 'bootstrap_failure');
            if ($isProduction) {
                // 生产环境禁止悄悄使用本地默认数据库、队列等业务配置。
                throw new \RuntimeException('Nacos configuration could not be loaded during production bootstrap.');
            }
        }

        return null;
    }

    /**
     * 强制拉取只刷新共享缓存，不改变已经启动的 Application 配置。
     * 写入和释放锁都必须确认成功，才能向调用方返回成功结果。
     *
     * @return array{content: string, md5: string, last_modified: int|null, fetched_at: int}
     */
    public static function pull(string $basePath): array
    {
        // 只有主动拉取才使用已启动的容器；Application 创建前的 bootstrap 不走这里。
        $app = Application::getInstance();
        if ($app instanceof Application && $app->bound('config')) {
            self::$startedAt = microtime(true);
            $config = (array) $app['config']->get('nacos', []);
            $isProduction = strtolower((string) $app['config']->get('app.env', 'production')) === 'production';
        } else {
            $app = null;
            list($config, $nacosEnv) = self::loadConfiguration($basePath);
            $isProduction = strtolower((string) $nacosEnv('APP_ENV', 'production')) === 'production';
        }
        if (($config['enabled'] ?? false) !== true) {
            throw new \RuntimeException('Nacos dynamic configuration is disabled.');
        }

        return self::refresh($config, true, $isProduction, $app);
    }

    /** @return array{0: array<string, mixed>, 1: callable} */
    private static function loadConfiguration(string $basePath): array
    {
        self::$startedAt = microtime(true);
        $nacosEnv = self::environmentReader($basePath);
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../config/nacos.php';

        return [$config, $nacosEnv];
    }

    private static function environmentReader(string $basePath): callable
    {
        // 机器环境优先，本地白名单仅写入数组仓库，不提前污染业务环境变量。
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(PutenvAdapter::class)
            ->addReader(ServerConstAdapter::class)
            ->addReader(EnvConstAdapter::class)
            ->addAdapter(ArrayAdapter::class)
            ->immutable()
            ->allowList(self::STARTUP_KEYS)
            ->make();

        $file = '.env';
        $candidates = [];
        $cliEnvironment = self::cliEnvironment();
        if ($cliEnvironment !== null && $cliEnvironment !== '') {
            $candidates[] = $file.'.'.$cliEnvironment;
        }
        $machineEnvironment = $repository->get('APP_ENV');
        if ($machineEnvironment !== null && $machineEnvironment !== '') {
            $candidates[] = $file.'.'.$machineEnvironment;
        }
        foreach ($candidates as $candidate) {
            if (is_file($basePath.'/'.$candidate)) {
                $file = $candidate;
                break;
            }
        }

        self::$selectedEnvironmentFile = $file;
        Dotenv::create($repository, $basePath, $file)->safeLoad();

        return static function (string $key, $default = null) use ($repository) {
            $value = $repository->get($key);
            if ($value === null) {
                return $default;
            }
            switch (strtolower($value)) {
                case 'true':
                case '(true)':
                    return true;
                case 'false':
                case '(false)':
                    return false;
                case 'null':
                case '(null)':
                    return null;
                case 'empty':
                case '(empty)':
                    return '';
            }
            if (strlen($value) > 1 && $value[0] === '"' && substr($value, -1) === '"') {
                return substr($value, 1, -1);
            }

            return $value;
        };
    }

    private static function cliEnvironment(): ?string
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            return null;
        }
        $arguments = array_slice($_SERVER['argv'] ?? [], 1);
        foreach ($arguments as $index => $argument) {
            if ($argument === '--env') {
                return isset($arguments[$index + 1]) ? (string) $arguments[$index + 1] : null;
            }
            if (strpos($argument, '--env=') === 0) {
                return substr($argument, 6);
            }
        }

        return null;
    }

    private static function command(): ?string
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            return null;
        }
        $arguments = array_slice($_SERVER['argv'] ?? [], 1);
        $skip = false;
        foreach ($arguments as $argument) {
            if ($skip) {
                $skip = false;
                continue;
            }
            if ($argument === '--env') {
                $skip = true;
                continue;
            }
            if ($argument !== '' && $argument[0] !== '-') {
                return $argument;
            }
        }

        return null;
    }

    private static function configurationCachePath(string $basePath, callable $nacosEnv): string
    {
        $configured = $nacosEnv('APP_CONFIG_CACHE');
        if ($configured !== null) {
            $configured = (string) $configured;
            $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $configured;
            putenv('APP_CONFIG_CACHE='.$configured);
        }
        $path = $configured === null ? 'bootstrap/cache/config.php' : $configured;

        return self::isAbsolutePath($path) ? $path : $basePath.'/'.$path;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return preg_match('~^(?:[A-Za-z]:[\\\\/]|[\\\\/])~', $path) === 1;
    }

    /**
     * fresh → 抢锁 → 锁后复查 → 拉取并校验 → 携带 token 写入 → 释放锁。
     * Redis 故障时普通启动可直接读取远端；锁竞争失败不等于 Redis 故障。
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function refresh(array $config, bool $force, bool $isProduction, ?Application $app = null): array
    {
        $id = [
            (string) ($config['namespace'] ?? ''),
            (string) ($config['group'] ?? 'DEFAULT_GROUP'),
            (string) ($config['data_id'] ?? ''),
        ];

        try {
            $store = self::makeStore($config, $app);
            $initial = self::readCache($store, $id, $config);
        } catch (\Throwable $exception) {
            self::emit($config, 'redis_bypass');
            if ($force) {
                throw new \RuntimeException('Nacos refresh cache is unavailable.');
            }

            return self::fetchRemote($config, $app);
        }

        if (!$force && $initial !== null && self::fresh($initial, $config)) {
            self::emit($config, 'fresh', $initial);

            return $initial;
        }

        try {
            $token = $store->acquireLock($id[0], $id[1], $id[2]);
        } catch (\Throwable $exception) {
            self::emit($config, 'redis_bypass', $initial);
            if ($force) {
                throw new \RuntimeException('Nacos refresh lock could not be acquired.');
            }
            try {
                return self::fetchRemote($config, $app);
            } catch (\Throwable $exception) {
                return self::fallback($initial, $config, $isProduction);
            }
        }

        if (!is_string($token) || $token === '') {
            self::emit($config, 'lock_contended', $initial);
            if ($force) {
                throw new \RuntimeException('Nacos refresh lock could not be acquired.');
            }

            return self::fallback($initial, $config, $isProduction);
        }

        try {
            try {
                $latest = self::readCache($store, $id, $config);
            } catch (\Throwable $exception) {
                self::emit($config, 'redis_bypass', $initial);
                if ($force) {
                    throw new \RuntimeException('Nacos refresh cache is unavailable.');
                }
                $latest = null;
            }

            // 普通启动可以复用其他进程刚刷新的值；pull 必须完成自己的确认写入。
            // force pull 即使复查到其他进程刚写入的 fresh，也必须继续请求 Nacos。
            if ($force === false && $latest !== null && self::fresh($latest, $config)) {
                self::emit($config, 'fresh', $latest);

                return $latest;
            }

            $stale = $latest ?? $initial;
            try {
                $remote = self::fetchRemote($config, $app);
            } catch (\Throwable $exception) {
                return self::fallback($force ? null : $stale, $config, $isProduction);
            }

            try {
                // token 由 Redis 原子校验，过期锁持有者不能覆盖其他进程的新版本。
                if ($store->put($id[0], $id[1], $id[2], $remote, $token) !== true) {
                    throw new \RuntimeException();
                }
            } catch (\Throwable $exception) {
                self::emit($config, 'cache_write_failed', $remote);
                if ($force) {
                    throw new \RuntimeException('Nacos cache write was not confirmed.');
                }
            }

            return $remote;
        } finally {
            try {
                if ($store->releaseLock($id[0], $id[1], $id[2], $token) !== true) {
                    throw new \RuntimeException();
                }
            } catch (\Throwable $exception) {
                self::emit($config, 'lock_release_failed');
                if ($force) {
                    throw new \RuntimeException('Nacos refresh lock was not released.');
                }
            }
        }
    }

    /** @return array<string, mixed>|null */
    private static function readCache($store, array $id, array $config): ?array
    {
        return self::validCachePayload($store->get($id[0], $id[1], $id[2]), $config);
    }

    private static function makeStore(array $config, ?Application $app = null)
    {
        $factories = self::factories();
        if (isset($factories['store'])) {
            return $factories['store']($config);
        }

        // 保持测试工厂优先；命令复用宿主绑定，启动前才直接创建服务。
        return $app !== null && $app->bound(NacosRedisStore::class)
            ? $app->make(NacosRedisStore::class) : new NacosRedisStore($config);
    }

    /** @return array<string, mixed> */
    private static function fetchRemote(array $config, ?Application $app = null): array
    {
        try {
            $factories = self::factories();
            $client = isset($factories['client']) ? $factories['client']($config)
                : ($app !== null && $app->bound(NacosClient::class)
                    ? $app->make(NacosClient::class) : new NacosClient($config));
            $value = $client->fetch(
                (string) ($config['data_id'] ?? ''),
                (string) ($config['group'] ?? 'DEFAULT_GROUP'),
                (string) ($config['namespace'] ?? '')
            );
        } catch (\Throwable $exception) {
            self::emit($config, 'remote_failure');
            throw new \RuntimeException('Nacos remote configuration is unavailable.');
        }

        if (!self::payloadShapeIsValid($value)) {
            self::emit($config, 'invalid_config');
            throw new \RuntimeException('Nacos configuration is invalid.');
        }
        try {
            self::parse((string) $value['content']);
        } catch (\Throwable $exception) {
            self::emit($config, 'invalid_config');
            throw new \RuntimeException('Nacos configuration is invalid.');
        }
        self::emit($config, 'remote', $value);

        return $value;
    }

    /** @return array<string, mixed>|null */
    private static function validCachePayload($value, array $config): ?array
    {
        if (!self::payloadShapeIsValid($value)) {
            return null;
        }
        try {
            self::parse((string) $value['content']);
        } catch (\Throwable $exception) {
            self::emit($config, 'invalid_config');

            return null;
        }
        $age = time() - (int) $value['fetched_at'];
        $retention = (int) ($config['cache']['ttl'] ?? 300)
            + (int) ($config['cache']['stale_ttl'] ?? 60);

        return $age >= 0 && $age <= $retention ? $value : null;
    }

    private static function payloadShapeIsValid($value): bool
    {
        if (!is_array($value)
            || !is_string($value['content'] ?? null)
            || !is_string($value['md5'] ?? null)
            || !array_key_exists('last_modified', $value)
            || !is_int($value['fetched_at'] ?? null)
            || $value['fetched_at'] <= 0) {
            return false;
        }

        return $value['last_modified'] === null || is_int($value['last_modified']);
    }

    private static function fresh(array $value, array $config): bool
    {
        $age = time() - (int) $value['fetched_at'];

        return $age >= 0 && $age <= (int) ($config['cache']['ttl'] ?? 300);
    }

    /** @return array<string, mixed> */
    private static function fallback(?array $stale, array $config, bool $isProduction): array
    {
        if (!$isProduction
            && ($config['failure_strategy'] ?? 'use_stale') === 'use_stale'
            && self::validCachePayload($stale, $config) !== null) {
            self::emit($config, 'stale', $stale);

            return $stale;
        }

        throw new \RuntimeException('Nacos configuration is unavailable.');
    }

    private static function inject(array $payload): void
    {
        // 完整解析成功后再统一注入，非法正文不能留下半份环境变量。
        $values = self::parse((string) $payload['content']);
        foreach ($values as $key => $value) {
            if (strpos($key, 'NACOS_') === 0 || in_array($key, ['APP_CONFIG_CACHE', 'APP_BASE_PATH'], true)) {
                continue;
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    /** @return array<string, string> */
    private static function parse(string $content): array
    {
        /** @var array<string, string> $values */
        $values = Dotenv::parse($content);

        return $values;
    }

    private static function emit(array $config, string $event, ?array $value = null): void
    {
        $sink = self::factories()['events'] ?? null;
        if (!is_callable($sink)) {
            return;
        }
        $record = [
            'event' => $event,
            'namespace' => (string) ($config['namespace'] ?? ''),
            'group' => (string) ($config['group'] ?? 'DEFAULT_GROUP'),
            'dataId' => (string) ($config['data_id'] ?? ''),
            'version' => $value === null ? null : md5((string) ($value['content'] ?? '')),
            'elapsed_ms' => max(0, (int) round((microtime(true) - self::$startedAt) * 1000)),
        ];
        try {
            $sink($record);
        } catch (\Throwable $exception) {
            // 监控失败不能影响配置加载，也不能泄露异常正文。
        }
    }

    private static function factories(): array
    {
        $factories = $GLOBALS['nacos.bootstrap.factories'] ?? [];

        return is_array($factories) ? $factories : [];
    }
}
