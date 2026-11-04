<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility;

use TailwindPhp\Engine\Candidate\Value\Classifier;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Data\PropertyOrder;

/**
 * One way a utility root compiles (design doc 07 §Definition schema): a row of
 * `Data\Utilities::table()`, validated once when it is built.
 *
 * A row is either one definition or, with `variants`, an ordered list of
 * sub-definitions tried in turn (`bg` → colour, image, size, …); keys written
 * next to `variants` (only `order`) apply to every sub-definition. A
 * definition is one of:
 *
 * - static: `static` (property => value), for a utility written without a
 *   value (`flex`, `sr-only`);
 * - functional: `props` receive the value resolved by {@see ValueResolver}
 *   from `named`, `theme`, `bare`, `fraction`, `arbitrary`, with `negative`,
 *   `default` (the value when the root is used alone) and `modifier`;
 * - handled: `handler` names a {@see Handlers} method for output that is not
 *   "property := value"; the functional keys may come with it for the
 *   handler to use;
 * - custom: the nodes of a static `@utility` ({@see self::custom()}).
 *
 * Unknown keys and ill-typed values are refused with an
 * `\InvalidArgumentException` — a mistake in the table fails at build time,
 * never as a silently missing utility.
 */
final class Definition
{
    /**
     * Bare-value kinds (design doc 07 rule 3).
     *
     * @var list<string>
     */
    public const BARE_KINDS = ['spacing-multiplier', 'integer', 'percentage', 'angle', 'duration', 'line-count'];

    /**
     * @var list<string>
     */
    public const MODIFIERS = ['opacity', 'line-height'];

    /**
     * @var array<string, true>
     */
    private const KEYS = [
        'static' => true, 'props' => true, 'theme' => true, 'bare' => true, 'fraction' => true,
        'negative' => true, 'arbitrary' => true, 'default' => true, 'order' => true, 'named' => true,
        'modifier' => true, 'handler' => true, 'variants' => true,
    ];

    /**
     * Keys that make a definition take a value.
     *
     * @var list<string>
     */
    private const FUNCTIONAL_KEYS = ['props', 'theme', 'bare', 'fraction', 'arbitrary', 'named', 'default'];

    /**
     * @var string
     */
    private $root;

    /**
     * @var array<string, string>|null
     */
    private $static;

    /**
     * @var list<Node>|null
     */
    private $nodes;

    /**
     * @var list<string>
     */
    private $props = [];

    /**
     * @var list<string>
     */
    private $theme = [];

    /**
     * @var string|null
     */
    private $bare;

    /**
     * @var bool
     */
    private $fraction = false;

    /**
     * @var bool
     */
    private $negative = false;

    /**
     * @var bool|list<string> True accepts any arbitrary value, a list only those types.
     */
    private $arbitrary = false;

    /**
     * @var string|null
     */
    private $default;

    /**
     * @var string|null
     */
    private $order;

    /**
     * @var array<string, string>
     */
    private $named = [];

    /**
     * @var string|null
     */
    private $modifier;

    /**
     * @var string|null
     */
    private $handler;

    /**
     * @var bool
     */
    private $functional = false;

    /**
     * @var list<self>
     */
    private $definitions = [];

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    /**
     * Build and validate the definition(s) of one table row.
     *
     * @param array<mixed> $row
     *
     * @throws \InvalidArgumentException
     */
    public static function fromRow(string $root, array $row): self
    {
        self::assertRoot($root);

        if (!\array_key_exists('variants', $row)) {
            $definition = self::single($root, $row);
            $definition->definitions = [$definition];

            return $definition;
        }

        $variants = $row['variants'];
        unset($row['variants']);

        foreach (\array_keys($row) as $key) {
            if ($key !== 'order') {
                throw self::invalid($root, \sprintf('key "%s" cannot sit next to "variants"', (string) $key));
            }
        }

        if (!\is_array($variants) || $variants === [] || !self::isList($variants)) {
            throw self::invalid($root, '"variants" must be a non-empty list of rows');
        }

        $parent = new self($root);
        $parent->order = self::optionalOrder($root, $row);

        foreach ($variants as $variant) {
            if (!\is_array($variant)) {
                throw self::invalid($root, 'each entry of "variants" must be a row');
            }

            if (\array_key_exists('variants', $variant) || \array_key_exists('order', $variant)) {
                throw self::invalid($root, 'a sub-definition takes neither "variants" nor "order"');
            }

            $sub = self::single($root, $variant);
            $sub->order = $parent->order;
            $parent->definitions[] = $sub;
            $parent->functional = $parent->functional || $sub->functional;
        }

        return $parent;
    }

