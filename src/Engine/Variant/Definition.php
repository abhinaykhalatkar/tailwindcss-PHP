<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Variant;

use TailwindPhp\Engine\Css\SelectorList;
use TailwindPhp\Support\Str;

/**
 * One variant, built from a row of `Data\Variants::table()` (design doc 07
 * §Phase 3c). An immutable value that has been through the schema check once, so
 * the registry and the applier can trust its shape.
 *
 * The row keys:
 *
 *     selector    a selector, or a list of selectors, each containing `&`
 *                 (the candidate's class selector goes where `&` is)
 *     media       an `@media` query; supports / container likewise
 *     starting-style  `true`: wrap in `@starting-style { … }` (a custom variant
 *                 `@custom-variant x (@starting-style)`; no query)
 *     compound    `group`, `peer`: the row is a template for a compound variant
 *     inner       on an expanded compound row, the inner variant's selector
 *     inject      declarations added inside the selector rule (`before`, `after`)
 *     order       the variant's place in v4's order (lower sorts first)
 *     functional  the variant takes a value; `{value}` in the template stands for it
 *     theme       where a functional variant's named values come from
 *     named       the fixed named values of a functional variant
 *
 * A bad row is a programming error in a hand-maintained table, so it throws
 * `\InvalidArgumentException` when the definition is built, naming the variant
 * (truncated) and the rule broken but never echoing a value.
 */
final class Definition
{
    private const KEYS = [
        'selector' => true,
        'media' => true,
        'supports' => true,
        'container' => true,
        'starting-style' => true,
        'compound' => true,
        'inner' => true,
        'inject' => true,
        'order' => true,
        'functional' => true,
        'theme' => true,
        'named' => true,
    ];

    private const PLACEHOLDER = '{value}';

    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $order;

    /**
     * @var list<string>
     */
    private $selectors;

    /**
     * @var string|null
     */
    private $media;

    /**
     * @var string|null
     */
    private $supports;

    /**
     * @var string|null
     */
    private $container;

    /**
     * @var bool
     */
    private $startingStyle;

    /**
     * @var string|null
     */
    private $compound;

    /**
     * @var string|null
     */
    private $inner;

    /**
     * @var array<string, string>
     */
    private $inject;

    /**
     * @var string|null
     */
    private $functional;

    /**
     * @var string|null
     */
    private $theme;

    /**
     * @var list<string>
     */
    private $named;

