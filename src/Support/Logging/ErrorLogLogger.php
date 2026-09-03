<?php

declare(strict_types=1);

namespace TailwindPhp\Support\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * The fallback logger used when the integrator supplies none
 * (design doc 15 §Logging).
 *
 * Forwards `warning` and above to `\error_log()` as a single line prefixed with
 * `[tailwindcss-php]`, and drops everything below the threshold. Two properties
 * are deliberate:
 *
 *  - **One line per event.** Newlines in the message or in an interpolated value
 *    become spaces, so a multi-line payload can neither break line-oriented log
 *    tooling nor forge what looks like a second entry.
 *  - **No stack traces.** An `exception` context key is rendered as class plus
 *    message only; a trace would put absolute paths into a log that may be
 *    shipped elsewhere (threat T-06).
 *
 * The `log()` signature is intentionally untyped. `psr/log` 3 declares
 * `string|\Stringable $message`, which does not exist on the PHP 7.3 floor;
 * widening a parameter is always allowed, so an untyped one satisfies psr/log 1,
 * 2 and 3 on every supported PHP.
 */
final class ErrorLogLogger extends AbstractLogger
{
    /**
     * Prefix identifying our lines in a shared error log.
     */
    private const PREFIX = '[tailwindcss-php]';

    /**
     * PSR-3 levels most severe first; the index is the severity rank.
     *
     * @var array<string, int>
     */
    private const SEVERITY = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * @var int
     */
    private $threshold;

    /**
     * @param string $minimumLevel Lowest level that is written; quieter levels are dropped.
     *
     * @throws InvalidArgumentException When $minimumLevel is not a PSR-3 level.
     */
    public function __construct(string $minimumLevel = LogLevel::WARNING)
    {
        $this->threshold = self::severityOf($minimumLevel);
    }

    /**
     * @param mixed $level
     * @param mixed $message
     * @param array<string, mixed> $context
     *
     * @throws InvalidArgumentException When $level is not a PSR-3 level.
     */
    public function log($level, $message, array $context = []): void
    {
        $severity = self::severityOf(\is_string($level) ? $level : '');

        if ($severity > $this->threshold) {
            return;
        }

        $text = self::interpolate(self::stringify($message), $context);

        \error_log(\sprintf(
            '%s %s %s',
            self::PREFIX,
            \strtoupper(\is_string($level) ? $level : ''),
            self::oneLine($text)
        ));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function severityOf(string $level): int
    {
        if (!isset(self::SEVERITY[$level])) {
            throw new InvalidArgumentException(\sprintf('Unknown log level "%s".', $level));
        }

        return self::SEVERITY[$level];
    }

    /**
     * Replace `{key}` with the matching context value.
     *
     * Only values that have a sensible string form are substituted; anything
     * else leaves its placeholder in place rather than emitting "Array".
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replacements = [];

        foreach ($context as $key => $value) {
            if ($key === 'exception' && $value instanceof \Throwable) {
                $replacements['{' . $key . '}'] = self::describeThrowable($value);

                continue;
            }

            $rendered = self::stringifyOrNull($value);

            if ($rendered !== null) {
                $replacements['{' . $key . '}'] = $rendered;
            }
        }

        $interpolated = $replacements === []
            ? $message
            : \strtr($message, $replacements);

        // PSR-3 keeps `exception` meaningful even when the message does not
        // reference it, so append it rather than losing the cause.
        if (
            isset($context['exception'])
            && $context['exception'] instanceof \Throwable
            && \strpos($message, '{exception}') === false
        ) {
            $interpolated .= ' | ' . self::describeThrowable($context['exception']);
        }

        return $interpolated;
    }

    /**
     * Class and message only — never the trace (threat T-06).
     */
    private static function describeThrowable(\Throwable $throwable): string
    {
        return \get_class($throwable) . ': ' . $throwable->getMessage();
    }

    /**
     * @param mixed $value
     */
    private static function stringify($value): string
    {
        $rendered = self::stringifyOrNull($value);

        return $rendered ?? '';
    }

    /**
     * @param mixed $value
     */
    private static function stringifyOrNull($value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }

        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value)) {
            return self::scalarToString($value);
        }

        if (\is_object($value) && \method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param bool|float|int|null $value
     */
    private static function scalarToString($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * Collapse every line break to a space so one event is one log line.
     */
    private static function oneLine(string $text): string
    {
        return \str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }
}
