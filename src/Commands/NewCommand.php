<?php

namespace PocketFrame\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Yaml\Yaml;
use Composer\Script\Event;

class NewCommand extends Command
{
  protected static $defaultName = 'new';
  private $projectPath;
  private $config = [];
  private $rollbackSteps = [];

  protected function configure()
  {
    $this
      ->setName('new')
      ->setDescription('Create a new PocketFrame application')
      ->addArgument('name', InputArgument::REQUIRED)
      ->addOption(
        'stability',
        's',
        InputOption::VALUE_OPTIONAL,
        'Minimum stability (dev, alpha, beta, RC, stable)',
        'dev'
      )
      ->addOption(
        'config',
        'c',
        InputOption::VALUE_OPTIONAL,
        'Custom configuration file path'
      );
  }

  public static function postInstall(Event $event)
  {
    $io = $event->getIO();
    $io->write("<info>✓ PocketFrame installer ready to use!</info>");
    $io->write("Run: <comment>pocketframe new project-name</comment>");
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->showBranding($output);
    $projectName = $input->getArgument('name');
    $this->projectPath = getcwd() . '/' . $projectName;
    $stability = $input->getOption('stability');
    $fs = new Filesystem();

    try {
      // Step 1: Create project using composer create-project
      $this->createProject($output, $projectName, $stability);

      // Step 2: Perform project setup
      $this->setupProject($input, $output);

      // Telemetry
      // $this->sendTelemetry($output);

      return Command::SUCCESS;
    } catch (\Exception $e) {
      $output->writeln("<error>Installation failed: {$e->getMessage()}</error>");
      $this->rollback($fs, $output);
      return Command::FAILURE;
    }
  }

