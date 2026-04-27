<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\UserPreferences;
use App\Repository\UserPreferencesRepositoryInterface;
use App\Service\UserPreferencesService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserPreferencesService::class)]
#[AllowMockObjectsWithoutExpectations]
final class UserPreferencesServiceTest extends TestCase
{
    private UserPreferencesRepositoryInterface&MockObject $repository;
    private UserPreferencesService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(UserPreferencesRepositoryInterface::class);
        $this->service = new UserPreferencesService($this->repository);
    }

    #[Test]
    public function getPreferencesReturnsExistingPreferences(): void
    {
        $user = $this->createUser();
        $preferences = new UserPreferences($user);
        $preferences->setTheme('dark');

        $this->repository
            ->expects(self::once())
            ->method('findByUser')
            ->with($user)
            ->willReturn($preferences);

        $this->repository
            ->expects(self::never())
            ->method('save');

        $result = $this->service->getPreferences($user);

        self::assertSame($preferences, $result);
        self::assertSame('dark', $result->getTheme());
    }

    #[Test]
    public function getPreferencesCreatesDefaultsWhenNoneExist(): void
    {
        $user = $this->createUser();

        $this->repository
            ->expects(self::once())
            ->method('findByUser')
            ->with($user)
            ->willReturn(null);

        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(UserPreferences::class));

        $result = $this->service->getPreferences($user);

        self::assertSame($user, $result->getUser());
        self::assertSame(UserPreferences::DEFAULT_THEME, $result->getTheme());
        self::assertSame(UserPreferences::DEFAULT_TIMEZONE, $result->getTimezone());
        self::assertSame(UserPreferences::DEFAULT_DATE_RANGE, $result->getDefaultDateRange());
        self::assertSame(UserPreferences::DEFAULT_REFRESH_INTERVAL, $result->getRefreshInterval());
    }

    #[Test]
    public function savePreferencesCallsRepository(): void
    {
        $user = $this->createUser();
        $preferences = new UserPreferences($user);

        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with($preferences);

        $this->service->savePreferences($preferences);
    }

    #[Test]
    public function createDefaultPreferencesReturnsNewInstance(): void
    {
        $user = $this->createUser();

        $result = $this->service->createDefaultPreferences($user);

        self::assertInstanceOf(UserPreferences::class, $result);
        self::assertSame($user, $result->getUser());
        self::assertSame(UserPreferences::DEFAULT_THEME, $result->getTheme());
        self::assertSame(UserPreferences::DEFAULT_TIMEZONE, $result->getTimezone());
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setPassword('hashed');

        return $user;
    }
}
