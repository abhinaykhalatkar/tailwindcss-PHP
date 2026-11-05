<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;

/**
 * Turns the conformance corpus into goldens with the pinned official CLI
 * (design doc 16 §Golden generation; decisions ENG-A1, ENG-A7, ENG-A11).
 *
 * What a golden is: the stylesheet the real Tailwind produces for an input, with
 * its banner removed and LF line endings, committed so the engine can be compared
 * against it without the binary. Anyone can run the conformance tests; only
 * regenerating goldens needs the CLI.
 *
 * **The compile step is injected as a callable** — in production
 * `[CliRunner, 'compile']` — which is what lets every rule below be tested
 * without a Tailwind binary: input assembly, banner handling, what counts as
 * drift. The one thing a test double cannot prove (that the real binary accepts
 * the assembled input) is proven by running the generator against the real one.
 *
 * Inputs, per kind of corpus entry:
 *
 * - **candidate lists** (`utilities/`, `variants/`): the preamble below plus one
 *   space-separated line of candidates. `@source none;` is not a valid 4.1
 *   preamble (CCP-A35, ENG-A7), so detection is switched off with the import's
 *   own `source(none)` option and the candidate file is the only source.
 * - **directive fixtures**: the fixture stylesheet itself, with the same
 *   `@source` line appended, plus the entry's own candidate list and any partial
 *   it may `@import`. A fixture that imports a Tailwind bundle must say
 *   `source(none)` itself, or the binary would also scan the temporary project's
 *   own files.
 * - **extractor fixtures**: the preamble pointing `@source` at the fixture file;
 *   the golden is the list of candidates the CLI accepted, recovered from the
 *   generated utilities layer ({@see self::acceptedCandidates()}).
 *
 * **Never a path from an argument (T-01).** A `--only` selector is looked up in
 * the corpus listing; everything written goes to a path {@see CorpusPaths}
 * derived from a listed key.
 *
 * Lives under `tools/`: maintainer tooling, not autoloaded, not shipped.
 */
final class GoldenGenerator
{
    /**
     * ENG-A7: the preamble for candidate lists and extractor fixtures.
     */
    private const SOURCE_NONE_IMPORT = "@import \"tailwindcss\" source(none);\n";

    private const CANDIDATE_SOURCE = "@source \"./candidates.txt\";\n";

    private const BANNER_START = '/*! tailwindcss v';

    private const UTILITIES_LAYER = '@layer utilities';

    private const HEX = '0123456789abcdefABCDEF';

    private const IDENTIFIER_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    /**
     * @var CorpusPaths
     */
    private $paths;

    /**
     * @var callable(string, string, array<string, string>, bool): string
     */
    private $compile;

    /**
     * @var string
     */
    private $version;

    /**
     * `$compile` receives the `input.css` text, the `candidates.txt` text, the
     * extra files by name and whether to minify, and returns the raw stylesheet.
     *
     * @param callable(string, string, array<string, string>, bool): string $compile
     * @param string $version The release the goldens claim (`golden/VERSION`).
     */
    public function __construct(CorpusPaths $paths, callable $compile, string $version)
    {
        $this->paths = $paths;
        $this->compile = $compile;
        $this->version = $version;
    }

    /**
     * Generate (or, with `$check`, only compare) the goldens.
     *
     * The result has one row per golden file. `status` is `written`,
     * `unchanged`, `drift` (check mode: the committed bytes differ) or
     * `missing` (check mode: there is no committed golden).
     *
     * @param string|null $only A corpus key (`variants/responsive`) or a group
     *                          (`variants`); null for everything.
     * @param bool $check Compare only; write nothing.
     *
     * @return list<array{key: string, file: string, status: string}>
     *
     * @throws \InvalidArgumentException When `$only` is not in the corpus listing.
     * @throws \RuntimeException When the binary's output cannot be trusted.
     */
    public function run(?string $only, bool $check): array
    {
        if ($only !== null && !$this->paths->isSelector($only)) {
            throw new \InvalidArgumentException(\sprintf(
                '--only must name a corpus entry or one of its groups (%s); "%s" is neither.',
                \implode(', ', CorpusPaths::groups()),
                self::slice($only, 0, 60)
            ));
        }

        $keys = $only === null ? $this->paths->keys() : $this->paths->select($only);
        $results = [];

        foreach ($keys as $key) {
            // Every artefact of an entry is built before any is written, so a
            // refusal part-way through cannot leave a plain golden beside a
            // stale minified one.
            foreach ($this->artefactsFor($key) as $path => $contents) {
                $results[] = [
                    'key' => $key,
                    'file' => $this->relative($path),
                    'status' => $this->settle($path, $contents, $check),
                ];
            }
        }

        return $results;
    }

