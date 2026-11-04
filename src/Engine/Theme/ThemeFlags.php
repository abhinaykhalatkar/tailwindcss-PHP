<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Theme;

use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Str;

/**
 * The options of one `@theme` block (design doc 07 §Phase 2).
 *
 * - `inline`    — utilities substitute the value instead of writing `var(--x)`.
 * - `static`    — every variable of the block is emitted, used or not.
 * - `reference` — the variables resolve, but are never emitted.
 * - `default`   — the block has lower precedence than any non-default one.
 *
 * Written `@theme inline reference { … }`, in any order, with any whitespace
 * between the words. An unknown word is reported as `E_THEME_INVALID` naming it,
 * and the rest of the block is processed all the same: one misspelt option
 * should not silently drop a hundred variables.
 *
 * Immutable.
 */
final class ThemeFlags
{
    /**
     * Whitespace that separates words in an at-rule prelude.
     */
    private const SEPARATORS = " \t\r\n\f";

    /**
     * Most of an unknown word that is echoed back in a diagnostic.
     */
    private const MAX_ECHO_BYTES = 40;

    /**
     * @var bool
     */
    private $inline;

    /**
     * @var bool
     */
    private $static;

    /**
     * @var bool
     */
    private $reference;

    /**
     * @var bool
     */
    private $default;

    public function __construct(
        bool $inline = false,
        bool $static = false,
        bool $reference = false,
        bool $default = false
    ) {
        $this->inline = $inline;
        $this->static = $static;
        $this->reference = $reference;
        $this->default = $default;
    }

    /**
     * No options: a plain `@theme { … }`.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Read the prelude of an `@theme` rule (the text between `@theme` and `{`).
     *
     * Options are matched exactly and in lower case, as Tailwind does.
     */
    public static function parse(string $params, DiagnosticBag $diagnostics): self
    {
        $inline = false;
        $static = false;
        $reference = false;
        $default = false;

        foreach (self::words($params) as $word) {
            switch ($word) {
                case 'inline':
                    $inline = true;

                    break;
                case 'static':
                    $static = true;

                    break;
                case 'reference':
                    $reference = true;

                    break;
                case 'default':
                    $default = true;

                    break;
                default:
                    $diagnostics->add(new Diagnostic(
                        Diagnostic::ERROR,
                        Diagnostic::E_THEME_INVALID,
                        \sprintf('Unknown @theme option "%s".', Str::truncateBytes($word, self::MAX_ECHO_BYTES))
                    ));
            }
        }

        return new self($inline, $static, $reference, $default);
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function isStatic(): bool
    {
        return $this->static;
    }

    public function isReference(): bool
    {
        return $this->reference;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    /**
     * @return list<string>
     */
    private static function words(string $params): array
    {
        $words = [];
        $length = \strlen($params);
        $start = -1;

        for ($i = 0; $i < $length; ++$i) {
            $separator = \strpos(self::SEPARATORS, $params[$i]) !== false;

            if ($separator && $start >= 0) {
                $words[] = (string) \substr($params, $start, $i - $start);
                $start = -1;
            } elseif (!$separator && $start < 0) {
                $start = $i;
            }
        }

        if ($start >= 0) {
            $words[] = (string) \substr($params, $start);
        }

        return $words;
    }
}
