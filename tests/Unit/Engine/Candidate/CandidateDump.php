<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Candidate;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Candidate\Variant;

/**
 * Projections of parsed candidates for assertions.
 *
 * `line()` is a compact one-line form for the grammar table:
 *
 *     [md,hover]!-fn:mt=4 /50     variants, important, negative, root=value, modifier
 *     static:flex                 a static candidate
 *     fn:w=1/2 (fraction)         a fraction value
 *     fn:bg=[length:var(--x)]     an arbitrary value with its type hint
 *     fn:bg=(color:--brand)       a variable value
 *     prop:color=red              an arbitrary property
 *
 * `fields()` is the full structure, for the contract test.
 */
final class CandidateDump
{
    private function __construct()
    {
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return list<string>
     */
    public static function lines(array $candidates): array
    {
        $lines = [];

        foreach ($candidates as $candidate) {
            $lines[] = self::line($candidate);
        }

        return $lines;
    }

    public static function line(Candidate $candidate): string
    {
        $text = '';

        if ($candidate->variants !== []) {
            $variants = [];

            foreach ($candidate->variants as $variant) {
                $variants[] = self::variant($variant);
            }

            $text .= '[' . \implode(',', $variants) . ']';
        }

        $text .= ($candidate->important ? '!' : '') . ($candidate->negative ? '-' : '');

        if ($candidate->kind === Candidate::STATIC) {
            $text .= 'static:' . $candidate->root;
        } elseif ($candidate->kind === Candidate::FUNCTIONAL) {
            $text .= 'fn:' . $candidate->root . '=' . self::value($candidate->value);
        } else {
            $text .= 'prop:' . $candidate->property . '=' . self::value($candidate->value);
        }

        if ($candidate->modifier !== null) {
            $text .= ' /' . self::modifier($candidate->modifier);
        }

        return $text;
    }

    public static function variant(Variant $variant): string
    {
        $text = $variant->name;

        if ($variant->value !== null) {
            $text .= '-' . $variant->value;
        }

        if ($variant->arbitrary !== null) {
            $text .= '[' . $variant->arbitrary . ']';
        }

        if ($variant->modifier !== null) {
            $text .= '/' . self::modifier($variant->modifier);
        }

        return $text;
    }

    /**
     * @param list<Candidate> $candidates
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(array $candidates): array
    {
        $rows = [];

        foreach ($candidates as $candidate) {
            $value = $candidate->value;
            $rows[] = [
                'kind' => $candidate->kind,
                'root' => $candidate->root,
                'value' => $value === null ? null : [\get_class($value) => self::value($value)],
                'modifier' => $candidate->modifier === null
                    ? null
                    : [$candidate->modifier->kind => $candidate->modifier->value],
                'important' => $candidate->important,
                'negative' => $candidate->negative,
                'property' => $candidate->property,
                'raw' => $candidate->raw,
            ];
        }

        return $rows;
    }

    private static function value(?ValueInterface $value): string
    {
        if ($value instanceof NamedValue) {
            return $value->name . ($value->fraction ? ' (fraction)' : '');
        }

        if ($value instanceof ArbitraryValue) {
            return '[' . ($value->dataType === null ? '' : $value->dataType . ':') . $value->normalised . ']';
        }

        if ($value instanceof VarValue) {
            return '(' . ($value->dataType === null ? '' : $value->dataType . ':') . $value->name . ')';
        }

        return '';
    }

    private static function modifier(Modifier $modifier): string
    {
        if ($modifier->kind === Modifier::ARBITRARY) {
            return '[' . $modifier->value . ']';
        }

        if ($modifier->kind === Modifier::VAR) {
            return '(' . $modifier->value . ')';
        }

        return $modifier->value;
    }
}
