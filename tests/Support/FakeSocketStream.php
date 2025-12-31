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
final class FakeSocketStream
{
    private const string SCHEME = 'fakesocket';
    public mixed $context = null;

    /** @var array<string, string> */
    public static array $responses = [];

    /** @var array<string, string> */
    public static array $requests = [];

    private string $id = '';
    private mixed $resource = null;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($mode, $options, $openedPath);
        $id = (string) parse_url($path, PHP_URL_HOST);
        if ($id === '') {
            $id = ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        }

        $this->id = $id;
        $response = self::$responses[$this->id] ?? '';

        try {
            $this->resource = TestHelper::openTempStream();
        } catch (RuntimeException) {
            return false;
        }

        fwrite($this->resource, $response);
        rewind($this->resource);
        self::$requests[$this->id] = '';

        return true;
    }

    public function stream_read(int $count): string
    {
        if (!is_resource($this->resource)) {
            return '';
        }
        if ($count <= 0) {
            return '';
        }
        $chunk = fread($this->resource, $count);
        return $chunk === false ? '' : $chunk;
    }

    public function stream_write(string $data): int
    {
        self::$requests[$this->id] .= $data;
        return strlen($data);
    }

    public function stream_eof(): bool
    {
        if (!is_resource($this->resource)) {
            return true;
        }

        return feof($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
    }

    public function stream_cast(int $castAs): mixed
    {
        unset($castAs);
        if (!is_resource($this->resource)) {
            return null;
        }
        return $this->resource;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        unset($option, $arg1, $arg2);
        return true;
    }

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function reset(): void
    {
        self::$responses = [];
        self::$requests = [];
    }

    public static function uriFor(string $id): string
    {
        return self::SCHEME . '://' . $id;
    }
}
// phpcs:enable
