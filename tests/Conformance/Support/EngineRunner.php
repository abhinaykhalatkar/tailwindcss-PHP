<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * Runs one corpus entry through the whole PHP engine the way
 * `tools/generate-goldens.php` runs it through the CLI (TASK-0047 criterion 1):
 *
 * - a `utilities` or `variants` entry is compiled from the default entry
 *   stylesheet, `@import "tailwindcss" source(none);`, with the entry's
 *   candidates;
 * - a `directives` entry is compiled from its own stylesheet (the other
 *   stylesheets of the directory are what it may `@import`) with the
 *   candidates of its `.candidates.txt`, if it has one.
 *
 * The engine runs with its default limits: a hard limit that trips is a
 * diagnostic in the result, never a skip. Reads fixtures under
 * `tests/Conformance` only and writes nothing (T-01).
 *
 * It also reads goldens: one that carries a legitimate parser diagnostic (an
 * unquoted `url(javascript:…)` the CLI emits and a CSS parser rightly refuses)
 * is read with the diagnostic tolerated, see {@see self::golden()}.
 */
final class EngineRunner
{
    private const ENTRY = "@import \"tailwindcss\" source(none);\n";

    /**
     * @var CorpusPaths
     */
    private $paths;

    /**
     * @var array<string, string>|null File name => contents, for `@import`.
     */
    private $partials;

    public function __construct(?CorpusPaths $paths = null)
    {
        $this->paths = $paths ?? new CorpusPaths();
    }

    /**
     * The entries the engine is measured on: every `utilities`, `variants` and
     * `directives` key (the extractor corpus is not a stylesheet).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach (['utilities', 'variants', 'directives'] as $group) {
            foreach ($this->paths->select($group) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The candidates an entry was compiled from.
     *
     * @return list<string>
     */
    public function candidates(string $key): array
    {
        if ($this->paths->kind($key) === CorpusPaths::KIND_DIRECTIVE) {
            $path = (string) $this->paths->candidatesPath($key);

            return \is_file($path) ? CorpusPaths::parseCandidates(self::read($path)) : [];
        }

        return CorpusPaths::parseCandidates(self::read($this->paths->sourcePath($key)));
    }

    /**
     * The engine's CSS for an entry, the engine's own diagnostics (code and
     * message) and the diagnostics of parsing that CSS back: an output that does
     * not re-parse is a failure of the engine, never tolerated.
     *
     * @return array{css: string, diagnostics: list<string>, reparse: list<string>}
     */
    public function run(string $key): array
    {
        $directive = $this->paths->kind($key) === CorpusPaths::KIND_DIRECTIVE;
        $entry = $directive ? self::read($this->paths->sourcePath($key)) : self::ENTRY;
        $path = $directive ? \basename($this->paths->sourcePath($key)) : CompileRequest::DEFAULT_ENTRY_PATH;

        $output = (new Compiler())->compile(new CompileRequest(
            $entry,
            new InMemoryImportResolver($this->partials()),
            $this->candidates($key),
            false,
            true,
            CompileRequest::DEFAULT_MAX_CANDIDATES,
            $path
        ));

        $diagnostics = [];

        foreach ($output->diagnostics->all() as $diagnostic) {
            $diagnostics[] = $diagnostic->code() . ': ' . $diagnostic->message();
        }

        $bag = new DiagnosticBag();
        Parser::parse($output->css, $bag);
        $reparse = [];

        foreach ($bag->all() as $diagnostic) {
            $reparse[] = $diagnostic->code() . ' line ' . (string) $diagnostic->line() . ': ' . $diagnostic->message();
        }

        return ['css' => $output->css, 'diagnostics' => $diagnostics, 'reparse' => $reparse];
    }

    /**
     * The CLI's golden for an entry, ready to compare.
     *
     * A golden that parses clean is returned as it is. One that does not (the
     * CLI keeps `background-image: url(javascript:alert(1))` and a CSS parser
     * drops that declaration as a bad url) is returned re-serialised from what
     * parsed, so the comparator sees no diagnostic and compares the rest, and
     * `tolerated` names the candidates whose rule lost a declaration: for an
     * allow-listed one, "both sides empty" is a confirmed difference, not a
     * case that passes now.
     *
     * @return array{css: string, tolerated: list<string>}
     */
    public function golden(string $key): array
    {
        $css = self::read($this->paths->goldenPath($key));
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);

        if ($bag->all() === []) {
            return ['css' => $css, 'tolerated' => []];
        }

        $attribution = new CandidateAttribution($this->candidates($key));
        $lines = \explode("\n", $css);
        $tolerated = [];

        foreach ($bag->all() as $diagnostic) {
            $selector = self::selectorAbove($lines, (int) $diagnostic->line());
            $candidate = $selector === null ? null : $attribution->attribute($selector);

            if ($candidate !== null && !\in_array($candidate, $tolerated, true)) {
                $tolerated[] = $candidate;
            }
        }

        return ['css' => Serializer::pretty($sheet), 'tolerated' => $tolerated];
    }

    /**
     * The selector of the rule that holds a 1-based line of the golden, or
     * null: the nearest line above it that opens a rule (`.name {`).
     *
     * @param list<string> $lines
     */
    private static function selectorAbove(array $lines, int $line): ?string
    {
        for ($i = \min($line, \count($lines)) - 1; $i >= 0; --$i) {
            $text = \trim($lines[$i]);

            if ($text !== '' && $text[0] === '.' && \substr($text, -2) === ' {') {
                return \substr($text, 0, -2);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function partials(): array
    {
        if ($this->partials === null) {
            $this->partials = [];

            foreach ($this->paths->partials() as $name => $path) {
                $this->partials[$name] = self::read($path);
            }
        }

        return $this->partials;
    }

    private static function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException('Cannot read ' . \basename($path) . '.');
        }

        return \str_replace("\r\n", "\n", $contents);
    }
}
