<?php

declare(strict_types=1);

namespace TailwindPhp\PreProcessor;

use TailwindPhp\Exception\LogSafety;
use TailwindPhp\Support\Path;
use TailwindPhp\Support\Regex;

/**
 * The optional SCSS stage in front of Tailwind (design doc 06 §Pre-processor,
 * FR-PIPE-5, decision CCP-A32).
 *
 * Sass and Tailwind disagree about a few lines, and one of them is the first
 * line of every Tailwind stylesheet: `@import "tailwindcss";`. Sass treats a
 * plain `@import` whose specifier has no `.css` suffix, no `url()` and no media
 * query as a *Sass* import, tries to resolve it against its load paths, and
 * fails. `@variant` and `@custom-variant` bodies use `&` at a nesting level
 * where Sass forbids it. Those directives are therefore **shielded** — replaced
 * by a loud placeholder comment that every Sass output style preserves — and
 * restored byte-exact afterwards. Everything else Tailwind knows (`@theme`,
 * `@utility`, `@layer`, `@source`, `@plugin`, `@apply` inside a rule, `.css`
 * imports) passes through Sass natively as unknown at-rules; only whitespace
 * is normalised.
 *
 * A bare specifier is shielded only when Sass could **not** resolve it: an
 * `@import "tokens";` next to `_tokens.scss` is a Sass partial and stays a Sass
 * import; `@import "tailwindcss";` has no file behind it and is Tailwind's.
 *
 * Works with scssphp 1.12+ and 2.x: the two majors share `compileString()`,
 * `setOutputStyle()`, `addImportPath()` and `CompilationResult`.
 */
final class ScssPreProcessor implements PreProcessorInterface
{
    /**
     * A top-level `@import` (double- or single-quoted) that Sass would try to
     * resolve. `m` anchors each line; `D` keeps `$` off a trailing newline.
     */
    private const SASS_IMPORT_PATTERN = '#^[ \t]*@import[ \t]+([^;\r\n]+);[ \t]*$#mD';

    /**
     * Block directives whose bodies Sass cannot parse at the top level.
     *
     * @var list<string>
     */
    private const SHIELDED_BLOCKS = ['@variant', '@custom-variant'];

    /**
     * `%s` is a per-call nonce, so a placeholder-looking comment that already
     * sits in the source or arrives from a third-party partial can never be
     * mistaken for one of ours on restore (round-5 verifier, M-2).
     */
    private const PLACEHOLDER = '/*! tw-shield-%s-%d */';

    private const PLACEHOLDER_PATTERN = '#/\*! tw-shield-%s-([0-9]+) \*/#';

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var list<string> Extra Sass load paths, project-relative.
     */
    private $loadPaths;

    /**
     * @param string $projectRoot Absolute; imports are reported relative to it.
     * @param array<string, mixed> $options `preprocessor_options`: `load_paths`
     *                                      (list of project-relative directories
     *                                      Sass may import from).
     */
    public function __construct(string $projectRoot, array $options = [])
    {
        $this->projectRoot = $projectRoot;
        $this->loadPaths = [];

        $paths = $options['load_paths'] ?? [];

        if (\is_array($paths)) {
            foreach ($paths as $path) {
                if (\is_string($path) && $path !== '') {
                    $this->loadPaths[] = $path;
                }
            }
        }
    }

    /**
     * @throws \RuntimeException When scssphp is not installed or the entry does
     *                           not compile; the orchestrator maps it to a
     *                           `CompilationException` with one `E_CSS_SYNTAX`
     *                           diagnostic (CCP-A14). The message is scrubbed.
     */
    public function process(string $entryText, string $entryRelPath): PreProcessResult
    {
        $compilerClass = 'ScssPhp\\ScssPhp\\Compiler';

        if (!\class_exists($compilerClass)) {
            throw new \RuntimeException(
                'preprocessor "scss" needs the scssphp/scssphp package (composer require scssphp/scssphp).'
            );
        }

        $entryAbs = $this->projectRoot . '/' . $entryRelPath;
        $searchDirs = $this->searchDirectories(\dirname($entryAbs));

        /** @var array<int, string> $shielded */
        $shielded = [];
        $nonce = \bin2hex(\random_bytes(4));

        // Only code is shielded: a directive inside a block comment must stay
        // there, or the placeholder's own terminator would end the comment
        // early and Sass would choke on what follows (round-5 verifier, M-1).
        $shield = function (string $code) use (&$shielded, $searchDirs, $nonce): string {
            $code = $this->shieldBlocks($code, $shielded, $nonce);

            return $this->shieldImports($code, $searchDirs, $shielded, $nonce);
        };
        $text = self::mapCodeSegments($entryText, $shield);

        // Referenced by name rather than imported: scssphp is optional and
        // `class_exists` above is the real guard. `OutputStyle::EXPANDED` is a
        // string constant in 1.x and an enum case in 2.x, and both supported
        // majors (`^1.12 || ^2.0`) have it; `constant()` resolves either.
        $compiler = new $compilerClass();
        $compiler->setOutputStyle(\constant('ScssPhp\\ScssPhp\\OutputStyle::EXPANDED'));

        foreach ($searchDirs as $dir) {
            $compiler->addImportPath($dir);
        }

        try {
            $result = $compiler->compileString($text, $entryAbs);
        } catch (\Throwable $e) {
            // scssphp quotes the absolute path of the failing file in its
            // message; the diagnostic this becomes is rendered in a browser.
            throw new \RuntimeException(LogSafety::scrubText($e->getMessage()), 0, $e);
        }

        return new PreProcessResult(
            $this->restore($result->getCss(), $shielded, $nonce),
            $this->relativeImports($result->getIncludedFiles(), $entryAbs)
        );
    }

