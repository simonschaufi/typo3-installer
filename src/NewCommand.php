<?php

declare(strict_types=1);

namespace TYPO3\Installer\Console;

use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class NewCommand extends Command
{
    /**
     * Configure the command options
     */
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new TYPO3 CMS project')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('release', null, InputOption::VALUE_OPTIONAL, 'Installs a specific TYPO3 release')
            ->addOption('min', null, InputOption::VALUE_NONE, 'Install only the minimal distribution')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    /**
     * Execute the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->write(
            PHP_EOL . '<fg=yellow> _________     _______   ____ ____
|__   __\ \   / /  __ \ / __ \___ \
   | |   \ \_/ /| |__) | |  | |__) |
   | |    \   / |  ___/| |  | |__ <
   | |     | |  | |    | |__| |__) |
   |_|     |_|  |_|     \____/____/</>' . PHP_EOL . PHP_EOL);

        $name = $input->getArgument('name');

        $directory = $this->getInstallationDirectory($name);

        $release = $input->getOption('release') ?: '';

        if (!$input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($directory === '.' && $input->getOption('force')) {
            throw new RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();

        $distribution = $input->getOption('min') ? 'typo3/minimal' : 'typo3/cms-base-distribution';

        $commands = [
            $composer . " create-project $distribution \"$directory\" \"$release\"",
        ];

        if ($directory !== '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY === 'Windows') {
                array_unshift($commands, "rd /s /q \"$directory\"");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($input->getOption('git') || $input->getOption('github') !== false) {
                $this->createRepository($directory, $input, $output);
            }

            if ($input->getOption('github') !== false) {
                $this->pushToGitHub($name, $directory, $input, $output);
            }

            $output->writeln(PHP_EOL . '<comment>TYPO3 project is ready! Build something amazing!</comment>');
        }

        return $process->getExitCode();
    }

    /**
     * Return the local machine's default Git branch if set or default to `main`
     */
    private function defaultBranch(): string
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    /**
     * Create a Git repository and commit the base TYPO3 skeleton
     */
    private function createRepository(string $directory, InputInterface $input, OutputInterface $output): void
    {
        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Set up a fresh TYPO3 project"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output, $directory);
    }

    /**
     * Create a GitHub repository and push the git log to it
     */
    private function pushToGitHub(
        string $name,
        string $directory,
        InputInterface $input,
        OutputInterface $output
    ): void {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        if (!$process->isSuccessful()) {
            $output->writeln(
                '<comment>Warning: Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...</comment>'
            );

            return;
        }

        if (!$process->isSuccessful()) {
            $output->writeln(
                '  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...'
                . PHP_EOL
            );

            return;
        }

        $name = $input->getOption('organization') ? $input->getOption('organization') . "/$name" : $name;
        $flags = $input->getOption('github') ?: '--private';

        $commands = [
            "gh repo create {$name} --source=. --push {$flags}",
        ];

        $this->runCommands($commands, $input, $output, $directory, ['GIT_TERMINAL_PROMPT' => 0]);
    }

    /**
     * Verify that the application does not already exist
     */
    private function verifyApplicationDoesntExist(string $directory): void
    {
        if ($directory !== getcwd() && (is_dir($directory) || is_file($directory))) {
            throw new RuntimeException('TYPO3 project already exists!');
        }
    }

    /**
     * Get the installation directory
     */
    protected function getInstallationDirectory(string $name): string
    {
        return $name !== '.' ? getcwd() . '/' . $name : '.';
    }

    /**
     * Get the composer command for the environment
     */
    private function findComposer(): string
    {
        $composerPath = getcwd() . '/composer.phar';

        if (file_exists($composerPath)) {
            return '"' . PHP_BINARY . '" ' . $composerPath;
        }

        return 'composer';
    }

    /**
     * Run the given commands
     */
    private function runCommands(
        array $commands,
        InputInterface $input,
        OutputInterface $output,
        string $workingPath = null,
        array $env = []
    ): Process {
        if (!$output->isDecorated()) {
            $commands = array_map(static function ($value) {
                if (str_starts_with($value, 'chmod')) {
                    return $value;
                }

                if (str_starts_with($value, 'git')) {
                    return $value;
                }

                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(static function ($value) {
                if (str_starts_with($value, 'chmod')) {
                    return $value;
                }

                if (str_starts_with($value, 'git')) {
                    return $value;
                }

                return $value . ' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> ' . $e->getMessage() . PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    ' . $line);
        });

        return $process;
    }
}
