<?php

declare(strict_types=1);

namespace App\Tests\Integration\LiveComponent;

use App\Entity\User;
use App\Tests\Factories\ApiTokenFactory;
use App\Tests\Factories\UserFactory;
use App\Tests\Factories\UserTokenAccessFactory;
use App\Twig\Components\DashboardStats;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DashboardStatsComponentTest extends KernelTestCase
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
            name: DashboardStats::class,
            data: [],
        )->actingAs($this->user);

        $this->assertStringContainsString('tokens', $testComponent->render()->toString());
    }

    #[Test]
    public function componentRendersEmptyStatsForUserWithNoTokenAccess(): void
    {
        ApiTokenFactory::createOne();

        $testComponent = $this->createLiveComponent(
            name: DashboardStats::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('0', $rendered);
    }

    #[Test]
    public function componentRendersStatsForAdminWithAllTokens(): void
    {
        ApiTokenFactory::createMany(3);

        $testComponent = $this->createLiveComponent(
            name: DashboardStats::class,
            data: [],
        )->actingAs($this->adminUser);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('3', $rendered);
    }

    #[Test]
    public function componentRendersStatsForUserWithTokenAccess(): void
    {
        $token = ApiTokenFactory::createOne();
        ApiTokenFactory::createOne();

        UserTokenAccessFactory::createOne([
            'user' => $this->user,
            'token' => $token,
        ]);

        $testComponent = $this->createLiveComponent(
            name: DashboardStats::class,
            data: [],
        )->actingAs($this->user);

        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('1', $rendered);
    }

    #[Test]
    public function refreshActionClearsCache(): void
    {
        $testComponent = $this->createLiveComponent(
            name: DashboardStats::class,
            data: [],
        )->actingAs($this->adminUser);

        $testComponent->render();

        ApiTokenFactory::createOne();

        $testComponent->call('refresh');
        $rendered = $testComponent->render()->toString();

        $this->assertStringContainsString('1', $rendered);
    }
}