    /**
     * Apply $map to every stretch of the text that is not inside a `/* … *\/`
     * block comment, leaving the comments byte-identical.
     *
     * @param callable(string): string $map
     */
    private static function mapCodeSegments(string $text, callable $map): string
    {
        $out = '';
        $offset = 0;
        $length = \strlen($text);

        while ($offset < $length) {
            $start = \strpos($text, '/*', $offset);

            if ($start === false) {
                $out .= $map((string) \substr($text, $offset));

                break;
            }

            $end = \strpos($text, '*/', $start + 2);
            $end = $end === false ? $length : $end + 2;

            $out .= $map((string) \substr($text, $offset, $start - $offset));
            $out .= \substr($text, $start, $end - $start);
            $offset = $end;
        }

        return $out;
    }

    /**
     * The entry's own directory first, then the configured load paths.
     *
     * @return list<string>
     */
    private function searchDirectories(string $entryDir): array
    {
        $dirs = [$entryDir];

        foreach ($this->loadPaths as $path) {
            $dirs[] = $this->projectRoot . '/' . $path;
        }

        return $dirs;
    }

    /**
     * Replace every `@variant` / `@custom-variant` statement or block with a
     * placeholder. Braces are balanced by counting; strings inside a variant
     * body are rare enough that a brace inside quotes is treated as a brace.
     *
     * @param array<int, string> $shielded
     */
    private function shieldBlocks(string $text, array &$shielded, string $nonce): string
    {
        $out = '';
        $length = \strlen($text);
        $i = 0;

        while ($i < $length) {
            $directive = self::directiveAt($text, $i);

            if ($directive === null) {
                $out .= $text[$i];
                ++$i;

                continue;
            }

            $end = self::endOfDirective($text, $i);
            $shielded[] = (string) \substr($text, $i, $end - $i);
            $out .= \sprintf(self::PLACEHOLDER, $nonce, \count($shielded) - 1);
            $i = $end;
        }

        return $out;
    }

    /**
     * The shielded block directive starting at $i, when $i is at a line start
     * (ignoring indentation) and the word boundary after it is respected.
     */
    private static function directiveAt(string $text, int $i): ?string
    {
        if ($text[$i] !== '@') {
            return null;
        }

        // Only at the start of a line (after indentation): `@variant` inside a
        // declaration value or a comment is not ours to touch.
        $j = $i - 1;

        while ($j >= 0 && ($text[$j] === ' ' || $text[$j] === "\t")) {
            --$j;
        }

        if ($j >= 0 && $text[$j] !== "\n") {
            return null;
        }

        foreach (self::SHIELDED_BLOCKS as $directive) {
            $len = \strlen($directive);

            if (\substr($text, $i, $len) !== $directive) {
                continue;
            }

            $next = $text[$i + $len] ?? '';

            if ($next === ' ' || $next === "\t" || $next === '{' || $next === '(' || $next === "\n") {
                return $directive;
            }
        }

        return null;
    }

    /**
     * Index just past the directive: past its `;` for a statement, past the
     * matching `}` for a block.
     */
    private static function endOfDirective(string $text, int $start): int
    {
        $length = \strlen($text);
        $depth = 0;

        for ($i = $start; $i < $length; ++$i) {
            $byte = $text[$i];

            if ($byte === '{') {
                ++$depth;
            } elseif ($byte === '}') {
                --$depth;

                if ($depth === 0) {
                    return $i + 1;
                }
            } elseif ($byte === ';' && $depth === 0) {
                return $i + 1;
            }
        }

        return $length;
    }

