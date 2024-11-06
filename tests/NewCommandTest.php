<?php

declare(strict_types=1);

namespace TYPO3\Installer\Console\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\Installer\Console\NewCommand;

class NewCommandTest extends TestCase
{
    /**
     * @test
     */
    public function itCanScaffoldANewTypo3Cms(): void
    {
        $scaffoldDirectoryName = 'tests-output/my-app';
        $scaffoldDirectory = __DIR__ . '/../' . $scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec("rd /s /q \"$scaffoldDirectory\"");
            } else {
                exec("rm -rf \"$scaffoldDirectory\"");
            }
        }

        $app = new Application('TYPO3 Installer');
        $app->add(new NewCommand);

        $tester = new CommandTester($app->find('new'));

        $statusCode = $tester->execute(['name' => $scaffoldDirectoryName], ['interactive' => false, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]);

        self::assertSame(0, $statusCode);
        self::assertDirectoryExists($scaffoldDirectory . '/vendor');
        self::assertFileExists($scaffoldDirectory . '/composer.json');
    }
}
