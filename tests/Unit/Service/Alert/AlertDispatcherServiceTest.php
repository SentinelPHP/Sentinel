<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Alert;

use App\Entity\ApiSchema;
use App\Entity\ApiToken;
use App\Entity\SchemaDrift;
use SentinelPHP\Drift\Enum\DriftSeverity;
use SentinelPHP\Drift\Enum\DriftType;
use SentinelPHP\Dto\Enum\SchemaType;
use App\Message\AlertDispatchMessage;
use App\Service\Alert\AlertChannelInterface;
use App\Service\Alert\AlertDispatcherService;
use App\ValueObject\AlertResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SentinelPHP\Drift\ClassifierInterface;
use SentinelPHP\Drift\Enum\DriftSeverity as LibrarySeverity;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(AlertDispatcherService::class)]
#[AllowMockObjectsWithoutExpectations]
final class AlertDispatcherServiceTest extends TestCase
{
    private ClassifierInterface&MockObject $driftClassifier;
    private MessageBusInterface&MockObject $messageBus;

    protected function setUp(): void
    {
        $this->driftClassifier = $this->createMock(ClassifierInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
    }

    #[Test]
    public function itDispatchesToAllEnabledChannels(): void
    {
        $drift = $this->createDrift();

        $channel1 = $this->createMockChannel('slack', true);
        $channel2 = $this->createMockChannel('webhook', true);

        $channel1->expects(self::once())
            ->method('send')
            ->with($drift)
            ->willReturn(AlertResult::success('slack'));

        $channel2->expects(self::once())
            ->method('send')
            ->with($drift)
            ->willReturn(AlertResult::success('webhook'));

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$channel1, $channel2]);
        $result = $dispatcher->dispatch($drift);

