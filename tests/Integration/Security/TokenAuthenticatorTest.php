<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Security\TokenAuthenticator;
use App\Security\TokenAuthenticatorInterface;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class TokenAuthenticatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TokenAuthenticatorInterface $authenticator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticator = self::getContainer()->get(TokenAuthenticatorInterface::class);
    }

    #[Test]
    public function authenticateSucceedsWithValidActiveToken(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('test-token-123')
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer test-token-123');

        $result = $this->authenticator->authenticate($request);

        self::assertTrue($result->isAuthenticated);
        self::assertNotNull($result->token);
        self::assertNull($result->error);
    }

    #[Test]
    public function authenticateFailsWithInactiveToken(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('inactive-token')
            ->inactive()
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer inactive-token');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertNull($result->token);
        self::assertStringContainsString('Invalid', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWithNonExistentToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer non-existent-token');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('Invalid', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWithMissingAuthorizationHeader(): void
    {
        $request = Request::create('/api/test', 'GET');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('Authorization', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWithNonBearerToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
        self::assertStringContainsString('Authorization', $result->error ?? '');
    }

    #[Test]
    public function authenticateFailsWithEmptyBearerToken(): void
    {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ');

        $result = $this->authenticator->authenticate($request);

        self::assertFalse($result->isAuthenticated);
    }

    #[Test]
    public function authenticatedTokenHasCorrectAttributes(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('attr-test-token')
            ->withAllowedTargets(['api.example.com', '*.stripe.com'])
            ->create(['name' => 'Test API Token']);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer attr-test-token');

        $result = $this->authenticator->authenticate($request);

        self::assertTrue($result->isAuthenticated);
        self::assertNotNull($result->token);
        self::assertSame('Test API Token', $result->token->getName());
        self::assertTrue($result->token->isTargetAllowed('api.example.com'));
        self::assertTrue($result->token->isTargetAllowed('payments.stripe.com'));
        self::assertFalse($result->token->isTargetAllowed('evil.com'));
    }

    #[Test]
    public function tokenIsCachedAfterFirstLookup(): void
    {
        ApiTokenFactory::new()
            ->withKnownToken('cached-token')
            ->create();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer cached-token');

        $result1 = $this->authenticator->authenticate($request);
        $result2 = $this->authenticator->authenticate($request);

        self::assertTrue($result1->isAuthenticated);
        self::assertTrue($result2->isAuthenticated);
        self::assertSame($result1->token?->getId()->toRfc4122(), $result2->token?->getId()->toRfc4122());
    }
}
