<?php

namespace PocketFrame\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
  protected static $defaultName = 'new';

  protected function configure()
  {
    $this
      ->setDescription('Create a new PocketFrame application')
      ->addArgument('name', InputArgument::REQUIRED, 'The name of the project');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $name = $input->getArgument('name');
    $fs = new Filesystem();

    // Validate directory
    if ($fs->exists($name)) {
      $output->writeln("<error>Directory '$name' already exists!</error>");
      return Command::FAILURE;
    }

    // Clone skeleton repo
    $output->writeln("<info>Creating project...</info>");
    $process = new Process(['git', 'clone', 'https://github.com/yourusername/pocketframe-skeleton.git', $name]);
    $process->run();

    if (!$process->isSuccessful()) {
      $output->writeln("<error>Failed to clone repository</error>");
      return Command::FAILURE;
    }

    // Install dependencies
    $output->writeln("<info>Installing dependencies...</info>");
    $process = new Process(['composer', 'install'], $name);
    $process->setTimeout(300);
    $process->run();

    if (!$process->isSuccessful()) {
      $output->writeln("<error>Dependency installation failed</error>");
      return Command::FAILURE;
    }

    $output->writeln("<info>Project created successfully!</info>");
    return Command::SUCCESS;
  }
}
