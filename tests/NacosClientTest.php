<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use CodeGopher\LaravelNacos\NacosClient;
use Mockery;
use PHPUnit\Framework\TestCase;

final class NacosClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testFetchRequestsTheNacosConfigAndReturnsItsArrayPayload(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $content = "APP_NAME=warehouse\nFEATURE_ENABLED=true";

        $httpClient->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $uri, array $options): bool {
                return $method === 'GET'
                    && parse_url($uri, PHP_URL_PATH) === '/nacos/v1/cs/configs'
                    && parse_url($uri, PHP_URL_HOST) === 'nacos.test'
                    && $options['query'] === [
                        'dataId' => 'application.env',
                        'group' => 'DEFAULT_GROUP',
                        'tenant' => 'warehouse',
                    ]
                    && $options['connect_timeout'] === 1.25
                    && $options['timeout'] === 3.5
                    && $options['http_errors'] === false;
            })
            ->andReturn(new Response(200, [], $content));

        $client = new NacosClient($this->config(), $httpClient);
        $startedAt = time();

        $config = $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');

        $this->assertSame($content, $config['content']);
        $this->assertSame(md5($content), $config['md5']);
        $this->assertNull($config['last_modified']);
        $this->assertGreaterThanOrEqual($startedAt, $config['fetched_at']);
        $this->assertLessThanOrEqual(time(), $config['fetched_at']);
    }

    public function testFetchLogsInBeforeFetchingConfigWhenUsernameAndPasswordAreConfigured(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->ordered('nacos-requests')
            ->withArgs(function (string $method, string $uri, array $options): bool {
                return $method === 'POST'
                    && parse_url($uri, PHP_URL_PATH) === '/nacos/v1/auth/login'
                    && $options['form_params'] === [
                        'username' => 'nacos-user',
                        'password' => 'nacos-password',
                    ]
                    && $options['connect_timeout'] === 1.25
                    && $options['timeout'] === 3.5
                    && $options['http_errors'] === false;
            })
            ->andReturn(new Response(200, [], json_encode(['accessToken' => 'login-token'])));

        $httpClient->shouldReceive('request')
            ->once()
            ->ordered('nacos-requests')
            ->withArgs(function (string $method, string $uri, array $options): bool {
                return $method === 'GET'
                    && parse_url($uri, PHP_URL_PATH) === '/nacos/v1/cs/configs'
                    && $options['query']['accessToken'] === 'login-token';
            })
            ->andReturn(new Response(200, [], 'APP_NAME=warehouse'));

        $client = new NacosClient($this->config([
            'username' => 'nacos-user',
            'password' => 'nacos-password',
        ]), $httpClient);

        $config = $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');

        $this->assertSame('APP_NAME=warehouse', $config['content']);
    }

    public function testFetchReusesTheLoginTokenForSubsequentRequests(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->ordered('nacos-requests')
            ->andReturn(new Response(200, [], json_encode(['accessToken' => 'login-token'])));

        $httpClient->shouldReceive('request')
            ->twice()
            ->ordered('nacos-requests')
            ->withArgs(function (string $method, string $uri, array $options): bool {
                return $method === 'GET'
                    && parse_url($uri, PHP_URL_PATH) === '/nacos/v1/cs/configs'
                    && $options['query']['accessToken'] === 'login-token';
            })
            ->andReturn(new Response(200, [], 'APP_NAME=warehouse'));

        $client = new NacosClient($this->config([
            'username' => 'nacos-user',
            'password' => 'nacos-password',
        ]), $httpClient);

        $firstConfig = $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');
        $secondConfig = $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');

        $this->assertSame('APP_NAME=warehouse', $firstConfig['content']);
        $this->assertSame('APP_NAME=warehouse', $secondConfig['content']);
    }

    public function testFetchUsesConfiguredAccessTokenWithoutLoggingIn(): void
    {
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->withArgs(function (string $method, string $uri, array $options): bool {
                return $method === 'GET'
                    && parse_url($uri, PHP_URL_PATH) === '/nacos/v1/cs/configs'
                    && $options['query']['accessToken'] === 'configured-token';
            })
            ->andReturn(new Response(200, [], 'APP_NAME=warehouse'));

        $client = new NacosClient($this->config([
            'username' => 'nacos-user',
            'password' => 'nacos-password',
            'access_token' => 'configured-token',
        ]), $httpClient);

        $config = $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');

        $this->assertSame('APP_NAME=warehouse', $config['content']);
    }

    public function testFetchConvertsTransportTimeoutToASafeRuntimeException(): void
    {
        $password = 'nacos-password-must-not-leak';
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException(
                'Connection timed out password=' . $password,
                new Request('GET', 'http://nacos.test/nacos/v1/cs/configs'),
                null,
                ['errno' => 28]
            ));

        $client = new NacosClient($this->config([
            'password' => $password,
        ]), $httpClient);

        try {
            $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertStringNotContainsString($password, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /**
     * @dataProvider non2xxResponses
     */
    public function testFetchConvertsNon2xxResponsesToSafeRuntimeExceptions(int $statusCode): void
    {
        $secretResponseBody = 'password=nacos-password token=nacos-token';
        $httpClient = Mockery::mock(ClientInterface::class);
        $httpClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response($statusCode, [], $secretResponseBody));

        $client = new NacosClient($this->config(), $httpClient);

        try {
            $client->fetch('application.env', 'DEFAULT_GROUP', 'warehouse');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) $statusCode, $exception->getMessage());
            $this->assertStringNotContainsString($secretResponseBody, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public function non2xxResponses(): array
    {
        return [
            'not found' => [404],
            'server error' => [500],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'server_addr' => 'http://nacos.test:8848',
            'timeout' => 3.5,
            'connect_timeout' => 1.25,
            'username' => null,
            'password' => null,
            'access_token' => null,
        ], $overrides);
    }
}
