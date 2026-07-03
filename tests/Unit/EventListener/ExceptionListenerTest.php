<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\ExceptionListener;
use App\Exception\AuthenticationException;
use App\Exception\InvalidTargetException;
use App\Exception\TargetUnreachableException;
use App\Service\RequestContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(ExceptionListener::class)]
#[AllowMockObjectsWithoutExpectations]
final class ExceptionListenerTest extends TestCase
{
    private RequestContext $requestContext;
    private LoggerInterface&MockObject $logger;
    private HttpKernelInterface&MockObject $kernel;

    protected function setUp(): void
    {
        $this->requestContext = new RequestContext();
        $this->requestContext->initialize();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
    }

    #[Test]
    public function handlesAuthenticationExceptionWith401(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = AuthenticationException::missingToken();
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('AuthenticationException'),
                self::callback(fn (array $ctx) => $ctx['http_status'] === 401)
            );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        self::assertArrayHasKey('request_id', $content);
    }

    #[Test]
    public function handlesInvalidTargetExceptionWith403(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = InvalidTargetException::hostNotAllowed('https://evil.com', 'evil.com');
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('InvalidTargetException'),
                self::callback(fn (array $ctx) => 
                    $ctx['http_status'] === 403 &&
                    $ctx['target_url'] === 'https://evil.com' &&
                    $ctx['validation_error'] === 'host_not_allowed'
                )
            );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function handlesInvalidTargetExceptionMissingHeaderWith400(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = InvalidTargetException::missingHeader('X-Sentinel-Target');
        $event = $this->createExceptionEvent($exception);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function handlesTargetUnreachableExceptionWith502(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = TargetUnreachableException::timeout('https://slow.api.com/endpoint', 30);
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('TargetUnreachableException'),
                self::callback(fn (array $ctx) => 
                    $ctx['http_status'] === 502 &&
                    $ctx['target_host'] === 'slow.api.com' &&
                    $ctx['target_url'] === 'https://slow.api.com/endpoint'
                )
            );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(502, $response->getStatusCode());
    }

    #[Test]
    public function handlesGenericExceptionWith500(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = new \RuntimeException('Something went wrong');
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('RuntimeException'),
                self::callback(fn (array $ctx) => $ctx['http_status'] === 500)
            );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        self::assertIsArray($content);
        self::assertSame('An internal error occurred', $content['message']);
    }

    #[Test]
    public function showsExceptionMessageInDebugMode(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, true);
        $exception = new \RuntimeException('Detailed error message');
        $event = $this->createExceptionEvent($exception);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);

        $content = json_decode($response->getContent() ?: '', true);
        self::assertIsArray($content);
        self::assertSame('Detailed error message', $content['message']);
    }

    #[Test]
    public function includesTraceInDebugMode(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, true);
        $exception = new \RuntimeException('Test error');
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(fn (array $ctx) => isset($ctx['trace']))
            );

        $listener($event);
    }

    #[Test]
    public function includesRequestContextInLogContext(): void
    {
        $this->requestContext->setTokenId('test-token-id');
        $this->requestContext->setTargetUrl('https://api.example.com/test');

        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = TargetUnreachableException::requestFailed('https://api.example.com/test', 'Connection refused');
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(fn (array $ctx) => 
                    $ctx['request_id'] === $this->requestContext->getRequestId() &&
                    $ctx['token_id'] === 'test-token-id'
                )
            );

        $listener($event);
    }

    #[Test]
    public function logs4xxAsWarning(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = AuthenticationException::invalidToken();
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $listener($event);
    }

    #[Test]
    public function logs5xxAsError(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = TargetUnreachableException::dnsResolutionFailed('unknown.host');
        $event = $this->createExceptionEvent($exception);

        $this->logger->expects(self::once())->method('error');
        $this->logger->expects(self::never())->method('warning');

        $listener($event);
    }

    private function createExceptionEvent(\Throwable $exception, string $path = '/proxy'): ExceptionEvent
    {
        $request = Request::create($path, 'GET');

        return new ExceptionEvent(
            $this->kernel,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $exception
        );
    }

    #[Test]
    public function skipsNonApiRoutes(): void
    {
        $listener = new ExceptionListener($this->requestContext, $this->logger, false);
        $exception = new \RuntimeException('Dashboard error');
        $event = $this->createExceptionEvent($exception, '/dashboard');

        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::never())->method('warning');

        $listener($event);

        self::assertNull($event->getResponse());
    }
}