    /**
     * Replace every bare-package `@import` Sass could not resolve.
     *
     * @param list<string> $searchDirs
     * @param array<int, string> $shielded
     */
    private function shieldImports(string $text, array $searchDirs, array &$shielded, string $nonce): string
    {
        return Regex::replaceCallback(
            self::SASS_IMPORT_PATTERN,
            static function (array $match) use (&$shielded, $searchDirs, $nonce): string {
                $spec = self::barePackageSpecifier($match[1]);

                if ($spec === null || self::sassFileExists($spec, $searchDirs)) {
                    return $match[0];
                }

                $shielded[] = \trim($match[0]);

                return \sprintf(self::PLACEHOLDER, $nonce, \count($shielded) - 1);
            },
            $text
        );
    }

    /**
     * The quoted specifier when the import is the bare form Sass resolves
     * itself (`"tailwindcss"`, `"tokens"`), else null: `url(...)`, a `.css`
     * suffix, a media query or a list are plain CSS imports Sass emits as-is.
     */
    private static function barePackageSpecifier(string $specifiers): ?string
    {
        $specifiers = \trim($specifiers);

        if (\strncasecmp($specifiers, 'url(', 4) === 0) {
            return null;
        }

        $quote = $specifiers[0] ?? '';

        if ($quote !== '"' && $quote !== "'") {
            return null;
        }

        $end = \strpos($specifiers, $quote, 1);

        if ($end === false || \trim(\substr($specifiers, $end + 1)) !== '') {
            return null;
        }

        $spec = (string) \substr($specifiers, 1, $end - 1);

        return \substr($spec, -4) === '.css' ? null : $spec;
    }

    /**
     * Whether Sass would find a file for the specifier — the same candidate
     * list Sass uses: `x.scss`, `_x.scss`, `x.sass`, `_x.sass`, `x/_index.scss`,
     * `x/index.scss`. Only then is the import a Sass import.
     *
     * @param list<string> $searchDirs
     */
    private static function sassFileExists(string $spec, array $searchDirs): bool
    {
        if ($spec === '' || \strpos($spec, "\0") !== false) {
            return false;
        }

        $dir = \dirname($spec);
        $base = \basename($spec);
        $prefix = $dir === '.' ? '' : $dir . '/';

        $candidates = [
            $spec,
            $prefix . $base . '.scss',
            $prefix . '_' . $base . '.scss',
            $prefix . $base . '.sass',
            $prefix . '_' . $base . '.sass',
            $spec . '/_index.scss',
            $spec . '/index.scss',
        ];

        foreach ($searchDirs as $searchDir) {
            foreach ($candidates as $candidate) {
                if (\is_file($searchDir . '/' . $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $shielded
     */
    private function restore(string $css, array $shielded, string $nonce): string
    {
        if ($shielded === []) {
            return $css;
        }

        return Regex::replaceCallback(
            \sprintf(self::PLACEHOLDER_PATTERN, $nonce),
            static function (array $match) use ($shielded): string {
                return $shielded[(int) $match[1]] ?? $match[0];
            },
            $css
        );
    }

    /**
     * The Sass import graph as project-relative paths, entry excluded — these
     * become `manifest.imports` (kind `i`) so an edited partial invalidates the
     * cache. A file outside the project has no relative form and is skipped.
     *
     * @param array<int, string> $included
     *
     * @return list<string>
     */
    private function relativeImports(array $included, string $entryAbs): array
    {
        $imports = [];
        $entry = Path::normalize($entryAbs);

        foreach ($included as $file) {
            $normalised = Path::normalize(self::fromFileUrl((string) $file));

            if ($normalised === $entry) {
                continue;
            }

            try {
                $imports[] = Path::relative($this->projectRoot, $normalised);
            } catch (\TailwindPhp\Exception\PathException $e) {
                continue;
            }
        }

        $imports = \array_values(\array_unique($imports));
        \sort($imports, \SORT_STRING);

        return $imports;
    }

    /**
     * scssphp 2.x reports included files as `file:///C:/x/y.scss` URLs
     * (percent-encoded); 1.x reports plain paths. Both become a plain path.
     */
    private static function fromFileUrl(string $file): string
    {
        if (\strncasecmp($file, 'file://', 7) !== 0) {
            return $file;
        }

        $path = \rawurldecode((string) \substr($file, 7));

        // `file:///C:/x` carries a leading slash before the drive letter.
        if (\strlen($path) >= 3 && $path[0] === '/' && $path[2] === ':') {
            $path = (string) \substr($path, 1);
        }

        return $path;
    }
}