    /**
     * A static `@utility` (design doc 07 Phase 4; the directive handler builds
     * the nodes): its body is emitted as written.
     *
     * @param list<Node> $nodes
     *
     * @throws \InvalidArgumentException
     */
    public static function custom(string $root, array $nodes): self
    {
        self::assertRoot($root);

        $definition = new self($root);
        $definition->nodes = $nodes;
        $definition->definitions = [$definition];

        return $definition;
    }

    /**
     * Whether a row takes a value (so the root also matches `<root>-…`),
     * checked without building it.
     *
     * @param array<mixed> $row
     */
    public static function rowIsFunctional(array $row): bool
    {
        if (isset($row['variants']) && \is_array($row['variants'])) {
            foreach ($row['variants'] as $variant) {
                if (\is_array($variant) && self::rowIsFunctional($variant)) {
                    return true;
                }
            }

            return false;
        }

        foreach (self::FUNCTIONAL_KEYS as $key) {
            if (\array_key_exists($key, $row)) {
                return true;
            }
        }

        return isset($row['handler'])
            && \is_string($row['handler'])
            && (Handlers::TAKES_VALUE[$row['handler']] ?? false);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The definitions to try, in order: the sub-definitions of a `variants`
     * row, otherwise this one.
     *
     * @return list<self>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Whether the root accepts a value (any sub-definition does).
     */
    public function isFunctional(): bool
    {
        return $this->functional;
    }

    /**
     * @return array<string, string>|null
     */
    public function staticDeclarations(): ?array
    {
        return $this->static;
    }

    /**
     * @return list<Node>|null
     */
    public function customNodes(): ?array
    {
        return $this->nodes;
    }

    /**
     * @return list<string>
     */
    public function props(): array
    {
        return $this->props;
    }

    /**
     * Theme namespaces tried in order (`color`, `spacing`), without `--`.
     *
     * @return list<string>
     */
    public function theme(): array
    {
        return $this->theme;
    }

    public function bare(): ?string
    {
        return $this->bare;
    }

    public function fraction(): bool
    {
        return $this->fraction;
    }

    public function negative(): bool
    {
        return $this->negative;
    }

    /**
     * Whether `[…]` / `(--var)` values are accepted at all.
     */
    public function acceptsArbitrary(): bool
    {
        return $this->arbitrary !== false;
    }

    /**
     * The accepted data types of an arbitrary value, or null for any.
     *
     * @return list<string>|null
     */
    public function arbitraryTypes(): ?array
    {
        return \is_array($this->arbitrary) ? $this->arbitrary : null;
    }

    public function defaultValue(): ?string
    {
        return $this->default;
    }

    /**
     * The {@see PropertyOrder} entry this utility sorts by instead of its
     * declared properties, or null.
     */
    public function order(): ?string
    {
        return $this->order;
    }

    /**
     * @return array<string, string>
     */
    public function named(): array
    {
        return $this->named;
    }

    public function modifier(): ?string
    {
        return $this->modifier;
    }

    public function handler(): ?string
    {
        return $this->handler;
    }

    /**
     * @param array<mixed> $row
     *
     * @throws \InvalidArgumentException
     */
    private static function single(string $root, array $row): self
    {
        foreach (\array_keys($row) as $key) {
            if (!isset(self::KEYS[$key])) {
                throw self::invalid($root, \sprintf('unknown key "%s"', (string) $key));
            }
        }

        $definition = new self($root);
        $definition->order = self::optionalOrder($root, $row);

        if (\array_key_exists('static', $row)) {
            foreach (\array_keys($row) as $key) {
                if ($key !== 'static' && $key !== 'order') {
                    throw self::invalid($root, \sprintf('a static definition takes no "%s"', (string) $key));
                }
            }

            $definition->static = self::stringMap($root, 'static', $row['static']);

            if ($definition->static === []) {
                throw self::invalid($root, '"static" needs at least one declaration');
            }

            return $definition;
        }

        $definition->props = self::stringList($root, 'props', self::field($row, 'props', []));
        $definition->theme = self::stringList($root, 'theme', self::field($row, 'theme', []));
        $definition->named = self::stringMap($root, 'named', self::field($row, 'named', []));
        $definition->bare = self::optionalChoice($root, 'bare', $row, self::BARE_KINDS);
        $definition->modifier = self::optionalChoice($root, 'modifier', $row, self::MODIFIERS);
        $definition->fraction = self::bool($root, 'fraction', self::field($row, 'fraction', false));
        $definition->negative = self::bool($root, 'negative', self::field($row, 'negative', false));
        $definition->default = self::optionalString($root, 'default', $row);
        $definition->arbitrary = self::arbitrary($root, self::field($row, 'arbitrary', false));

        if (\array_key_exists('handler', $row)) {
            if (!\is_string($row['handler']) || !isset(Handlers::TAKES_VALUE[$row['handler']])) {
                throw self::invalid($root, 'unknown handler');
            }

            $definition->handler = $row['handler'];
        } elseif ($definition->props === []) {
            throw self::invalid($root, 'a functional definition needs "props" or a "handler"');
        }

        $definition->functional = self::rowIsFunctional($row);

        return $definition;
    }

    /**
     * @param array<mixed> $row
     *
     * @throws \InvalidArgumentException
     */
    private static function optionalOrder(string $root, array $row): ?string
    {
        $order = self::optionalString($root, 'order', $row);

        if ($order !== null && PropertyOrder::index($order) === null) {
            throw self::invalid($root, \sprintf('"order" %s is not in Data\PropertyOrder', $order));
        }

        return $order;
    }

    /**
     * @param mixed $value
     *
     * @return bool|list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function arbitrary(string $root, $value)
    {
        if (\is_bool($value)) {
            return $value;
        }

        $types = self::stringList($root, 'arbitrary', $value);

        foreach ($types as $type) {
            if (!\in_array($type, Classifier::TYPES, true)) {
                throw self::invalid($root, \sprintf('unknown arbitrary data type "%s"', $type));
            }
        }

        if ($types === []) {
            throw self::invalid($root, '"arbitrary" is true, false or a non-empty list of data types');
        }

        return $types;
    }

    /**
     * @param array<mixed> $row
     * @param list<string> $choices
     *
     * @throws \InvalidArgumentException
     */
    private static function optionalChoice(string $root, string $key, array $row, array $choices): ?string
    {
        $value = self::optionalString($root, $key, $row);

        if ($value !== null && !\in_array($value, $choices, true)) {
            throw self::invalid($root, \sprintf('"%s" must be one of %s', $key, \implode(', ', $choices)));
        }

        return $value;
    }

    /**
     * @param array<mixed> $row
     *
     * @throws \InvalidArgumentException
     */
    private static function optionalString(string $root, string $key, array $row): ?string
    {
        // An explicit null is a mistake in the table, not an absent key.
        if (!\array_key_exists($key, $row)) {
            return null;
        }

        if (!\is_string($row[$key]) || $row[$key] === '') {
            throw self::invalid($root, \sprintf('"%s" must be a non-empty string', $key));
        }

        return $row[$key];
    }

    /**
     * @param mixed $value
     *
     * @throws \InvalidArgumentException
     */
    private static function bool(string $root, string $key, $value): bool
    {
        if (!\is_bool($value)) {
            throw self::invalid($root, \sprintf('"%s" must be a boolean', $key));
        }

        return $value;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function stringList(string $root, string $key, $value): array
    {
        if (!\is_array($value) || !self::isList($value)) {
            throw self::invalid($root, \sprintf('"%s" must be a list of strings', $key));
        }

        $list = [];

        foreach ($value as $item) {
            if (!\is_string($item) || $item === '') {
                throw self::invalid($root, \sprintf('"%s" must be a list of strings', $key));
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param mixed $value
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException
     */
    private static function stringMap(string $root, string $key, $value): array
    {
        if (!\is_array($value)) {
            throw self::invalid($root, \sprintf('"%s" must map strings to strings', $key));
        }

        $map = [];

        foreach ($value as $name => $item) {
            if (!\is_string($name) || $name === '' || !\is_string($item) || $item === '') {
                throw self::invalid($root, \sprintf('"%s" must map strings to strings', $key));
            }

            $map[$name] = $item;
        }

        return $map;
    }

    /**
     * $row[$key], or $default only when the key is absent: an explicit null
     * is a mistake in the table and reaches the type check.
     *
     * @param array<mixed> $row
     * @param mixed $default
     *
     * @return mixed
     */
    private static function field(array $row, string $key, $default)
    {
        return \array_key_exists($key, $row) ? $row[$key] : $default;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;

        foreach (\array_keys($value) as $key) {
            if ($key !== $expected) {
                return false;
            }

            ++$expected;
        }

        return true;
    }

    /**
     * A root is lower-case letters, digits and single inner hyphens: what a
     * class name can carry unescaped and the trie can split on `-`.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertRoot(string $root): void
    {
        $length = \strlen($root);
        $valid = $length > 0 && $length <= 64 && $root[0] !== '-' && $root[$length - 1] !== '-'
            && \strpos($root, '--') === false
            && \strspn($root, 'abcdefghijklmnopqrstuvwxyz0123456789-') === $length;

        if (!$valid) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid utility root "%s".',
                \substr($root, 0, 64)
            ));
        }
    }

    private static function invalid(string $root, string $problem): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf('Utility "%s": %s.', $root, $problem));
    }
}
