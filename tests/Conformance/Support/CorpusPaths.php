<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

/**
 * Where every conformance input lives and where its golden goes
 * (design doc 16 §Corpus layout, decisions ENG-A1 and ENG-A11).
 *
 * One map, three consumers: the golden generator, the importers that read a
 * golden to learn the pinned CLI's own output, and the conformance test that
 * compares the engine against it. Each of them asking this class instead of
 * building a path keeps the layout in one place — and means a path is never
 * assembled from caller input: a **key** such as `variants/responsive` is only
 * ever looked up in the listing this class built from the directory, so an
 * argument like `../../etc/passwd` is not a path to be joined, it is a string
 * that is not in the list (threat T-01).
 *
 * ```
 * corpus/utilities/<name>.txt          candidates, one per line   golden/utilities/<name>.css (+ .min.css)
 * corpus/variants/<name>.txt           candidates, one per line   golden/variants/<name>.css  (+ .min.css)
 * corpus/directives/<name>.css         entry CSS, with a sibling  golden/directives/<name>.css (+ .min.css)
 *                   <name>.candidates.txt
 * tests/Fixtures/extractor/<file>      real-world source files    golden/extractor/<stem>.json
 * ```
 *
 * A `*.css` in `corpus/directives/` is an **entry** only when it has a
 * `.candidates.txt` sibling; any other `*.css` there is a partial that an entry
 * `@import`s, and travels with every entry into its temporary project.
 */
final class CorpusPaths
{
    /**
     * One candidate per line, `#` comments, blank lines ignored.
     */
    public const KIND_CANDIDATES = 'candidates';

    /**
     * An entry stylesheet plus its candidate list.
     */
    public const KIND_DIRECTIVE = 'directive';

    /**
     * A source file the CLI's extractor scans.
     */
    public const KIND_EXTRACTOR = 'extractor';

    /**
     * Names that are documentation, not corpus.
     */
    private const IGNORED = ['README.txt'];

    /**
     * @var string
     */
    private $root;

    /**
     * @var string
     */
    private $extractorDir;

    /**
     * @var list<string>|null
     */
    private $keys;

    /**
     * @param string|null $root The `tests/Conformance` directory.
     * @param string|null $extractorDir The directory of extractor fixtures.
     */
    public function __construct(?string $root = null, ?string $extractorDir = null)
    {
        $root = $root ?? \dirname(__DIR__);
        $extractorDir = $extractorDir ?? \dirname(__DIR__, 2) . '/Fixtures/extractor';

        $this->root = self::normalize($root);
        $this->extractorDir = self::normalize($extractorDir);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * The four groups, in the order goldens are generated.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return ['utilities', 'variants', 'directives', 'extractor'];
    }

    /**
     * Every corpus entry as `group/name`, sorted.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $keys = [];

        foreach (self::groups() as $group) {
            foreach ($this->namesIn($group) as $name) {
                $keys[] = $group . '/' . $name;
            }
        }

        \sort($keys, \SORT_STRING);
        $this->keys = $keys;

        return $keys;
    }

    public function has(string $key): bool
    {
        return \in_array($key, $this->keys(), true);
    }

    /**
     * Whether `$selector` names a whole group (`variants`) or one entry.
     */
    public function isSelector(string $selector): bool
    {
        return $this->has($selector) || \in_array($selector, self::groups(), true);
    }

    /**
     * The keys a selector expands to: one entry, or every entry of a group.
     *
     * @return list<string>
     */
    public function select(string $selector): array
    {
        if ($this->has($selector)) {
            return [$selector];
        }

        $selected = [];

        foreach ($this->keys() as $key) {
            if (\strncmp($key, $selector . '/', \strlen($selector) + 1) === 0) {
                $selected[] = $key;
            }
        }

        return $selected;
    }

    public function kind(string $key): string
    {
        $this->assertKey($key);

        switch (self::groupOf($key)) {
            case 'directives':
                return self::KIND_DIRECTIVE;
            case 'extractor':
                return self::KIND_EXTRACTOR;
            default:
                return self::KIND_CANDIDATES;
        }
    }

    /**
     * The file the golden is generated from: the candidate list, the entry
     * stylesheet, or the extractor fixture itself.
     */
    public function sourcePath(string $key): string
    {
        $this->assertKey($key);

        $name = self::nameOf($key);

        switch (self::groupOf($key)) {
            case 'directives':
                return $this->root . '/corpus/directives/' . $name . '.css';
            case 'extractor':
                return $this->extractorDir . '/' . $this->extractorFileFor($name);
            default:
                return $this->root . '/corpus/' . self::groupOf($key) . '/' . $name . '.txt';
        }
    }

    /**
     * The candidate list of a directive entry; null for every other kind.
     */
    public function candidatesPath(string $key): ?string
    {
        if ($this->kind($key) !== self::KIND_DIRECTIVE) {
            return null;
        }

        return $this->root . '/corpus/directives/' . self::nameOf($key) . '.candidates.txt';
    }

