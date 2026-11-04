<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate;

/**
 * One variant of a candidate, as written (design doc 07 grammar):
 *
 *     hover            name 'hover'
 *     group-hover      name 'group', value 'hover'  (when the variant root set
 *                      knows `group`; without one, name 'group-hover')
 *     data-[state=on]  name 'data', arbitrary 'state=on'
 *     [&>*]            name '', arbitrary '&>*'
 *     supports-(--x)   name 'supports', arbitrary 'var(--x)'
 *     group-hover/item … plus modifier 'item'
 *
 * `arbitrary` has passed `ArbitraryValue::validate` and is normalised
 * (underscores → spaces). Interpreting the variant — selector, at-rule,
 * compound — is `Variant\Registry`'s job (TASK-0031); this is plain data.
 */
final class Variant
{
    /**
     * @var string Empty for a bare `[…]` variant.
     */
    public $name;

    /**
     * @var string|null
     */
    public $value;

    /**
     * @var string|null
     */
    public $arbitrary;

    /**
     * @var string The variant text as written, without the trailing `:`.
     */
    public $raw;

    /**
     * @var Modifier|null
     */
    public $modifier;

    public function __construct(
        string $name,
        ?string $value,
        ?string $arbitrary,
        string $raw,
        ?Modifier $modifier = null
    ) {
        $this->name = $name;
        $this->value = $value;
        $this->arbitrary = $arbitrary;
        $this->raw = $raw;
        $this->modifier = $modifier;
    }
}
