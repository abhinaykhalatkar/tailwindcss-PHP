<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Comment;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\Hash;

/**
 * Turns the pinned official CLI's own output into the engine's two data files
 * (decision ENG-A5, design doc 07 §Phase 2 and §Layers, ADR-012):
 *
 * - `Engine\Data\DefaultTheme` — every default theme variable and the default
 *   keyframes, from `@import "tailwindcss/theme.css" theme(static);`
 * - `Engine\Data\Preflight` — the base stylesheet, from
 *   `@import "tailwindcss/preflight.css";`
 *
 * Nothing is extracted from the binary and nothing comes from npm: the CLI is
 * asked, like any user would, what it generates for those two inputs, and the
 * answer is parsed with the library's own `Css\Parser`. Upgrading the pinned
 * release is therefore a script run followed by a diff.
 *
 * **What the generator promises about the files it writes**
 *
 * - They contain string, boolean and array literals only (threat T-08). The
 *   generated source is tokenised before it is returned, and anything outside a
 *   short whitelist — a call, a variable, an include, a `new` — is refused, so a
 *   stylesheet value that merely *looks* like code stays a string.
 * - The header records where the data came from: the exact input, the CLI
 *   options, the release, the SHA-256 the library pins for **every** platform
 *   binary of that release, and the SHA-256 of the stylesheet the CLI printed.
 *   All platforms rather than the one that ran, so the header is identical on a
 *   Windows laptop and on the Linux CI job that checks it.
 * - The upstream copyright and the full MIT licence are reproduced verbatim,
 *   never as a bare label (ADR-012). `tests/Integration/Tools/ImportersTest`
 *   keeps {@see self::LICENCE} equal to the block in `NOTICE`.
 * - Output paths are the two constants below, never an argument (T-01).
 *
 * The compile step is injected, in production `[CliRunner, 'compile']`, which
 * is what verifies the binary against the pinned digest before every run
 * (T-04) and never downloads one (T-18).
 *
 * Lives under `tools/`: maintainer tooling, not autoloaded, not shipped. It is
 * loaded by path together with `CliRunner.php` and `GoldenGenerator.php`.
 */
final class DataImporter
{
    public const THEME_FILE = 'src/Engine/Data/DefaultTheme.php';

    public const PREFLIGHT_FILE = 'src/Engine/Data/Preflight.php';

    /**
     * `theme(static)` makes the CLI emit every variable, used or not, and with
     * them the default keyframes; no candidates are needed.
     */
    public const THEME_INPUT = "@import \"tailwindcss/theme.css\" theme(static);\n";

    public const PREFLIGHT_INPUT = "@import \"tailwindcss/preflight.css\";\n";

    /**
     * The upstream licence, verbatim from `NOTICE` (one line per line, without
     * the indentation `NOTICE` gives it).
     */
    public const LICENCE = <<<'TEXT'
        MIT License

        Copyright (c) Tailwind Labs, Inc.

        Permission is hereby granted, free of charge, to any person obtaining a copy
        of this software and associated documentation files (the "Software"), to deal
        in the Software without restriction, including without limitation the rights
        to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
        copies of the Software, and to permit persons to whom the Software is
        furnished to do so, subject to the following conditions:

        The above copyright notice and this permission notice shall be included in all
        copies or substantial portions of the Software.

        THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
        IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
        FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
        AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
        LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
        OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
        SOFTWARE.
        TEXT;

    private const THEME_SELECTOR = ':root, :host';

    private const IDENTIFIER_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';

    private const NAMESPACE_NAME = 'TailwindPhp\\Engine\\Data';

    /**
     * @var callable(string, string, array<string, string>, bool): string
     */
    private $compile;

    /**
     * @var string
     */
    private $version;

    /**
     * @var array<string, string>
     */
    private $pinnedDigests;

    /**
     * `$compile` takes the input CSS, the candidates text, extra files and the
     * minify flag, and returns the raw stylesheet.
     *
     * @param callable(string, string, array<string, string>, bool): string $compile
     * @param string $version The release the data is for (`golden/VERSION`).
     * @param array<string, string> $pinnedDigests Platform => SHA-256 pinned for
     *                                             that release in `Checksums`.
     */
    public function __construct(callable $compile, string $version, array $pinnedDigests)
    {
        $this->compile = $compile;
        $this->version = $version;
        $this->pinnedDigests = $pinnedDigests;
    }

    /**
     * Ask the CLI and build `DefaultTheme.php`.
     *
     * @return array{file: string, contents: string}
     *
     * @throws \RuntimeException When the output is not what the data file needs.
     */
    public function theme(): array
    {
        return [
            'file' => self::THEME_FILE,
            'contents' => $this->themeFromCss($this->fetch(self::THEME_INPUT)),
        ];
    }

