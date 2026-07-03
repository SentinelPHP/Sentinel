<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\AuthenticationException;
use App\Exception\InvalidTargetException;
use App\Exception\TargetUnreachableException;
use App\Service\RequestContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ExceptionListener
{
    private const array API_PATHS = ['/proxy', '/health', '/metrics'];

    public function __construct(
        private readonly RequestContext $requestContext,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only handle exceptions for API routes, let Symfony handle dashboard/web routes
        if (!$this->isApiRoute($path)) {
            return;
        }

        $exception = $event->getThrowable();
        $statusCode = $this->getStatusCode($exception);
        $context = $this->buildLogContext($exception, $statusCode);

        $this->logException($exception, $context, $statusCode);

        $response = $this->createJsonResponse($exception, $statusCode);
        $event->setResponse($response);
    }

    private function isApiRoute(string $path): bool
    {
        foreach (self::API_PATHS as $apiPath) {
            if (str_starts_with($path, $apiPath)) {
                return true;
            }
        }
        return false;
    }

    private function getStatusCode(\Throwable $exception): int
    {
        return match (true) {
            $exception instanceof AuthenticationException => $exception->getHttpStatusCode(),
            $exception instanceof InvalidTargetException => $exception->getHttpStatusCode(),
            $exception instanceof TargetUnreachableException => $exception->getHttpStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLogContext(\Throwable $exception, int $statusCode): array
    {
        $context = $this->requestContext->toLogContext();

        $context['exception_class'] = $exception::class;
        $context['http_status'] = $statusCode;

        if ($exception instanceof AuthenticationException && $exception->tokenId !== null) {
            $context['token_id'] = $exception->tokenId;
        }

        if ($exception instanceof InvalidTargetException) {
            if ($exception->targetUrl !== null) {
                $context['target_url'] = $exception->targetUrl;
            }
            if ($exception->validationError !== null) {
                $context['validation_error'] = $exception->validationError;
            }
        }

        if ($exception instanceof TargetUnreachableException) {
            if ($exception->targetHost !== null) {
                $context['target_host'] = $exception->targetHost;
            }
            if ($exception->targetUrl !== null) {
                $context['target_url'] = $exception->targetUrl;
            }
        }

        if ($this->debug) {
            $context['trace'] = $exception->getTraceAsString();
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logException(\Throwable $exception, array $context, int $statusCode): void
    {
        $message = sprintf('[%s] %s', $exception::class, $exception->getMessage());

        if ($statusCode >= 500) {
            $this->logger->error($message, $context);
        } elseif ($statusCode >= 400) {
            $this->logger->warning($message, $context);
        } else {
            $this->logger->info($message, $context);
        }
    }

    private function createJsonResponse(\Throwable $exception, int $statusCode): JsonResponse
    {
        $data = [
            'error' => true,
            'message' => $this->getClientMessage($exception, $statusCode),
        ];

        $requestId = $this->requestContext->getRequestId();
        if ($requestId !== null) {
            $data['request_id'] = $requestId;
        }

        return new JsonResponse($data, $statusCode);
    }

    private function getClientMessage(\Throwable $exception, int $statusCode): string
    {
        if ($statusCode >= 500 && !$this->debug) {
            return 'An internal error occurred';
        }

        return $exception->getMessage();
    }
}
