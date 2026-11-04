<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate;

use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\ValueInterface;

/**
 * One interpretation of a candidate string (design doc 07 §Phase 3, ENG-A4,
 * ENG-A13).
 *
 * A candidate string may have several interpretations — one per (modifier
 * reading × matching root) — and each is complete on its own: one `root`, a
 * `value` classified from *that root's* tail, its modifier. The compiler
 * tries them in order and keeps the first that compiles; nothing is chosen
 * after the fact.
 *
 * - `static`: the whole utility is a root (`flex`, `text-center`, or a
 *   functional root used without a value such as `border`); `value` null.
 * - `functional`: `root` + `value` (`p-4`, `bg-[#fff]`, `bg-(--brand)`).
 * - `arbitrary`: an arbitrary property `[property:value]`; `root` null,
 *   `property` set, `value` an {@see ArbitraryValue}.
 *
 * `variants` are in written order (left to right); the applier applies them
 * right to left. Public properties set once by the constructor.
 */
final class Candidate
{
    public const STATIC = 'static';
    public const FUNCTIONAL = 'functional';
    public const ARBITRARY = 'arbitrary';

    /**
     * @var string The full candidate as written.
     */
    public $raw;

    /**
     * @var list<Variant>
     */
    public $variants;

    /**
     * @var bool
     */
    public $important;

    /**
     * @var bool
     */
    public $negative;

    /**
     * @var string One of the kind constants.
     */
    public $kind;

    /**
     * @var string|null Null for the arbitrary kind.
     */
    public $root;

    /**
     * @var ValueInterface|null
     */
    public $value;

    /**
     * @var Modifier|null
     */
    public $modifier;

    /**
     * @var string|null The property of an arbitrary-property candidate.
     */
    public $property;

    /**
     * @param list<Variant> $variants
     */
    public function __construct(
        string $raw,
        array $variants,
        bool $important,
        bool $negative,
        string $kind,
        ?string $root,
        ?ValueInterface $value = null,
        ?Modifier $modifier = null,
        ?string $property = null
    ) {
        $this->raw = $raw;
        $this->variants = $variants;
        $this->important = $important;
        $this->negative = $negative;
        $this->kind = $kind;
        $this->root = $root;
        $this->value = $value;
        $this->modifier = $modifier;
        $this->property = $property;
    }
}
