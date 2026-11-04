<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Css\Node;

/**
 * A comment kept in the tree: the parser keeps only a leading `/*! … *\/`
 * licence banner (design doc 07 §Phase 1).
 *
 * `text` is everything between the opening and closing comment delimiters,
 * so a banner's text starts with `!`.
 */
final class Comment implements Node
{
    /**
     * @var string
     */
    public $text;

    /**
     * @var int|null
     */
    public $line;

    /**
     * @var int|null
     */
    public $col;

    public function __construct(string $text, ?int $line = null, ?int $col = null)
    {
        $this->text = $text;
        $this->line = $line;
        $this->col = $col;
    }
}