    /**
     * Ask the CLI and build `Preflight.php`.
     *
     * @return array{file: string, contents: string}
     *
     * @throws \RuntimeException When the output is not what the data file needs.
     */
    public function preflight(): array
    {
        return [
            'file' => self::PREFLIGHT_FILE,
            'contents' => $this->preflightFromCss($this->fetch(self::PREFLIGHT_INPUT)),
        ];
    }

    /**
     * The source of `DefaultTheme.php` for a stylesheet the CLI printed (LF,
     * banner already removed). Public so the committed file can be reproduced
     * from a golden without a binary.
     *
     * @throws \RuntimeException
     */
    public function themeFromCss(string $css): string
    {
        $sheet = $this->parse($css);
        $nodes = $sheet->nodes;
        $first = $nodes === [] ? null : $nodes[0];

        if (!$first instanceof Rule || $first->selector !== self::THEME_SELECTOR) {
            throw new \RuntimeException('The theme output does not start with a ":root, :host" rule.');
        }

        $vars = [];

        foreach ($first->nodes as $declaration) {
            if (
                !$declaration instanceof Declaration
                || \strncmp($declaration->property, '--', 2) !== 0
                || $declaration->important
            ) {
                throw new \RuntimeException('The ":root, :host" rule holds something other than plain variables.');
            }

            if (isset($vars[$declaration->property])) {
                throw new \RuntimeException(\sprintf('The theme declares %s twice.', $declaration->property));
            }

            $vars[$declaration->property] = self::oneLine($declaration->value);
        }

        if ($vars === []) {
            throw new \RuntimeException('The theme output declares no variables.');
        }

        $keyframes = [];

        foreach (\array_slice($nodes, 1) as $node) {
            if (!$node instanceof AtRule || $node->name !== 'keyframes' || $node->nodes === null) {
                throw new \RuntimeException('The theme output holds something other than @keyframes blocks.');
            }

            $name = \trim($node->params);

            if ($name === '' || \strspn($name, self::IDENTIFIER_BYTES) !== \strlen($name)) {
                throw new \RuntimeException('A @keyframes name is not a plain identifier.');
            }

            if (isset($keyframes[$name])) {
                throw new \RuntimeException(\sprintf('The theme defines @keyframes %s twice.', $name));
            }

            $keyframes[$name] = \rtrim(Serializer::pretty(new StyleSheet([$node])), "\n");
        }

        $source = $this->render(
            'DefaultTheme',
            self::THEME_INPUT,
            'tools/import-default-theme.php',
            $css,
            \sprintf('%d variables, %d keyframes', \count($vars), \count($keyframes)),
            'Tailwind CSS default theme (design tokens and default keyframes), converted to PHP data.',
            [
                ['name' => 'vars', 'doc' => 'Variable name => value, in the order theme.css declares them.',
                    'return' => 'array<string, string>', 'value' => $vars],
                ['name' => 'keyframes', 'return' => 'array<string, string>', 'value' => $keyframes,
                    'doc' => 'Keyframes name => the whole `@keyframes` block, ready to emit or to parse.'],
            ]
        );

        return $source;
    }

    /**
     * The source of `Preflight.php` for a stylesheet the CLI printed (LF,
     * banner already removed).
     *
     * @throws \RuntimeException
     */
    public function preflightFromCss(string $css): string
    {
        $sheet = $this->parse($css);

        if ($sheet->nodes === []) {
            throw new \RuntimeException('The preflight output is empty.');
        }

        $ast = [];

        foreach ($sheet->nodes as $node) {
            if (!$node instanceof Rule && !$node instanceof AtRule) {
                throw new \RuntimeException('The preflight output holds something other than rules.');
            }

            if ($node instanceof AtRule && !\in_array($node->name, ['supports', 'media', 'layer'], true)) {
                throw new \RuntimeException(\sprintf('The preflight output holds an unexpected @%s.', $node->name));
            }

            $ast[] = self::toArray($node);
        }

        return $this->render(
            'Preflight',
            self::PREFLIGHT_INPUT,
            'tools/import-preflight.php',
            $css,
            \sprintf('%d top-level nodes', \count($ast)),
            'Tailwind CSS preflight (the base stylesheet), converted to PHP data.',
            [
                ['name' => 'css', 'doc' => 'The CLI\'s preflight, verbatim: LF line endings, banner removed.',
                    'return' => 'string', 'value' => $css],
                ['name' => 'ast', 'doc' => 'The same stylesheet as plain arrays (no objects, no source positions).',
                    'return' => 'list<array<string, mixed>>', 'value' => $ast],
            ]
        );
    }

