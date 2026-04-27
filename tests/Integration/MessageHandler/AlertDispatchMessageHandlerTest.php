<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use SentinelPHP\Drift\Enum\DriftSeverity;
use App\Message\AlertDispatchMessage;
use App\MessageHandler\AlertDispatchMessageHandler;
use App\Tests\Factories\ApiSchemaFactory;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\SchemaDriftFactory;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AlertDispatchMessageHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private AlertDispatchMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->handler = self::getContainer()->get(AlertDispatchMessageHandler::class);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function handlerProcessesDriftWithoutError(): void
    {
        $token = ApiTokenFactory::createOne([
            'alertMinSeverity' => DriftSeverity::Warning,
        ]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $drift = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
        ]);

        $message = new AlertDispatchMessage($drift->getId()->toRfc4122());

        ($this->handler)($message);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function handlerSkipsNonExistentDrift(): void
    {
        $nonExistentId = Uuid::v7()->toRfc4122();
        $message = new AlertDispatchMessage($nonExistentId);

        ($this->handler)($message);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function handlerProcessesDriftBelowSeverityThreshold(): void
    {
        $token = ApiTokenFactory::createOne([
            'alertMinSeverity' => DriftSeverity::Critical,
        ]);
        $schema = ApiSchemaFactory::createOne(['token' => $token]);
        $drift = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Info,
        ]);

        $message = new AlertDispatchMessage($drift->getId()->toRfc4122());

        ($this->handler)($message);
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function handlerProcessesMultipleDriftsIndependently(): void
    {
        $token = ApiTokenFactory::createOne();
        $schema = ApiSchemaFactory::createOne(['token' => $token]);

        $drift1 = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Warning,
        ]);
        $drift2 = SchemaDriftFactory::createOne([
            'token' => $token,
            'schema' => $schema,
            'severity' => DriftSeverity::Critical,
        ]);

        ($this->handler)(new AlertDispatchMessage($drift1->getId()->toRfc4122()));
        ($this->handler)(new AlertDispatchMessage($drift2->getId()->toRfc4122()));
    }
}
