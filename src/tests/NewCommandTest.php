<?php

namespace PocketFrame\Tests;

use PHPUnit\Framework\TestCase;
use PocketFrame\Commands\NewCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class NewCommandTest extends TestCase
{
  private $filesystem;
  private $testDirectory;
  private $originalCwd;

  protected function setUp(): void
  {
    $this->filesystem = new Filesystem();
    $this->testDirectory = sys_get_temp_dir() . '/pocketframe-test';
    $this->originalCwd = getcwd();
    $this->filesystem->remove($this->testDirectory);
    $this->filesystem->mkdir($this->testDirectory);
  }

  protected function tearDown(): void
  {
    $this->filesystem->remove($this->testDirectory);
    chdir($this->originalCwd);
  }

  public function testBasicInstallation()
  {
    $commandTester = $this->createCommandTester();
    $commandTester->setInputs([
      'mysql',    // Database driver
      'testdb',   // Database name
      'root',     // Database user
      '',         // Database password
      'yes',      // Docker support
      'yes',      // Telemetry
      'yes'       // Initialize Git
    ]);

    $exitCode = $commandTester->execute([
      'name' => 'test-project'
    ]);

    $this->assertEquals(0, $exitCode);
    $this->assertDirectoryExists($this->testDirectory . '/test-project');
    $this->assertFileExists($this->testDirectory . '/test-project/.env');
    $this->assertFileExists($this->testDirectory . '/test-project/compose.yml');
  }

  public function testConfigFileUsage()
  {
    $config = [
      'db_driver' => 'sqlite',
      'with_docker' => false,
      'telemetry' => false,
      'init_git' => false
    ];

    $configPath = $this->testDirectory . '/config.json';
    file_put_contents($configPath, json_encode($config));

    $commandTester = $this->createCommandTester();
    $exitCode = $commandTester->execute([
      'name' => 'config-project',
      '--config' => $configPath
    ]);

    $this->assertEquals(0, $exitCode);
    $envContent = file_get_contents($this->testDirectory . '/config-project/.env');
    $this->assertStringContainsString('DB_CONNECTION=sqlite', $envContent);
  }

  public function testInvalidConfigFile()
  {
    $this->expectException(\RuntimeException::class);

    $configPath = $this->testDirectory . '/invalid-config.json';
    file_put_contents($configPath, '{invalid json}');

    $commandTester = $this->createCommandTester();
    $commandTester->execute([
      'name' => 'invalid-config-project',
      '--config' => $configPath
    ]);
  }

  public function testDatabaseCreation()
  {
    $commandTester = $this->createCommandTester();
    $commandTester->setInputs([
      'pgsql',    // Database driver
      'pgdb',     // Database name
      'pguser',   // Database user
      'pgpass',   // Database password
      'no',       // Docker support
      'no',       // Telemetry
      'no'        // Initialize Git
    ]);

    $exitCode = $commandTester->execute(['name' => 'pgsql-project']);

    $this->assertEquals(0, $exitCode);
    $envContent = file_get_contents($this->testDirectory . '/pgsql-project/.env');
    $this->assertStringContainsString('DB_CONNECTION=pgsql', $envContent);
    $this->assertStringContainsString('DB_DATABASE=pgdb', $envContent);
  }

  public function testDockerConfiguration()
  {
    $commandTester = $this->createCommandTester();
    $commandTester->setInputs([
      'mysql',
      'testdb',
      'root',
      '',
      'yes',
      'no',
      'no'
    ]);

    $exitCode = $commandTester->execute(['name' => 'docker-project']);

    $this->assertEquals(0, $exitCode);
    $composePath = $this->testDirectory . '/docker-project/compose.yml';
    $this->assertFileExists($composePath);

    $composeContent = Yaml::parseFile($composePath);
    $this->assertEquals('mysql:8.0', $composeContent['services']['db']['image']);
    $this->assertArrayHasKey('phpmyadmin', $composeContent['services']);
  }

  public function testRollbackOnFailure()
  {
    // Create a read-only directory to force failure
    $this->filesystem->mkdir($this->testDirectory . '/readonly', 0444);

    $commandTester = $this->createCommandTester();
    $exitCode = $commandTester->execute([
      'name' => 'readonly/failing-project'
    ]);

    $this->assertEquals(1, $exitCode);
    $this->assertDirectoryDoesNotExist($this->testDirectory . '/readonly/failing-project');
  }

  private function createCommandTester(): CommandTester
  {
    $application = new Application();
    $application->add(new NewCommand());

    $command = $application->find('new');
    // $command->setProcessFactory(function (array $command) {
    //   return $this->createMockProcess($command);
    // });

    return new CommandTester($command);
  }

  private function createMockProcess(array $command): Process
  {
    $process = $this->createMock(Process::class);
    $process->method('run')->willReturnCallback(function () use ($command) {
      // Simulate successful commands
      if ($command[0] === 'git' && $command[1] === 'clone') {
        $this->filesystem->mirror(
          __DIR__ . '/../src/skeleton',
          $this->testDirectory . '/' . $command[3]
        );
      }
      return 0;
    });
    $process->method('isSuccessful')->willReturn(true);
    $process->method('getErrorOutput')->willReturn('');

    return $process;
  }
}
