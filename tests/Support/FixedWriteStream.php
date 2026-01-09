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
 */
final class FixedWriteStream
{
    private const string SCHEME = 'fixedwrite';
    public mixed $context = null;
    private int $writeResult = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($mode, $options, $openedPath);

        $parts = parse_url($path);
        $result = 0;
        if (is_array($parts)) {
            $value = $parts['host'] ?? '0';
            $result = (int) $value;
        }

        $this->writeResult = $result;
        return true;
    }

    public function stream_write(string $data): int
    {
        unset($data);
        return $this->writeResult;
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

    public static function uriFor(int $result): string
    {
        return self::SCHEME . '://' . $result;
    }
}
// phpcs:enable
