<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

/**
 * The six static security rules of design doc 16 §Security suite, in one place.
 *
 * Two front ends share this engine — `tests/Security/StaticChecksTest.php` so
 * the rules run on every developer machine, and `tools/static-security-checks.php`
 * so they run in the CI security job (design doc 20 §ci.yml). Duplicating a rule
 * between them would let the two drift, which is worse than having one.
 *
 * **The PHP rules parse rather than grep.** `token_get_all()` means a docblock
 * that merely mentions a forbidden call is not a violation — the tree contains
 * several such mentions, deliberately, because explaining why a call is confined
 * is part of the control. A textual scan would either flag those or force the
 * comments to be written around the check, and a rule people work around stops
 * being a rule.
 *
 * Lives under `tools/`, which is maintainer tooling: not autoloaded, not
 * shipped (design doc 17). It may therefore use PCRE itself, which rule R1
 * forbids elsewhere, and it depends on nothing in `src/`.
 */
final class StaticSecurityChecks
{
    /**
     * Threat T-12: one auditable call site for PCRE, which is what makes the
     * `preg_last_error()` check meaningful.
     */
    public const RULE_PCRE = 'no-pcre-outside-support-regex';

    /**
     * Threat T-08: nothing near the cache may load code.
     */
    public const RULE_CODE_LOADING = 'no-dynamic-code-loading';

    /**
     * Threat T-09: request state is read in one place, so debug mode and
     * configuration can never be influenced by a request.
     */
    public const RULE_SUPERGLOBAL = 'no-superglobals-outside-console-and-serverrequest';

    /**
     * Threat T-18: the only network code is the opt-in binary download.
     */
    public const RULE_NETWORK = 'no-network-outside-install-cli';

    /**
     * Design doc 15: an unqualified `catch (Exception $e)` inside a namespace
     * resolves to a class that does not exist, so the handler never runs.
     */
    public const RULE_CATCH = 'catch-must-name-a-resolvable-class';

    /**
     * ADR-014: artefacts hold project-relative paths only.
     */
    public const RULE_MANIFEST_PATH = 'no-absolute-paths-in-fixture-manifests';

    /**
     * Files allowed to call PCRE, relative to the scanned root.
     *
     * @var list<string>
     */
    private const PCRE_ALLOWED = ['Support/Regex.php'];

    /**
     * Files allowed to load code (threat T-08).
     *
     * `Config/ConfigFile.php` is the single sanctioned `require` site in `src/`
     * (decision HBC-A9): a `config/tailwind.php` returns an array and has to be
     * executed to do so. It earns the entry by proving, before the `require`,
     * that the path is an existing `.php` file confined to the project root and
     * **not** inside the cache directory — otherwise a written artefact could
     * become executable configuration.
     *
     * `bin/tailwind-php` is outside `src/`; the `Engine\Data` loaders and the
     * `Console` bootstrap join this list when they exist.
     *
     * @var list<string>
     */
    private const CODE_LOADING_ALLOWED = ['Config/ConfigFile.php'];

    /**
     * Request state is read only by the console (which has no request) and by
     * the one class whose job is to parse `$_SERVER` (T-09).
     *
     * @var list<string>
     */
    private const SUPERGLOBAL_ALLOWED = ['Console/', 'Http/ServerRequest.php'];

    /**
     * The single opt-in, CLI-only download path (T-18, T-04).
     *
     * @var list<string>
     */
    private const NETWORK_ALLOWED = ['Console/Command/InstallCliCommand.php'];

    /**
     * @var int
     */
    private $scannedFiles = 0;