    /**
     * Write `$contents` to `$path`, or with `$check` only compare.
     *
     * @return string `written`, `unchanged`, `drift` (check mode: differs) or
     *                `missing` (check mode: no file).
     */
    public static function settle(string $path, string $contents, bool $check): string
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
            throw new \RuntimeException('Could not write the generated file.');
        }

        return 'written';
    }

    /**
     * The command line front end shared by `import-default-theme.php` and
     * `import-preflight.php`.
     *
     *   --check          write nothing; exit 1 when the committed file would change
     *   --binary=<path>  a binary inside the repository instead of tools/.cache/
     *
     * Exit codes: 0 clean, 1 drift found by --check, 2 usage or environment error.
     *
     * @param string $kind `theme` or `preflight`.
     * @param list<string> $argv The script's `$argv`.
     * @param resource|null $out Defaults to STDOUT.
     * @param resource|null $err Defaults to STDERR.
     */
    public static function main(string $kind, array $argv, string $repositoryRoot, $out = null, $err = null): int
    {
        $out = $out ?? \STDOUT;
        $err = $err ?? \STDERR;
        $script = $kind === 'theme' ? 'import-default-theme' : 'import-preflight';
        $usage = 'Usage: php tools/' . $script . ".php [--check] [--binary=<path inside the repository>]\n";
        $check = false;
        $binary = null;

        foreach (\array_slice($argv, 1) as $argument) {
            if ($argument === '--check') {
                $check = true;

                continue;
            }

            if (\strncmp($argument, '--binary=', 9) === 0) {
                $binary = \substr($argument, 9);

                continue;
            }

            \fwrite($err, 'Unknown argument "' . \substr($argument, 0, 40) . "\".\n" . $usage);

            return 2;
        }

        try {
            $root = \rtrim(\str_replace('\\', '/', $repositoryRoot), '/');
            $version = self::pinnedVersion($root);
            $runner = self::runnerFor($root, $version, $binary);
            $digests = Checksums::load()->forVersion($version);

            if ($digests === null) {
                throw new \RuntimeException(\sprintf('The checksum table does not pin %s.', $version));
            }

            $importer = new self([$runner, 'compile'], $version, $digests);
            $result = $kind === 'theme' ? $importer->theme() : $importer->preflight();
            $status = self::settle($root . '/' . $result['file'], $result['contents'], $check);
        } catch (\RuntimeException $e) {
            \fwrite($err, $e->getMessage() . "\n");

            return 2;
        }

        \fwrite($out, \sprintf("%-9s %s\n", $status, $result['file']));

        if ($check && ($status === 'drift' || $status === 'missing')) {
            \fwrite($err, 'The committed file is stale; run `php tools/' . $script . ".php` and commit the result.\n");

            return 1;
        }

        return 0;
    }

    /**
     * The runner for a repository, optionally for an explicit binary.
     *
     * An explicit path goes through {@see Environment::binaryPath()}, so it must
     * resolve to a file inside the repository: `--binary=../elsewhere` is not a
     * way to run something unrecognised (T-01). Whatever is found is verified
     * against the pinned digest by the runner before it is executed (T-04).
     *
     * @throws \RuntimeException When no usable binary is found.
     */
    public static function runnerFor(string $repositoryRoot, string $version, ?string $binary): CliRunner
    {
        if ($binary === null) {
            return CliRunner::forRepository($repositoryRoot, $version);
        }

        $environment = new Environment();
        $path = $binary === ''
            ? null
            : $environment->binaryPath($binary, $repositoryRoot, [$repositoryRoot]);

        if ($path === null) {
            throw new \RuntimeException(
                '--binary must name an existing binary inside the repository. ' . CliRunner::installHint($version)
            );
        }

        return new CliRunner(
            $path,
            $version,
            Checksums::load(),
            $environment,
            new Process(),
            new Filesystem(),
            \sys_get_temp_dir()
        );
    }

    // ------------------------------------------------------------- internals

    /**
     * Compile an input with the verified binary and clean the answer.
     */
    private function fetch(string $input): string
    {
        $raw = ($this->compile)($input, '', [], false);

        return GoldenGenerator::stripBanner(GoldenGenerator::normalise($raw), $this->version);
    }

    /**
     * @throws \RuntimeException When the CSS does not parse without a diagnostic.
     */
    private function parse(string $css): StyleSheet
    {
        $diagnostics = new DiagnosticBag();
        $sheet = Parser::parse($css, $diagnostics);

        if (!$diagnostics->isEmpty()) {
            throw new \RuntimeException('The CLI output did not parse cleanly with Css\\Parser.');
        }

        return $sheet;
    }

    /**
     * A wrapped value on one line. The CLI breaks long lists over several lines
     * with a newline and indentation; collapsing those is whitespace-only, and a
     * CSS string cannot contain a raw newline, so no string is touched.
     */
    private static function oneLine(string $value): string
    {
        $collapsed = \preg_replace('/\n[ \t]*/', ' ', $value);

        if (!\is_string($collapsed)) {
            throw new \RuntimeException('A theme value could not be normalised.');
        }

        return \trim($collapsed);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Node $node): array
    {
        if ($node instanceof Declaration) {
            return [
                'type' => 'decl',
                'property' => $node->property,
                'value' => $node->value,
                'important' => $node->important,
            ];
        }

        if ($node instanceof Rule) {
            return ['type' => 'rule', 'selector' => $node->selector, 'nodes' => self::children($node->nodes)];
        }

        if ($node instanceof AtRule) {
            return [
                'type' => 'at-rule',
                'name' => $node->name,
                'params' => $node->params,
                'nodes' => $node->nodes === null ? null : self::children($node->nodes),
            ];
        }

        if ($node instanceof Comment) {
            return ['type' => 'comment', 'text' => $node->text];
        }

        throw new \RuntimeException('Unknown stylesheet node.');
    }

    /**
     * @param array<array-key, Node> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private static function children(array $nodes): array
    {
        $children = [];

        foreach ($nodes as $node) {
            $children[] = self::toArray($node);
        }

        return $children;
    }

    /**
     * Assemble a data class.
     *
     * @param array<int, array{name: string, doc: string, return: string, value: mixed}> $methods
     */
    private function render(
        string $class,
        string $input,
        string $script,
        string $css,
        string $summary,
        string $title,
        array $methods
    ): string {
        $source = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "// phpcs:ignoreFile -- generated data: the long literals are the point.\n\n"
            . 'namespace ' . self::NAMESPACE_NAME . ";\n\n"
            . $this->header($title, $input, $script, $css, $summary)
            . 'final class ' . $class . "\n{\n"
            . "    public const VERSION = '" . $this->version . "';\n\n"
            . "    private function __construct()\n    {\n    }\n";

        $names = ['__construct'];

        foreach ($methods as $method) {
            $names[] = $method['name'];
            $type = $method['return'] === 'string' ? 'string' : 'array';
            $source .= "\n    /**\n     * " . $method['doc'] . "\n     *\n     * @return " . $method['return']
                . "\n     */\n"
                . '    public static function ' . $method['name'] . '(): ' . $type . "\n    {\n"
                . '        return ' . self::export($method['value'], 2, true) . ";\n    }\n";
        }

        $source .= "}\n";

        self::assertLiteralsOnly($source, $class, $names);

        return $source;
    }

    private function header(string $title, string $input, string $script, string $css, string $summary): string
    {
        $lines = [
            $title,
            '',
            'GENERATED FILE. Do not edit by hand: run `php ' . $script . '` and commit the result.',
            '',
            'Provenance',
            '  generator   ' . $script . ' (class TailwindPhp\\Tools\\DataImporter)',
            '  tailwind    ' . $this->version . ' (the release pinned in tests/Conformance/golden/VERSION)',
        ];

        $inputLines = \explode("\n", \rtrim($input, "\n"));

        foreach ($inputLines as $index => $line) {
            $lines[] = ($index === 0 ? '  input.css   ' : '              ') . $line;
        }

        $lines[] = '  candidates  none';
        $lines[] = '  options     tailwindcss -i input.css -o output.css --cwd <temporary project>';
        $lines[] = '  binaries    SHA-256 pinned for this release, one per platform (resources/checksums.json):';

        $digests = $this->pinnedDigests;
        \ksort($digests, \SORT_STRING);

        foreach ($digests as $platform => $digest) {
            $lines[] = '                ' . \str_pad((string) $platform, 16) . ' ' . $digest;
        }

        $lines[] = '  output      SHA-256 of the stylesheet the CLI printed (banner removed, LF):';
        $lines[] = '                ' . Hash::sha256($css);
        $lines[] = '  content     ' . $summary;
        $lines[] = '';
        $lines[] = 'Upstream attribution (ADR-012). This file contains data derived from Tailwind CSS,';
        $lines[] = 'https://github.com/tailwindlabs/tailwindcss, reproduced under the licence below.';
        $lines[] = 'tailwindcss-php is independent of Tailwind Labs and is not endorsed by it.';
        $lines[] = '';

        foreach (\explode("\n", self::LICENCE) as $line) {
            $lines[] = $line;
        }

        $header = "/**\n";

        foreach ($lines as $line) {
            $header .= \rtrim(' * ' . $line) . "\n";
        }

        return $header . " */\n";
    }

    /**
     * Export a value as PHP source, in a fixed style: four-space indent, short
     * arrays, single-quoted strings, trailing commas. Scalar-only arrays below
     * the top level sit on one line.
     *
     * @param mixed $value
     */
    private static function export($value, int $level, bool $top = false): string
    {
        if (\is_string($value)) {
            return "'" . \str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (!\is_array($value)) {
            throw new \RuntimeException('Only strings, booleans, integers, null and arrays can be exported.');
        }

        if ($value === []) {
            return '[]';
        }

        $isList = \array_keys($value) === \range(0, \count($value) - 1);
        $leaf = true;

        foreach ($value as $item) {
            if (\is_array($item)) {
                $leaf = false;
            }
        }

        $pad = \str_repeat('    ', $level + 1);
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = ($isList ? '' : self::export((string) $key, 0) . ' => ') . self::export($item, $level + 1);
        }

        if ($leaf && !$top) {
            return '[' . \implode(', ', $parts) . ']';
        }

        return "[\n" . $pad . \implode(",\n" . $pad, $parts) . ",\n" . \str_repeat('    ', $level) . ']';
    }

    /**
     * Refuse any generated source that is more than literals (T-08).
     *
     * Tokenised with `TOKEN_PARSE`, which also rejects a syntax error, so a
     * generator bug cannot produce a file that fails to load. Allowed: the open
     * tag, comments, `declare(strict_types=1)`, one namespace, one final class
     * with a constant, a private constructor and the named static methods, and
     * string/boolean/null/array literals. A call is allowed only for `declare`
     * and for the declarations themselves.
     *
     * @param list<string> $methods
     *
     * @throws \RuntimeException
     */
    private static function assertLiteralsOnly(string $php, string $class, array $methods): void
    {
        try {
            $tokens = \token_get_all($php, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \RuntimeException('The generated file does not parse: ' . $e->getMessage());
        }

        $allowedWords = \array_merge(
            ['strict_types', 'TailwindPhp', 'Engine', 'Data', $class, 'VERSION', 'true', 'false', 'null',
                'array', 'string'],
            $methods
        );
        $allowedTokens = [
            \T_OPEN_TAG, \T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT, \T_DECLARE, \T_LNUMBER, \T_STRING,
            \T_NS_SEPARATOR, \T_NAMESPACE, \T_FINAL, \T_CLASS, \T_CONST, \T_PUBLIC, \T_PRIVATE, \T_STATIC,
            \T_FUNCTION, \T_RETURN, \T_ARRAY, \T_CONSTANT_ENCAPSED_STRING, \T_DOUBLE_ARROW,
        ];

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED'] as $name) {
            if (\defined($name)) {
                $allowedTokens[] = (int) \constant($name);
            }
        }

        $previous = null;

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                if (!\in_array($token, [';', '{', '}', '[', ']', '(', ')', ',', ':', '='], true)) {
                    throw new \RuntimeException('The generated file contains an unexpected character.');
                }

                // A parenthesis is only legitimate after `declare` and after the
                // names of the methods this file declares; anything else is a call.
                if (
                    $token === '('
                    && $previous !== \T_DECLARE
                    && !(\is_string($previous) && \in_array($previous, $methods, true))
                ) {
                    throw new \RuntimeException('The generated file contains a call.');
                }

                $previous = $token;

                continue;
            }

            if (!\in_array($token[0], $allowedTokens, true)) {
                throw new \RuntimeException(
                    'The generated file contains ' . \token_name($token[0]) . ', which is not data.'
                );
            }

            if ($token[0] === \T_STRING && !\in_array($token[1], $allowedWords, true)) {
                throw new \RuntimeException('The generated file contains an unexpected name.');
            }

            if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                continue;
            }

            $previous = $token[0] === \T_STRING ? $token[1] : $token[0];
        }
    }

    /**
     * The release in `tests/Conformance/golden/VERSION`.
     *
     * @throws \RuntimeException
     */
    private static function pinnedVersion(string $root): string
    {
        $file = $root . '/tests/Conformance/golden/VERSION';
        $contents = \is_file($file) ? \file_get_contents($file) : false;
        $version = \is_string($contents) ? \trim($contents) : '';

        if ($version === '') {
            throw new \RuntimeException('tests/Conformance/golden/VERSION is missing or empty.');
        }

        return $version;
    }
}
