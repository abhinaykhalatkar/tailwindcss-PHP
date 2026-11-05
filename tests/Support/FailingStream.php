<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

/**
 * A user stream wrapper that opens fine and then fails the way a real stream
 * does halfway: `failing-stream://read-fails` answers every read with `false`
 * (PHP 8's `fread()` then returns `false`), and `failing-stream://short-write`
 * accepts only half of every write (a full disk). Anything else behaves as an
 * empty, endlessly writable stream.
 *
 * Register once per test with {@see self::register()} and drop it in
 * `tearDown()` with {@see self::unregister()}.
 */
final class FailingStream
{
    public const SCHEME = 'failing-stream';

    /**
     * Set by PHP for every wrapper instance.
     *
     * @var resource|null
     */
    public $context;

    /**
     * @var string
     */
    private $behaviour = '';

    public static function register(): void
    {
        if (!\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    public static function unregister(): void
    {
        if (\in_array(self::SCHEME, \stream_get_wrappers(), true)) {
            \stream_wrapper_unregister(self::SCHEME);
        }
    }

    // The method names are PHP's stream-wrapper protocol, not ours to camel-case.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->behaviour = (string) \substr($path, \strlen(self::SCHEME) + 3);

        return true;
    }

    /**
     * @return string|false
     */
    public function stream_read(int $count)
    {
        return $this->behaviour === 'read-fails' ? false : '';
    }

    public function stream_write(string $data): int
    {
        return $this->behaviour === 'short-write' ? \intdiv(\strlen($data), 2) : \strlen($data);
    }

    public function stream_eof(): bool
    {
        return $this->behaviour !== 'read-fails';
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
}
