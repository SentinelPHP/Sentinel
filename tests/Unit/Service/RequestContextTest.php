<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        $this->context = new RequestContext();
    }

    #[Test]
    public function initializeGeneratesRequestId(): void
    {
        self::assertNull($this->context->getRequestId());

        $this->context->initialize();

        $requestId = $this->context->getRequestId();
        self::assertNotNull($requestId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        );
    }

    #[Test]
    public function setTokenIdStoresTokenId(): void
    {
        $tokenId = '550e8400-e29b-41d4-a716-446655440000';

        $this->context->setTokenId($tokenId);

        self::assertSame($tokenId, $this->context->getTokenId());
    }

    #[Test]
    public function setTokenIdAcceptsNull(): void
    {
        $this->context->setTokenId('some-id');
        $this->context->setTokenId(null);

        self::assertNull($this->context->getTokenId());
    }

    #[Test]
    public function setTargetUrlStoresUrlAndExtractsHost(): void
    {
        $url = 'https://api.example.com/v1/users?page=1';

        $this->context->setTargetUrl($url);

        self::assertSame($url, $this->context->getTargetUrl());
        self::assertSame('api.example.com', $this->context->getTargetHost());
    }

    #[Test]
    public function setTargetUrlHandlesUrlWithoutHost(): void
    {
        $url = '/relative/path';

        $this->context->setTargetUrl($url);

        self::assertSame($url, $this->context->getTargetUrl());
        self::assertNull($this->context->getTargetHost());
    }

    #[Test]
    public function setTargetUrlAcceptsNull(): void
    {
        $this->context->setTargetUrl('https://example.com');
        $this->context->setTargetUrl(null);

        self::assertNull($this->context->getTargetUrl());
        self::assertNull($this->context->getTargetHost());
    }

    #[Test]
    public function toArrayReturnsAllFields(): void
    {
        $this->context->initialize();
        $this->context->setTokenId('token-123');
        $this->context->setTargetUrl('https://api.example.com/test');

        $array = $this->context->toArray();

        self::assertArrayHasKey('request_id', $array);
        self::assertArrayHasKey('token_id', $array);
        self::assertArrayHasKey('target_url', $array);
        self::assertArrayHasKey('target_host', $array);
        self::assertSame('token-123', $array['token_id']);
        self::assertSame('https://api.example.com/test', $array['target_url']);
        self::assertSame('api.example.com', $array['target_host']);
    }

    #[Test]
    public function toLogContextFiltersNullValues(): void
    {
        $this->context->initialize();
        $this->context->setTokenId('token-123');

        $logContext = $this->context->toLogContext();

        self::assertArrayHasKey('request_id', $logContext);
        self::assertArrayHasKey('token_id', $logContext);
        self::assertArrayNotHasKey('target_url', $logContext);
        self::assertArrayNotHasKey('target_host', $logContext);
    }

    #[Test]
    public function resetClearsAllFields(): void
    {
        $this->context->initialize();
        $this->context->setTokenId('token-123');
        $this->context->setTargetUrl('https://api.example.com');

        $this->context->reset();

        self::assertNull($this->context->getRequestId());
        self::assertNull($this->context->getTokenId());
        self::assertNull($this->context->getTargetUrl());
        self::assertNull($this->context->getTargetHost());
    }

    #[Test]
    public function multipleInitializeGeneratesDifferentIds(): void
    {
        $this->context->initialize();
        $firstId = $this->context->getRequestId();

        $this->context->reset();
        $this->context->initialize();
        $secondId = $this->context->getRequestId();

        self::assertNotSame($firstId, $secondId);
    }
}