    /**
     * Every rule this engine implements.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            self::RULE_PCRE,
            self::RULE_CODE_LOADING,
            self::RULE_SUPERGLOBAL,
            self::RULE_NETWORK,
            self::RULE_CATCH,
            self::RULE_MANIFEST_PATH,
        ];
    }

    /**
     * Run every rule and return the violations found.
     *
     * @param string $srcRoot Directory whose `*.php` files are scanned.
     * @param string|null $manifestDir Directory of fixture manifests for R6; a
     *                                 missing or null directory simply yields no
     *                                 violations, so the rule is safe to run
     *                                 before any fixture exists.
     *
     * @return list<array{rule: string, file: string, line: int, text: string}>
     */
    public function run(string $srcRoot, ?string $manifestDir): array
    {
        $this->scannedFiles = 0;

        $violations = [];
        $root = self::normalize($srcRoot);

        foreach (self::phpFilesIn($root) as $file) {
            ++$this->scannedFiles;

            $relative = self::relative($root, $file);
            $source = \file_get_contents($file);

            if ($source === false) {
                continue;
            }

            foreach (self::inspect($relative, $source) as $violation) {
                $violations[] = $violation;
            }
        }

        foreach (self::manifestViolations($manifestDir) as $violation) {
            ++$this->scannedFiles;
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * Number of files the last {@see self::run()} looked at.
     */
    public function scannedFiles(): int
    {
        return $this->scannedFiles;
    }

    /**
     * @return list<array{rule: string, file: string, line: int, text: string}>
     */
    private static function inspect(string $relative, string $source): array
    {
        $tokens = \token_get_all($source);
        $violations = [];
        $imports = self::importsOf($tokens);
        $depth = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                if ($token === '{') {
                    ++$depth;
                } elseif ($token === '}') {
                    --$depth;
                }

                continue;
            }

            [$id, $text, $line] = [$token[0], $token[1], $token[2]];

            // Comments and string literals never constitute a call.
            if ($id === \T_COMMENT || $id === \T_DOC_COMMENT || $id === \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            // The bare function name, whether written `preg_match(` or, as the
            // fixer rewrites every global call, `\preg_match(`. On PHP 8 the
            // latter is a single name token rather than a separator plus a
            // T_STRING, so matching only T_STRING would miss exactly the form
            // this codebase actually contains.
            $call = self::callName($id, $text);

            if (
                $call !== null && \strncmp($call, 'preg_', 5) === 0
                && !self::isAllowed($relative, self::PCRE_ALLOWED)
            ) {
                $violations[] = self::violation(self::RULE_PCRE, $relative, $line, $call . '()');
            }

            if (self::isCodeLoading($id, $call) && !self::isAllowed($relative, self::CODE_LOADING_ALLOWED)) {
                $violations[] = self::violation(self::RULE_CODE_LOADING, $relative, $line, \trim($text));
            }

            if (self::isRequestState($id, $text, $call) && !self::isAllowed($relative, self::SUPERGLOBAL_ALLOWED)) {
                $violations[] = self::violation(self::RULE_SUPERGLOBAL, $relative, $line, $text);
            }

            if (self::isNetwork($call, $tokens, $i) && !self::isAllowed($relative, self::NETWORK_ALLOWED)) {
                $violations[] = self::violation(self::RULE_NETWORK, $relative, $line, $text);
            }

            if ($id === \T_CATCH) {
                foreach (self::unresolvedCatchTypes($tokens, $i, $imports) as $type) {
                    $violations[] = self::violation(
                        self::RULE_CATCH,
                        $relative,
                        $line,
                        \sprintf('catch (%s) is neither fully qualified nor imported', $type)
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Token ids that carry a (possibly namespaced) name.
     *
     * PHP 8 collapsed `\Foo\Bar` into one token; the constants do not exist on
     * the 7.3 floor, so they are looked up by name rather than referenced.
     *
     * @return list<int>
     */
    private static function nameTokenIds(): array
    {
        $ids = [\T_STRING];

        foreach (['T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'] as $constant) {
            if (\defined($constant)) {
                $value = \constant($constant);

                if (\is_int($value)) {
                    $ids[] = $value;
                }
            }
        }

        return $ids;
    }

    /**
     * The unqualified function name a token denotes, or null when the token is
     * not a call to a global function.
     *
     * `\preg_match` yields `preg_match`; `Foo\bar` yields null, because a
     * namespaced call is not the global function the rules are about.
     *
     * @param int|string $id
     */
    private static function callName($id, string $text): ?string
    {
        if (!\is_int($id) || !\in_array($id, self::nameTokenIds(), true)) {
            return null;
        }

        $name = \ltrim($text, '\\');

        return \strpos($name, '\\') === false ? $name : null;
    }

    /**
     * @param int|string $id
     */
    private static function isCodeLoading($id, ?string $call): bool
    {
        if (
            $id === \T_INCLUDE || $id === \T_INCLUDE_ONCE || $id === \T_REQUIRE
            || $id === \T_REQUIRE_ONCE || $id === \T_EVAL
        ) {
            return true;
        }

        return $call === 'unserialize';
    }

    /**
     * @param int|string $id
     */
    private static function isRequestState($id, string $text, ?string $call): bool
    {
        if ($id === \T_VARIABLE) {
            return \in_array($text, ['$_GET', '$_POST', '$_COOKIE', '$_REQUEST'], true);
        }

        return $call === 'getenv';
    }

    /**
     * `curl_*` anywhere, or an `http(s)` literal handed to a stream opener.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isNetwork(?string $call, array $tokens, int $index): bool
    {
        if ($call === null) {
            return false;
        }

        if (\strncmp($call, 'curl_', 5) === 0) {
            return true;
        }

        if ($call !== 'fopen' && $call !== 'file_get_contents') {
            return false;
        }

        return self::firstStringArgumentIsHttp($tokens, $index);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function firstStringArgumentIsHttp(array $tokens, int $index): bool
    {
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count && $i < $index + 6; ++$i) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if ($token[0] === \T_WHITESPACE) {
                    continue;
                }

                if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
                    $literal = \trim($token[1], "'\"");

                    return \stripos($literal, 'http://') === 0 || \stripos($literal, 'https://') === 0;
                }

                return false;
            }

            if ($token !== '(') {
                return false;
            }
        }

        return false;
    }

    /**
     * Catch types that are neither fully qualified nor imported.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, true> $imports
     *
     * @return list<string>
     */
    private static function unresolvedCatchTypes(array $tokens, int $index, array $imports): array
    {
        $count = \count($tokens);
        $names = self::nameTokenIds();
        $unresolved = [];
        $current = '';
        $started = false;

        for ($i = $index + 1; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if ($token[0] === \T_WHITESPACE || $token[0] === \T_COMMENT || $token[0] === \T_DOC_COMMENT) {
                    continue;
                }

                // A separator on 7.3, part of the name token on 8.x — either
                // way it lands in $current and is judged the same.
                if ($token[0] === \T_NS_SEPARATOR || \in_array($token[0], $names, true)) {
                    $current .= $token[1];

                    continue;
                }

                if ($token[0] === \T_VARIABLE) {
                    // The captured variable ends the type list — flush the type
                    // accumulated so far before stopping, or a single-type
                    // `catch (Exception $e)` would never be judged.
                    if (!self::isResolvable($current, $imports)) {
                        $unresolved[] = $current;
                    }

                    break;
                }

                continue;
            }

            if ($token === '(') {
                $started = true;

                continue;
            }

            if ($token === '|' || $token === ')') {
                if (!self::isResolvable($current, $imports)) {
                    $unresolved[] = $current;
                }

                $current = '';

                if ($token === ')') {
                    break;
                }

                continue;
            }

            if (!$started) {
                break;
            }
        }

        return $unresolved;
    }

    /**
     * A catch type is fine when it is empty (nothing to judge), written with any
     * namespace separator at all — `\Throwable`, `Foo\Bar` — or imported by a
     * `use` statement. What design doc 15 warns about is the bare, unimported
     * name that silently resolves into the current namespace and never matches.
     *
     * @param array<string, true> $imports
     */
    private static function isResolvable(string $type, array $imports): bool
    {
        if ($type === '') {
            return true;
        }

        if (\strpos($type, '\\') !== false) {
            return true;
        }

        return isset($imports[$type]);
    }

    /**
     * Class names imported by a top-level `use` statement.
     *
     * Brace depth distinguishes an import from a trait `use` inside a class
     * body and from a closure's `use (...)`: only depth zero is an import.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array<string, true>
     */
    private static function importsOf(array $tokens): array
    {
        $imports = [];
        $depth = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                if ($token === '{') {
                    ++$depth;
                } elseif ($token === '}') {
                    --$depth;
                }

                continue;
            }

            if ($token[0] !== \T_USE || $depth !== 0) {
                continue;
            }

            $name = '';
            $alias = '';
            $seenAs = false;

            for ($j = $i + 1; $j < $count; ++$j) {
                $inner = $tokens[$j];

                if (\is_array($inner)) {
                    if ($inner[0] === \T_WHITESPACE) {
                        continue;
                    }

                    if ($inner[0] === \T_AS) {
                        $seenAs = true;

                        continue;
                    }

                    if ($inner[0] === \T_NS_SEPARATOR || \in_array($inner[0], self::nameTokenIds(), true)) {
                        if ($seenAs) {
                            $alias .= $inner[1];
                        } else {
                            $name .= $inner[1];
                        }

                        continue;
                    }

                    continue;
                }

                if ($inner === '(') {
                    // A closure's `use (...)`, not an import.
                    $name = '';

                    break;
                }

                if ($inner === ';' || $inner === ',') {
                    break;
                }
            }

            if ($name === '') {
                continue;
            }

            $short = $alias !== '' ? $alias : self::shortName($name);
            $imports[$short] = true;
        }

        return $imports;
    }

    private static function shortName(string $name): string
    {
        $position = \strrpos($name, '\\');

        return $position === false ? $name : \substr($name, $position + 1);
    }

    /**
     * @return list<array{rule: string, file: string, line: int, text: string}>
     */
    private static function manifestViolations(?string $manifestDir): array
    {
        if ($manifestDir === null || !\is_dir($manifestDir)) {
            return [];
        }

        $files = \glob(self::normalize($manifestDir) . '/*.json');

        if ($files === false) {
            return [];
        }

        $violations = [];

        foreach ($files as $file) {
            $contents = \file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $decoded = \json_decode($contents, true);

            if (!\is_array($decoded)) {
                continue;
            }

            foreach (self::absoluteStringsIn($decoded) as $value) {
                $violations[] = self::violation(
                    self::RULE_MANIFEST_PATH,
                    \basename($file),
                    self::lineOf($contents, $value),
                    $value
                );
            }
        }

        return $violations;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function absoluteStringsIn(array $data): array
    {
        $found = [];

        foreach ($data as $value) {
            if (\is_array($value)) {
                foreach (self::absoluteStringsIn($value) as $nested) {
                    $found[] = $nested;
                }

                continue;
            }

            if (\is_string($value) && self::looksAbsolute($value)) {
                $found[] = $value;
            }
        }

        return $found;
    }

    private static function looksAbsolute(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if ($value[0] === '/' || \strncmp($value, '\\\\', 2) === 0 || $value[0] === '\\') {
            return true;
        }

        if (\strlen($value) < 3 || $value[1] !== ':') {
            return false;
        }

        $ordinal = \ord($value[0]);
        $isLetter = ($ordinal >= 65 && $ordinal <= 90) || ($ordinal >= 97 && $ordinal <= 122);

        return $isLetter && ($value[2] === '/' || $value[2] === '\\');
    }

    private static function lineOf(string $haystack, string $needle): int
    {
        $position = \strpos($haystack, $needle);

        if ($position === false) {
            return 1;
        }

        return \substr_count($haystack, "\n", 0, $position) + 1;
    }

    /**
     * @param list<string> $allowed
     */
    private static function isAllowed(string $relative, array $allowed): bool
    {
        foreach ($allowed as $entry) {
            if (\substr($entry, -1) === '/') {
                if (\strncmp($relative, $entry, \strlen($entry)) === 0) {
                    return true;
                }

                continue;
            }

            if ($relative === $entry) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{rule: string, file: string, line: int, text: string}
     */
    private static function violation(string $rule, string $file, int $line, string $text): array
    {
        return [
            'rule' => $rule,
            'file' => $file,
            'line' => $line,
            'text' => $text,
        ];
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $root): array
    {
        if (!\is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            if ($entry->getExtension() !== 'php') {
                continue;
            }

            $files[] = self::normalize($entry->getPathname());
        }

        \sort($files);

        return $files;
    }

    private static function normalize(string $path): string
    {
        return \rtrim(\str_replace('\\', '/', $path), '/');
    }

    private static function relative(string $root, string $file): string
    {
        if (\strncmp($file, $root . '/', \strlen($root) + 1) === 0) {
            return \substr($file, \strlen($root) + 1);
        }

        return $file;
    }
}
