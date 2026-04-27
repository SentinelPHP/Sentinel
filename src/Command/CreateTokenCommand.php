<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ApiToken;
use App\Enum\LogLevel;
use App\Enum\TokenMode;
use App\Security\TokenAuthenticatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sentinel:token:create',
    description: 'Create a new API token for authentication',
)]
final class CreateTokenCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenAuthenticatorInterface $tokenAuthenticator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Name/label for the token')
            ->addOption(
                'targets',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Allowed target hosts (e.g., api.example.com, *.stripe.com). Empty means all hosts allowed.'
            )
            ->addOption(
                'log-level',
                'l',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Log level override for this token (%s). Omit to use global default.',
                    implode(', ', LogLevel::values())
                )
            )
            ->addOption(
                'mode',
                'm',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Token mode (%s). Default: passive.',
                    implode(', ', TokenMode::values())
                ),
                'passive'
            )
            ->addOption(
                'learning-threshold',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of samples required before auto-promoting schema to master (only applies in learning mode)'
            )
            ->addOption(
                'auto-switch',
                null,
                InputOption::VALUE_NONE,
                'Automatically switch to validating mode when all schemas reach threshold'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var list<string> $targets */
        $targets = $input->getOption('targets');
        /** @var string|null $logLevelInput */
        $logLevelInput = $input->getOption('log-level');
        /** @var string $modeInput */
        $modeInput = $input->getOption('mode');
        /** @var string|null $thresholdInput */
        $thresholdInput = $input->getOption('learning-threshold');
        /** @var bool $autoSwitch */
        $autoSwitch = $input->getOption('auto-switch');

        $logLevel = null;
        if ($logLevelInput !== null) {
            $logLevel = LogLevel::tryFrom($logLevelInput);
            if ($logLevel === null) {
                $io->error(sprintf(
                    'Invalid log level "%s". Valid values: %s',
                    $logLevelInput,
                    implode(', ', LogLevel::values())
                ));
                return Command::FAILURE;
            }
        }

        $mode = TokenMode::tryFrom($modeInput);
        if ($mode === null) {
            $io->error(sprintf(
                'Invalid mode "%s". Valid values: %s',
                $modeInput,
                implode(', ', TokenMode::values())
            ));
            return Command::FAILURE;
        }

        $learningThreshold = null;
        if ($thresholdInput !== null) {
            $learningThreshold = (int) $thresholdInput;
            if ($learningThreshold <= 0) {
                $io->error('Learning threshold must be a positive integer.');
                return Command::FAILURE;
            }
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = $this->tokenAuthenticator->hashToken($plainToken);

        $apiToken = new ApiToken();
        $apiToken->setName($name);
        $apiToken->setTokenHash($tokenHash);
        $apiToken->setAllowedTargets($targets);
        $apiToken->setLogLevel($logLevel);
        $apiToken->setMode($mode);
        $apiToken->setLearningThreshold($learningThreshold);
        $apiToken->setAutoSwitchToValidating($autoSwitch);

        $this->entityManager->persist($apiToken);
        $this->entityManager->flush();

        $io->success('API token created successfully!');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', $apiToken->getId()->toRfc4122()],
                ['Name', $apiToken->getName()],
                ['Allowed Targets', $targets === [] ? 'All hosts' : implode(', ', $apiToken->getAllowedTargets())],
                ['Log Level', $logLevel !== null ? $logLevel->value : 'Global default'],
                ['Mode', $mode->value],
                ['Learning Threshold', $learningThreshold !== null ? (string) $learningThreshold : 'Manual promotion'],
                ['Auto-switch to Validating', $autoSwitch ? 'Yes' : 'No'],
                ['Created At', $apiToken->getCreatedAt()->format('Y-m-d H:i:s')],
            ]
        );

        $io->warning('Save this token securely - it cannot be retrieved later!');
        $io->writeln('');
        $io->writeln('<info>Bearer Token:</info>');
        $io->writeln($plainToken);

        return Command::SUCCESS;
    }
}
