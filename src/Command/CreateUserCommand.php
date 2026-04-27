<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'sentinel:user:create',
    description: 'Create a new dashboard user for web authentication',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address for the user')
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_REQUIRED,
                'Password for the user (will prompt if not provided)'
            )
            ->addOption(
                'admin',
                null,
                InputOption::VALUE_NONE,
                'Grant admin role (ROLE_ADMIN) to the user'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var string|null $password */
        $password = $input->getOption('password');
        /** @var bool $isAdmin */
        $isAdmin = $input->getOption('admin');

        // Validate email format
        $emailConstraint = new Assert\Email();
        $violations = $this->validator->validate($email, $emailConstraint);
        if (count($violations) > 0 || $email === '') {
            $io->error(sprintf('Invalid email address: %s', $email));
            return Command::FAILURE;
        }
        /** @var non-empty-string $email Email is validated and non-empty at this point */

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser !== null) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        // Prompt for password if not provided
        if ($password === null) {
            /** @var string|null $enteredPassword */
            $enteredPassword = $io->askHidden('Enter password');
            if ($enteredPassword === null || $enteredPassword === '') {
                $io->error('Password cannot be empty.');
                return Command::FAILURE;
            }

            $confirmPassword = $io->askHidden('Confirm password');
            if ($enteredPassword !== $confirmPassword) {
                $io->error('Passwords do not match.');
                return Command::FAILURE;
            }
            $password = $enteredPassword;
        }

        // Validate password length
        if (strlen((string) $password) < 8) {
            $io->error('Password must be at least 8 characters long.');
            return Command::FAILURE;
        }

        // Create user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if ($isAdmin) {
            $user->setRoles(['ROLE_ADMIN']);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success('User created successfully!');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $user->getId()->toRfc4122()],
                ['Email', $user->getEmail()],
                ['Roles', implode(', ', $user->getRoles())],
                ['Created At', $user->getCreatedAt()->format('Y-m-d H:i:s')],
            ]
        );

        return Command::SUCCESS;
    }
}
