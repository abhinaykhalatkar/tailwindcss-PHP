<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Directive;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Import\ResolvedImport;
use TailwindPhp\Support\Str;

/**
 * Relative `@import`s (design doc 07 §Pipeline, §Complexity): resolved only
 * through {@see ImportResolverInterface} — this class never builds a path
 * (T-01; `FilesystemImportResolver` confines) — and parsed with
 * {@see Parser}. The processor splices the result in place.
 *
 * Bounded (T-05), whatever the shape of the import graph:
 *
 * - at most `ProcessorOptions::maxImports` import operations per compile —
 *   every `@import` tried counts, refused ones included; past it one
 *   `E_CSS_SYNTAX` and no further resolver call;
 * - each (specifier, importing file) pair is resolved once and each resolved
 *   file parsed once (memoised), however often it is imported;
 * - an import deeper than `maxImportDepth` is `E_IMPORT_DEPTH`; one already on
 *   the import chain (the entry included) is `E_IMPORT_CYCLE`;
 * - the entry plus every spliced import may not pass `maxTotalCss` bytes —
 *   each splice counts, so the output is bounded too (`E_CSS_SYNTAX`, nothing
 *   more is imported).
 *
 * A relative specifier that resolves to nothing is `E_IMPORT_NOT_FOUND`. Any
 * other specifier the resolver does not know (a URL, a package) is left as
 * authored CSS, as the resolver contract asks. Diagnostics name the specifier
 * as written (≤ 64 bytes, cut UTF-8 safely), never a resolved path (T-06).
 */
final class ImportHandler
{
    private const MAX_ECHO = 64;

    /**
     * @var ImportResolverInterface
     */
    private $resolver;

    /**
     * @var ProcessorOptions
     */
    private $options;

    /**
     * @var int Bytes spliced so far, the entry included.
     */
    private $bytes;

    /**
     * @var int Import operations so far.
     */
    private $operations = 0;

    /**
     * @var bool True once a budget is spent: nothing more is imported.
     */
    private $exhausted = false;

    /**
     * @var array<string, ResolvedImport|null> "spec\0from" => what the resolver answered.
     */
    private $resolved = [];

    /**
     * @var array<string, StyleSheet> Resolved path => its parsed sheet.
     */
    private $parsed = [];

    public function __construct(ImportResolverInterface $resolver, ProcessorOptions $options)
    {
        $this->resolver = $resolver;
        $this->options = $options;
        $this->bytes = $options->entryBytes;
    }

    /**
     * The parsed import with its resolved path, or null when it is not
     * imported (diagnosed, or left as authored CSS — then $keep is true).
     *
     * The sheet may be shared between imports of the same file: the processor
     * builds new rule and block nodes when it walks it (`ApplyHandler::extract`
     * copies every rule), so only leaf nodes — declarations, statements —
     * are shared, and nothing downstream mutates those.
     *
     * @param list<string> $chain Resolved paths of the files being imported, outermost first.
     *
     * @return array{0: StyleSheet, 1: string}|null
     *
     * @param-out bool $keep
     */
    public function import(
        ImportOptions $import,
        AtRule $node,
        string $fromRelPath,
        array $chain,
        DiagnosticBag $bag,
        ?bool &$keep = null
    ): ?array {
        $keep = false;
        $spec = $import->spec;

        if ($this->exhausted) {
            return null;
        }

        if (++$this->operations > $this->options->maxImports) {
            $this->exhausted = true;
            $this->report($node, Diagnostic::E_CSS_SYNTAX, \sprintf(
                'The stylesheet has more than %d imports; the rest are ignored.',
                $this->options->maxImports
            ), $bag);

            return null;
        }

        if (\count($chain) >= $this->options->maxImportDepth) {
            $this->report($node, Diagnostic::E_IMPORT_DEPTH, \sprintf(
                'Imports nest deeper than %d levels at %s; the import is ignored.',
                $this->options->maxImportDepth,
                self::echo($spec)
            ), $bag);

            return null;
        }

        $key = $spec . "\0" . $fromRelPath;

        if (!\array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $this->resolver->resolve($spec, $fromRelPath);
        }

        $resolved = $this->resolved[$key];

        if ($resolved === null) {
            if (self::isRelative($spec)) {
                $message = \sprintf('The import %s was not found.', self::echo($spec));
                $this->report($node, Diagnostic::E_IMPORT_NOT_FOUND, $message, $bag);
            } else {
                $keep = true;
            }

            return null;
        }

        $path = $resolved->relPath();

        if ($path === $this->options->entryPath || \in_array($path, $chain, true)) {
            $message = \sprintf('The import %s imports itself; it is ignored.', self::echo($spec));
            $this->report($node, Diagnostic::E_IMPORT_CYCLE, $message, $bag);

            return null;
        }

        $css = $resolved->css();
        $this->bytes += \strlen($css);

        if ($this->bytes > $this->options->maxTotalCss) {
            $this->exhausted = true;
            $this->report($node, Diagnostic::E_CSS_SYNTAX, \sprintf(
                'The stylesheet and its imports exceed %d bytes at %s; no more is imported.',
                $this->options->maxTotalCss,
                self::echo($spec)
            ), $bag);

            return null;
        }

        if (!isset($this->parsed[$path])) {
            $this->parsed[$path] = Parser::parse($css, $bag);
        }

        return [$this->parsed[$path], $path];
    }

    /**
     * Import operations tried so far (for tests and reports).
     */
    public function operations(): int
    {
        return $this->operations;
    }

    private static function isRelative(string $spec): bool
    {
        return \strncmp($spec, './', 2) === 0 || \strncmp($spec, '../', 3) === 0;
    }

    private function report(AtRule $node, string $code, string $message, DiagnosticBag $bag): void
    {
        $bag->add(new Diagnostic(Diagnostic::ERROR, $code, $message, null, $node->line));
    }

    private static function echo(string $text): string
    {
        return Str::truncateBytes($text, self::MAX_ECHO + 3, '…');
    }
}
