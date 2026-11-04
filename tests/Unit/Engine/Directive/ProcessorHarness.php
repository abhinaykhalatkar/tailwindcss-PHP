<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * Runs the directive processor on CSS text with an in-memory import map and
 * keeps every collaborator for the test to inspect.
 */
final class ProcessorHarness
{
    /**
     * @var ProcessedSheet
     */
    public $sheet;

    /**
     * @var DiagnosticBag
     */
    public $bag;

    /**
     * @var ThemeRegistry
     */
    public $theme;

    /**
     * @var UtilityRegistry
     */
    public $utilities;

    /**
     * @var VariantRegistry
     */
    public $variants;

    /**
     * @param array<string, string> $files Project-relative path => CSS.
     */
    public function __construct(string $css, array $files = [], ?ProcessorOptions $options = null)
    {
        $this->bag = new DiagnosticBag();
        $this->theme = new ThemeRegistry();
        $this->utilities = UtilityRegistry::default();
        $this->variants = new VariantRegistry(Variants::table());
        $this->sheet = Processor::process(
            Parser::parse($css, $this->bag),
            new InMemoryImportResolver($files),
            $this->theme,
            $this->utilities,
            $this->variants,
            $options ?? new ProcessorOptions('input.css', \strlen($css)),
            $this->bag
        );
    }

    /**
     * The authored nodes as compact CSS (empty rules dropped, as the serializer does).
     */
    public function authored(): string
    {
        return self::css($this->sheet->authored);
    }

    /**
     * @param list<Node> $nodes
     */
    public static function css(array $nodes): string
    {
        return Serializer::compact(new StyleSheet($nodes));
    }

    /**
     * Diagnostic codes in order.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $codes = [];

        foreach ($this->bag->all() as $diagnostic) {
            $codes[] = $diagnostic->code();
        }

        return $codes;
    }

    /**
     * Diagnostic messages in order.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->bag->all() as $diagnostic) {
            $messages[] = $diagnostic->message();
        }

        return $messages;
    }
}
