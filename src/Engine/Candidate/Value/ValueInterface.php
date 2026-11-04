<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Candidate\Value;

/**
 * A functional utility's value: {@see NamedValue} (`p-4`, `w-1/2`),
 * {@see ArbitraryValue} (`w-[32rem]`) or {@see VarValue} (`bg-(--brand)`).
 *
 * A marker only; the utility compiler switches on the concrete class.
 */
interface ValueInterface
{
}
