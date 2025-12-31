<?php

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Bench;

use Krvh\MinimalPhpAsync\Async;
use Krvh\MinimalPhpAsync\Runtime;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(10)]
#[Bench\Revs(200)]
#[Bench\Warmup(2)]
#[Bench\RetryThreshold(5.0)]
#[Bench\OutputTimeUnit('microseconds')]
final class AsyncBench
{
    public function benchRun(): void
    {
        Async::withRuntime(
            new Runtime(),
            static fn(): mixed => Async::run(static fn(): int => 1),
        );
    }

    public function benchSpawnAwait(): void
    {
        Async::withRuntime(new Runtime(), static function (): void {
            $task = Async::spawn(static fn(): int => 1);
            $task->await();
        });
    }

    public function benchAllTwo(): void
    {
        Async::withRuntime(new Runtime(), static function (): void {
            Async::all([
                static fn(): int => 1,
                static fn(): int => 2,
            ]);
        });
    }

    public function benchRaceTwo(): void
    {
        Async::withRuntime(new Runtime(), static function (): void {
            Async::race([
                static fn(): int => 1,
                static function (): int {
                    Async::sleep(0.0);
                    return 2;
                },
            ]);
        });
    }

    public function benchTimeoutFast(): void
    {
        Async::withRuntime(new Runtime(), static function (): void {
            Async::timeout(static fn(): int => 1, 0.01);
        });
    }
}
