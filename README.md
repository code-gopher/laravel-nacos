# code-gopher/laravel-nacos

Laravel 8 的 Nacos 动态配置包。启动时从 Nacos 读取 dotenv 配置，使用 Redis 做共享缓存和分布式刷新锁，支持 PHP-FPM、Artisan CLI、队列 Worker、RabbitMQ Consumer 和 Workerman。

- GitHub：<https://github.com/code-gopher/laravel-nacos>
- Packagist：<https://packagist.org/packages/code-gopher/laravel-nacos>

## 特性

- Redis 命中 fresh 配置时不请求 Nacos，过期后由分布式锁控制刷新。
- 配置正文通过 dotenv 校验后才写入 Redis。
- 不生成 `.env.nacos`、PHP 配置快照，不使用 APCu。
- 仅提供 `nacos:pull` 命令主动刷新配置。
- 动态模式禁止 `config:cache` 和 `optimize`，避免配置被固化到 PHP 文件。
- 错误信息不会输出密码、Token 或配置正文。

## 环境要求

- PHP 7.4 或更高版本
- Laravel 8.75 或更高版本
- PHP `ext-redis` 扩展
- 可访问的 Nacos 和 Redis 服务

## 安装

```bash
composer require code-gopher/laravel-nacos
```

Laravel 会自动发现 `CodeGopher\LaravelNacos\NacosServiceProvider`。如果项目关闭了 Provider 自动发现，请在 `config/app.php` 中手动注册该类。

### 发布配置文件（可选）

如需在项目内维护 Nacos 配置文件，执行：

```bash
php artisan vendor:publish --tag=nacos-config
```

文件会发布到 `config/nacos.php`。启动时优先读取该文件；未发布时使用包内默认配置。
该命令不连接 Nacos 或 Redis，可在首次安装和离线部署时执行。

## 快速接入

### 1. 创建启动入口

在 Laravel 项目的 `bootstrap/nacos.php` 中写入：

```php
<?php

\CodeGopher\LaravelNacos\Bootstrapper::run($basePath);
```

### 2. 调整 `bootstrap/app.php`

`run()` 必须在创建 Laravel Application 之前调用，`configureApplication()` 必须在创建之后调用：

```php
<?php

$basePath = $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__);

// 在创建 Application 前读取 Redis/Nacos。
require __DIR__ . '/nacos.php';

$app = new Illuminate\Foundation\Application($basePath);

// 固定本次启动选定的 .env 文件。
\CodeGopher\LaravelNacos\Bootstrapper::configureApplication($app);

return $app;
```

### 3. 配置机器环境变量

以下变量应由容器环境、systemd、PHP-FPM pool 或 Secret 管理系统提供：

```dotenv
APP_ENV=production

NACOS_ENABLED=true
NACOS_SERVER_ADDR=https://nacos.example.internal:8848
NACOS_NAMESPACE=production
NACOS_GROUP=WMS
NACOS_DATA_ID=wms-api-production.env

# 用户名密码和 Access Token 二选一。
NACOS_USERNAME=wms-api
NACOS_PASSWORD=replace-with-secret
NACOS_ACCESS_TOKEN=

NACOS_REDIS_HOST=redis-config.internal
NACOS_REDIS_PORT=6379
NACOS_REDIS_PASSWORD=replace-with-secret
NACOS_REDIS_DB=0
NACOS_REDIS_TTL=300
NACOS_REDIS_STALE_TTL=60
NACOS_REDIS_LOCK_TTL=10
```

注意：

- `NACOS_SERVER_ADDR` 必须是一个带 scheme 的地址，不要写逗号分隔的节点列表，也不要在末尾重复写 `/nacos`。
- `NACOS_USERNAME`、`NACOS_PASSWORD` 和 `NACOS_ACCESS_TOKEN` 只应保存在 Secret 中。
- `NACOS_ENABLED=false` 时恢复 Laravel 原生的本地 `.env` 加载行为。

### 4. 发布 Nacos 配置

Nacos 的 `namespace`、`group` 和 `dataId` 必须与机器环境变量一致。配置正文使用 dotenv 格式，例如：

```dotenv
APP_NAME="WMS API"
APP_DEBUG=false
DB_HOST=mysql.internal
DB_PORT=3306
DB_DATABASE=wms
QUEUE_CONNECTION=rabbitmq
```

`NACOS_*`、`APP_CONFIG_CACHE` 和 `APP_BASE_PATH` 是保留键，远端配置不能覆盖它们。

## 配置刷新

```bash
# 从 Nacos 拉取配置并刷新 Redis；失败时返回非 0。
php artisan nacos:pull
```

发布建议：先执行 `nacos:pull` 确认配置可用，再滚动重启常驻进程。

- PHP-FPM：reload，使新请求重新读取配置。
- `queue:work`：执行 `php artisan queue:restart`，由 Supervisor 重启 Worker。
- RabbitMQ Consumer、Workerman：按 Supervisor、systemd 或 Workerman 的 reload/restart 流程重启。

已经开始执行的请求、Job 或消息不会被强制切换到新配置。

## Redis 缓存策略

Redis Key 格式：

```text
wms:nacos:config:{namespace}:{group}:{dataId}
wms:nacos:lock:{namespace}:{group}:{dataId}
```

| 配置项 | 默认值 | 说明 |
| --- | ---: | --- |
| `NACOS_REDIS_TTL` | `300` 秒 | fresh 配置的有效时间 |
| `NACOS_REDIS_STALE_TTL` | `60` 秒 | fresh 过期后允许使用 stale 的时间 |
| `NACOS_REDIS_LOCK_TTL` | `10` 秒 | 单次刷新锁的持有时间 |

生产环境无法取得有效配置时会启动失败，不会静默使用 stale。非生产环境可通过 `NACOS_FAILURE_STRATEGY=use_stale` 使用有效 stale 配置。

## 配置缓存限制

动态模式不能与 Laravel 配置缓存同时使用，以下命令会被拒绝：

```bash
php artisan config:cache
php artisan optimize
```

历史部署生成过配置缓存时，可以执行：

```bash
php artisan config:clear
```

本包不会生成或保存本地配置快照；Redis 不可用时也不会改用本地快照。

## 安全建议

- Nacos、Redis 凭据只放在 Secret 管理系统中。
- Nacos 账户只授予当前 namespace、group 和 dataId 的读取权限。
- 不要把 Nacos 配置正文中的业务密码当作普通配置处理。
- 生产发布前先执行 `nacos:pull`，确认成功后再重启常驻进程。

## 开发与测试

在源码仓库根目录执行：

```bash
composer install
composer lint
composer test
```

## 许可证

[MIT License](https://opensource.org/license/mit/)
