<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\ApiToken;
use App\Message\SchemaLearnMessage;
use App\MessageHandler\SchemaLearnMessageHandler;
use App\Service\SchemaLearningServiceInterface;
use App\Tests\Factories\ApiTokenFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use SentinelPHP\Redact\PiiRedactorInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SchemaLearnMessageHandler::class)]
#[AllowMockObjectsWithoutExpectations]
final class SchemaLearnMessageHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private SchemaLearningServiceInterface&MockObject $schemaLearningService;
    private PiiRedactorInterface&MockObject $piiRedactor;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->schemaLearningService = $this->createMock(SchemaLearningServiceInterface::class);
        $this->piiRedactor = $this->createMock(PiiRedactorInterface::class);
    }

    private function createHandler(): SchemaLearnMessageHandler
    {
        return new SchemaLearnMessageHandler(
            $this->schemaLearningService,
            self::getContainer()->get('App\Repository\ApiTokenRepository'),
            $this->piiRedactor,
        );
    }

    #[Test]
    public function invokeCallsSchemaLearningService(): void
    {
        $token = ApiTokenFactory::createOne();
        $tokenId = $token->getId()->toRfc4122();

        $this->piiRedactor->method('redact')->willReturnArgument(0);

        $expectedTokenId = $token->getId();
        $this->schemaLearningService->expects($this->once())
            ->method('learn')
            ->with(
                $this->callback(fn (ApiToken $t): bool => $t->getId()->equals($expectedTokenId)),
                'api.example.com',
                '/users',
                'GET',
                '{"id": 1, "name": "John"}'
            );

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: $tokenId,
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: '{"id": 1, "name": "John"}',
        );

        $handler($message);
    }

    #[Test]
    public function invokeDoesNothingWhenTokenNotFound(): void
    {
        $this->schemaLearningService->expects($this->never())->method('learn');

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: '00000000-0000-0000-0000-000000000000',
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: '{"id": 1}',
        );

        $handler($message);
    }

    #[Test]
    public function invokeRedactsResponseBodyBeforeLearning(): void
    {
        $token = ApiTokenFactory::createOne();
        $tokenId = $token->getId()->toRfc4122();

        $originalBody = '{"email": "john@example.com", "ssn": "123-45-6789"}';
        $redactedBody = '{"email": "[REDACTED]", "ssn": "[REDACTED]"}';

        $this->piiRedactor->expects($this->once())
            ->method('redact')
            ->with($originalBody, null, null)
            ->willReturn($redactedBody);

        $this->schemaLearningService->expects($this->once())
            ->method('learn')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $redactedBody
            );

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: $tokenId,
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: $originalBody,
        );

        $handler($message);
    }

    #[Test]
    public function invokeUsesCustomRedactionPatterns(): void
    {
        $token = ApiTokenFactory::createOne([
            'customRedactionPatterns' => ['custom_field' => '/custom-pattern/'],
        ]);
        $tokenId = $token->getId()->toRfc4122();

        $this->piiRedactor->expects($this->once())
            ->method('redact')
            ->with(
                $this->anything(),
                null,
                ['custom_field' => '/custom-pattern/']
            )
            ->willReturn('{}');

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: $tokenId,
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: '{"custom_field": "value"}',
        );

        $handler($message);
    }

    #[Test]
    public function invokeHandlesEmptyResponseBody(): void
    {
        $token = ApiTokenFactory::createOne();
        $tokenId = $token->getId()->toRfc4122();

        $this->piiRedactor->expects($this->never())->method('redact');

        $this->schemaLearningService->expects($this->once())
            ->method('learn')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                ''
            );

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: $tokenId,
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: '',
        );

        $handler($message);
    }

    #[Test]
    public function invokeHandlesRedactorReturningArray(): void
    {
        $token = ApiTokenFactory::createOne();
        $tokenId = $token->getId()->toRfc4122();

        $this->piiRedactor->method('redact')->willReturn(['redacted' => 'data']);

        $this->schemaLearningService->expects($this->once())
            ->method('learn')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                '{"redacted":"data"}'
            );

        $handler = $this->createHandler();
        $message = new SchemaLearnMessage(
            tokenId: $tokenId,
            targetHost: 'api.example.com',
            path: '/users',
            method: 'GET',
            responseBody: '{"original": "data"}',
        );

        $handler($message);
    }
}
