<?php

declare(strict_types=1);

namespace Tests\Unit\HttpClient;

use App\HttpClient\ApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Тесты базовой обёртки ApiClient (Guzzle).
 *
 * ApiClient не принимает клиент в конструкторе, поэтому подмена HTTP-слоя —
 * через Reflection: заменяем приватное поле $client экземпляром с MockHandler.
 */
final class ApiClientTest extends TestCase
{
    /** @var list<RequestInterface> перехваченные запросы */
    private array $sentRequests = [];

    private function apiWith(Response|\Throwable ...$queue): ApiClient
    {
        $mock = new MockHandler($queue);
        $stack = HandlerStack::create($mock);
        $history = Middleware::history($this->sentRequests);
        $stack->push($history);

        $api = new ApiClient('https://api.example.com/', []);

        $reflection = new \ReflectionProperty(ApiClient::class, 'client'); 
        $reflection->setAccessible(true);
        
        $reflection->setValue($api, new Client([
            'handler'  => $stack,
            'base_uri' => 'https://api.example.com/',
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
            ],
        ]));

        return $api;
    }

    #[Test]
    public function getReturnsResponseBody(): void
    {
        $api = $this->apiWith(new Response(200, [], 'hello-body'));

        $this->assertSame('hello-body', $api->get('ping'));
    }

    #[Test]
    public function getUsesDefaultHeadersAndMethod(): void
    {
        $api = $this->apiWith(new Response(200, [], '{}'));
        $api->get('ping');

        $request = $this->sentRequests[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertStringContainsString('Mozilla', $request->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function postSendsJsonBody(): void
    {
        $api = $this->apiWith(new Response(201, [], '{"created":true}'));

        $body = $api->post('company', ['inn' => '8601023568']);

        $this->assertSame('{"created":true}', $body);

        $request = $this->sentRequests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $request->getBody(), true);
        $this->assertSame(['inn' => '8601023568'], $decoded);
    }

    #[Test]
    public function customOptionsArePassedToGuzzle(): void
    {
        $api = $this->apiWith(new Response(200, [], 'ok'));
        $api->get('ping', ['headers' => ['X-Trace' => 'abc123']]);

        $this->assertSame('abc123', $this->sentRequests[0]['request']->getHeaderLine('X-Trace'));
    }

    #[Test]
    public function httpErrorPropagatesAsGuzzleException(): void
    {
        $api = $this->apiWith(
            new RequestException('Server error', new Request('GET', 'ping'), new Response(500))
        );

        $this->expectException(\GuzzleHttp\Exception\RequestException::class);

        $api->get('ping');
    }
}
