<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Security\RateLimiterInterface;
use App\Security\TokenAuthenticationResult;
use App\Security\TokenAuthenticatorInterface;
use App\Storage\MessengerStorageFactory;
use App\Validation\TargetUrlValidatorInterface;
use SentinelPHP\Core\Config\InterceptorConfig;
use SentinelPHP\Core\SentinelInterceptor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ProxyService
{
    public const string TARGET_HEADER = 'X-Sentinel-Target';

    private const array HOP_BY_HOP_HEADERS = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
        'transfer-encoding',
        'upgrade',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TargetUrlValidatorInterface $targetUrlValidator,
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
        private readonly MessengerStorageFactory $storageFactory,
        private readonly RequestContext $requestContext,
        private readonly ?StatusServiceInterface $statusService = null,
        private readonly ?RateLimiterInterface $rateLimiter = null,
    ) {
    }

    public function proxy(Request $request): Response
    {
        $startTime = hrtime(true);
        $clientIp = $request->getClientIp() ?? 'unknown';

        $rateLimitError = $this->checkRateLimit($clientIp);
        if ($rateLimitError !== null) {
            return $rateLimitError;
        }

        $authResult = $this->authenticate($request, $clientIp);
        if ($authResult instanceof Response) {
            return $authResult;
        }

        $targetUrl = $this->extractAndValidateTargetHeader($request);
        if ($targetUrl instanceof Response) {
            return $targetUrl;
        }

        $tokenHostError = $this->validateTokenHostAccess($targetUrl, $authResult->token);
        if ($tokenHostError !== null) {
            return $tokenHostError;
        }

        $validationResult = $this->validateTargetUrl($targetUrl);
        if ($validationResult instanceof Response) {
            return $validationResult;
        }

        $proxyResult = $this->executeProxyRequest($request, $targetUrl, $validationResult);

        $latencyMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->interceptAndStore(
            $authResult->token,
            $request->getMethod(),
            $targetUrl,
            $proxyResult,
            $latencyMs,
        );

        $this->statusService?->incrementRequestCounter();

        return $proxyResult->response;
    }

    private function interceptAndStore(
        ?ApiToken $token,
        string $method,
        string $url,
        ProxyResult $proxyResult,
        float $latencyMs,
    ): void {
        if ($token === null) {
            return;
        }

        $storage = $this->storageFactory->createForToken($token);
        // Use config that captures response body for schema learning/validation
        // but skips PII redaction (handled separately by message handlers)
        $config = new InterceptorConfig(
            redactPii: false,
            generateSchemas: false,
            captureRequestBody: true,
            captureResponseBody: true,
            captureHeaders: true,
        );

        $interceptor = new SentinelInterceptor($storage, $config);

        $interceptor->intercept(
            method: $method,
            url: $url,
            statusCode: $proxyResult->statusCode,
            latencyMs: $latencyMs,
            requestHeaders: $proxyResult->requestHeaders,
            requestBody: $proxyResult->requestBody !== '' ? $proxyResult->requestBody : null,
            responseHeaders: $proxyResult->responseHeaders ?? [],
            responseBody: $proxyResult->responseBody,
            id: Uuid::v7()->toRfc4122(),
        );
    }

    private function checkRateLimit(string $clientIp): ?Response
    {
        if ($this->rateLimiter === null) {
            return null;
        }

        $authRateLimit = $this->rateLimiter->isAuthFailureAllowed($clientIp);
        if (!$authRateLimit->isAllowed) {
            return $this->createRateLimitResponse($authRateLimit->retryAfterSeconds ?? 60);
        }

        return null;
    }

    private function authenticate(Request $request, string $clientIp): TokenAuthenticationResult|Response
    {
        $authResult = $this->tokenAuthenticator->authenticate($request);

        if (!$authResult->isAuthenticated) {
            $this->rateLimiter?->recordAuthFailure($clientIp);

            return $this->createErrorResponse(
                Response::HTTP_UNAUTHORIZED,
                $authResult->error ?? 'Authentication failed'
            );
        }

        $this->rateLimiter?->clearAuthFailures($clientIp);
        $this->requestContext->setTokenId($authResult->token?->getId()?->toRfc4122());

        return $authResult;
    }

    private function extractAndValidateTargetHeader(Request $request): string|Response
    {
        $targetUrl = $this->extractTargetUrl($request);

        if ($targetUrl === null) {
            return $this->createErrorResponse(
                Response::HTTP_BAD_REQUEST,
                'Missing required header: ' . self::TARGET_HEADER
            );
        }

        $this->requestContext->setTargetUrl($targetUrl);

        return $targetUrl;
    }

    private function validateTokenHostAccess(string $targetUrl, ?ApiToken $token): ?Response
    {
        $targetHost = $this->extractHost($targetUrl) ?? '';

        if ($targetHost !== '' && $token !== null && !$token->isTargetAllowed($targetHost)) {
            return $this->createErrorResponse(
                Response::HTTP_FORBIDDEN,
                'Target host is not allowed for this token'
            );
        }

        return null;
    }

    /**
     * Validates target URL and returns resolved IP to prevent DNS rebinding attacks.
     */
    private function validateTargetUrl(string $targetUrl): string|Response
    {
        $validationResult = $this->targetUrlValidator->validateWithResolvedIp($targetUrl);

        if (!$validationResult->isValid) {
            return $this->createErrorResponse(
                Response::HTTP_FORBIDDEN,
                'Invalid target URL: ' . ($validationResult->error ?? 'Unknown error')
            );
        }

        return $validationResult->resolvedIp ?? '';
    }

    private function executeProxyRequest(
        Request $request,
        string $targetUrl,
        string $resolvedIp,
    ): ProxyResult {
        $filteredHeaders = $this->filterHeaders($request);
        $requestBody = $request->getContent();

        try {
            $httpResponse = $this->forwardRequest(
                $request,
                $targetUrl,
                $filteredHeaders,
                $requestBody,
                $resolvedIp !== '' ? $resolvedIp : null
            );

            return new ProxyResult(
                response: $this->convertToSymfonyResponse($httpResponse),
                statusCode: $httpResponse->statusCode,
                requestHeaders: $filteredHeaders,
                requestBody: $requestBody,
                responseHeaders: $httpResponse->headers,
                responseBody: $httpResponse->body,
            );
        } catch (HttpClientException $e) {
            return new ProxyResult(
                response: $this->createErrorResponse(
                    Response::HTTP_BAD_GATEWAY,
                    'Failed to reach target: ' . $e->getMessage()
                ),
                statusCode: Response::HTTP_BAD_GATEWAY,
                requestHeaders: $filteredHeaders,
                requestBody: $requestBody,
                responseHeaders: null,
                responseBody: null,
            );
        }
    }

    private function extractTargetUrl(Request $request): ?string
    {
        $target = $request->headers->get(self::TARGET_HEADER);

        if ($target === null || $target === '') {
            return null;
        }

        return $target;
    }

    /**
     * @param array<string, string> $headers
     * @throws HttpClientException
     */
    private function forwardRequest(Request $request, string $targetUrl, array $headers, string $body, ?string $resolvedIp = null): HttpResponse
    {
        $method = $request->getMethod();

        return $this->httpClient->request(
            $method,
            $targetUrl,
            $headers,
            $body !== '' ? $body : null,
            $resolvedIp
        );
    }

    /**
     * @return array<string, string>
     */
    private function filterHeaders(Request $request): array
    {
        $filtered = [];

        foreach ($request->headers->all() as $name => $values) {
            $lowerName = strtolower($name);

            // Skip hop-by-hop headers
            if (in_array($lowerName, self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }

            // Skip the sentinel target header
            if ($lowerName === strtolower(self::TARGET_HEADER)) {
                continue;
            }

            // Skip host header (will be set by HTTP client based on target URL)
            if ($lowerName === 'host') {
                continue;
            }

            // Use first value for each header
            $filtered[$name] = $values[0] ?? '';
        }

        return $filtered;
    }

    private function convertToSymfonyResponse(HttpResponse $httpResponse): Response
    {
        $headers = [];
        foreach ($httpResponse->headers as $name => $value) {
            $lowerName = strtolower($name);

            // Skip hop-by-hop headers in response
            if (in_array($lowerName, self::HOP_BY_HOP_HEADERS, true)) {
                continue;
            }

            $headers[$name] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        return new Response(
            $httpResponse->body,
            $httpResponse->statusCode,
            $headers
        );
    }

    private function createErrorResponse(int $statusCode, string $message): Response
    {
        return new Response(
            (string) json_encode([
                'error' => true,
                'message' => $message,
            ]),
            $statusCode,
            ['Content-Type' => 'application/json']
        );
    }

    private function createRateLimitResponse(int $retryAfterSeconds): Response
    {
        return new Response(
            (string) json_encode([
                'error' => true,
                'message' => 'Too many requests. Please try again later.',
            ]),
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'Content-Type' => 'application/json',
                'Retry-After' => (string) $retryAfterSeconds,
            ]
        );
    }

    private function extractHost(string $url): ?string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
}
