<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;

/**
 * `@layer` (design doc 07 §Pipeline): a statement `@layer a, b;` declares
 * layer order; a block `@layer name { … }` holds authored CSS, which stays in
 * the authored stream as written (the CLI emits it in source order after its
 * own layers); only statements declare order.
 */
final class LayerHandler
{
    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_.';

    /**
     * The layer names of `@layer` (a statement's list, a block's single name);
     * names that are not plain identifiers are dropped.
     *
     * @return list<string>
     */
    public static function names(AtRule $layer): array
    {
        $names = [];

        foreach (\explode(',', $layer->params) as $name) {
            $name = \trim($name);

            if ($name !== '' && \strlen($name) <= 64 && \strspn($name, self::NAME_BYTES) === \strlen($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Add $names to $order, keeping the first position of each.
     *
     * @param list<string> $order
     * @param list<string> $names
     */
    public static function record(array &$order, array $names): void
    {
        foreach ($names as $name) {
            if (!\in_array($name, $order, true)) {
                $order[] = $name;
            }
        }
    }
}
