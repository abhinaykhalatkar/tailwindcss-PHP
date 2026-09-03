<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Diagnostic;

use TailwindPhp\Exception\LogSafety;

/**
 * One compiler-level problem with a stable, documented code
 * (design doc 15 §Diagnostics).
 *
 * Diagnostics are not exceptions: they describe something wrong with the *input*
 * (an unknown utility, a malformed `@theme`) and are collected rather than
 * thrown, so one compile can report every problem at once. Infrastructure
 * failures are exceptions instead.
 *
 * Immutable: the fields are set once in the constructor and exposed through
 * getters named after the property (design doc 18 §Design rules, §Naming).
 * `file` is always project-relative (ADR-014) and `message` never contains an
 * absolute path (threat T-06) — both are the caller's responsibility.
 */
final class Diagnostic
{
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const INFO = 'info';

    public const E_CSS_SYNTAX = 'E_CSS_SYNTAX';
    public const E_IMPORT_NOT_FOUND = 'E_IMPORT_NOT_FOUND';
    public const E_IMPORT_CYCLE = 'E_IMPORT_CYCLE';
    public const E_IMPORT_DEPTH = 'E_IMPORT_DEPTH';
    public const E_UNSUPPORTED_DIRECTIVE = 'E_UNSUPPORTED_DIRECTIVE';
    public const E_APPLY_UNKNOWN = 'E_APPLY_UNKNOWN';
    public const E_APPLY_DEPTH = 'E_APPLY_DEPTH';
    public const E_THEME_INVALID = 'E_THEME_INVALID';
    public const E_CLI_FAILED = 'E_CLI_FAILED';
    public const E_CLI_TIMEOUT = 'E_CLI_TIMEOUT';

    public const W_UNKNOWN_UTILITY = 'W_UNKNOWN_UTILITY';
    public const W_UNKNOWN_VARIANT = 'W_UNKNOWN_VARIANT';
    public const W_INVALID_ARBITRARY = 'W_INVALID_ARBITRARY';
    public const W_CANDIDATE_LIMIT = 'W_CANDIDATE_LIMIT';
    public const W_FILE_TOO_LARGE = 'W_FILE_TOO_LARGE';
    public const W_BINARY_SKIPPED = 'W_BINARY_SKIPPED';
    public const W_ROOT_MISSING = 'W_ROOT_MISSING';
    public const W_MANIFEST_CORRUPT = 'W_MANIFEST_CORRUPT';
    public const W_CHECKSUM_UNKNOWN = 'W_CHECKSUM_UNKNOWN';

    public const I_DRIVER_SELECTED = 'I_DRIVER_SELECTED';

    /**
     * Every documented code, for validation.
     *
     * @var list<string>
     */
    private const CODES = [
        self::E_CSS_SYNTAX,
        self::E_IMPORT_NOT_FOUND,
        self::E_IMPORT_CYCLE,
        self::E_IMPORT_DEPTH,
        self::E_UNSUPPORTED_DIRECTIVE,
        self::E_APPLY_UNKNOWN,
        self::E_APPLY_DEPTH,
        self::E_THEME_INVALID,
        self::E_CLI_FAILED,
        self::E_CLI_TIMEOUT,
        self::W_UNKNOWN_UTILITY,
        self::W_UNKNOWN_VARIANT,
        self::W_INVALID_ARBITRARY,
        self::W_CANDIDATE_LIMIT,
        self::W_FILE_TOO_LARGE,
        self::W_BINARY_SKIPPED,
        self::W_ROOT_MISSING,
        self::W_MANIFEST_CORRUPT,
        self::W_CHECKSUM_UNKNOWN,
        self::I_DRIVER_SELECTED,
    ];

    /**
     * @var string
     */
    private $level;

    /**
     * @var string
     */
    private $code;

    /**
     * @var string
     */
    private $message;

    /**
     * @var string|null
     */
    private $file;

    /**
     * @var int|null
     */
    private $line;

    /**
     * @var string|null
     */
    private $candidate;

