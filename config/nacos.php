<?php

/*
 * 这里只读取机器启动阶段可获得的 Nacos/Redis 参数。
 * 业务环境变量由 Bootstrapper 从 Nacos 校验后注入，不能在这里提前生成本地快照。
 */
$nacosEnv = $nacosEnv ?? 'env';

return [
    'enabled' => filter_var($nacosEnv('NACOS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'server_addr' => $nacosEnv('NACOS_SERVER_ADDR', ''),
    'namespace' => $nacosEnv('NACOS_NAMESPACE', ''),
    'group' => $nacosEnv('NACOS_GROUP', 'DEFAULT_GROUP'),
    'data_id' => $nacosEnv('NACOS_DATA_ID', ''),
    'username' => $nacosEnv('NACOS_USERNAME'),
    'password' => $nacosEnv('NACOS_PASSWORD'),
    'access_token' => $nacosEnv('NACOS_ACCESS_TOKEN'),
    'timeout' => (float) $nacosEnv('NACOS_TIMEOUT', 3.0),
    'connect_timeout' => (float) $nacosEnv('NACOS_CONNECT_TIMEOUT', 1.0),
    'cache' => [
        'timeout' => (float) $nacosEnv('NACOS_REDIS_TIMEOUT', 1.0),
        'connect_timeout' => (float) $nacosEnv('NACOS_REDIS_CONNECT_TIMEOUT', 1.0),
        'host' => $nacosEnv('NACOS_REDIS_HOST', '127.0.0.1'),
        'port' => (int) $nacosEnv('NACOS_REDIS_PORT', 6379),
        'password' => $nacosEnv('NACOS_REDIS_PASSWORD'),
        'database' => (int) $nacosEnv('NACOS_REDIS_DB', 0),
        'ttl' => (int) $nacosEnv('NACOS_REDIS_TTL', 300),
        'stale_ttl' => (int) $nacosEnv('NACOS_REDIS_STALE_TTL', 60),
        'lock_ttl' => (int) $nacosEnv('NACOS_REDIS_LOCK_TTL', 10),
    ],
    'failure_strategy' => $nacosEnv('NACOS_FAILURE_STRATEGY', 'use_stale'),
];