        self::assertFalse($result->skippedDueToSeverity);
        self::assertSame(2, $result->getSuccessCount());
        self::assertSame(0, $result->getFailureCount());
    }

    #[Test]
    public function itSkipsDisabledChannels(): void
    {
        $drift = $this->createDrift();

        $enabledChannel = $this->createMockChannel('slack', true);
        $disabledChannel = $this->createMockChannel('webhook', false);

        $enabledChannel->expects(self::once())
            ->method('send')
            ->willReturn(AlertResult::success('slack'));

        $disabledChannel->expects(self::never())
            ->method('send');

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$enabledChannel, $disabledChannel]);
        $result = $dispatcher->dispatch($drift);

        self::assertSame(1, $result->getSuccessCount());
    }

    #[Test]
    public function itSkipsDispatchWhenSeverityBelowThreshold(): void
    {
        $drift = $this->createDrift(DriftSeverity::Info);

        $channel = $this->createMockChannel('slack', true);
        $channel->expects(self::never())->method('send');

        $this->driftClassifier
            ->expects(self::once())
            ->method('shouldAlert')
            ->with(LibrarySeverity::Info, null)
            ->willReturn(false);

        $dispatcher = $this->createDispatcher([$channel]);
        $result = $dispatcher->dispatch($drift);

        self::assertTrue($result->skippedDueToSeverity);
        self::assertSame(0, $result->getSuccessCount());
    }

    #[Test]
    public function itReturnsNoChannelsConfiguredWhenAllDisabled(): void
    {
        $drift = $this->createDrift();

        $channel1 = $this->createMockChannel('slack', false);
        $channel2 = $this->createMockChannel('webhook', false);

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$channel1, $channel2]);
        $result = $dispatcher->dispatch($drift);

        self::assertFalse($result->skippedDueToSeverity);
        self::assertSame(0, $result->getSuccessCount());
        self::assertCount(0, $result->results);
    }

    #[Test]
    public function itReturnsNoChannelsConfiguredWhenNoChannels(): void
    {
        $drift = $this->createDrift();

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([]);
        $result = $dispatcher->dispatch($drift);

        self::assertFalse($result->skippedDueToSeverity);
        self::assertCount(0, $result->results);
    }

    #[Test]
    public function itAggregatesSuccessesAndFailures(): void
    {
        $drift = $this->createDrift();

        $successChannel = $this->createMockChannel('slack', true);
        $failureChannel = $this->createMockChannel('webhook', true);

        $successChannel->method('send')
            ->willReturn(AlertResult::success('slack'));

        $failureChannel->method('send')
            ->willReturn(AlertResult::failure('webhook', 'Connection failed'));

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$successChannel, $failureChannel]);
        $result = $dispatcher->dispatch($drift);

        self::assertTrue($result->hasSuccesses());
        self::assertTrue($result->hasFailures());
        self::assertSame(1, $result->getSuccessCount());
        self::assertSame(1, $result->getFailureCount());
    }

    #[Test]
    public function itCatchesChannelExceptions(): void
    {
        $drift = $this->createDrift();

        $throwingChannel = $this->createMockChannel('slack', true);
        $workingChannel = $this->createMockChannel('webhook', true);

        $throwingChannel->method('send')
            ->willThrowException(new \RuntimeException('Unexpected error'));

        $workingChannel->method('send')
            ->willReturn(AlertResult::success('webhook'));

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$throwingChannel, $workingChannel]);
        $result = $dispatcher->dispatch($drift);

        self::assertSame(1, $result->getSuccessCount());
        self::assertSame(1, $result->getFailureCount());

        $failures = $result->getFailedResults();
        self::assertCount(1, $failures);
        self::assertSame('slack', $failures[0]->channelName);
        self::assertSame('Unexpected error', $failures[0]->errorMessage);
    }

    #[Test]
    public function itDispatchesAsyncMessage(): void
    {
        $drift = $this->createDrift();

        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (AlertDispatchMessage $message) use ($drift): bool {
                self::assertSame($drift->getId()->toRfc4122(), $message->driftId);
                return true;
            }))
            ->willReturn(new Envelope(new AlertDispatchMessage($drift->getId()->toRfc4122())));

        $dispatcher = $this->createDispatcher([]);
        $dispatcher->dispatchAsync($drift);
    }

    #[Test]
    public function itReturnsSuccessfulResultsOnly(): void
    {
        $drift = $this->createDrift();

        $channel1 = $this->createMockChannel('slack', true);
        $channel2 = $this->createMockChannel('webhook', true);
        $channel3 = $this->createMockChannel('email', true);

        $channel1->method('send')->willReturn(AlertResult::success('slack'));
        $channel2->method('send')->willReturn(AlertResult::failure('webhook', 'Failed'));
        $channel3->method('send')->willReturn(AlertResult::success('email'));

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$channel1, $channel2, $channel3]);
        $result = $dispatcher->dispatch($drift);

        $successes = $result->getSuccessfulResults();
        self::assertCount(2, $successes);
        self::assertSame('slack', $successes[0]->channelName);
        self::assertSame('email', $successes[1]->channelName);
    }

    #[Test]
    public function itReturnsFailedResultsOnly(): void
    {
        $drift = $this->createDrift();

        $channel1 = $this->createMockChannel('slack', true);
        $channel2 = $this->createMockChannel('webhook', true);

        $channel1->method('send')->willReturn(AlertResult::success('slack'));
        $channel2->method('send')->willReturn(AlertResult::failure('webhook', 'Timeout'));

        $this->driftClassifier->method('shouldAlert')->willReturn(true);

        $dispatcher = $this->createDispatcher([$channel1, $channel2]);
        $result = $dispatcher->dispatch($drift);

        $failures = $result->getFailedResults();
        self::assertCount(1, $failures);
        self::assertSame('webhook', $failures[0]->channelName);
        self::assertSame('Timeout', $failures[0]->errorMessage);
    }

    #[Test]
    public function itUsesTokenThresholdForSeverityCheck(): void
    {
        $token = new ApiToken();
        $token->setName('test-token');
        $token->setAlertMinSeverity(DriftSeverity::Critical);

        $drift = $this->createDriftWithToken($token, DriftSeverity::Warning);

        $channel = $this->createMockChannel('slack', true);
        $channel->expects(self::never())->method('send');

        $this->driftClassifier
            ->expects(self::once())
            ->method('shouldAlert')
            ->with(LibrarySeverity::Warning, LibrarySeverity::Critical)
            ->willReturn(false);

        $dispatcher = $this->createDispatcher([$channel]);
        $result = $dispatcher->dispatch($drift);

        self::assertTrue($result->skippedDueToSeverity);
    }

    /**
     * @param list<AlertChannelInterface> $channels
     */
    private function createDispatcher(array $channels): AlertDispatcherService
    {
        return new AlertDispatcherService(
            $channels,
            $this->driftClassifier,
            $this->messageBus,
            new NullLogger(),
        );
    }

    private function createMockChannel(string $name, bool $enabled): AlertChannelInterface&MockObject
    {
        $channel = $this->createMock(AlertChannelInterface::class);
        $channel->method('getName')->willReturn($name);
        $channel->method('isEnabled')->willReturn($enabled);

        return $channel;
    }

    private function createDrift(DriftSeverity $severity = DriftSeverity::Warning): SchemaDrift
    {
        $token = new ApiToken();
        $token->setName('test-token');

        return $this->createDriftWithToken($token, $severity);
    }

    private function createDriftWithToken(ApiToken $token, DriftSeverity $severity): SchemaDrift
    {
        $schema = new ApiSchema();
        $schema->setToken($token);
        $schema->setTargetHost('api.example.com');
        $schema->setEndpointPath('/users');
        $schema->setHttpMethod('GET');
        $schema->setSchemaType(SchemaType::Response);
        $schema->setJsonSchema(['type' => 'object']);
        $schema->setVersion(1);

        $drift = new SchemaDrift();
        $drift->setSchema($schema);
        $drift->setToken($token);
        $drift->setDriftType(DriftType::TypeChanged);
        $drift->setPath('$.data.user.email');
        $drift->setExpectedValue(['type' => 'string']);
        $drift->setActualValue(['type' => 'integer']);
        $drift->setSeverity($severity);

        return $drift;
    }
}
