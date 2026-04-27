<?php

declare(strict_types=1);

namespace App\Tests\Unit\Swoole;

use App\Service\RequestContext;
use App\Swoole\SwooleRequestHandler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

#[CoversClass(SwooleRequestHandler::class)]
#[AllowMockObjectsWithoutExpectations]
#[RequiresPhpExtension('swoole')]
final class SwooleRequestHandlerTest extends TestCase
{
    private KernelInterface&MockObject $kernel;
    private RequestContext $requestContext;
    private LoggerInterface&MockObject $logger;
    private SwooleRequestHandler $handler;

    protected function setUp(): void
    {
        $this->kernel = $this->createMock(KernelInterface::class);
        $this->requestContext = new RequestContext();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new SwooleRequestHandler($this->kernel, $this->requestContext, $this->logger);
    }

    #[Test]
    public function convertRequestCreatesSymfonyRequestFromSwooleRequest(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => [
                'request_method' => 'POST',
                'request_uri' => '/api/test',
                'server_protocol' => 'HTTP/1.1',
            ],
            'header' => [
                'host' => 'localhost:8080',
                'content-type' => 'application/json',
                'authorization' => 'Bearer test-token',
            ],
            'get' => ['foo' => 'bar'],
            'post' => ['name' => 'test'],
            'cookie' => ['session' => 'abc123'],
            'rawContent' => '{"key":"value"}',
        ]);

        $symfonyRequest = $this->handler->convertRequest($swooleRequest);

        $this->assertInstanceOf(SymfonyRequest::class, $symfonyRequest);
        $this->assertSame('POST', $symfonyRequest->getMethod());
        $this->assertSame('/api/test', $symfonyRequest->getPathInfo());
        $this->assertSame('bar', $symfonyRequest->query->get('foo'));
        $this->assertSame('test', $symfonyRequest->request->get('name'));
        $this->assertSame('abc123', $symfonyRequest->cookies->get('session'));
        $this->assertSame('{"key":"value"}', $symfonyRequest->getContent());
        $this->assertSame('application/json', $symfonyRequest->headers->get('content-type'));
        $this->assertSame('Bearer test-token', $symfonyRequest->headers->get('authorization'));
    }

    #[Test]
    public function convertRequestHandlesEmptyRequest(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => [
                'request_method' => 'GET',
                'request_uri' => '/',
            ],
            'header' => [],
            'get' => null,
            'post' => null,
            'cookie' => null,
            'files' => null,
            'rawContent' => '',
        ]);

        $symfonyRequest = $this->handler->convertRequest($swooleRequest);

        $this->assertInstanceOf(SymfonyRequest::class, $symfonyRequest);
        $this->assertSame('GET', $symfonyRequest->getMethod());
        $this->assertSame('/', $symfonyRequest->getPathInfo());
        $this->assertEmpty($symfonyRequest->query->all());
        $this->assertEmpty($symfonyRequest->request->all());
    }

    #[Test]
    public function convertRequestMapsHeadersToServerArray(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => [
                'request_method' => 'GET',
                'request_uri' => '/test',
            ],
            'header' => [
                'x-custom-header' => 'custom-value',
                'accept' => 'application/json',
                'content-length' => '100',
            ],
        ]);

        $symfonyRequest = $this->handler->convertRequest($swooleRequest);

        $this->assertSame('custom-value', $symfonyRequest->headers->get('x-custom-header'));
        $this->assertSame('application/json', $symfonyRequest->headers->get('accept'));
        $this->assertSame('100', $symfonyRequest->headers->get('content-length'));
    }

    #[Test]
    public function sendResponseSetsStatusCodeAndHeaders(): void
    {
        $symfonyResponse = new SymfonyResponse(
            content: '{"status":"ok"}',
            status: 201,
            headers: [
                'Content-Type' => 'application/json',
                'X-Custom' => 'value',
            ]
        );

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->expects($this->once())
            ->method('status')
            ->with(201);

        $swooleResponse->expects($this->atLeast(2))
            ->method('header');

        $swooleResponse->expects($this->once())
            ->method('end')
            ->with('{"status":"ok"}');

        $this->handler->sendResponse($symfonyResponse, $swooleResponse);
    }

    #[Test]
    public function handleProcessesRequestThroughKernel(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => [
                'request_method' => 'GET',
                'request_uri' => '/health',
            ],
            'header' => [],
        ]);

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->expects($this->once())->method('status')->with(200);
        $swooleResponse->expects($this->once())->method('end');

        $symfonyResponse = new SymfonyResponse('OK', 200);

        $this->kernel->expects($this->once())
            ->method('handle')
            ->willReturn($symfonyResponse);

        $this->handler->handle($swooleRequest, $swooleResponse);
    }

    #[Test]
    public function handleCallsTerminateOnTerminableKernel(): void
    {
        $terminableKernel = $this->createMock(TerminableKernelInterface::class);
        $handler = new SwooleRequestHandler($terminableKernel, $this->requestContext, $this->logger);

        $swooleRequest = $this->createSwooleRequestMock([
            'server' => ['request_method' => 'GET', 'request_uri' => '/'],
            'header' => [],
        ]);

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->method('status');
        $swooleResponse->method('end');

        $symfonyResponse = new SymfonyResponse('OK');

        $terminableKernel->expects($this->once())
            ->method('handle')
            ->willReturn($symfonyResponse);

        $terminableKernel->expects($this->once())
            ->method('terminate');

        $handler->handle($swooleRequest, $swooleResponse);
    }

    #[Test]
    public function handleReturnsErrorResponseOnException(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => ['request_method' => 'GET', 'request_uri' => '/'],
            'header' => [],
        ]);

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->expects($this->once())->method('status')->with(500);
        $swooleResponse->expects($this->once())
            ->method('header')
            ->with('Content-Type', 'application/json');

        $endContent = null;
        $swooleResponse->expects($this->once())
            ->method('end')
            ->willReturnCallback(function ($content) use (&$endContent): bool {
                $endContent = $content;
                return true;
            });

        $this->kernel->expects($this->once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('Test error'));

        $this->kernel->method('isDebug')->willReturn(false);

        $this->handler->handle($swooleRequest, $swooleResponse);

        /** @var string $endContentStr */
        $endContentStr = $endContent;
        $decoded = json_decode($endContentStr, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Internal Server Error', $decoded['error']);
        $this->assertSame('An error occurred', $decoded['message']);
        $this->assertArrayHasKey('request_id', $decoded);
    }

    #[Test]
    public function handleShowsExceptionMessageInDebugMode(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => ['request_method' => 'GET', 'request_uri' => '/'],
            'header' => [],
        ]);

        $swooleResponse = $this->createMock(SwooleResponse::class);
        $swooleResponse->method('status');
        $swooleResponse->method('header');

        $endContent = null;
        $swooleResponse->method('end')
            ->willReturnCallback(function ($content) use (&$endContent): bool {
                $endContent = $content;
                return true;
            });

        $this->kernel->method('handle')
            ->willThrowException(new \RuntimeException('Detailed error message'));

        $this->kernel->method('isDebug')->willReturn(true);

        $this->handler->handle($swooleRequest, $swooleResponse);

        /** @var string $endContentStr */
        $endContentStr = $endContent;
        $decoded = json_decode($endContentStr, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Detailed error message', $decoded['message']);
    }

    #[Test]
    public function convertRequestHandlesEmptyFilesArray(): void
    {
        $swooleRequest = $this->createSwooleRequestMock([
            'server' => ['request_method' => 'POST', 'request_uri' => '/upload'],
            'header' => ['content-type' => 'multipart/form-data'],
            'files' => [],
        ]);

        $symfonyRequest = $this->handler->convertRequest($swooleRequest);

        $this->assertEmpty($symfonyRequest->files->all());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSwooleRequestMock(array $data): SwooleRequest&MockObject
    {
        $mock = $this->createMock(SwooleRequest::class);

        $mock->server = $data['server'] ?? [];
        $mock->header = $data['header'] ?? [];
        $mock->get = $data['get'] ?? null;
        $mock->post = $data['post'] ?? null;
        $mock->cookie = $data['cookie'] ?? null;
        $mock->files = $data['files'] ?? null;

        $mock->method('rawContent')
            ->willReturn($data['rawContent'] ?? '');

        return $mock;
    }
}

interface TerminableKernelInterface extends KernelInterface, TerminableInterface
{
}
