---
description: Add a new Symfony console command
---

# Add Console Command

## Steps

1. **Create the command** in `src/Command/{Name}Command.php`:
   ```php
   <?php
   declare(strict_types=1);
   namespace App\Command;

   use Symfony\Component\Console\Attribute\AsCommand;
   use Symfony\Component\Console\Command\Command;
   use Symfony\Component\Console\Input\InputArgument;
   use Symfony\Component\Console\Input\InputInterface;
   use Symfony\Component\Console\Input\InputOption;
   use Symfony\Component\Console\Output\OutputInterface;
   use Symfony\Component\Console\Style\SymfonyStyle;

   #[AsCommand(
       name: 'sentinel:{command-name}',
       description: 'Description of what this command does',
   )]
   final class {Name}Command extends Command
   {
       public function __construct(
           // Inject dependencies here
       ) {
           parent::__construct();
       }

       protected function configure(): void
       {
           $this
               ->addArgument('arg1', InputArgument::REQUIRED, 'Argument description')
               ->addOption('option1', 'o', InputOption::VALUE_OPTIONAL, 'Option description', 'default');
       }

       protected function execute(InputInterface $input, OutputInterface $output): int
       {
           $io = new SymfonyStyle($input, $output);

           // Command logic here

           $io->success('Command completed successfully.');

           return Command::SUCCESS;
       }
   }
   ```

2. **Verify command is registered**:
   ```bash
   // turbo
   ddev exec bin/console list sentinel
   ```

3. **Test the command manually**:
   ```bash
   ddev exec bin/console sentinel:{command-name} --help
   ```

4. **Create unit test** in `tests/Unit/Command/{Name}CommandTest.php` (optional for simple commands)

5. **Run static analysis**:
   ```bash
   // turbo
   ddev exec vendor/bin/phpstan analyse src/Command/{Name}Command.php
   ```
