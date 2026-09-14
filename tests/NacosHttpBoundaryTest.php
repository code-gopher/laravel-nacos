<?php

declare(strict_types=1);

namespace CodeGopher\LaravelNacos\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use CodeGopher\LaravelNacos\NacosClient;
use PHPUnit\Framework\TestCase;

final class NacosHttpBoundaryTest extends TestCase
{
    public function testRealGuzzleEncodesLoginAndQueryWithoutCrossContamination(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"accessToken":"a+b /&"}'),
            new Response(200, ['Last-Modified' => 'Wed, 21 Oct 2015 07:28:00 GMT'], 'APP_NAME=WMS'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new NacosClient(['server_addr' => 'https://nacos.invalid', 'username' => 'user+ /&', 'password' => 'pass+ /&'], new Client(['handler' => $handler]));
        $result = $client->fetch('app +.env', 'A&B', 'space /');
        $this->assertSame('username=user%2B+%2F%26&password=pass%2B+%2F%26', (string) $history[0]['request']->getBody());
        $this->assertSame('dataId=app%20%2B.env&group=A%26B&tenant=space%20%2F&accessToken=a%2Bb%20%2F%26', $history[1]['request']->getUri()->getQuery());
        $this->assertSame('', $history[0]['request']->getUri()->getQuery());
        $this->assertSame('', (string) $history[1]['request']->getBody());
        $this->assertSame(1445412480, $result['last_modified']);
    }

    /** @dataProvider invalidAuthentication */
    public function testRejectsInvalidAuthenticationWithoutSendingConfigRequest(int $status, string $body): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response($status, [], $body), new Response(200, [], 'X=y')]));
        $handler->push(Middleware::history($history));
        $client = new NacosClient(['server_addr' => 'https://nacos.invalid', 'username' => 'user', 'password' => 'synthetic-password'], new Client(['handler' => $handler]));
        $error = null;
        try {
            $client->fetch('d', 'g', 'n');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('synthetic-', $error->getMessage());
        $this->assertNull($error->getPrevious());
    }

    /** @dataProvider responseBodyFailures */
    public function testUnreadableResponseBodyIsSanitized(bool $authentication): void
    {
        $stream = \Mockery::mock(\Psr\Http\Message\StreamInterface::class);
        $stream->shouldReceive('__toString')->once()->andThrow(new \RuntimeException('synthetic-secret'));
        $response = new Response(200, [], $stream);
        $config = ['server_addr' => 'https://nacos.invalid'];
        if ($authentication) {
            $config += ['username' => 'user', 'password' => 'synthetic-password'];
        }
        $client = new NacosClient($config, new Client(['handler' => new MockHandler([$response])]));
        try {
            $client->fetch('d', 'g', 'n');
            $this->fail('Expected a safe RuntimeException.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(\RuntimeException::class, get_class($exception));
            $this->assertStringNotContainsString('synthetic-', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        } finally {
            \Mockery::close();
        }
    }

    public function responseBodyFailures(): array
    {
        return [[false], [true]];
    }

    public function invalidAuthentication(): array
    {
        return [[200, '{"accessToken":""}'], [200, '{"accessToken":"  "}'], [200, '{}'], [200, '{"accessToken":12}'], [200, 'synthetic-invalid-json'], [403, 'synthetic-password']];
    }

    /** @dataProvider transportFailures */
    public function testTransportClassificationUsesOnlySafeErrno(bool $connect, int $errno, bool $timeout): void
    {
        $request = new Request('GET', 'https://nacos.invalid?accessToken=synthetic-token');
        $exception = $connect ? new ConnectException('synthetic-secret', $request, null, ['errno' => $errno])
            : new RequestException('synthetic-secret', $request, null, null, ['errno' => $errno]);
        $client = new NacosClient(['server_addr' => 'https://nacos.invalid'], new Client(['handler' => HandlerStack::create(new MockHandler([$exception]))]));
        $error = null;
        try {
            $client->fetch('d', 'g', 'n');
        } catch (\RuntimeException $caught) {
            $error = $caught;
        }
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame($timeout, strpos($error->getMessage(), 'timed out') !== false);
        $this->assertStringNotContainsString('synthetic-', $error->getMessage());
        $this->assertNull($error->getPrevious());
    }

    public function transportFailures(): array
    {
        return [[true, 6, false], [true, 7, false], [true, 28, true], [false, 28, true]];
    }

    /** @dataProvider invalidTimeouts */
    public function testHttpTimeoutCannotDisableTheRequestBudget(string $key, $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NacosClient(['server_addr' => 'https://nacos.invalid', $key => $value], new Client(['handler' => new MockHandler()]));
    }

    public function invalidTimeouts(): array
    {
        return [['timeout', 0], ['timeout', INF], ['timeout', 'bad'], ['connect_timeout', -1]];
    }

    public function testAuthenticationRedirectCannotExtendBudgetOrForwardCredentials(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(307, ['Location' => 'https://other.invalid/login']),
            new Response(200, [], '{"accessToken":"forwarded"}'), new Response(200, [], 'X=y'),
        ]));
        $handler->push(Middleware::history($history));
        $client = new NacosClient(['server_addr' => 'https://nacos.invalid', 'username' => 'user', 'password' => 'secret'], new Client(['handler' => $handler]));
        $error = null;
        try {
            $client->fetch('d', 'g', 'n');
        } catch (\RuntimeException $exception) {
            $error = $exception;
        }
        $this->assertCount(1, $history);
        $this->assertInstanceOf(\RuntimeException::class, $error);
    }

}
