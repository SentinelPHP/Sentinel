<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent;

use App\Entity\User;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\RequestLogFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use App\Twig\Components\ServiceHealthGrid;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ServiceHealthGridComponentTest extends KernelTestCase
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
    public function componentRendersForAuthenticatedUser(): void
    {
        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertNotEmpty($rendered);
    }

    #[Test]
    public function componentRendersEmptyGridForUserWithNoTokenAccess(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createOne([
            'token' => $token,
            'targetHost' => 'api.example.com',
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertStringNotContainsString('api.example.com', $rendered);
    }

    #[Test]
    public function componentRendersServicesForAdmin(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(5, [
            'token' => $token,
            'targetHost' => 'api.example.com',
            'latencyMs' => 100,
            'responseStatusCode' => 200,
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('api.example.com', $rendered);
    }

    #[Test]
    public function componentRendersServicesForUserWithAccess(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(5, [
            'token' => $token,
            'targetHost' => 'api.example.com',
            'latencyMs' => 100,
            'responseStatusCode' => 200,
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('api.example.com', $rendered);
    }

    #[Test]
    public function componentRendersStatusCounts(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(10, [
            'token' => $token,
            'targetHost' => 'healthy.example.com',
            'latencyMs' => 50,
            'responseStatusCode' => 200,
        ]);

        RequestLogFactory::createMany(10, [
            'token' => $token,
            'targetHost' => 'slow.example.com',
            'latencyMs' => 800,
            'responseStatusCode' => 200,
        ]);

        RequestLogFactory::createMany(10, [
            'token' => $token,
            'targetHost' => 'unhealthy.example.com',
            'latencyMs' => 2000,
            'responseStatusCode' => 500,
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertMatchesRegularExpression('/(green|yellow|red)/i', $rendered);
    }

    #[Test]
    public function componentRendersGreenStatusForHealthyService(): void
    {
        $token = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(20, [
            'token' => $token,
            'targetHost' => 'healthy.example.com',
            'latencyMs' => 50,
            'responseStatusCode' => 200,
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('healthy.example.com', $rendered);
    }

    #[Test]
    public function refreshActionClearsCache(): void
    {
        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->adminUser);

        $testComponent->render();

        $token = ApiTokenFactory::createOne();
        RequestLogFactory::createMany(5, [
            'token' => $token,
            'targetHost' => 'new.example.com',
            'latencyMs' => 100,
            'responseStatusCode' => 200,
        ]);

        $testComponent->call('refresh');
        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('new.example.com', $rendered);
    }

    #[Test]
    public function componentOnlyShowsAccessibleServicesForRegularUser(): void
    {
        $accessibleToken = ApiTokenFactory::createOne();
        $inaccessibleToken = ApiTokenFactory::createOne();

        RequestLogFactory::createMany(5, [
            'token' => $accessibleToken,
            'targetHost' => 'accessible.example.com',
            'latencyMs' => 100,
            'responseStatusCode' => 200,
        ]);

        RequestLogFactory::createMany(5, [
            'token' => $inaccessibleToken,
            'targetHost' => 'inaccessible.example.com',
            'latencyMs' => 100,
            'responseStatusCode' => 200,
        ]);

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $accessibleToken,
        ]);

        $testComponent = $this->createLiveComponent(
            name: ServiceHealthGrid::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('accessible.example.com', $rendered);
        $this->assertStringNotContainsString('inaccessible.example.com', $rendered);
    }
}
