<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\ApiToken;
use App\Http\HttpClientException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Security\TokenAuthenticationResult;
use App\Security\TokenAuthenticatorInterface;
use App\Service\ProxyService;
use App\Service\RequestContext;
use App\Storage\MessengerStorageFactory;
use App\Validation\TargetUrlValidationResultWithIp;
use App\Validation\TargetUrlValidatorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ProxyService::class)]
#[AllowMockObjectsWithoutExpectations]
final class ProxyServiceTest extends TestCase
{
    private HttpClientInterface&MockObject $httpClient;
    private TargetUrlValidatorInterface&MockObject $targetUrlValidator;
    private TokenAuthenticatorInterface&MockObject $tokenAuthenticator;
    private MessengerStorageFactory $storageFactory;
    private RequestContext $requestContext;
    private ApiToken&MockObject $apiToken;
    private ProxyService $proxyService;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->targetUrlValidator = $this->createMock(TargetUrlValidatorInterface::class);
        $this->tokenAuthenticator = $this->createMock(TokenAuthenticatorInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $this->requestContext = new RequestContext();
        $this->requestContext->initialize();
        $this->apiToken = $this->createMock(ApiToken::class);

        $messageBus->method('dispatch')->willReturnCallback(
            fn (object $message) => new Envelope($message)
        );

        $this->storageFactory = new MessengerStorageFactory($messageBus);

        $this->proxyService = new ProxyService(
            $this->httpClient,
            $this->targetUrlValidator,
            $this->tokenAuthenticator,
            $this->storageFactory,
            $this->requestContext,
        );

        $this->targetUrlValidator
            ->method('validateWithResolvedIp')
            ->willReturn(TargetUrlValidationResultWithIp::valid(null));

        $this->apiToken->method('isActive')->willReturn(true);
        $this->apiToken->method('isTargetAllowed')->willReturn(true);
        $this->apiToken->method('getLogLevel')->willReturn(null);

        $this->tokenAuthenticator
            ->method('authenticate')
            ->willReturn(TokenAuthenticationResult::success($this->apiToken));
    }

    #[Test]
    public function proxyReturnsBadRequestWhenTargetHeaderMissing(): void
    {
        $request = Request::create('/api/users', 'GET');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        /** @var string $message */
        $message = $content['message'];
        self::assertStringContainsString('X-Sentinel-Target', $message);
    }

    #[Test]
    public function proxyReturnsBadRequestWhenTargetHeaderEmpty(): void
    {
        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Sentinel-Target', '');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    #[Test]
    public function proxyReturnsForbiddenWhenTargetUrlInvalid(): void
    {
        $validator = $this->createMock(TargetUrlValidatorInterface::class);
        $validator->method('validateWithResolvedIp')
            ->willReturn(TargetUrlValidationResultWithIp::invalid('Target resolves to a private IP address'));

        $proxyService = new ProxyService($this->httpClient, $validator, $this->tokenAuthenticator, $this->storageFactory, $this->requestContext);

        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Sentinel-Target', 'http://192.168.1.1/internal');

        $response = $proxyService->proxy($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        /** @var string $message */
        $message = $content['message'];
        self::assertStringContainsString('private IP', $message);
    }

    #[Test]
    public function proxyForwardsGetRequestToTarget(): void
    {
        $request = Request::create('/api/users', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/users');
        $request->headers->set('Authorization', 'Bearer token123');

        $targetResponse = new HttpResponse(
            200,
            ['Content-Type' => 'application/json'],
            '{"users": []}'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.example.com/users',
                self::callback(function (array $headers): bool {
                    return isset($headers['authorization'])
                        && $headers['authorization'] === 'Bearer token123'
                        && !isset($headers['x-sentinel-target']);
                }),
                null
            )
            ->willReturn($targetResponse);

        $response = $this->proxyService->proxy($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('{"users": []}', $response->getContent());
    }

    #[Test]
    public function proxyForwardsPostRequestWithBody(): void
    {
        $request = Request::create(
            '/api/users',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name": "John"}'
        );
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/users');

        $targetResponse = new HttpResponse(
            201,
            ['Content-Type' => 'application/json', 'Location' => '/users/1'],
            '{"id": 1, "name": "John"}'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.example.com/users',
                self::anything(),
                '{"name": "John"}'
            )
            ->willReturn($targetResponse);

        $response = $this->proxyService->proxy($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/users/1', $response->headers->get('Location'));
    }

    #[Test]
    public function proxyFiltersHopByHopHeaders(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');
        $request->headers->set('Connection', 'keep-alive');
        $request->headers->set('Keep-Alive', 'timeout=5');
        $request->headers->set('Transfer-Encoding', 'chunked');
        $request->headers->set('X-Custom-Header', 'custom-value');

        $targetResponse = new HttpResponse(200, [], 'OK');

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.example.com/test',
                self::callback(function (array $headers): bool {
                    $lowerKeys = array_map('strtolower', array_keys($headers));
                    return !in_array('connection', $lowerKeys, true)
                        && !in_array('keep-alive', $lowerKeys, true)
                        && !in_array('transfer-encoding', $lowerKeys, true)
                        && in_array('x-custom-header', $lowerKeys, true);
                }),
                null
            )
            ->willReturn($targetResponse);

        $this->proxyService->proxy($request);
    }

    #[Test]
    public function proxyFiltersHopByHopHeadersFromResponse(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');

        $targetResponse = new HttpResponse(
            200,
            [
                'Content-Type' => 'text/plain',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                'X-Response-Header' => 'value',
            ],
            'OK'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($targetResponse);

        $response = $this->proxyService->proxy($request);

        self::assertSame('text/plain', $response->headers->get('Content-Type'));
        self::assertSame('value', $response->headers->get('X-Response-Header'));
        self::assertNull($response->headers->get('Connection'));
        self::assertNull($response->headers->get('Transfer-Encoding'));
    }

    #[Test]
    public function proxyPassesThroughTargetErrorResponses(): void
    {
        $request = Request::create('/api/users/999', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/users/999');

        $targetResponse = new HttpResponse(
            404,
            ['Content-Type' => 'application/json'],
            '{"error": "Not found"}'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willReturn($targetResponse);

        $response = $this->proxyService->proxy($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error": "Not found"}', $response->getContent());
    }

    #[Test]
    public function proxyReturnsBadGatewayOnConnectionError(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://unreachable.example.com/test');

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->willThrowException(HttpClientException::connectionFailed(
                'unreachable.example.com',
                443,
                'Connection refused'
            ));

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        /** @var string $message */
        $message = $content['message'];
        self::assertStringContainsString('Failed to reach target', $message);
    }
}
