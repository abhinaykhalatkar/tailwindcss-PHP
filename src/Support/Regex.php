<?php

declare(strict_types=1);

namespace TailwindPhp\Support;

/**
 * The only place in this library where a `preg_*` function is called
 * (design doc 12 threat T-12; design doc 17 §Support).
 *
 * Two things make that worth centralising. First, every PCRE call can fail
 * *silently*: `preg_match` returns `false` both for a pattern that does not
 * compile and for a subject that blew the backtrack limit, and a caller that
 * only checks for a truthy result treats a resource-exhaustion failure as "no
 * match". Every wrapper here inspects `preg_last_error()` and converts a
 * failure into a thrown error instead. Second, a single call site is what makes
 * the CI grep (`preg_` appears only in this file) a meaningful control.
 *
 * The thrown exception is a plain `\RuntimeException`, deliberately *not* one of
 * our domain exceptions: a PCRE failure means a pattern in this library is wrong
 * or a limit is set too low. It is an internal defect, not a user configuration
 * problem, and it should surface as such rather than being reported to the user
 * as bad input.
 *
 * Byte semantics: no wrapper adds the `u` modifier. Patterns operate on bytes
 * unless the caller opts in explicitly, matching the project-wide rule that
 * string handling is byte-oriented (design doc 18 §Design rules).
 */
final class Regex
{
    private function __construct()
    {
    }

    /**
     * @param array<int|string, string>|null $matches Populated with the capture groups.
     *
     * @param-out array<int|string, string> $matches
     *
     * @throws \RuntimeException When the pattern fails to compile or PCRE errors.
     */
    public static function match(string $pattern, string $subject, ?array &$matches = null): bool
    {
        $captured = [];

        $result = self::guarded($pattern, static function () use ($pattern, $subject, &$captured) {
            return \preg_match($pattern, $subject, $captured);
        });

        $matches = $captured;

        return $result === 1;
    }

    /**
     * @param array<int|string, array<int, string>>|null $matches Populated with all capture groups.
     *
     * @param-out array<int|string, array<int, string>> $matches
     *
     * @throws \RuntimeException When the pattern fails to compile or PCRE errors.
     */
    public static function matchAll(string $pattern, string $subject, ?array &$matches = null): int
    {
        $captured = [];

        $result = self::guarded($pattern, static function () use ($pattern, $subject, &$captured) {
            return \preg_match_all($pattern, $subject, $captured);
        });

        $matches = $captured;

        return \is_int($result) ? $result : 0;
    }

    /**
     * @throws \RuntimeException When the pattern fails to compile or PCRE errors.
     */
    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
    {
        $result = self::guarded($pattern, static function () use ($pattern, $replacement, $subject, $limit) {
            return \preg_replace($pattern, $replacement, $subject, $limit);
        });

        if (!\is_string($result)) {
            throw self::failure($pattern, 'preg_replace did not return a string');
        }

        return $result;
    }

    /**
     * @param callable(array<int|string, string>): string $callback
     *
     * @throws \RuntimeException When the pattern fails to compile or PCRE errors.
     */
    public static function replaceCallback(
        string $pattern,
        callable $callback,
        string $subject,
        int $limit = -1
    ): string {
        $result = self::guarded($pattern, static function () use ($pattern, $callback, $subject, $limit) {
            return \preg_replace_callback($pattern, $callback, $subject, $limit);
        });

        if (!\is_string($result)) {
            throw self::failure($pattern, 'preg_replace_callback did not return a string');
        }

        return $result;
    }

