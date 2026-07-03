<?php

declare(strict_types=1);

namespace SentinelPHP\Core\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SentinelPHP\Core\Client\IdGeneratorInterface;
use SentinelPHP\Core\SentinelInterceptor;

/**
 * Guzzle middleware that intercepts all requests and responses.
 *
 * Usage:
 * ```php
 * $stack = HandlerStack::create();
 * $stack->push(GuzzleMiddleware::create($interceptor));
 * $client = new Client(['handler' => $stack]);
 * ```
 */
final class GuzzleMiddleware
{
    public function __construct(
        private readonly SentinelInterceptor $interceptor,
        private readonly ?IdGeneratorInterface $idGenerator = null,
    ) {
    }

    /**
     * Create a middleware callable for use with Guzzle HandlerStack.
     */
    public static function create(
        SentinelInterceptor $interceptor,
        ?IdGeneratorInterface $idGenerator = null,
    ): callable {
        $middleware = new self($interceptor, $idGenerator);

        return static fn (callable $handler): callable => $middleware->handle($handler);
    }

    /**
     * @return callable(RequestInterface, array<string, mixed>): PromiseInterface
     */
    public function handle(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $startTime = hrtime(true);

            /** @var PromiseInterface $promise */
            $promise = $handler($request, $options);

            return $promise->then(
                function (ResponseInterface $response) use ($request, $startTime): ResponseInterface {
                    $latencyMs = (hrtime(true) - $startTime) / 1_000_000;

                    /** @var array<string, array<int, string>> $requestHeaders */
                    $requestHeaders = $request->getHeaders();
                    /** @var array<string, array<int, string>> $responseHeaders */
                    $responseHeaders = $response->getHeaders();

                    $this->interceptor->intercept(
                        method: $request->getMethod(),
                        url: (string) $request->getUri(),
                        statusCode: $response->getStatusCode(),
                        latencyMs: $latencyMs,
                        requestHeaders: $this->flattenHeaders($requestHeaders),
                        requestBody: (string) $request->getBody(),
                        responseHeaders: $this->flattenHeaders($responseHeaders),
                        responseBody: (string) $response->getBody(),
                        id: $this->idGenerator?->generate(),
                    );

                    // Rewind response body so it can be read again
                    $response->getBody()->rewind();

                    return $response;
                }
            );
        };
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, string|list<string>>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = [];

        foreach ($headers as $name => $values) {
            /** @var list<string> $valueList */
            $valueList = array_values($values);
            $flattened[$name] = count($valueList) === 1 ? $valueList[0] : $valueList;
        }

        return $flattened;
    }
}
