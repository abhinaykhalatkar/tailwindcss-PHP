<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate;

use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;

/**
 * The part after a depth-0 `/` (design doc 07 step 9): `bg-red-500/50`
 * (named), `/[0.33]` (arbitrary, validated and normalised) or `/(--o)`
 * (a CSS variable; `value` is the name, the compiler wraps it in `var()`).
 */
final class Modifier
{
    public const NAMED = 'named';
    public const ARBITRARY = 'arbitrary';
    public const VAR = 'var';

    /**
     * @var string One of the constants above.
     */
    public $kind;

    /**
     * @var string
     */
    public $value;

    public function __construct(string $kind, string $value)
    {
        $this->kind = $kind;
        $this->value = $value;
    }

    /**
     * Parse modifier text, or null when it is empty or unsafe (the whole
     * candidate is then rejected, as v4 does for `bg-red-500/[]`).
     */
    public static function parse(string $text): ?self
    {
        if ($text === '') {
            return null;
        }

        $last = $text[\strlen($text) - 1];

        if ($text[0] === '[') {
            if ($last !== ']') {
                return null;
            }

            $value = ArbitraryValue::validate((string) \substr($text, 1, -1));

            return $value === null || \trim($value) === '' ? null : new self(self::ARBITRARY, $value);
        }

        if ($text[0] === '(') {
            $inner = (string) \substr($text, 1, -1);

            if ($last !== ')' || \strncmp($inner, '--', 2) !== 0 || \strlen($inner) === 2) {
                return null;
            }

            // Raw and normalised must both be safe (as for `[…]`), though the
            // name is kept with its underscores.
            return ArbitraryValue::validate($inner) !== null ? new self(self::VAR, $inner) : null;
        }

        return Parser::isPlainName($text) ? new self(self::NAMED, $text) : null;
    }
}