    /**
     * @param string $level One of self::ERROR, self::WARNING, self::INFO.
     * @param string $code One of the stable code constants on this class.
     * @param string $message Human text, free of absolute paths.
     * @param string|null $file Project-relative path the problem sits in.
     * @param int|null $line 1-based line number within $file.
     * @param string|null $candidate The class-name candidate that triggered it.
     *
     * @throws \InvalidArgumentException When $level is not one of the three levels.
     */
    public function __construct(
        string $level,
        string $code,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $candidate = null
    ) {
        if ($level !== self::ERROR && $level !== self::WARNING && $level !== self::INFO) {
            throw new \InvalidArgumentException(\sprintf('Unknown diagnostic level "%s".', $level));
        }

        // The codes are a published contract (design doc 15 §Diagnostics):
        // `doctor` prints them and integrators match on them, so an ad-hoc code
        // is a bug rather than an extension point.
        if (!\in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown diagnostic code "%s".', $code));
        }

        // `file` is project-relative everywhere in this library (ADR-014) and
        // anything else would travel straight into a log, the debug overlay or
        // the manifest's warnings[] (threat T-06), so producers are held to a
        // clean relative path here rather than trusted.
        if ($file !== null && !self::isCleanRelativePath($file)) {
            throw new \InvalidArgumentException(
                'Diagnostic file must be a clean project-relative path: no root, drive, wrapper, '
                . '".." segment or surrounding whitespace.'
            );
        }

        $this->level = $level;
        $this->code = $code;
        // Free text reaches the manifest and the overlay verbatim, so it is
        // scrubbed at construction rather than at each consumer.
        $this->message = LogSafety::scrubText($message);
        $this->file = $file;
        $this->line = $line;
        $this->candidate = $candidate === null ? null : LogSafety::scrubText($candidate);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function file(): ?string
    {
        return $this->file;
    }

    public function line(): ?int
    {
        return $this->line;
    }

    public function candidate(): ?string
    {
        return $this->candidate;
    }

    public function isError(): bool
    {
        return $this->level === self::ERROR;
    }

    public function isWarning(): bool
    {
        return $this->level === self::WARNING;
    }

    /**
     * A path fit to store in an artefact: relative, no wrapper, no traversal,
     * no drive, no surrounding whitespace (ADR-014).
     */
    private static function isCleanRelativePath(string $path): bool
    {
        if ($path === '' || \trim($path) !== $path) {
            return false;
        }

        if (\strpos($path, "\0") !== false) {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return false;
        }

        // A drive prefix, with or without a following separator ("C:x" is
        // drive-relative and just as unusable in an artefact).
        if (\strlen($path) >= 2 && $path[1] === ':') {
            $ordinal = \ord($path[0]);

            if (($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122)) {
                return false;
            }
        }

        // Checked independently of LogSafety, which deliberately exempts
        // http(s) from *scrubbing* — a URL is fine in a sentence but is never a
        // project-relative artefact path (ADR-014).
        if (self::hasAnyScheme($path)) {
            return false;
        }

        if (LogSafety::looksLikePath($path)) {
            return false;
        }

        foreach (\explode('/', \str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * True for `scheme://…` with any scheme at all, and for the schemeless
     * `data:` wrapper.
     */
    private static function hasAnyScheme(string $path): bool
    {
        if (\strncasecmp($path, 'data:', 5) === 0) {
            return true;
        }

        $position = \strpos($path, '://');

        if ($position === false || $position === 0) {
            return false;
        }

        for ($i = 0; $i < $position; ++$i) {
            $ordinal = \ord($path[$i]);
            $char = $path[$i];

            $isLetter = ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);
            $isDigit = $ordinal >= 48 && $ordinal <= 57;

            if (!$isLetter && !$isDigit && $char !== '+' && $char !== '.' && $char !== '-') {
                return false;
            }
        }

        $first = \ord($path[0]);

        return ($first >= 65 && $first <= 90) || ($first >= 97 && $first <= 122);
    }

    /**
     * Log-safe projection used for exception context arrays and the manifest's
     * `warnings[]` list (design doc 15 §Diagnostics).
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'code' => $this->code,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'candidate' => $this->candidate,
        ];
    }
}
