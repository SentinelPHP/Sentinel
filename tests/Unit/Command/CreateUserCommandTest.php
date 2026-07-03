<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(CreateUserCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class CreateUserCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private ValidatorInterface&MockObject $validator;
    /** @var EntityRepository<User>&MockObject */
    private EntityRepository&MockObject $userRepository;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->userRepository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($this->userRepository);

        $command = new CreateUserCommand(
            $this->entityManager,
            $this->passwordHasher,
            $this->validator
        );

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function itCreatesUserSuccessfully(): void
    {
        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->userRepository
            ->method('findOneBy')
            ->with(['email' => 'test@example.com'])
            ->willReturn(null);

        $this->passwordHasher
            ->expects(self::once())
            ->method('hashPassword')
            ->willReturn('hashed_password');

        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(User::class));

        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $this->commandTester->execute([
            'email' => 'test@example.com',
            '--password' => 'securepassword123',
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('User created successfully', $this->commandTester->getDisplay());
        self::assertStringContainsString('test@example.com', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itCreatesAdminUser(): void
    {
        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->passwordHasher
            ->method('hashPassword')
            ->willReturn('hashed_password');

        $capturedUser = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (User $user) use (&$capturedUser): bool {
                $capturedUser = $user;
                return true;
            }));

        $this->entityManager
            ->method('flush');

        $this->commandTester->execute([
            'email' => 'admin@example.com',
            '--password' => 'securepassword123',
            '--admin' => true,
        ]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertNotNull($capturedUser);
        self::assertContains('ROLE_ADMIN', $capturedUser->getRoles());
    }

    #[Test]
    public function itFailsForInvalidEmail(): void
    {
        $violation = $this->createMock(\Symfony\Component\Validator\ConstraintViolationInterface::class);
        $violations = new ConstraintViolationList([$violation]);

        $this->validator
            ->method('validate')
            ->willReturn($violations);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->commandTester->execute([
            'email' => 'invalid-email',
            '--password' => 'securepassword123',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Invalid email address', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itFailsForExistingUser(): void
    {
        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $existingUser = new User();
        $this->userRepository
            ->method('findOneBy')
            ->with(['email' => 'existing@example.com'])
            ->willReturn($existingUser);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->commandTester->execute([
            'email' => 'existing@example.com',
            '--password' => 'securepassword123',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('already exists', $this->commandTester->getDisplay());
    }

    #[Test]
    public function itFailsForShortPassword(): void
    {
        $this->validator
            ->method('validate')
            ->willReturn(new ConstraintViolationList());

        $this->userRepository
            ->method('findOneBy')
            ->willReturn(null);

        $this->entityManager
            ->expects(self::never())
            ->method('persist');

        $this->commandTester->execute([
            'email' => 'test@example.com',
            '--password' => 'short',
        ]);

        self::assertSame(1, $this->commandTester->getStatusCode());
        self::assertStringContainsString('at least 8 characters', $this->commandTester->getDisplay());
    }
}
