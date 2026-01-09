#!/usr/bin/env php
<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
$phpBinary = PHP_BINARY;
$checker = $rootDir . '/vendor/bin/roave-backward-compatibility-check';

chdir($rootDir);

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

function inGitRepo(string $cwd): bool
{
    $output = [];
    $status = 0;
    exec('git rev-parse --is-inside-work-tree', $output, $status);

    return $status === 0 && isset($output[0]) && trim($output[0]) === 'true';
}

if (!is_executable($checker)) {
    fwrite(STDERR, "Backward compatibility checker not found at {$checker}.\n");
    exit(1);
}

if (!inGitRepo($rootDir)) {
    fwrite(STDERR, "Git repository not detected; skipping backward compatibility check.\n");
    exit(0);
}

$from = getenv('BC_FROM');
$to = getenv('BC_TO') ?: 'HEAD';

if ($from === false || $from === '') {
    $tags = [];
    $tagStatus = 0;
    exec('git tag --list --sort=-version:refname', $tags, $tagStatus);

    if ($tagStatus === 0 && isset($tags[0]) && trim($tags[0]) !== '') {
        $from = trim($tags[0]);
    } else {
        $revStatus = 0;
        exec('git rev-parse --verify HEAD~1 2>/dev/null', $revOutput, $revStatus);
        if ($revStatus === 0) {
            $from = 'HEAD~1';
        } else {
            fwrite(
                STDERR,
                "No git tags or previous commit found; skipping backward compatibility check.\n"
            );
            exit(0);
        }
    }
}

$command = [$phpBinary, $checker, '--from=' . $from, '--to=' . $to];
exit(runCommand($command, $rootDir));
