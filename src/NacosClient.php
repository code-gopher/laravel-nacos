<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Nacos v1 配置 HTTP 客户端。
 *
 * 客户端只负责认证和读取配置，不负责缓存或环境变量注入。所有异常都会转换成
 * 不带请求内容、凭据和远端异常链的固定异常，避免启动日志泄露敏感信息。
 */
final class NacosClient
{
    /** @var ClientInterface */
    private $httpClient;

    /** @var string */
    private $serverAddr;

    /** @var float */
    private $timeout;

    /** @var float */
    private $connectTimeout;

    /** @var string|null */
    private $username;

    /** @var string|null */
    private $password;

    /** @var string|null */
    private $configuredAccessToken;

    /** @var string|null */
    private $loginAccessToken;

    /**
     * @param array<string, mixed> $config Nacos 连接配置
     *
     * 注入 HTTP 客户端主要用于测试，也允许宿主项目复用自己的 Guzzle Handler。
     *
     */
    public function __construct(array $config, ?ClientInterface $httpClient = null)
    {
        $this->serverAddr = rtrim((string) ($config['server_addr'] ?? ''), '/');
        if ($this->serverAddr === '') {
            throw new \RuntimeException('Nacos server address is not configured.');
        }

        $this->httpClient = $httpClient ?: new Client();
        $this->timeout = $this->positiveTimeout($config['timeout'] ?? 3.0);
        $this->connectTimeout = $this->positiveTimeout($config['connect_timeout'] ?? 1.0);
        $this->username = $this->nullableString($config['username'] ?? null);
        $this->password = $this->nullableString($config['password'] ?? null);
        $this->configuredAccessToken = $this->nullableString($config['access_token'] ?? null);
        $this->loginAccessToken = null;
    }

    /**
     * 拉取指定 namespace、group 和 dataId 的配置正文。
     *
     * access token 优先使用机器直接提供的令牌；用户名密码登录得到的令牌只在
     * 当前 PHP 进程内复用，不写入 Redis，也不写入任何本地文件。
     *
     * @return array{content: string, md5: string, last_modified: int|null, fetched_at: int}
     */
    public function fetch(string $dataId, string $group, string $namespace): array
    {
        $query = [
            'dataId' => $dataId,
            'group'  => $group,
            'tenant' => $namespace,
        ];

        $accessToken = $this->getAccessToken();
        if ($accessToken !== null) {
            $query['accessToken'] = $accessToken;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $this->url('/nacos/v1/cs/configs'),
                $this->requestOptions(['query' => $query])
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException($this->transportErrorMessage($exception));
        }

        $this->assertSuccessful($response, 'configuration fetch');

        $content = $this->responseBody($response);

        return [
            'content' => $content,
            'md5' => md5($content),
            'last_modified' => $this->lastModified($response),
            'fetched_at' => time(),
        ];
    }

    /**
     * 获取本次请求使用的令牌。
     *
     * 认证失败直接中止配置请求，不能把未认证的响应当成配置正文继续处理。
     */
    private function getAccessToken(): ?string
    {
        if ($this->configuredAccessToken !== null) {
            return $this->configuredAccessToken;
        }

        if ($this->loginAccessToken !== null) {
            return $this->loginAccessToken;
        }

        if ($this->username === null || $this->password === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                $this->url('/nacos/v1/auth/login'),
                $this->requestOptions([
                    'form_params' => [
                        'username' => $this->username,
                        'password' => $this->password,
                    ],
                ])
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException($this->transportErrorMessage($exception));
        }

        $this->assertSuccessful($response, 'authentication');

        $payload = json_decode($this->responseBody($response), true);
        if (!is_array($payload) || !isset($payload['accessToken']) || !is_string($payload['accessToken']) || trim($payload['accessToken']) === '') {
            throw new \RuntimeException('Nacos authentication response did not contain an access token.');
        }

        $this->loginAccessToken = $payload['accessToken'];

        return $this->loginAccessToken;
    }

    /**
     * 合并固定的请求安全边界。
     *
     * 禁止自动跟随重定向，避免把用户名、密码或令牌转发到非预期主机。
     * http_errors=false 让客户端统一按状态码生成脱敏异常。
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestOptions(array $options): array
    {
        return array_merge([
            'connect_timeout' => $this->connectTimeout,
            'timeout'         => $this->timeout,
            'http_errors'     => false,
            'allow_redirects' => false,
        ], $options);
    }

    /**
     * 只接受 2xx 响应，其他状态只保留操作名和状态码。
     */
    private function assertSuccessful(ResponseInterface $response, string $operation): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Nacos %s failed with HTTP %d.',
            $operation,
            $statusCode
        ));
    }

    /**
     * 拼接固定的 Nacos API 路径。
     */
    private function url(string $path): string
    {
        return $this->serverAddr . $path;
    }

    /**
     * 响应流也可能读取失败，不能把流异常中的正文或凭据带入启动日志。
     */
    private function responseBody(ResponseInterface $response): string
    {
        try {
            return (string) $response->getBody();
        } catch (Throwable $exception) {
            throw new \RuntimeException('Nacos response body could not be read.');
        }
    }

    /**
     * 把 Guzzle 传输异常归类为可观测但不泄露细节的错误信息。
     */
    private function transportErrorMessage(Throwable $exception): string
    {
        if (($exception instanceof ConnectException || $exception instanceof RequestException)
            && (int) ($exception->getHandlerContext()['errno'] ?? 0) === 28) {
            return 'Nacos request timed out.';
        }
        if ($exception instanceof ConnectException) {
            return 'Nacos connection failed.';
        }

        return 'Nacos request failed.';
    }

    /**
     * 兼容 Nacos 返回的 Unix 时间戳和标准 HTTP 日期格式。
     */
    private function lastModified(ResponseInterface $response): ?int
    {
        $value = $response->getHeaderLine('Last-Modified');
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * 把空字符串统一成 null，避免误开启认证或把空地址当成有效配置。
     *
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * 校验超时必须是有限正数，防止通过 0、负数或 INF 关闭请求预算。
     *
     * @param mixed $value
     */
    private function positiveTimeout($value): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
            throw new \InvalidArgumentException('Nacos HTTP timeouts must be finite and greater than zero.');
        }
        return (float) $value;
    }
}