    /**
     * @param int $flags Only 0 or PREG_SPLIT_NO_EMPTY. The capture and offset
     *                   flags make `preg_split` return nested arrays, which this
     *                   method's `list<string>` contract cannot represent, so
     *                   they are refused rather than silently mangled.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException When an unsupported flag is requested.
     * @throws \RuntimeException When the pattern fails to compile or PCRE errors.
     */
    public static function split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
    {
        if ($flags !== 0 && $flags !== \PREG_SPLIT_NO_EMPTY) {
            throw new \InvalidArgumentException(
                'Regex::split() supports only PREG_SPLIT_NO_EMPTY; '
                . 'PREG_SPLIT_DELIM_CAPTURE and PREG_SPLIT_OFFSET_CAPTURE do not yield a list of strings.'
            );
        }

        $result = self::guarded($pattern, static function () use ($pattern, $subject, $limit, $flags) {
            return \preg_split($pattern, $subject, $limit, $flags);
        });

        if (!\is_array($result)) {
            throw self::failure($pattern, 'preg_split did not return an array');
        }

        $pieces = [];

        foreach ($result as $piece) {
            $pieces[] = (string) $piece;
        }

        return $pieces;
    }

    /**
     * Escape a literal so it can be embedded in a pattern.
     *
     * Cannot fail: it neither compiles nor executes a pattern.
     */
    public static function quote(string $s, ?string $delimiter = null): string
    {
        // Branching rather than passing null through: on the 7.3 floor the second
        // parameter is declared `string`, so a null would be a type error there
        // even though 8.x widened it to `?string`.
        if ($delimiter === null) {
            return \preg_quote($s);
        }

        return \preg_quote($s, $delimiter);
    }

    /**
     * Run one PCRE call with warnings captured and the error state inspected.
     *
     * `set_error_handler` is used rather than the `@` operator because silencing
     * with `@` is forbidden project-wide (design doc 12) and because we want the
     * warning *text* — for a pattern that does not compile, PHP reports the
     * reason there while `preg_last_error()` may still read as "no error" on the
     * 7.3 floor.
     *
     * @param \Closure(): (int|string|array<int, mixed>|null|false) $operation
     *
     * @return int|string|array<int, mixed>|null A false result is never returned:
     *                                           it is converted into an exception.
     *
     * @throws \RuntimeException
     */
    private static function guarded(string $pattern, \Closure $operation)
    {
        /** @var list<string> $warnings */
        $warnings = [];

        // Only PCRE's own diagnostics are ours to convert. A warning raised by
        // the caller's replaceCallback() — an undefined array key, say — must
        // keep its normal reporting path, otherwise an unrelated notice would be
        // reported to the user as "Regex failure". Returning false is not enough
        // for that: PHP would fall back to its *internal* handler and the
        // application's own handler would never see the warning, so the previous
        // one is captured and delegated to explicitly.
        //
        // The handler is installed unmasked on purpose. A severity mask looks
        // tidier but is worse: PHP does not walk down the handler stack, so an
        // error outside the mask bypasses the application's handler entirely
        // instead of reaching it. Delegating gives the same "only PCRE warnings
        // are converted" outcome while keeping every other error on its normal
        // path.
        $previous = null;

        $previous = \set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file = '',
                int $line = 0
            ) use (
                &$warnings,
                &$previous
            ): bool {
                if (\strncmp($message, 'preg_', 5) === 0) {
                    $warnings[] = $message;

                    return true;
                }

                if ($previous !== null) {
                    // PHP falls through to its own handler only on an exact
                    // false, so a handler that returns void/null must not be
                    // turned into `false` here or the warning is reported twice.
                    return $previous($severity, $message, $file, $line) !== false;
                }

                return false;
            }
        );

        try {
            $result = $operation();
        } finally {
            \restore_error_handler();
        }

        if ($warnings !== []) {
            throw self::failure($pattern, $warnings[0]);
        }

        $code = \preg_last_error();

        if ($code !== \PREG_NO_ERROR) {
            throw self::failure($pattern, \preg_last_error_msg());
        }

        if ($result === false) {
            throw self::failure($pattern, 'the pattern could not be executed');
        }

        return $result;
    }

    private static function failure(string $pattern, string $reason): \RuntimeException
    {
        return new \RuntimeException(\sprintf('Regex failure for pattern %s: %s', $pattern, $reason));
    }
}
