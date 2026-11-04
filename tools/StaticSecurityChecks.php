<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

/**
 * The seven static security rules of design doc 16 §Security suite, in one place.
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
     * Decision ENG-A8 (design doc 23 §M2): the engine is a pure function of its
     * inputs, so it makes no filesystem, clock, random or environment call.
     * That is what lets goldens stay byte-stable and the cache fingerprint
     * stand in for "same inputs".
     */
    public const RULE_ENGINE_PURITY = 'no-filesystem-time-random-in-engine';

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
     * `Compiler/Cli/Environment.php` is the third entry and the narrowest.
     * `Environment::childEnvironment()` calls `getenv()` for exactly two names —
     * `SystemRoot` and `PATHEXT` — and only on Windows. Those are machine
     * variables the operating system sets at boot: no request can set or
     * influence them, which is the line T-09 actually draws (it is about
     * request-derived state, not about the environment as such). `SystemRoot` is
     * not optional either — `cmd.exe` cannot execute a `.cmd` binary without it
     * and exits 1 with empty stdout and stderr, and `.cmd` is a supported binary
     * form (CCP-A22/A23). Neither value is ever interpolated into a command
     * line; both become environment entries for the child and nothing else.
     *
     * @var list<string>
     */
    private const SUPERGLOBAL_ALLOWED = [
        'Console/',
        'Http/ServerRequest.php',
        'Compiler/Cli/Environment.php',
    ];

    /**
     * The single opt-in, CLI-only download path (T-18, T-04).
     *
     * @var list<string>
     */
    private const NETWORK_ALLOWED = ['Console/Command/InstallCliCommand.php'];

    /**
     * Where rule R7 applies, relative to the scanned root.
     */
    private const ENGINE_PREFIX = 'Engine/';

    /**
     * The engine's one sanctioned reader (ENG-A8): resolving an `@import` has
     * to read a file, and it does so through a confined, injectable resolver.
     * Kept as a list so a later task can add an entry — but only with an ADR
     * naming why that file may touch the world.
     *
     * @var list<string>
     */
    private const ENGINE_PURITY_EXEMPT = ['Engine/Import/FilesystemImportResolver.php'];

    /**
     * Global functions that read or write the filesystem, the clock, a random
     * source or the process environment (rule R7).
     *
     * @var list<string>
     */
    private const ENGINE_FORBIDDEN_CALLS = [
        // Filesystem.
        'file_get_contents', 'file_put_contents', 'file_exists', 'file', 'fopen', 'fread', 'fwrite', 'fclose',
        'readfile', 'is_file', 'is_dir', 'is_readable', 'is_writable', 'is_link', 'stat', 'lstat', 'filemtime',
        'filesize', 'realpath', 'mkdir', 'rmdir', 'unlink', 'rename', 'copy', 'tempnam', 'tmpfile', 'opendir',
        'readdir', 'scandir', 'glob', 'chmod', 'touch', 'flock',
        // Clock.
        'time', 'microtime', 'hrtime', 'date', 'gmdate', 'mktime', 'strtotime',
        // Randomness.
        'rand', 'mt_rand', 'random_int', 'random_bytes', 'uniqid', 'lcg_value', 'shuffle', 'array_rand',
        // Process state and blocking.
        'getenv', 'putenv', 'sleep', 'usleep',
    ];

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
            self::RULE_ENGINE_PURITY,
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

            if (
                $call !== null && \in_array($call, self::ENGINE_FORBIDDEN_CALLS, true)
                && self::isEngineFile($relative) && !self::isAllowed($relative, self::ENGINE_PURITY_EXEMPT)
                && self::isGlobalFunctionCall($tokens, $i)
            ) {
                $violations[] = self::violation(self::RULE_ENGINE_PURITY, $relative, $line, $call . '()');
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

    private static function isEngineFile(string $relative): bool
    {
        return \strncmp($relative, self::ENGINE_PREFIX, \strlen(self::ENGINE_PREFIX)) === 0;
    }

    /**
     * Whether the name token at `$index` is a call to a global function.
     *
     * A name is only a call when `(` follows it, and it is only the *global*
     * function when nothing before it makes it a member or a declaration: not
     * `$x->file(`, not `Foo::file(`, not `function file(`, not `new file(`.
     * Rules R1–R6 get by without this because their names (`preg_*`, `curl_*`,
     * `getenv`) are never also method names; R7 lists words like `file`, `copy`
     * and `time` that very plausibly are.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isGlobalFunctionCall(array $tokens, int $index): bool
    {
        if (self::significantToken($tokens, self::significantIndex($tokens, $index, 1)) !== '(') {
            return false;
        }

        $previousIndex = self::significantIndex($tokens, $index, -1);
        $previous = self::significantToken($tokens, $previousIndex);

        // `function &file(` is a by-reference declaration; look past the `&`.
        if ($previous === '&') {
            $previous = self::significantToken($tokens, self::significantIndex($tokens, $previousIndex, -1));
        }

        if (!\is_array($previous)) {
            return true;
        }

        $blocking = [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW];

        // The nullsafe operator does not exist on the 7.3 floor.
        if (\defined('T_NULLSAFE_OBJECT_OPERATOR')) {
            $nullsafe = \constant('T_NULLSAFE_OBJECT_OPERATOR');

            if (\is_int($nullsafe)) {
                $blocking[] = $nullsafe;
            }
        }

        return !\in_array($previous[0], $blocking, true);
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function significantToken(array $tokens, ?int $index)
    {
        return $index === null ? null : $tokens[$index];
    }

    /**
     * Index of the nearest token in `$direction` (1 or -1) that is neither
     * whitespace nor a comment, or null when there is none.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function significantIndex(array $tokens, ?int $index, int $direction): ?int
    {
        if ($index === null) {
            return null;
        }

        $skipped = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];
        $count = \count($tokens);

        for ($i = $index + $direction; $i >= 0 && $i < $count; $i += $direction) {
            $token = $tokens[$i];

            if (!\is_array($token) || !\in_array($token[0], $skipped, true)) {
                return $i;
            }
        }

        return null;
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
