<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\LogLevel;
use App\Enum\TokenMode;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sentinel:token:update',
    description: 'Update an existing API token configuration',
)]
final class UpdateTokenCommand extends Command
{
    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Token name or UUID')
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'New name for the token'
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_REQUIRED,
                sprintf('Token mode (%s)', implode(', ', TokenMode::values()))
            )
            ->addOption(
                'log-level',
                'l',
                InputOption::VALUE_REQUIRED,
                sprintf('Log level (%s)', implode(', ', LogLevel::values()))
            )
            ->addOption(
                'learning-threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of samples before auto-promoting schema (0 to disable)'
            )
            ->addOption(
                'auto-switch',
                null,
                InputOption::VALUE_REQUIRED,
                'Auto-switch to validating mode (true/false)'
            )
            ->addOption(
                'active',
                null,
                InputOption::VALUE_REQUIRED,
                'Enable or disable the token (true/false)'
            )
            ->addOption(
                'targets',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Replace allowed target hosts (use --targets= to clear)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $identifier */
        $identifier = $input->getArgument('identifier');

        $token = $this->tokenRepository->findOneBy(['name' => $identifier])
            ?? $this->tokenRepository->find($identifier);

        if ($token === null) {
            $io->error(sprintf('Token not found: %s', $identifier));
            return Command::FAILURE;
        }

        $changes = [];

        // Update name
        /** @var string|null $newName */
        $newName = $input->getOption('name');
        if ($newName !== null) {
            $token->setName($newName);
            $changes[] = ['Name', $newName];
        }

        // Update mode
        /** @var string|null $modeInput */
        $modeInput = $input->getOption('mode');
        if ($modeInput !== null) {
            $mode = TokenMode::tryFrom($modeInput);
            if ($mode === null) {
                $io->error(sprintf('Invalid mode "%s". Valid: %s', $modeInput, implode(', ', TokenMode::values())));
                return Command::FAILURE;
            }
            $token->setMode($mode);
            $changes[] = ['Mode', $mode->value];
        }

        // Update log level
        /** @var string|null $logLevelInput */
        $logLevelInput = $input->getOption('log-level');
        if ($logLevelInput !== null) {
            $logLevel = LogLevel::tryFrom($logLevelInput);
            if ($logLevel === null) {
                $io->error(sprintf('Invalid log level "%s". Valid: %s', $logLevelInput, implode(', ', LogLevel::values())));
                return Command::FAILURE;
            }
            $token->setLogLevel($logLevel);
            $changes[] = ['Log Level', $logLevel->value];
        }

        // Update learning threshold
        /** @var string|null $thresholdInput */
        $thresholdInput = $input->getOption('learning-threshold');
        if ($thresholdInput !== null) {
            $threshold = (int) $thresholdInput;
            if ($threshold < 0) {
                $io->error('Learning threshold must be 0 or a positive integer.');
                return Command::FAILURE;
            }
            $token->setLearningThreshold($threshold === 0 ? null : $threshold);
            $changes[] = ['Learning Threshold', $threshold === 0 ? 'Disabled' : (string) $threshold];
        }

        // Update auto-switch
        /** @var string|null $autoSwitchInput */
        $autoSwitchInput = $input->getOption('auto-switch');
        if ($autoSwitchInput !== null) {
            $autoSwitch = filter_var($autoSwitchInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($autoSwitch === null) {
                $io->error('Invalid value for --auto-switch. Use true or false.');
                return Command::FAILURE;
            }
            $token->setAutoSwitchToValidating($autoSwitch);
            $changes[] = ['Auto-switch', $autoSwitch ? 'Yes' : 'No'];
        }

        // Update active status
        /** @var string|null $activeInput */
        $activeInput = $input->getOption('active');
        if ($activeInput !== null) {
            $active = filter_var($activeInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                $io->error('Invalid value for --active. Use true or false.');
                return Command::FAILURE;
            }
            $token->setIsActive($active);
            $changes[] = ['Active', $active ? 'Yes' : 'No'];
        }

        // Update targets
        /** @var list<string> $targetsInput */
        $targetsInput = $input->getOption('targets');
        if ($input->getOption('targets') !== []) {
            $token->setAllowedTargets($targetsInput);
            $changes[] = ['Allowed Targets', $targetsInput === [] ? 'All hosts' : implode(', ', $targetsInput)];
        }

        if ($changes === []) {
            $io->warning('No changes specified. Use --help to see available options.');
            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Token "%s" updated successfully!', $token->getName()));
        $io->table(['Property', 'New Value'], $changes);

        // Show current full state
        $io->section('Current Token State');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $token->getId()->toRfc4122()],
                ['Name', $token->getName()],
                ['Active', $token->isActive() ? 'Yes' : 'No'],
                ['Mode', $token->getMode()->value],
                ['Allowed Targets', $token->getAllowedTargets() === [] ? 'All hosts' : implode(', ', $token->getAllowedTargets())],
                ['Log Level', $token->getLogLevel() !== null ? $token->getLogLevel()->value : 'Global default'],
                ['Learning Threshold', $token->getLearningThreshold() !== null ? (string) $token->getLearningThreshold() : 'Manual promotion'],
                ['Auto-switch', $token->isAutoSwitchToValidating() ? 'Yes' : 'No'],
            ]
        );

        return Command::SUCCESS;
    }
}
