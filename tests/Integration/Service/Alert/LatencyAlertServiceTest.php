<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Alert;

use App\Redis\RedisClientInterface;
use App\Service\Alert\LatencyAlertService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(LatencyAlertService::class)]
final class LatencyAlertServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private RedisClientInterface $redisClient;
    private string $testId;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var RedisClientInterface $redisClient */
        $redisClient = self::getContainer()->get(RedisClientInterface::class);
        $this->redisClient = $redisClient;

        // Unique ID to avoid Redis key collisions between test runs
        $this->testId = bin2hex(random_bytes(4));
    }

    private function createService(
        int $defaultWarningThreshold = 500,
        int $defaultCriticalThreshold = 1000,
    ): LatencyAlertService {
        return new LatencyAlertService(
            self::getContainer()->get('App\Repository\AlertConfigurationRepository'),
            $this->redisClient,
            null,
            $defaultWarningThreshold,
            $defaultCriticalThreshold,
        );
    }

    #[Test]
    public function checkAndAlertReturnsFalseWhenBelowWarningThreshold(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        self::assertFalse($service->checkAndAlert("api-below-{$this->testId}.example.com", 400));
    }

    #[Test]
    public function checkAndAlertReturnsTrueWhenAboveWarningThreshold(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        self::assertTrue($service->checkAndAlert("api-warning-{$this->testId}.example.com", 600));
    }

    #[Test]
    public function checkAndAlertReturnsFalseWhenInCooldown(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        // First call sets cooldown
        $service->checkAndAlert("api-cooldown-{$this->testId}.example.com", 600);

        // Second call should be in cooldown
        self::assertFalse($service->checkAndAlert("api-cooldown-{$this->testId}.example.com", 600));
    }

    #[Test]
    public function getThresholdsReturnsDefaultsWhenNoConfig(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500, defaultCriticalThreshold: 1000);
        $thresholds = $service->getThresholds("api-thresholds-{$this->testId}.example.com");

        self::assertSame(500, $thresholds['warning']);
        self::assertSame(1000, $thresholds['critical']);
    }

    #[Test]
    public function exceedsWarningThresholdReturnsTrueWhenAbove(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        self::assertTrue($service->exceedsWarningThreshold("api-warn-above-{$this->testId}.example.com", 500));
        self::assertTrue($service->exceedsWarningThreshold("api-warn-above-{$this->testId}.example.com", 600));
    }

    #[Test]
    public function exceedsWarningThresholdReturnsFalseWhenBelow(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        self::assertFalse($service->exceedsWarningThreshold("api-warn-below-{$this->testId}.example.com", 499));
    }

    #[Test]
    public function exceedsCriticalThresholdReturnsTrueWhenAbove(): void
    {
        $service = $this->createService(defaultCriticalThreshold: 1000);

        self::assertTrue($service->exceedsCriticalThreshold("api-crit-above-{$this->testId}.example.com", 1000));
        self::assertTrue($service->exceedsCriticalThreshold("api-crit-above-{$this->testId}.example.com", 1500));
    }

    #[Test]
    public function exceedsCriticalThresholdReturnsFalseWhenBelow(): void
    {
        $service = $this->createService(defaultCriticalThreshold: 1000);

        self::assertFalse($service->exceedsCriticalThreshold("api-crit-below-{$this->testId}.example.com", 999));
    }

    #[Test]
    public function checkAndAlertAcceptsTokenId(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        // Should not throw when tokenId is provided
        $result = $service->checkAndAlert("api-tokenid-{$this->testId}.example.com", 600, 'token-123');
        self::assertTrue($result);
    }

    #[Test]
    public function cooldownWorksWithPortInHostname(): void
    {
        $service = $this->createService(defaultWarningThreshold: 500);

        // First call should succeed
        $result1 = $service->checkAndAlert("api-{$this->testId}.example.com:8080", 600);
        self::assertTrue($result1);

        // Second call should be in cooldown
        $result2 = $service->checkAndAlert("api-{$this->testId}.example.com:8080", 600);
        self::assertFalse($result2);
    }
}
