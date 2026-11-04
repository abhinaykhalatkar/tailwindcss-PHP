<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate\Value;

/**
 * A CSS-variable value written with parentheses: `bg-(--brand)` or, with a
 * type hint, `bg-(color:--brand)` (design doc 07 step 8).
 *
 * `name` is what goes inside `var(…)` — it always starts with `--` and may
 * carry a fallback (`--brand,red`). The compiler emits `var(<name>)`.
 */
final class VarValue implements ValueInterface
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var string|null
     */
    public $dataType;

    public function __construct(string $name, ?string $dataType = null)
    {
        $this->name = $name;
        $this->dataType = $dataType;
    }

    /**
     * Parse the text between the parentheses, or null when it is not a safe
     * custom-property reference. Underscores are kept: a variable name is
     * never decoded (v4 does the same). The text must nevertheless pass
     * {@see ArbitraryValue::validate()} — raw *and* normalised — so it stays
     * safe even if a later stage decodes a fallback's underscores.
     */
    public static function parse(string $inner): ?self
    {
        $dataType = null;
        $hint = ArbitraryValue::typeHintLength($inner);

        if ($hint > 0) {
            $dataType = (string) \substr($inner, 0, $hint);
            $inner = (string) \substr($inner, $hint + 1);
        }

        if (\strncmp($inner, '--', 2) !== 0 || \strlen($inner) === 2 || ArbitraryValue::validate($inner) === null) {
            return null;
        }

        return new self($inner, $dataType);
    }
}
