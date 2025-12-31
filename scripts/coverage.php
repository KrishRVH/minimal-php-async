#!/usr/bin/env php
<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$phpunit = $rootDir . '/vendor/bin/phpunit';

function runCommand(array $command, string $cwd): int
{
    $process = proc_open(
        $command,
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start process.\n");
        return 1;
    }

    return proc_close($process);
}

if (extension_loaded('xdebug')) {
    putenv('XDEBUG_MODE=coverage');
    $command = [
        $phpBinary,
        '-d',
        'memory_limit=512M',
        $phpunit,
        '--coverage-text',
        '--path-coverage',
        '--show-uncovered-for-coverage-text',
        '--coverage-filter',
        'src',
    ];
    exit(runCommand($command, $rootDir));
}

if (extension_loaded('pcov')) {
    $command = [
        $phpBinary,
        '-d',
        'pcov.enabled=1',
        '-d',
        'pcov.directory=src',
        $phpunit,
        '--coverage-text',
        '--show-uncovered-for-coverage-text',
        '--coverage-filter',
        'src',
    ];
    exit(runCommand($command, $rootDir));
}

fwrite(STDERR, "No coverage driver available (xdebug or pcov); running tests without coverage.\n");

$command = [$phpBinary, $phpunit];
exit(runCommand($command, $rootDir));