  private function loadConfig(string $configPath, Filesystem $fs)
  {
    if (!$fs->exists($configPath)) {
      throw new \RuntimeException("Config file not found: $configPath");
    }

    $config = json_decode(file_get_contents($configPath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException("Invalid JSON config file");
    }

    $this->config = array_merge($this->config, $config);
  }


  private function setupProject(InputInterface $input, OutputInterface $output)
  {
    chdir($this->projectPath);

    $this->checkPlatformRequirements($output);
    $this->askForDatabaseDetails($input, $output);
    $this->askForOptionalFeatures($input, $output);
    $this->setupEnvironment($output);
    $this->createDatabase($output);
    $this->setupDocker($output);
    $this->initializeGit($output);
    $this->runPostInstallCommands($output);
    $this->showSuccessMessage($output);
  }



  private function showBranding(OutputInterface $output)
  {
    $output->writeln(<<<ART
        <fg=cyan;options=bold>
         ____            _      ______
        |  _ \ ___  ___ | | __ |  _ \ __ _ _ __ ___
        | |_) / _ \/ _ \| |/ / | |_) / _` | '__/ _ \
        |  __/  __/ (_) |   <  |  _ < (_| | | |  __/
        |_|   \___|\___/|_|\_\ |_| \_\__,_|_|  \___|
        </>
        PocketFrame Installer - Build Amazing Things!
        ART);
  }

  private function createProject(OutputInterface $output, string $name, string $stability)
  {
    $output->writeln("\n<fg=blue>🚀 Creating new PocketFrame application...</>");

    $process = new Process([
      'composer',
      'create-project',
      'pocketframe/application',
      $name,
      '--stability=' . $stability,
      '--no-interaction',
      '--remove-vcs'
    ]);

    $this->runProcess($process, $output, 'Creating project structure');
    $this->rollbackSteps[] = 'create-project';
  }

  private function checkPlatformRequirements(OutputInterface $output)
  {
    $output->writeln("\n<fg=blue>Checking system requirements...</>");

    // PHP Extensions
    $requiredExtensions = ['pdo', 'mbstring', 'openssl'];
    foreach ($requiredExtensions as $ext) {
      if (!extension_loaded($ext)) {
        throw new \RuntimeException("Missing required PHP extension: $ext");
      }
    }

    // Node.js check
    $process = new Process(['node', '--version']);
    if (!$process->run()) {
      $output->writeln("<comment>Node.js is not installed - some features might not work</comment>");
    }

    $output->writeln("<info>✓ System requirements met</info>");
  }

  private function installDependencies(OutputInterface $output, string $projectDir)
  {
    $output->writeln("\n<fg=blue>📦 Installing dependencies...</>");

    // Run composer install
    $process = new Process(['composer', 'install'], $projectDir);
    $this->runProcess($process, $output, 'Installing packages');

    // Check for composer.lock in rollback
    $this->rollbackSteps[] = 'dependencies';
  }


  private function askForDatabaseDetails(InputInterface $input, OutputInterface $output)
  {
    /** @var QuestionHelper $helper */
    $helper = $this->getHelper('question');

    // Database location question
    $locationQuestion = new ChoiceQuestion(
      'Where should the database be created?',
      ['SQLite file', 'MySQL server', 'PostgreSQL server'],
      0
    );

    $location = $helper->ask($input, $output, $locationQuestion);

    // Map location to driver
    $this->config['db_driver'] = match ($location) {
      'SQLite file' => 'sqlite',
      'MySQL server' => 'mysql',
      'PostgreSQL server' => 'pgsql'
    };

    // Collect credentials if needed
    if ($this->config['db_driver'] !== 'sqlite') {
      $questions = [
        'db_name' => new Question('Database name: ', 'pocketframe'),
        'db_user' => new Question('Database user: ', $this->config['db_driver'] === 'mysql' ? 'root' : 'postgres'),
        'db_password' => new Question('Database password: '),
      ];

      foreach ($questions as $key => $q) {
        $q->setHidden(true);
        $this->config[$key] = $helper->ask($input, $output, $q);
      }
    }
  }

  private function setupEnvironment(OutputInterface $output)
  {

    $output->writeln("\n<fg=blue>🔧 Configuring environment...</>");

    $fs = new Filesystem();
    $envPath = $this->projectPath . '/.env';
    $projectName = basename($this->projectPath);

    // Copy .env.example if .env doesn't exist
    if (!$fs->exists($envPath)) {
      $fs->copy($this->projectPath . '/.env.example', $envPath);
    }

    // Update core application settings
    $envContent = file_get_contents($envPath);

    // Set application name
    $envContent = preg_replace(
      '/^APP_NAME=.*$/m',
      'APP_NAME="' . addslashes($projectName) . '"',
      $envContent
    );

    // Generate application key
    $this->runProcess(
      new Process(['php', 'pocket', 'add:key'], $this->projectPath),
      $output,
      'Generating application key'
    );


    $fs = new Filesystem();
    $envPath = $this->projectPath . '/.env';
    $projectName = basename($this->projectPath);

    // Update .env with core settings
    $envContent = file_get_contents($envPath);

    // Set application name
    $envContent = preg_replace(
      '/^APP_NAME=.*$/m',
      'APP_NAME="' . addslashes($projectName) . '"',
      $envContent
    );

    // Generate and set application key
    $this->runProcess(
      new Process(['php', 'pocket', 'add:key', '--force'], $this->projectPath),
      $output,
      'Generating APP_KEY'
    );

    // Update database configuration
    $driver = $this->config['db_driver'];
    $envContent = preg_replace('/^DB_CONNECTION=.*$/m', "DB_CONNECTION=$driver", $envContent);

    if ($driver === 'sqlite') {
      $envContent = preg_replace(
        '/^# DB_DATABASE=.*$/m',
        'DB_DATABASE=database/database.sqlite',
        $envContent
      );
    } else {
      $replacements = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $driver === 'mysql' ? '3306' : '5432',
        'DB_DATABASE' => $this->config['db_name'],
        'DB_USERNAME' => $this->config['db_user'],
        'DB_PASSWORD' => $this->config['db_password']
      ];

      foreach ($replacements as $key => $value) {
        $envContent = preg_replace(
          "/^# $key=.*$/m",
          "$key=$value",
          $envContent
        );
      }
    }

    // Clean up commented database configurations
    $envContent = preg_replace('/^# (DB_.*)$/m', '# $1', $envContent);

    file_put_contents($envPath, $envContent);
  }

  private function escapeEnvValue($value)
  {
    // Escape special characters in .env values
    return '"' . str_replace(['"', "\n", "\r"], ['\"', '', ''], $value) . '"';
  }


  private function createDatabase(OutputInterface $output)
  {
    $output->writeln("\n<fg=blue>🗄️ Creating database...</>");

    if ($this->config['db_driver'] === 'sqlite') {
      touch($this->projectPath . '/database/database.sqlite');
      return;
    }

    $command = match ($this->config['db_driver']) {
      'mysql' => sprintf(
        "mysql -u %s -p%s -e 'CREATE DATABASE %s;'",
        $this->config['db_user'],
        $this->config['db_password'],
        $this->config['db_name']
      ),
      'pgsql' => sprintf(
        "createdb -U %s %s",
        $this->config['db_user'],
        $this->config['db_name']
      ),
    };

    $process = Process::fromShellCommandline($command);
    $this->runProcess($process, $output, 'Creating database');
  }

  private function rollback(Filesystem $fs, OutputInterface $output)
  {
    $output->writeln("\n<fg=red>⏪ Rolling back changes...</>");

    try {
      if (in_array('create-project', $this->rollbackSteps)) {
        $fs->remove($this->projectPath);
      }
    } catch (IOExceptionInterface $e) {
      $output->writeln("<error>Rollback failed: {$e->getMessage()}</error>");
    }
  }

  private function sendTelemetry(OutputInterface $output)
  {
    try {
      $data = [
        'timestamp' => date('c'),
        'features' => array_keys($this->config),
        'db_driver' => $this->config['db_driver'] ?? null,
      ];

      $process = new Process([
        'curl',
        '-X',
        'POST',
        'https://telemetry.pocketframe.github.io/collect',
        '-d',
        json_encode($data),
        '--silent'
      ]);

      $process->start(); // Run async
    } catch (\Exception $e) {
      // Fail silently
    }
  }

  private function runProcess(Process $process, OutputInterface $output, string $task)
  {
    $output->write("<comment>$task...</comment> ");
    $process->setTimeout(300); // Increased timeout
    $process->run();

    if (!$process->isSuccessful()) {
      $output->writeln("<error>✖</error>");
      throw new \RuntimeException($process->getErrorOutput());
    }

    $output->writeln("<info>✓</info>");
  }


  private function initializeGit(OutputInterface $output)
  {
    $output->writeln("\n<fg=blue>📦 Initializing Git repository...</>");

    try {
      $this->runProcess(new Process(['git', 'init'], $this->projectPath), $output, 'Initializing Git');
      $this->runProcess(new Process(['git', 'add', '.'], $this->projectPath), $output, 'Staging files');
      $this->runProcess(
        new Process(['git', 'commit', '-m', 'Initial commit by Pocketframe Installer'], $this->projectPath),
        $output,
        'Creating initial commit'
      );
    } catch (\Exception $e) {
      $output->writeln("<comment>Git initialization skipped: {$e->getMessage()}</comment>");
    }
  }

  private function setupDocker(OutputInterface $output)
  {
    if (!$this->config['with_docker']) return;

    $output->writeln("\n<fg=blue>🐳 Generating Docker configuration...</>");

    // Create Dockerfile
    $dockerfile = <<<DOCKER
        # Use the official PHP 8.2 image with Apache
        FROM php:8.2-apache

        # Install required PHP extensions
        RUN docker-php-ext-install mysqli pdo pdo_mysql

        # Enable Apache mod_rewrite
        RUN a2enmod rewrite

        # Set a global ServerName to avoid warnings
        RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

        # Copy project files to Apache root directory
        COPY . /var/www/html/

        # Set the correct permissions for the Apache directory
        RUN chown -R www-data:www-data /var/www/html/

        # Expose port 80
        EXPOSE 80
        DOCKER;

    file_put_contents($this->projectPath . '/Dockerfile', $dockerfile);

    // Create docker-compose.yml
    $composeConfig = [
      'version' => '3.8',
      'services' => [
        'app' => [
          'build' => '.',
          'ports' => ['8000:80'],
          'volumes' => ['./:/var/www/html'],
          'depends_on' => ['db'],
          'environment' => [
            'DB_HOST' => 'db',
            'DB_PORT' => $this->config['db_driver'] === 'mysql' ? '3306' : '5432',
          ]
        ],
        'db' => [
          'image' => $this->config['db_driver'] === 'mysql' ? 'mysql:8.0' : 'postgres:15',
          'environment' => [
            'MYSQL_ROOT_PASSWORD' => 'secret',
            'POSTGRES_PASSWORD' => 'secret'
          ],
          'volumes' => ['db_data:/var/lib/mysql']
        ],
        'phpmyadmin' => [
          'image' => 'phpmyadmin/phpmyadmin',
          'ports' => ['8080:80'],
          'environment' => [
            'PMA_HOST' => 'db',
            'PMA_USER' => 'root',
            'PMA_PASSWORD' => 'secret'
          ],
          'depends_on' => ['db']
        ]
      ],
      'volumes' => [
        'db_data' => []
      ]
    ];

    file_put_contents(
      $this->projectPath . '/compose.yml',
      Yaml::dump($composeConfig, 6, 4)
    );
  }

  private function runPostInstallCommands(OutputInterface $output)
  {
    $output->writeln("\n<fg=blue>⚡ Finalizing setup...</>");

    // Generate application key
    $this->runProcess(
      new Process(['php', 'pocket', 'add:key'], $this->projectPath),
      $output,
      'Generating application key'
    );

    // Install node dependencies if package.json exists
    if (file_exists($this->projectPath . '/package.json')) {
      $this->runProcess(
        new Process(['npm', 'install'], $this->projectPath),
        $output,
        'Installing Node.js dependencies'
      );
    }
  }

  private function askForOptionalFeatures(InputInterface $input, OutputInterface $output)
  {
    /** @var QuestionHelper $helper */
    $helper = $this->getHelper('question');

    $question = new ConfirmationQuestion(
      'Enable Docker support? [<comment>yes</comment>]: ',
      true
    );
    $this->config['with_docker'] = $helper->ask($input, $output, $question);

    $question = new ConfirmationQuestion(
      'Send anonymous usage statistics to help improve PocketFrame? [<comment>yes</comment>]: ',
      true
    );
    $this->config['telemetry'] = $helper->ask($input, $output, $question);

    $question = new ConfirmationQuestion(
      'Initialize Git repository? [<comment>yes</comment>]: ',
      true
    );
    $this->config['init_git'] = $helper->ask($input, $output, $question);
  }

  private function showSuccessMessage(OutputInterface $output)
  {
    $output->writeln(<<<SUCCESS

    <fg=green;options=bold>🎉 Installation successful!</>
    Next steps:
     1. cd {$this->projectPath}
     2. php pocket serve
     3. Start building!

    Documentation: <href=https://pocketframe.github.io/docs/>https://pocketframe.github.io/docs</>
    SUCCESS);
  }
}
