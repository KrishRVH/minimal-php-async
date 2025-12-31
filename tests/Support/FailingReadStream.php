<?php

/**
 * @phan-file-suppress PhanPluginPossiblyStaticPublicMethod
 * @phan-file-suppress PhanUnreferencedPublicMethod
 * @phan-file-suppress PhanUnreferencedPublicProperty
 * @phan-file-suppress PhanUnusedPublicFinalMethodParameter
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests\Support;

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps, SlevomatCodingStandard.PHP.DisallowReference

/**
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress UnusedParam
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-suppress UnusedFunctionCall
 */
final class FailingReadStream
{
    private const string SCHEME = 'failread';
    public mixed $context = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($count < 0) {
            return '';
        }
        return false;
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
}
// phpcs:enable