    /**
     * Stylesheets an entry may `@import`: every non-entry `*.css` beside it.
     *
     * @return array<string, string> file name => absolute path
     */
    public function partials(): array
    {
        $directory = $this->root . '/corpus/directives';
        $partials = [];

        foreach (self::listFiles($directory) as $file) {
            if (\substr($file, -4) !== '.css') {
                continue;
            }

            $name = \substr($file, 0, -4);

            if (!\is_file($directory . '/' . $name . '.candidates.txt')) {
                $partials[$file] = $directory . '/' . $file;
            }
        }

        \ksort($partials, \SORT_STRING);

        return $partials;
    }

    /**
     * Where the golden for a key is written.
     *
     * @param bool $minified The `--minify` variant (size comparison only).
     */
    public function goldenPath(string $key, bool $minified = false): string
    {
        $this->assertKey($key);

        $group = self::groupOf($key);
        $name = self::nameOf($key);

        if ($group === 'extractor') {
            // There is no minified extractor golden: the artefact is a list of
            // candidates, not a stylesheet.
            if ($minified) {
                throw new \InvalidArgumentException('An extractor golden has no minified variant.');
            }

            return $this->root . '/golden/extractor/' . $name . '.json';
        }

        return $this->root . '/golden/' . $group . '/' . $name . ($minified ? '.min.css' : '.css');
    }

    public function versionPath(): string
    {
        return $this->root . '/golden/VERSION';
    }

    /**
     * The CLI version the goldens were generated with.
     *
     * @throws \RuntimeException When `golden/VERSION` is missing or empty.
     */
    public function version(): string
    {
        $path = $this->versionPath();
        $contents = \is_file($path) ? \file_get_contents($path) : false;
        $version = \is_string($contents) ? \trim($contents) : '';

        if ($version === '') {
            throw new \RuntimeException('tests/Conformance/golden/VERSION is missing or empty.');
        }

        return $version;
    }

    /**
     * Parse a candidate list: `#` comments and blank lines dropped, every other
     * line split on whitespace.
     *
     * @return list<string>
     */
    public static function parseCandidates(string $contents): array
    {
        $candidates = [];

        foreach (\explode("\n", \str_replace("\r\n", "\n", $contents)) as $line) {
            $line = \trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            foreach (\explode(' ', \str_replace("\t", ' ', $line)) as $word) {
                if ($word !== '') {
                    $candidates[] = $word;
                }
            }
        }

        return $candidates;
    }

    /**
     * @throws \InvalidArgumentException When `$key` is not in the listing.
     */
    private function assertKey(string $key): void
    {
        if (!$this->has($key)) {
            throw new \InvalidArgumentException(
                \sprintf('"%s" is not an entry of the conformance corpus.', \substr($key, 0, 80))
            );
        }
    }

    /**
     * @return list<string>
     */
    private function namesIn(string $group): array
    {
        $names = [];

        if ($group === 'extractor') {
            foreach (self::listFiles($this->extractorDir) as $file) {
                $names[] = self::stemOf($file);
            }

            \sort($names, \SORT_STRING);

            return $this->uniqueStems($names);
        }

        $directory = $this->root . '/corpus/' . $group;

        foreach (self::listFiles($directory) as $file) {
            if (\in_array($file, self::IGNORED, true)) {
                continue;
            }

            if ($group === 'directives') {
                if (\substr($file, -4) !== '.css') {
                    continue;
                }

                $stem = \substr($file, 0, -4);

                if (\is_file($directory . '/' . $stem . '.candidates.txt')) {
                    $names[] = $stem;
                }

                continue;
            }

            if (\substr($file, -4) === '.txt') {
                $names[] = \substr($file, 0, -4);
            }
        }

        \sort($names, \SORT_STRING);

        return $names;
    }

    /**
     * Two fixtures sharing a stem would share a golden; refuse rather than
     * silently let one overwrite the other.
     *
     * @param list<string> $stems
     *
     * @return list<string>
     */
    private function uniqueStems(array $stems): array
    {
        if (\count($stems) !== \count(\array_unique($stems))) {
            throw new \RuntimeException('Two extractor fixtures share a file-name stem; rename one.');
        }

        return $stems;
    }

    private function extractorFileFor(string $stem): string
    {
        foreach (self::listFiles($this->extractorDir) as $file) {
            if (self::stemOf($file) === $stem) {
                return $file;
            }
        }

        throw new \RuntimeException(\sprintf('No extractor fixture has the stem "%s".', $stem));
    }

    /**
     * File name up to the first dot: `blade.blade.php` is `blade`.
     */
    private static function stemOf(string $file): string
    {
        $dot = \strpos($file, '.');

        return $dot === false || $dot === 0 ? $file : \substr($file, 0, $dot);
    }

    private static function groupOf(string $key): string
    {
        return \substr($key, 0, (int) \strpos($key, '/'));
    }

    private static function nameOf(string $key): string
    {
        return \substr($key, (int) \strpos($key, '/') + 1);
    }

    /**
     * Plain file names of a directory, sorted; empty when it does not exist.
     *
     * @return list<string>
     */
    private static function listFiles(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $entries = \scandir($directory);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if ($entry[0] !== '.' && \is_file($directory . '/' . $entry)) {
                $files[] = $entry;
            }
        }

        \sort($files, \SORT_STRING);

        return $files;
    }

    private static function normalize(string $path): string
    {
        return \rtrim(\str_replace('\\', '/', $path), '/');
    }
}
