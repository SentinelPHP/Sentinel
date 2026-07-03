<?php

declare(strict_types=1);

namespace App\Swoole;

use App\Service\RequestContext;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class SwooleRequestHandler
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RequestContext $requestContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse): void
    {
        $this->requestContext->reset();
        $this->requestContext->initialize();

        try {
            $symfonyRequest = $this->convertRequest($swooleRequest);
            $symfonyResponse = $this->kernel->handle($symfonyRequest);
            
            $this->sendResponse($symfonyResponse, $swooleResponse);
            
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($symfonyRequest, $symfonyResponse);
            }
        } catch (\Throwable $e) {
            $this->sendErrorResponse($e, $swooleResponse);
        }
    }

    public function convertRequest(SwooleRequest $swooleRequest): SymfonyRequest
    {
        $server = $this->buildServerArray($swooleRequest);
        
        /** @var array<string, mixed> $query */
        $query = $swooleRequest->get ?? [];
        /** @var array<string, mixed> $request */
        $request = $swooleRequest->post ?? [];
        /** @var array<string, mixed> $cookies */
        $cookies = $swooleRequest->cookie ?? [];
        /** @var array<string|int, mixed> $files */
        $files = $swooleRequest->files ?? [];

        return new SymfonyRequest(
            query: $query,
            request: $request,
            attributes: [],
            cookies: $cookies,
            files: $this->normalizeFiles($files),
            server: $server,
            content: $swooleRequest->rawContent() ?: null
        );
    }

    public function sendResponse(SymfonyResponse $symfonyResponse, SwooleResponse $swooleResponse): void
    {
        $swooleResponse->status($symfonyResponse->getStatusCode());
        
        // Headers to skip - Swoole manages these automatically.
        // Setting Content-Length with Accept-Encoding causes warning:
        // "The client has set 'Accept-Encoding', 'Content-Length' will be ignored"
        $skipHeaders = ['content-length', 'transfer-encoding', 'content-encoding', 'set-cookie'];
        
        foreach ($symfonyResponse->headers->all() as $name => $values) {
            if (in_array(strtolower($name), $skipHeaders, true)) {
                continue;
            }
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }
        
        // Handle cookies separately - Swoole has a dedicated cookie method
        foreach ($symfonyResponse->headers->getCookies() as $cookie) {
            $swooleResponse->cookie(
                $cookie->getName(),
                $cookie->getValue() ?? '',
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain() ?? '',
                $cookie->isSecure(),
                $cookie->isHttpOnly(),
                $cookie->getSameSite() ?? ''
            );
        }
        
        $swooleResponse->end($symfonyResponse->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildServerArray(SwooleRequest $swooleRequest): array
    {
        $server = [];
        
        /** @var array<string, mixed> $serverVars */
        $serverVars = $swooleRequest->server ?? [];
        foreach ($serverVars as $key => $value) {
            $server[strtoupper((string) $key)] = $value;
        }
        
        // Ensure REMOTE_ADDR is set for trusted proxy detection
        if (!isset($server['REMOTE_ADDR']) && isset($serverVars['remote_addr'])) {
            $server['REMOTE_ADDR'] = $serverVars['remote_addr'];
        }

        /** @var array<string, mixed> $headers */
        $headers = $swooleRequest->header ?? [];
        foreach ($headers as $key => $value) {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', (string) $key));
            $server[$headerKey] = $value;
        }

        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $headers['content-length'];
        }
        
        // Set HTTPS flag based on X-Forwarded-Proto for reverse proxy setups
        if (isset($headers['x-forwarded-proto']) && $headers['x-forwarded-proto'] === 'https') {
            $server['HTTPS'] = 'on';
        }
        
        return $server;
    }

    /**
     * @param array<string|int, mixed> $files
     * @return array<string|int, mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        
        foreach ($files as $key => $file) {
            if (is_array($file) && isset($file['tmp_name'])) {
                $normalized[$key] = $file;
            } elseif (is_array($file)) {
                $normalized[$key] = $this->normalizeFiles($file);
            }
        }
        
        return $normalized;
    }

    private function sendErrorResponse(\Throwable $e, SwooleResponse $swooleResponse): void
    {
        $context = $this->requestContext->toLogContext();
        $context['exception_class'] = $e::class;
        $context['http_status'] = 500;

        if ($this->kernel->isDebug()) {
            $context['trace'] = $e->getTraceAsString();
        }

        $this->logger->error(
            sprintf('[%s] %s', $e::class, $e->getMessage()),
            $context
        );

        $responseData = [
            'error' => 'Internal Server Error',
            'message' => $this->kernel->isDebug() ? $e->getMessage() : 'An error occurred',
        ];

        $requestId = $this->requestContext->getRequestId();
        if ($requestId !== null) {
            $responseData['request_id'] = $requestId;
        }

        $swooleResponse->status(500);
        $swooleResponse->header('Content-Type', 'application/json');
        $swooleResponse->end(json_encode($responseData, JSON_THROW_ON_ERROR));
    }
}
