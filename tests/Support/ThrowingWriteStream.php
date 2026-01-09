<?php

/**
 * @phan-file-suppress PhanPluginPossiblyStaticPublicMethod
 * @phan-file-suppress PhanUnreferencedPublicMethod
 * @phan-file-suppress PhanUnreferencedPublicProperty
 * @phan-file-suppress PhanUnusedPublicFinalMethodParameter
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

use RuntimeException;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps, SlevomatCodingStandard.PHP.DisallowReference

/**
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress UnusedParam
 * @psalm-suppress PossiblyUnusedProperty
 */
final class ThrowingWriteStream
{
    private const string SCHEME = 'throwwrite';
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);
        return true;
    }

    public function stream_write(string $data): int
    {
        unset($data);
        throw new RuntimeException('stream_write failed');
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }
    }

    public static function uriFor(string $id): string
    {
        return self::SCHEME . '://' . $id;
    }
}
// phpcs:enable
