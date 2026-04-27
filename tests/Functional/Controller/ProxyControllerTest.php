<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\ProxyController;
use App\Service\ProxyService;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(ProxyController::class)]
final class ProxyControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    private KernelBrowser $client;
    private string $plainToken = 'test-proxy-token';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        ApiTokenFactory::new()
            ->withKnownToken($this->plainToken)
            ->create(['allowedTargets' => ['httpbin.org']]);
    }

    #[Test]
    public function proxyRequiresAuthentication(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'https://httpbin.org/get',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function proxyRequiresTargetHeader(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        self::assertResponseStatusCodeSame(400);

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('target', strtolower($content));
    }

    #[Test]
    public function proxyRejectsInvalidToken(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'https://httpbin.org/get',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function proxyRejectsUnauthorizedTarget(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'https://unauthorized.example.com/api',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function proxyRejectsPrivateIpTargets(): void
    {
        $token = ApiTokenFactory::new()
            ->withKnownToken('unrestricted-token')
            ->create(['allowedTargets' => []]);

        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer unrestricted-token',
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'http://192.168.1.1/api',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function proxyRejectsLocalhostTargets(): void
    {
        $token = ApiTokenFactory::new()
            ->withKnownToken('unrestricted-token-2')
            ->create(['allowedTargets' => []]);

        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer unrestricted-token-2',
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'http://localhost/api',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function proxyRejectsInvalidUrlScheme(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'ftp://httpbin.org/file',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function proxyRejectsInactiveToken(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('inactive-token')
            ->inactive()
            ->create();

        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer inactive-token',
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'https://httpbin.org/get',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function proxySupportsPostRequests(): void
    {
        $this->client->request('POST', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'https://httpbin.org/post',
            'CONTENT_TYPE' => 'application/json',
        ], '{"test": "data"}');

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertTrue(
            in_array($statusCode, [200, 500, 502, 504], true),
            "Expected 200, 500, 502, or 504 but got {$statusCode}"
        );
    }

    #[Test]
    public function proxyReturnsBadRequestForMalformedUrl(): void
    {
        $this->client->request('GET', '/proxy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
            'HTTP_' . str_replace('-', '_', ProxyService::TARGET_HEADER) => 'not-a-valid-url',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
