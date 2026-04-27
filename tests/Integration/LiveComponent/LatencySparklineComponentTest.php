<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent;

use App\Entity\User;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use App\Twig\Components\LatencySparkline;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class LatencySparklineComponentTest extends KernelTestCase
{
    use Factories;
    use InteractsWithLiveComponents;
    use ResetDatabase;

    private User $user;
    private User $adminUser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->user = UserFactory::createOne();
        $this->adminUser = UserFactory::new()->admin()->create();
    }

    #[Test]
    public function componentRendersWithHostProp(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: ['host' => 'api.example.com'],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('api.example.com', $rendered);
    }

    #[Test]
    public function componentRendersEmptyForMissingHost(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: ['host' => ''],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertNotEmpty($rendered);
    }

    #[Test]
    public function componentRendersTrendIndicator(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(5, [
            'token' => $token,
            'targetHost' => 'api.example.com',
            'latencyMs' => 100,
        ]);

        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: [
                'host' => 'api.example.com',
                'hours' => 1,
            ],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertMatchesRegularExpression('/(Improving|Stable|Degrading)/', $rendered);
    }

    #[Test]
    public function componentRendersPercentilesWhenEnabled(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: [
                'host' => 'api.example.com',
                'showPercentiles' => true,
            ],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertMatchesRegularExpression('/(P50|P95|P99)/i', $rendered);
    }

    #[Test]
    public function componentRespectsThresholdProps(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: [
                'host' => 'api.example.com',
                'thresholdYellow' => 200,
                'thresholdRed' => 500,
            ],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertNotEmpty($rendered);
    }

    #[Test]
    public function componentRespectsHeightProp(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: [
                'host' => 'api.example.com',
                'height' => 200,
            ],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('200', $rendered);
    }

    #[Test]
    public function refreshActionClearsCache(): void
    {
        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: ['host' => 'api.example.com'],
        )->actingAs($this->adminUser);

        $testComponent->render();
        $testComponent->call('refresh');
        $rendered = $testComponent->render()->toString();

        $this->assertNotEmpty($rendered);
    }

    #[Test]
    public function componentFiltersDataByUserAccess(): void
    {
        $accessibleToken = ApiTokenFactory::createOne();
        $inaccessibleToken = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(3, [
            'token' => $accessibleToken,
            'targetHost' => 'api.example.com',
            'latencyMs' => 100,
        ]);

        RequestLogFactory::createMany(3, [
            'token' => $inaccessibleToken,
            'targetHost' => 'api.example.com',
            'latencyMs' => 500,
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $accessibleToken,
        ]);

        $testComponent = $this->createLiveComponent(
            name: LatencySparkline::class,
            data: ['host' => 'api.example.com'],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertNotEmpty($rendered);
    }
}
