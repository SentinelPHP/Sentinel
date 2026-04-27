<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\ApiToken;
use App\Enum\DataProtectionStrategy;
use App\Enum\LogLevel;
use App\Enum\TokenMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiToken::class)]
final class ApiTokenTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaultValues(): void
    {
        $token = new ApiToken();

        self::assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $token->getId());
        self::assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $token->getUpdatedAt());
        self::assertTrue($token->isActive());
        self::assertSame([], $token->getAllowedTargets());
        self::assertNull($token->getLogLevel());
        self::assertSame(TokenMode::Passive, $token->getMode());
        self::assertNull($token->getDataProtectionStrategy());
        self::assertNull($token->getCustomRedactionPatterns());
    }

    #[Test]
    public function settersAndGettersWork(): void
    {
        $token = new ApiToken();

        $token->setName('Test Token');
        $token->setTokenHash('abc123hash');
        $token->setAllowedTargets(['api.example.com']);
        $token->setIsActive(false);
        $token->setLogLevel(LogLevel::FullAudit);

        self::assertSame('Test Token', $token->getName());
        self::assertSame('abc123hash', $token->getTokenHash());
        self::assertSame(['api.example.com'], $token->getAllowedTargets());
        self::assertFalse($token->isActive());
        self::assertSame(LogLevel::FullAudit, $token->getLogLevel());
    }

    #[Test]
    public function modeGetterAndSetterWork(): void
    {
        $token = new ApiToken();

        $token->setMode(TokenMode::Learning);
        self::assertSame(TokenMode::Learning, $token->getMode());

        $token->setMode(TokenMode::Validating);
        self::assertSame(TokenMode::Validating, $token->getMode());

        $token->setMode(TokenMode::Passive);
        self::assertSame(TokenMode::Passive, $token->getMode());
    }

    #[Test]
    public function isTargetAllowedReturnsTrueWhenNoTargetsConfigured(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets([]);

        self::assertTrue($token->isTargetAllowed('any.host.com'));
        self::assertTrue($token->isTargetAllowed('another.host.org'));
    }

    #[Test]
    public function isTargetAllowedReturnsTrueForExactMatch(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets(['api.example.com', 'api.other.com']);

        self::assertTrue($token->isTargetAllowed('api.example.com'));
        self::assertTrue($token->isTargetAllowed('api.other.com'));
    }

    #[Test]
    public function isTargetAllowedReturnsFalseForNonMatchingHost(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets(['api.example.com']);

        self::assertFalse($token->isTargetAllowed('api.other.com'));
        self::assertFalse($token->isTargetAllowed('malicious.com'));
    }

    #[Test]
    public function isTargetAllowedSupportsWildcardPatterns(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets(['*.example.com']);

        self::assertTrue($token->isTargetAllowed('api.example.com'));
        self::assertTrue($token->isTargetAllowed('admin.example.com'));
        self::assertTrue($token->isTargetAllowed('sub.domain.example.com'));
    }

    #[Test]
    public function isTargetAllowedWildcardDoesNotMatchExactDomain(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets(['*.example.com']);

        self::assertFalse($token->isTargetAllowed('example.com'));
    }

    #[Test]
    public function isTargetAllowedIsCaseInsensitive(): void
    {
        $token = new ApiToken();
        $token->setAllowedTargets(['API.EXAMPLE.COM']);

        self::assertTrue($token->isTargetAllowed('api.example.com'));
        self::assertTrue($token->isTargetAllowed('Api.Example.Com'));
    }

    /**
     * @param list<string> $allowedTargets
     */
    #[Test]
    #[DataProvider('targetMatchingProvider')]
    public function isTargetAllowedMatchesCorrectly(array $allowedTargets, string $targetHost, bool $expected): void
    {
        $token = new ApiToken();
        /** @var list<string> $allowedTargets */
        $token->setAllowedTargets($allowedTargets);

        self::assertSame($expected, $token->isTargetAllowed($targetHost));
    }

    /**
     * @return iterable<string, array{allowedTargets: list<string>, targetHost: string, expected: bool}>
     */
    public static function targetMatchingProvider(): iterable
    {
        yield 'exact match' => [
            'allowedTargets' => ['api.stripe.com'],
            'targetHost' => 'api.stripe.com',
            'expected' => true,
        ];

        yield 'wildcard subdomain' => [
            'allowedTargets' => ['*.stripe.com'],
            'targetHost' => 'api.stripe.com',
            'expected' => true,
        ];

        yield 'multiple patterns - first matches' => [
            'allowedTargets' => ['api.stripe.com', '*.openai.com'],
            'targetHost' => 'api.stripe.com',
            'expected' => true,
        ];

        yield 'multiple patterns - second matches' => [
            'allowedTargets' => ['api.stripe.com', '*.openai.com'],
            'targetHost' => 'api.openai.com',
            'expected' => true,
        ];

        yield 'no match' => [
            'allowedTargets' => ['api.stripe.com'],
            'targetHost' => 'api.paypal.com',
            'expected' => false,
        ];

        yield 'wildcard does not match root' => [
            'allowedTargets' => ['*.stripe.com'],
            'targetHost' => 'stripe.com',
            'expected' => false,
        ];
    }

    #[Test]
    public function setAndGetDataProtectionStrategy(): void
    {
        $token = new ApiToken();

        self::assertNull($token->getDataProtectionStrategy());

        $result = $token->setDataProtectionStrategy(DataProtectionStrategy::Redact);

        self::assertSame($token, $result);
        self::assertSame(DataProtectionStrategy::Redact, $token->getDataProtectionStrategy());

        $token->setDataProtectionStrategy(DataProtectionStrategy::RedactEncrypt);
        self::assertSame(DataProtectionStrategy::RedactEncrypt, $token->getDataProtectionStrategy());

        $token->setDataProtectionStrategy(null);
        self::assertNull($token->getDataProtectionStrategy());
    }

    #[Test]
    public function setAndGetCustomRedactionPatterns(): void
    {
        $token = new ApiToken();
        $patterns = [
            'ssn' => '/\\d{3}-\\d{2}-\\d{4}/',
            'custom_key' => '/sk_[a-zA-Z0-9]+/',
        ];

        self::assertNull($token->getCustomRedactionPatterns());

        $result = $token->setCustomRedactionPatterns($patterns);

        self::assertSame($token, $result);
        self::assertSame($patterns, $token->getCustomRedactionPatterns());

        $token->setCustomRedactionPatterns(null);
        self::assertNull($token->getCustomRedactionPatterns());
    }
}
