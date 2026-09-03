<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Exception\TailwindPhpException;

/**
 * Contract every exception in the library has to satisfy.
 *
 * Each concrete case supplies one representative instance through
 * {@see makeException()}; the shared tests then assert the two guarantees the
 * hierarchy makes to integrators (design doc 15 §Exception hierarchy,
 * §Logging): the marker interface is implemented, and context() is safe to
 * hand straight to a PSR-3 logger.
 *
 * The file is deliberately *not* named `*Test.php` so PHPUnit does not try to
 * collect this abstract class as a test case of its own.
 */
abstract class ExceptionTestCase extends TestCase
{
    /**
     * Hostile values that must never survive into a message or a context array.
     * Every string argument of every constructor is fed one of these by
     * {@see makeHostileException()}.
     */
    public const HOSTILE = [
        '/etc/passwd',
        'C:\\Windows\\system32',
        '\\\\server\\share\\secret',
        'file:///etc/passwd',
        ' /leading/space',
    ];

    /**
     * One representative instance of the exception under test.
     */
    abstract protected function makeException(): TailwindPhpException;

    /**
     * The same exception built with an absolute path in *every* string argument
     * it accepts — free-text fields included (message, stderr, problem
     * sentences, reasons, diagnostic messages, extra context).
     *
     * The redaction contract is structural, not a convention followed by
     * whoever writes the call site, so it has to hold even when a producer
     * hands the constructor a path it never anticipated. Real cases: the
     * Tailwind CLI prints absolute input/output paths in its stderr, and a
     * config problem sentence quotes the file it is complaining about.
     */
    abstract protected function makeHostileException(): TailwindPhpException;

    /** @test */
    public function noAbsolutePathSurvivesIntoTheMessage(): void
    {
        $message = $this->makeHostileException()->getMessage();

        foreach (self::HOSTILE as $hostile) {
            self::assertStringNotContainsString(
                \trim($hostile),
                $message,
                \sprintf('getMessage() leaked "%s".', $hostile)
            );
        }
    }

    /** @test */
    public function noAbsolutePathSurvivesIntoTheContext(): void
    {
        $context = $this->makeHostileException()->context();

        foreach ($context as $value) {
            self::assertValueIsLogSafe($value);
            self::assertValueCarriesNoHostileSubstring($value);
        }
    }

    /**
     * A getter that returns the raw value while context() is scrubbed is the
     * same leak by another route — the debug overlay and the console renderer
     * both read accessors, not context(). Every public no-argument string
     * getter is checked by reflection so a new one cannot be added and
     * forgotten.
     *
     * @test
     */
    public function noAbsolutePathSurvivesIntoAnyStringAccessor(): void
    {
        $exception = $this->makeHostileException();
        $reflection = new \ReflectionClass($exception);
        $checked = 0;

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $name = $method->getName();

            // getTrace()/getTraceAsString() legitimately contain file paths;
            // they are PHP's own API, never our log or overlay output.
            if (\strncmp($name, 'getTrace', 8) === 0 || $name === '__toString' || $name === 'getFile') {
                continue;
            }

            $value = $method->invoke($exception);

            if (!\is_string($value)) {
                continue;
            }

            ++$checked;

            foreach (self::HOSTILE as $hostile) {
                self::assertStringNotContainsString(
                    \trim($hostile),
                    $value,
                    \sprintf('%s() leaked "%s".', $name, $hostile)
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'At least getMessage() should have been checked.');
    }

    /** @test */
    public function theRedactionMarkerIsVisible(): void
    {
        $exception = $this->makeHostileException();

        $haystack = $exception->getMessage() . '|' . self::flatten($exception->context());

        self::assertTrue(
            \strpos($haystack, '<path>') !== false
                || \strpos($haystack, '<absolute path redacted>') !== false,
            'A redacted value must leave a visible marker rather than vanish.'
        );
    }

    /**
     * @param mixed $value
     */
    private static function assertValueCarriesNoHostileSubstring($value): void
    {
        if (\is_array($value)) {
            foreach ($value as $nested) {
                self::assertValueCarriesNoHostileSubstring($nested);
            }

            return;
        }

        if (!\is_string($value)) {
            return;
        }

        foreach (self::HOSTILE as $hostile) {
            self::assertStringNotContainsString(
                \trim($hostile),
                $value,
                \sprintf('context() leaked "%s".', $hostile)
            );
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function flatten(array $context): string
    {
        $out = '';

        foreach ($context as $value) {
            if (\is_array($value)) {
                $out .= self::flatten($value);

                continue;
            }

            if (\is_string($value)) {
                $out .= $value . '|';
            }
        }

        return $out;
    }

    /** @test */
    public function implementsTheMarkerInterface(): void
    {
        $exception = $this->makeException();

        self::assertInstanceOf(TailwindPhpException::class, $exception);
        self::assertInstanceOf(\Throwable::class, $exception);
    }

    /** @test */
    public function contextIsLogSafe(): void
    {
        $context = $this->makeException()->context();

        foreach ($context as $value) {
            self::assertValueIsLogSafe($value);
        }
    }

    /**
     * Scalars, null and nested arrays of those only — and no string that looks
     * like an absolute filesystem path (threat T-06).
     *
     * @param mixed $value
     */
    private static function assertValueIsLogSafe($value): void
    {
        if (\is_array($value)) {
            foreach ($value as $nested) {
                self::assertValueIsLogSafe($nested);
            }

            return;
        }

        if ($value === null) {
            return;
        }

        self::assertTrue(
            \is_scalar($value),
            'Context values must be scalar, null, or arrays of scalars and null.'
        );

        if (\is_string($value)) {
            self::assertFalse(
                self::looksAbsolute($value),
                \sprintf('Context value "%s" looks like an absolute path.', $value)
            );
        }
    }

    /**
     * Absolute-path detection without a regular expression: POSIX root, UNC
     * share, or a Windows drive letter.
     */
    private static function looksAbsolute(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if ($value[0] === '/') {
            return true;
        }

        if (\strncmp($value, '\\\\', 2) === 0) {
            return true;
        }

        if (\strlen($value) < 3 || $value[1] !== ':') {
            return false;
        }

        // ASCII-only rather than ctype_alpha(): that needs ext-ctype, which is
        // not a declared dependency (ADR-008), and is locale-dependent.
        $ordinal = \ord($value[0]);
        $isLetter = ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);

        return $isLetter && ($value[2] === '/' || $value[2] === '\\');
    }
}
