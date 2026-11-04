<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * A declaration: `property: value` with an optional `!important`.
 *
 * `value` is the authored text between the colon and the end of the
 * declaration, trimmed, without the `!important` flag; a custom property
 * (`--x: { anything }`) keeps its value verbatim. Positions are filled only
 * when the parser runs in debug mode.
 */
final class Declaration implements Node
{
    /**
     * @var string
     */
    public $property;

    /**
     * @var string
     */
    public $value;

    /**
     * @var bool
     */
    public $important;

    /**
     * @var int|null
     */
    public $line;

    /**
     * @var int|null
     */
    public $col;

    public function __construct(
        string $property,
        string $value,
        bool $important = false,
        ?int $line = null,
        ?int $col = null
    ) {
        $this->property = $property;
        $this->value = $value;
        $this->important = $important;
        $this->line = $line;
        $this->col = $col;
    }
}
