<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * The single implementation of {@see TailwindPhpException::context()} and of the
 * log-safety rule that goes with it.
 *
 * Every exception in the hierarchy composes this trait, so the contract —
 * scalars only, and never an absolute path (design doc 15 §Logging, threat
 * T-06) — has exactly one place to be audited.
 *
 * The rule is enforced **structurally rather than by convention**. Early
 * versions only redacted the handful of parameters that were obviously paths,
 * which left every free-text field as a leak: the Tailwind CLI prints absolute
 * input and output paths in its stderr, a config problem sentence quotes the
 * file it is complaining about, and a diagnostic message names the import it
 * could not find. So {@see LogSafety} runs over *every* string that reaches a
 * message, a context array or a public accessor.
 *
 * @internal
 */
trait ExceptionContext
{
    /**
     * @var array<string, mixed>
     */
    private $context = [];

    /**
     * The visible replacement for a redacted location.
     *
     * A method rather than a constant because traits cannot declare constants on
     * the PHP 7.3 floor.
     */
    public static function pathMarker(): string
    {
        return LogSafety::marker();
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Set once from a constructor. Protected rather than private so the leaf
     * classes — which extend a branch parent that composes this trait — can
     * enrich the context with their own payload.
     *
     * Every string in the array, at any depth, is scrubbed on the way in.
     *
     * @param array<string, mixed> $context
     */
    protected function initContext(array $context): void
    {
        $this->context = self::scrubArray($context);
    }

    /**
     * Scrub free text before it becomes a message or an accessor's value.
     */
    protected static function scrubText(string $text): string
    {
        return LogSafety::scrubText($text);
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @return array<array-key, mixed>
     */
    protected static function scrubArray(array $context): array
    {
        return LogSafety::scrubArray($context);
    }

    /**
     * Whole-value replacement for the dedicated path parameters, where naming
     * the whole argument reads better than a per-token marker. Anything that is
     * not obviously absolute still goes through the token scrubber, which is
     * what catches wrapper URLs and paths glued behind a label.
     */
    protected static function redactIfAbsolute(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (\strpos($path, "\0") !== false) {
            return '<invalid path redacted>';
        }

        $isAbsolute = \strncmp($path, '/', 1) === 0
            || \strncmp($path, '\\', 1) === 0
            || (\strlen($path) >= 2 && $path[1] === ':' && self::isAsciiAlphaByte($path[0]));

        return $isAbsolute ? '<absolute path redacted>' : LogSafety::scrubText($path);
    }

    private static function isAsciiAlphaByte(string $byte): bool
    {
        $ordinal = \ord($byte);

        return ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);
    }
}
