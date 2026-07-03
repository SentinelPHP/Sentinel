<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Enum\TokenMode;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;
use App\Service\ProxyService;
use App\Service\RequestContext;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ProxyService::class)]
final class ProxyServiceIntegrationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private HttpClientInterface&MockObject $httpClient;
    private ProxyService $proxyService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->httpClient = $this->createMock(HttpClientInterface::class);

        $container->set(HttpClientInterface::class, $this->httpClient);

        /** @var ProxyService $proxyService */
        $proxyService = $container->get(ProxyService::class);
        $this->proxyService = $proxyService;

        /** @var RequestContext $requestContext */
        $requestContext = $container->get(RequestContext::class);
        $requestContext->initialize();
    }

    #[Test]
    public function fullProxyCycleWithRealTokenAndServices(): void
    {
        $plainToken = 'test-token-' . bin2hex(random_bytes(16));
        $token = ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets(['api.example.com'])
            ->withMode(TokenMode::Passive)
            ->create();

        $targetResponse = new HttpResponse(
            200,
            ['Content-Type' => 'application/json', 'X-Request-Id' => 'abc123'],
            '{"data": "success"}'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'POST',
                'https://api.example.com/v1/users',
                self::callback(fn(array $headers) => isset($headers['content-type'])),
                '{"name": "John"}',
                self::anything()
            )
            ->willReturn($targetResponse);

        $request = Request::create(
            '/v1/users',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name": "John"}'
        );
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/v1/users');

        $response = $this->proxyService->proxy($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('abc123', $response->headers->get('X-Request-Id'));
        self::assertSame('{"data": "success"}', $response->getContent());
    }

    #[Test]
    public function proxyCycleRejectsUnauthenticatedRequest(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
    }

    #[Test]
    public function proxyCycleRejectsInvalidToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer invalid-token-that-does-not-exist');
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        self::assertIsString($content['message']);
        self::assertStringContainsString('Invalid', $content['message']);
    }

    #[Test]
    public function proxyCycleRejectsInactiveToken(): void
    {
        $plainToken = 'inactive-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->inactive()
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
    }

    #[Test]
    public function proxyCycleRejectsDisallowedTarget(): void
    {
        $plainToken = 'restricted-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets(['api.allowed.com'])
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.malicious.com/test');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        self::assertIsString($content['message']);
        self::assertStringContainsString('not allowed', $content['message']);
    }

    #[Test]
    public function proxyCycleRejectsPrivateIpTarget(): void
    {
        $plainToken = 'test-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'http://192.168.1.1/internal');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        self::assertIsString($content['message']);
        self::assertStringContainsString('private', strtolower($content['message']));
    }

    #[Test]
    public function proxyCycleRejectsLocalhostTarget(): void
    {
        $plainToken = 'test-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'http://localhost/admin');

        $response = $this->proxyService->proxy($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());

        $content = json_decode((string) $response->getContent(), true);
        self::assertIsArray($content);
        self::assertTrue($content['error']);
        self::assertIsString($content['message']);
        self::assertStringContainsString('blocked', strtolower($content['message']));
    }

    #[Test]
    public function proxyCyclePreservesTargetErrorResponses(): void
    {
        $plainToken = 'test-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets(['api.example.com'])
            ->create();

        $targetResponse = new HttpResponse(
            404,
            ['Content-Type' => 'application/json'],
            '{"error": "Resource not found"}'
        );

        $this->httpClient->method('request')->willReturn($targetResponse);

        $request = Request::create('/api/users/999', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/users/999');

        $response = $this->proxyService->proxy($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error": "Resource not found"}', $response->getContent());
    }

    #[Test]
    public function proxyCycleStripsHopByHopHeaders(): void
    {
        $plainToken = 'test-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets(['api.example.com'])
            ->create();

        $targetResponse = new HttpResponse(
            200,
            [
                'Content-Type' => 'text/plain',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                'X-Custom-Header' => 'preserved',
            ],
            'OK'
        );

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with(
                'GET',
                'https://api.example.com/test',
                self::callback(function (array $headers): bool {
                    $lowerKeys = array_map('strtolower', array_keys($headers));
                    return !in_array('connection', $lowerKeys, true)
                        && !in_array('transfer-encoding', $lowerKeys, true);
                }),
                null,
                self::anything()
            )
            ->willReturn($targetResponse);

        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.example.com/test');
        $request->headers->set('Connection', 'keep-alive');
        $request->headers->set('Transfer-Encoding', 'chunked');

        $response = $this->proxyService->proxy($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('preserved', $response->headers->get('X-Custom-Header'));
        self::assertNull($response->headers->get('Connection'));
        self::assertNull($response->headers->get('Transfer-Encoding'));
    }

    #[Test]
    public function proxyCycleWithWildcardTargetAllowsMatchingHosts(): void
    {
        $plainToken = 'wildcard-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets(['*.stripe.com'])
            ->create();

        $targetResponse = new HttpResponse(200, [], '{"ok": true}');
        $this->httpClient->method('request')->willReturn($targetResponse);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://api.stripe.com/v1/charges');

        $response = $this->proxyService->proxy($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function proxyCycleWithEmptyAllowedTargetsAllowsAllHosts(): void
    {
        $plainToken = 'unrestricted-token-' . bin2hex(random_bytes(16));
        ApiTokenFactory::new()
            ->withKnownToken($plainToken)
            ->withAllowedTargets([])
            ->create();

        $targetResponse = new HttpResponse(200, [], '{"ok": true}');
        $this->httpClient->method('request')->willReturn($targetResponse);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Sentinel-Target', 'https://any-external-api.com/endpoint');

        $response = $this->proxyService->proxy($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
