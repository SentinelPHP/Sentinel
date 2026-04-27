<?php

declare(strict_types=1);

namespace App\Command;

use SentinelPHP\Encrypt\Encryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sentinel:encryption:generate-key',
    description: 'Generate a new encryption key for data protection',
)]
final class GenerateEncryptionKeyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'env-format',
                null,
                InputOption::VALUE_NONE,
                'Output in .env format (SENTINEL_ENCRYPTION_KEY=...)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = Encryptor::generateKey();

        /** @var bool $envFormat */
        $envFormat = $input->getOption('env-format');

        if ($envFormat) {
            $output->writeln(sprintf('SENTINEL_ENCRYPTION_KEY=%s', $key));
        } else {
            $io->success('Encryption key generated successfully!');
            $io->writeln('');
            $io->writeln('<info>Base64-encoded key (32 bytes / 256 bits):</info>');
            $io->writeln($key);
            $io->writeln('');
            $io->note([
                'Add this to your .env file:',
                sprintf('SENTINEL_ENCRYPTION_KEY=%s', $key),
            ]);
            $io->warning([
                'Store this key securely!',
                'If lost, encrypted data cannot be recovered.',
                'Never commit this key to version control.',
            ]);
        }

        return Command::SUCCESS;
    }
}