    /**
     * @param list<string> $selectors
     * @param array<string, string> $inject
     * @param list<string> $named
     */
    private function __construct(
        string $name,
        int $order,
        array $selectors,
        ?string $media,
        ?string $supports,
        ?string $container,
        bool $startingStyle,
        ?string $compound,
        ?string $inner,
        array $inject,
        ?string $functional,
        ?string $theme,
        array $named
    ) {
        $this->name = $name;
        $this->order = $order;
        $this->selectors = $selectors;
        $this->media = $media;
        $this->supports = $supports;
        $this->container = $container;
        $this->startingStyle = $startingStyle;
        $this->compound = $compound;
        $this->inner = $inner;
        $this->inject = $inject;
        $this->functional = $functional;
        $this->theme = $theme;
        $this->named = $named;
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws \InvalidArgumentException When the row breaks the schema.
     */
    public static function fromRow(string $name, array $row): self
    {
        if ($name === '' || \strpbrk($name, " \t\r\n\f") !== false) {
            throw new \InvalidArgumentException('A variant name must be non-empty and contain no whitespace.');
        }

        $label = 'Variant "' . Str::truncateBytes($name, 40) . '"';

        foreach (\array_keys($row) as $key) {
            if (!\is_string($key) || !isset(self::KEYS[$key])) {
                throw new \InvalidArgumentException($label . ' has an unknown row key.');
            }
        }

        if (!isset($row['order']) || !\is_int($row['order']) || $row['order'] < 0) {
            throw new \InvalidArgumentException($label . ' needs a non-negative integer "order".');
        }

        $selectors = self::parseSelectors($label, $row['selector'] ?? null, \array_key_exists('selector', $row));
        $media = self::optionalString($label, 'media', $row);
        $supports = self::optionalString($label, 'supports', $row);
        $container = self::optionalString($label, 'container', $row);
        $compound = self::optionalString($label, 'compound', $row);
        $inner = self::optionalString($label, 'inner', $row);
        $functional = self::optionalString($label, 'functional', $row);
        $theme = self::optionalString($label, 'theme', $row);

        $startingStyle = $row['starting-style'] ?? false;

        if (!\is_bool($startingStyle)) {
            throw new \InvalidArgumentException($label . ' has a "starting-style" that is not a boolean.');
        }

        if ($selectors === [] && $media === null && $supports === null && $container === null && !$startingStyle) {
            throw new \InvalidArgumentException(
                $label . ' needs a selector, media, supports, container or starting-style.'
            );
        }

        if ($inner !== null && ($compound === null || !SelectorList::containsAmpersand($inner))) {
            throw new \InvalidArgumentException($label . ' has an "inner" selector that is not part of a compound.');
        }

        if ($functional !== null && !self::hasPlaceholder($selectors, [$media, $supports, $container])) {
            throw new \InvalidArgumentException($label . ' is functional but no template holds {value}.');
        }

        return new self(
            $name,
            $row['order'],
            $selectors,
            $media,
            $supports,
            $container,
            $startingStyle,
            $compound,
            $inner,
            self::parseInject($label, $row['inject'] ?? []),
            $functional,
            $theme,
            self::parseNamed($label, $row['named'] ?? [])
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function order(): int
    {
        return $this->order;
    }

    /**
     * @return list<string>
     */
    public function selectors(): array
    {
        return $this->selectors;
    }

    public function media(): ?string
    {
        return $this->media;
    }

    public function supports(): ?string
    {
        return $this->supports;
    }

    public function container(): ?string
    {
        return $this->container;
    }

    /**
     * Whether the variant wraps in `@starting-style` (no query).
     */
    public function startingStyle(): bool
    {
        return $this->startingStyle;
    }

    public function compound(): ?string
    {
        return $this->compound;
    }

    public function inner(): ?string
    {
        return $this->inner;
    }

    /**
     * @return array<string, string>
     */
    public function inject(): array
    {
        return $this->inject;
    }

    public function functional(): ?string
    {
        return $this->functional;
    }

    public function isFunctional(): bool
    {
        return $this->functional !== null;
    }

    public function theme(): ?string
    {
        return $this->theme;
    }

    /**
     * @return list<string>
     */
    public function named(): array
    {
        return $this->named;
    }

    /**
     * A copy under another name and order, for the registry's generated rows
     * (breakpoints, compound expansions, custom variants).
     */
    public function withNameAndOrder(string $name, int $order): self
    {
        if ($name === '' || \strpbrk($name, " \t\r\n\f") !== false || $order < 0) {
            throw new \InvalidArgumentException('A variant name must be non-empty and contain no whitespace.');
        }

        $copy = clone $this;
        $copy->name = $name;
        $copy->order = $order;

        return $copy;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function parseSelectors(string $label, $value, bool $present): array
    {
        if (!$present) {
            return [];
        }

        $list = \is_array($value) ? $value : [$value];

        if ($list === [] || \array_keys($list) !== \range(0, \count($list) - 1)) {
            throw new \InvalidArgumentException($label . ' needs a selector or a non-empty list of selectors.');
        }

        $selectors = [];

        foreach ($list as $selector) {
            if (!\is_string($selector) || $selector === '' || !SelectorList::containsAmpersand($selector)) {
                throw new \InvalidArgumentException($label . ' has a selector that is not text containing "&".');
            }

            $selectors[] = $selector;
        }

        return $selectors;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function optionalString(string $label, string $key, array $row): ?string
    {
        if (!\array_key_exists($key, $row)) {
            return null;
        }

        if (!\is_string($row[$key]) || $row[$key] === '') {
            throw new \InvalidArgumentException($label . ' has a "' . $key . '" that is not non-empty text.');
        }

        return $row[$key];
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>
     */
    private static function parseInject(string $label, $value): array
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException($label . ' has an "inject" that is not a map.');
        }

        $inject = [];

        foreach ($value as $property => $declaration) {
            if (!\is_string($property) || $property === '' || !\is_string($declaration)) {
                throw new \InvalidArgumentException($label . ' has an "inject" that is not property => text.');
            }

            $inject[$property] = $declaration;
        }

        return $inject;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function parseNamed(string $label, $value): array
    {
        if (!\is_array($value) || ($value !== [] && \array_keys($value) !== \range(0, \count($value) - 1))) {
            throw new \InvalidArgumentException($label . ' has a "named" that is not a list.');
        }

        $named = [];

        foreach ($value as $item) {
            if (!\is_string($item) || $item === '') {
                throw new \InvalidArgumentException($label . ' has a "named" entry that is not non-empty text.');
            }

            $named[] = $item;
        }

        return $named;
    }

    /**
     * @param list<string> $selectors
     * @param list<string|null> $others
     */
    private static function hasPlaceholder(array $selectors, array $others): bool
    {
        foreach (\array_merge($selectors, $others) as $template) {
            if ($template !== null && \strpos($template, self::PLACEHOLDER) !== false) {
                return true;
            }
        }

        return false;
    }
}