    /**
     * Whether any golden differs from, or is absent from, the committed tree.
     *
     * @param list<array{key: string, file: string, status: string}> $results
     */
    public static function hasDrift(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['status'] === 'drift' || $result['status'] === 'missing') {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the CLI's banner and check it names the expected release.
     *
     * The banner is the only place the binary says which release produced a
     * stylesheet, so it is verified rather than merely stripped: a stylesheet
     * from another release must never become a golden labelled with this one.
     * The release is stamped once, in `golden/VERSION`.
     *
     * @throws \RuntimeException When there is no banner, it is not complete,
     *                           or it names a different release.
     */
    public static function stripBanner(string $css, string $expectedVersion): string
    {
        $start = \strlen(self::BANNER_START);

        if (\strncmp($css, self::BANNER_START, $start) !== 0) {
            throw new \RuntimeException(
                'The stylesheet does not start with the Tailwind banner, so it cannot be tied to a release.'
            );
        }

        $lineEnd = \strpos($css, "\n");
        $line = $lineEnd === false ? $css : self::slice($css, 0, $lineEnd);
        $space = \strpos($line, ' ', $start);

        if ($space === false || \strpos($line, '*/') === false) {
            throw new \RuntimeException('The Tailwind banner is incomplete.');
        }

        $found = self::slice($line, $start, $space - $start);

        if ($found !== $expectedVersion) {
            throw new \RuntimeException(\sprintf(
                'The stylesheet was produced by Tailwind v%s but the goldens are pinned to %s.',
                $found,
                $expectedVersion
            ));
        }

        return $lineEnd === false ? '' : self::slice($css, $lineEnd + 1);
    }

    /**
     * LF line endings, whatever the host or the checkout's autocrlf produced.
     */
    public static function normalise(string $text): string
    {
        return \str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Undo CSS identifier escaping (CSS Syntax 3 §4.3.7).
     *
     * `\:` becomes `:`, and a hex escape such as `\32 ` becomes the character it
     * names, with the single whitespace that terminates it consumed. Tailwind
     * escapes a leading digit this way, so `2xl:flex` appears in a stylesheet as
     * `.\32 xl\:flex`.
     *
     * Limits: this reverses the escaping of *an identifier* and nothing more. It
     * does not validate that the result is a legal class name, and an escape that
     * names no character (NUL, a surrogate, past U+10FFFF, a trailing
     * backslash) becomes U+FFFD, as the specification says.
     */
    public static function unescapeIdentifier(string $escaped): string
    {
        // One implementation: the byte scanner of the comparator's attribution, which is the one that moves to
        // `src/` with it; this tool already reads the conformance support code (TASK-0052).
        return CandidateAttribution::unescapeIdentifier($escaped);
    }

    /**
     * The candidates a generated stylesheet accepted, sorted and unique.
     *
     * Read from the **utilities layer**: every utility the CLI generated is a
     * rule there whose selector starts with the escaped candidate as a class.
     * The first class of each rule prelude is taken — including nested
     * preludes, because Tailwind 4 writes `.hover\:x { &:hover { … } }` — and
     * comma lists are split. Preludes that start with anything but `.` (`&`,
     * `@media`, `from`) are not candidates.
     *
     * Limits, so nobody trusts it further than it goes: a nested prelude that
     * *starts* with an unrelated class (an arbitrary variant such as
     * `[.dark_&]:p-4`, which nests under `.dark`) contributes that class too. The
     * extractor fixtures contain no such candidates; a fixture that did would
     * need its golden reviewed by hand.
     *
     * @return list<string>
     */
    public static function acceptedCandidates(string $css): array
    {
        $found = [];
        $offset = 0;
        $length = \strlen($css);

        while (($position = \strpos($css, self::UTILITIES_LAYER, $offset)) !== false) {
            $afterName = $position + \strlen(self::UTILITIES_LAYER);
            $open = $afterName;

            while ($open < $length && \strpos(" \t\r\n\f", $css[$open]) !== false) {
                ++$open;
            }

            // `@layer theme, base, components, utilities;` is a statement that
            // merely mentions the layer; only the block form holds rules.
            if ($open >= $length || $css[$open] !== '{') {
                $offset = $afterName;

                continue;
            }

            $close = self::matchingBrace($css, $open);

            foreach (self::classesInBlock(self::slice($css, $open + 1, $close - $open - 1)) as $candidate) {
                $found[$candidate] = true;
            }

            $offset = $close + 1;
        }

        $candidates = \array_map('strval', \array_keys($found));
        \sort($candidates, \SORT_STRING);

        return $candidates;
    }

    /**
     * Everything one corpus entry produces, keyed by golden path.
     *
     * @return array<string, string>
     */
    private function artefactsFor(string $key): array
    {
        $kind = $this->paths->kind($key);

        if ($kind === CorpusPaths::KIND_EXTRACTOR) {
            return $this->extractorArtefacts($key);
        }

        [$input, $candidates, $files] = $kind === CorpusPaths::KIND_DIRECTIVE
            ? $this->directiveInputs($key)
            : $this->candidateInputs($key);

        return $this->stylesheetArtefacts($key, $input, $candidates, $files);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function candidateInputs(string $key): array
    {
        $words = CorpusPaths::parseCandidates(self::normalise($this->read($this->paths->sourcePath($key))));

        return [self::SOURCE_NONE_IMPORT . self::CANDIDATE_SOURCE, \implode(' ', $words), []];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    private function directiveInputs(string $key): array
    {
        $entry = self::normalise($this->read($this->paths->sourcePath($key)));

        if ($entry !== '' && self::slice($entry, -1) !== "\n") {
            $entry .= "\n";
        }

        $words = CorpusPaths::parseCandidates(
            self::normalise($this->read((string) $this->paths->candidatesPath($key)))
        );
        $partials = [];

        foreach ($this->paths->partials() as $name => $path) {
            $partials[$name] = self::normalise($this->read($path));
        }

        return [$entry . self::CANDIDATE_SOURCE, \implode(' ', $words), $partials];
    }

    /**
     * The plain and the minified stylesheet of an entry.
     *
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    private function stylesheetArtefacts(string $key, string $input, string $candidates, array $files): array
    {
        $plain = $this->clean(($this->compile)($input, $candidates, $files, false));
        $minified = $this->clean(($this->compile)($input, $candidates, $files, true));

        return [
            $this->paths->goldenPath($key) => $plain,
            $this->paths->goldenPath($key, true) => $minified,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extractorArtefacts(string $key): array
    {
        $source = $this->paths->sourcePath($key);
        $file = \basename($source);
        $input = self::SOURCE_NONE_IMPORT . \sprintf("@source \"./%s\";\n", $file);

        $css = $this->clean(($this->compile)($input, '', [$file => self::normalise($this->read($source))], false));

        $json = \json_encode(
            self::acceptedCandidates($css),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );

        if (!\is_string($json)) {
            throw new \RuntimeException('The accepted candidates could not be encoded as JSON.');
        }

        return [$this->paths->goldenPath($key) => $json . "\n"];
    }

    /**
     * LF, banner checked and removed.
     */
    private function clean(string $raw): string
    {
        return self::stripBanner(self::normalise($raw), $this->version);
    }

    /**
     * Compare with, or write, one golden.
     */
    private function settle(string $path, string $contents, bool $check): string
    {
        $existing = \is_file($path) ? \file_get_contents($path) : false;

        if ($existing === $contents) {
            return 'unchanged';
        }

        if ($check) {
            return $existing === false ? 'missing' : 'drift';
        }

        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }

        if (\file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(\sprintf('Could not write %s.', $this->relative($path)));
        }

        return 'written';
    }

    private function relative(string $path): string
    {
        $root = $this->paths->root() . '/';

        return \strncmp($path, $root, \strlen($root)) === 0 ? self::slice($path, \strlen($root)) : $path;
    }

    private function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if (!\is_string($contents)) {
            throw new \RuntimeException(\sprintf('Corpus file %s could not be read.', $this->relative($path)));
        }

        return $contents;
    }

    // ------------------------------------------------------- CSS scanning

    /**
     * Index of the `}` that closes the `{` at `$open`, or the end of input.
     */
    private static function matchingBrace(string $css, int $open): int
    {
        $length = \strlen($css);
        $depth = 0;

        for ($i = $open; $i < $length; ++$i) {
            $byte = $css[$i];

            if ($byte === '\\') {
                ++$i;

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $i = self::endOfString($css, $i);

                continue;
            }

            if ($byte === '/' && $i + 1 < $length && $css[$i + 1] === '*') {
                $i = self::endOfComment($css, $i);

                continue;
            }

            if ($byte === '{') {
                ++$depth;
            } elseif ($byte === '}') {
                --$depth;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $length;
    }

    /**
     * The first class of every rule prelude in a block, at any nesting depth.
     *
     * @return list<string>
     */
    private static function classesInBlock(string $block): array
    {
        $length = \strlen($block);
        $buffer = '';
        $classes = [];

        for ($i = 0; $i < $length; ++$i) {
            $byte = $block[$i];

            if ($byte === '\\') {
                $buffer .= $byte . ($i + 1 < $length ? $block[$i + 1] : '');
                ++$i;

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $end = self::endOfString($block, $i);
                $buffer .= self::slice($block, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($byte === '/' && $i + 1 < $length && $block[$i + 1] === '*') {
                $i = self::endOfComment($block, $i);

                continue;
            }

            if ($byte === '{') {
                foreach (self::classesOfPrelude(\trim($buffer)) as $class) {
                    $classes[] = $class;
                }

                $buffer = '';

                continue;
            }

            if ($byte === ';' || $byte === '}') {
                $buffer = '';

                continue;
            }

            $buffer .= $byte;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private static function classesOfPrelude(string $prelude): array
    {
        if ($prelude === '' || $prelude[0] === '@') {
            return [];
        }

        $classes = [];

        foreach (self::splitTopLevel($prelude) as $selector) {
            $selector = \trim($selector);

            if ($selector === '' || $selector[0] !== '.') {
                continue;
            }

            $identifier = self::leadingIdentifier($selector, 1);

            if ($identifier !== '') {
                $classes[] = self::unescapeIdentifier($identifier);
            }
        }

        return $classes;
    }

    /**
     * Split a selector list on commas that are not inside (), [] or a string.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $text): array
    {
        $length = \strlen($text);
        $parts = [];
        $current = '';
        $depth = 0;

        for ($i = 0; $i < $length; ++$i) {
            $byte = $text[$i];

            if ($byte === '\\') {
                $current .= $byte . ($i + 1 < $length ? $text[$i + 1] : '');
                ++$i;

                continue;
            }

            if ($byte === '"' || $byte === "'") {
                $end = self::endOfString($text, $i);
                $current .= self::slice($text, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ($byte === '(' || $byte === '[') {
                ++$depth;
            } elseif ($byte === ')' || $byte === ']') {
                --$depth;
            }

            if ($byte === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $byte;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * The raw (still escaped) identifier starting at `$from`.
     */
    private static function leadingIdentifier(string $text, int $from): string
    {
        $length = \strlen($text);
        $i = $from;

        while ($i < $length) {
            $byte = $text[$i];

            if ($byte === '\\') {
                ++$i;

                if ($i >= $length) {
                    break;
                }

                if (\strspn($text[$i], self::HEX) === 1) {
                    $digits = self::hexRun($text, $i);
                    $i += \strlen($digits);
                    $i += self::terminatorLength($text, $i);

                    continue;
                }

                ++$i;

                while ($i < $length && (\ord($text[$i]) & 0xC0) === 0x80) {
                    ++$i;
                }

                continue;
            }

            if (\strspn($byte, self::IDENTIFIER_BYTES) === 1 || (\ord($byte) & 0x80) !== 0) {
                ++$i;

                continue;
            }

            break;
        }

        return self::slice($text, $from, $i - $from);
    }

    /**
     * Up to six hex digits starting at `$from`.
     */
    private static function hexRun(string $text, int $from): string
    {
        $length = \strlen($text);
        $end = $from;

        while ($end < $length && $end - $from < 6 && \strspn($text[$end], self::HEX) === 1) {
            ++$end;
        }

        return self::slice($text, $from, $end - $from);
    }

    /**
     * Bytes of the single whitespace that ends a hex escape, if one follows.
     */
    private static function terminatorLength(string $text, int $at): int
    {
        if ($at >= \strlen($text) || \strpos(" \t\n\r\f", $text[$at]) === false) {
            return 0;
        }

        return $text[$at] === "\r" && $at + 1 < \strlen($text) && $text[$at + 1] === "\n" ? 2 : 1;
    }

    /**
     * Index of the quote closing the string opened at `$open`, or the last byte.
     */
    private static function endOfString(string $text, int $open): int
    {
        $length = \strlen($text);
        $quote = $text[$open];

        for ($i = $open + 1; $i < $length; ++$i) {
            if ($text[$i] === '\\') {
                ++$i;

                continue;
            }

            if ($text[$i] === $quote) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * Index of the `/` ending the comment that starts at `$open`.
     */
    private static function endOfComment(string $text, int $open): int
    {
        $end = \strpos($text, '*/', $open + 2);

        return $end === false ? \strlen($text) - 1 : $end + 1;
    }

    /**
     * `substr()` that is always a string.
     *
     * Below PHP 8.0 it answers `false` when the start is exactly the length of
     * the text, and this class slices at the very end of its input often enough
     * (a banner with nothing after it, a rule with no body) for that to matter
     * on the 7.3 floor.
     */
    private static function slice(string $text, int $start, ?int $length = null): string
    {
        $slice = $length === null ? \substr($text, $start) : \substr($text, $start, $length);

        return $slice === false ? '' : $slice;
    }
}
