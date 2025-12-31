#!/usr/bin/env php
<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$infection = $rootDir . '/vendor/bin/infection';
$plugin = $rootDir . '/vendor/bin/roave-infection-static-analysis-plugin';
$extraArgs = [];

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

if (is_executable($plugin)) {
    $infection = $plugin;
    $extraArgs[] = '--psalm-config=psalm.xml';
}

if (extension_loaded('xdebug')) {
    putenv('XDEBUG_MODE=coverage');
    $command = [
        $phpBinary,
        '-d',
        'memory_limit=512M',
        $infection,
        '--threads=max',
        ...$extraArgs,
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
        $infection,
        '--threads=max',
        ...$extraArgs,
    ];
    exit(runCommand($command, $rootDir));
}

fwrite(STDERR, "No coverage driver available (xdebug or pcov); skipping infection.\n");
exit(0);
