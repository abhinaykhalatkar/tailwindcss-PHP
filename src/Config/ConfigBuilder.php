<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

use Psr\Log\LoggerInterface;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\PreProcessor\PreProcessorInterface;
use TailwindPhp\Support\Hash;
use TailwindPhp\Support\Json;
use TailwindPhp\Support\Path;
use TailwindPhp\Support\Regex;
use TailwindPhp\Support\Str;

/**
 * Turns a user array into a validated {@see Config} (design doc 11
 * §Resolution and validation).
 *
 * Two properties matter more than the individual rules:
 *
 * - **Every problem is collected, then thrown once.** A config with four
 *   mistakes reports four, not the first — a user fixes their file in one round
 *   instead of four (design doc 11).
 * - **No I/O beyond `realpath`** (through {@see Path}), so building a config
 *   costs well under a millisecond and cannot fail because a directory happens
 *   to be unreadable (FR-PIPE-2, NFR-PERF-6).
 *
 * Types are checked strictly: `'12'` is not an acceptable integer. Silent
 * juggling is how a typo in a config file becomes a mystery at runtime.
 */
final class ConfigBuilder
{
    /**
     * Longest a safelist/blocklist entry may be (NFR-SEC-6, decision CSF-A1).
     */
    private const MAX_CANDIDATE_BYTES = 256;

    /**
     * How much of a user value a problem message may quote back.
     */
    private const MAX_ECHOED_BYTES = 64;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $user
     *
     * @throws ConfigurationException With every problem found.
     */
    public static function fromArray(array $user): Config
    {
        $problems = [];

        $defaults = Defaults::table();
        $schema = Schema::table();

        // 1–2. Merge, and report anything that is not a known key.
        $merged = self::merge($defaults, $user, $schema, '', $problems);

        // 3. Types.
        self::checkTypes($merged, $schema, '', $problems);

        // 4. Project root.
        $projectRoot = self::resolveProjectRoot($merged, $problems);

        // 5. Paths — allowed roots first, since everything else confines to them.
        $roots = self::resolveAllowedRoots($projectRoot, $merged, $problems);
        $resolved = self::resolvePaths($projectRoot, $roots, $merged, $problems);

        // 7–9. Enums, extensions, candidate syntax.
        self::checkEnums($merged, $schema, '', $problems);
        self::checkCheckStore($merged, $problems);
        self::checkContents($merged, '', $problems);
        self::checkPreprocessor($merged, '', $problems);
        $merged['input_extensions'] = self::normaliseExtensions(
            $merged['input_extensions'],
            'input_extensions',
            $problems
        );
        $merged['source_extensions'] = self::normaliseExtensions(
            $merged['source_extensions'],
            'source_extensions',
            $problems
        );
        self::checkCandidateList($merged['safelist'], 'safelist', $problems);
        self::checkCandidateList($merged['blocklist'], 'blocklist', $problems);

        // 6 + 10. Bundles carry the route rules and the derived `minify`.
        $bundles = self::buildBundles($projectRoot, $roots, $merged, $resolved, $problems);

        if ($problems !== []) {
            throw new ConfigurationException($problems);
        }

        $values = self::assemble($projectRoot, $roots, $merged, $resolved);
        $hash = self::computeHash($merged, $values, $bundles);

        return new Config($values, $bundles, $hash);
    }

    // ------------------------------------------------------------ 1–2 merge

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $user
     * @param array<string, array<string, mixed>> $schema
     * @param list<string> $problems
     *
     * @return array<string, mixed>
     */
    private static function merge(array $defaults, array $user, array $schema, string $prefix, array &$problems): array
    {
        $merged = $defaults;

        foreach ($user as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (!\array_key_exists($key, $defaults)) {
                $problems[] = self::unknownKeyProblem((string) $key, $path, \array_keys($defaults));

                continue;
            }

            $spec = $schema[$key] ?? null;

            // Nested maps merge recursively; lists replace wholesale so a user
            // can shorten `sources` without fighting the defaults.
            if (
                $spec !== null
                && $spec['type'] === 'map'
                && $spec['nested'] !== []
                && \is_array($value)
                && \is_array($defaults[$key])
            ) {
                /** @var array<string, mixed> $childDefaults */
                $childDefaults = $defaults[$key];
                /** @var array<string, mixed> $childUser */
                $childUser = $value;
                /** @var array<string, array<string, mixed>> $childSchema */
                $childSchema = $spec['nested'];

                $merged[$key] = self::merge($childDefaults, $childUser, $childSchema, $path, $problems);

                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    /**
     * @param list<string> $known
     */
    private static function unknownKeyProblem(string $key, string $path, array $known): string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        foreach ($known as $candidate) {
            $distance = \levenshtein($key, (string) $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $candidate;
            }
        }

        // Only suggest something close enough to plausibly be the typo.
        if ($best !== null && $bestDistance <= 3) {
            return \sprintf('Unknown key "%s"; did you mean "%s"?', self::safe($path), $best);
        }

        return \sprintf('Unknown key "%s".', self::safe($path));
    }

    // ------------------------------------------------------------ 3 types

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $schema
     * @param list<string> $problems
     */
    private static function checkTypes(array $values, array $schema, string $prefix, array &$problems): void
    {
        foreach ($schema as $key => $spec) {
            if (!\array_key_exists($key, $values)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $value = $values[$key];

            if ($value === null) {
                if ($spec['nullable'] !== true) {
                    $problems[] = \sprintf('Key "%s" may not be null.', $path);
                }

                continue;
            }

            switch ($spec['type']) {
                case 'bool':
                    if (!\is_bool($value)) {
                        $problems[] = self::typeProblem($path, 'a boolean', $value);
                    }

                    break;
                case 'int':
                    if (!\is_int($value)) {
                        // Deliberately strict: '12' is a string, and accepting it
                        // hides a quoting mistake until something else breaks.
                        $problems[] = self::typeProblem($path, 'an integer', $value);

                        break;
                    }

                    if (\is_array($spec['range'])) {
                        [$min, $max] = $spec['range'];

                        if ($value < $min || $value > $max) {
                            // File modes are written as octal in every config
                            // file and every chmod call, so bounds of "0 and
                            // 511" are numbers the user cannot match against
                            // the 0755 they typed. The *received* value stays
                            // decimal: printing it as octal would misrepresent
                            // a plain `755` typo as something it is not.
                            $problems[] = \substr((string) $key, -5) === '_mode'
                                ? \sprintf(
                                    'Key "%s" must be between %04o and %04o in octal, got %d.',
                                    $path,
                                    $min,
                                    $max,
                                    $value
                                )
                                : \sprintf(
                                    'Key "%s" must be between %d and %d, got %d.',
                                    $path,
                                    $min,
                                    $max,
                                    $value
                                );
                        }
                    }

                    break;
                case 'string':
                    if (!\is_string($value)) {
                        $problems[] = self::typeProblem($path, 'a string', $value);
                    }

                    break;
                case 'list':
                    if (!\is_array($value) || !self::isList($value)) {
                        $problems[] = self::typeProblem($path, 'a list', $value);
                    }

                    break;
                case 'map':
                    if (!\is_array($value)) {
                        $problems[] = self::typeProblem($path, 'a map', $value);

                        break;
                    }

                    if ($spec['nested'] !== []) {
                        /** @var array<string, mixed> $child */
                        $child = $value;
                        /** @var array<string, array<string, mixed>> $childSchema */
                        $childSchema = $spec['nested'];

                        self::checkTypes($child, $childSchema, $path, $problems);
                    }

                    break;
                default:
                    break;
            }
        }
    }

    /**
     * @param mixed $value
     */
    private static function typeProblem(string $path, string $expected, $value): string
    {
        // `get_debug_type` yields a type name, never the value, so nothing
        // user-controlled reaches the message here.
        return \sprintf('Key "%s" must be %s, got %s.', self::safe($path), $expected, \get_debug_type($value));
    }

    /**
     * Make a user-supplied string safe to echo into a problem message.
     *
     * These messages end up in logs. A config value containing a newline can
     * therefore forge a log entry — `driver => "cli\n2026-01-01 FAKE LOG LINE"`
     * writes a second line that looks like ours (threat T-06, design doc 15).
     * Control bytes become `\xNN` escapes, and the value is capped so a
     * megabyte-long key cannot flood the log either.
     *
     * **Public because it is the single home of this rule**, shared with
     * {@see Config::bundle()} and {@see Driver::assert()}. A pure string
     * function with no state: nothing of the builder comes with it, and
     * `Driver::assert()` has no caller inside `src/`, so no request path pays
     * to autoload this class for it.
     *
     * @internal Not part of the published configuration API.
     */
    public static function safe(string $value): string
    {
        // 0x00–0x1F and 0x7F (DEL): everything that can move the cursor, end a
        // line, or vanish from a log viewer.
        return \addcslashes(Str::truncateBytes($value, self::MAX_ECHOED_BYTES), "\0..\37\177");
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isList(array $value): bool
    {
        $expected = 0;

        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }

            ++$expected;
        }

        return true;
    }

    // ------------------------------------------------------------- 4 root

    /**
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     */
    private static function resolveProjectRoot(array $merged, array &$problems): string
    {
        $configured = $merged['project_root'];

        if ($configured === null) {
            return ProjectRoot::detect();
        }

        if (!\is_string($configured)) {
            return ProjectRoot::detect();
        }

        // Gated before `realpath()`, which raises a ValueError on a NUL byte in
        // PHP 8 — an uncaught TypeError-family error rather than the
        // ConfigurationException the caller is told to expect. Every other raw
        // path in this class goes through Path for the same reason (T-01).
        if (Path::hasNul($configured) || Path::isWrapper($configured) || $configured === '') {
            $problems[] = 'Key "project_root" must be an absolute path to an existing directory.';

            return ProjectRoot::detect();
        }

        // A relative root resolves against the *current working directory*,
        // which differs between php-fpm, the CLI and a cron job — so every
        // confinement boundary in the library would depend on which SAPI
        // happened to run (doc 11: project_root must be absolute).
        if (!self::looksAbsolute($configured)) {
            $problems[] = 'Key "project_root" must be an absolute path.';

            return ProjectRoot::detect();
        }

        $real = \realpath($configured);

        if ($real === false) {
            $problems[] = 'Key "project_root" must be an existing directory.';

            return ProjectRoot::detect();
        }

        // A file resolves perfectly well; it just cannot be a project root, and
        // saying so here beats the confusing downstream "input does not exist".
        if (!\is_dir($real)) {
            $problems[] = 'Key "project_root" must be a directory, not a file.';

            return ProjectRoot::detect();
        }

        return Path::normalize($real);
    }

    // ------------------------------------------------------------ 5 paths

    /**
     * Each allowed root is its own confinement root (decision CSF-A2): it may
     * legitimately lie outside the project, so confining it to the project first
     * would make it impossible to configure one.
     *
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     *
     * @return list<string>
     */
    private static function resolveAllowedRoots(string $projectRoot, array $merged, array &$problems): array
    {
        $roots = [$projectRoot];

        if (!\is_array($merged['allowed_roots'])) {
            return $roots;
        }

        foreach ($merged['allowed_roots'] as $index => $entry) {
            if (!\is_string($entry)) {
                $problems[] = \sprintf('Key "allowed_roots.%s" must be a string.', (string) $index);

                continue;
            }

            $lexical = Path::normalize(
                self::looksAbsolute($entry) ? $entry : $projectRoot . '/' . $entry
            );

            try {
                $resolved = Path::resolve($projectRoot, $entry, [$lexical], true);

                // A root is a confinement boundary, and `Path::confine()` treats
                // its root as a directory prefix. A file here would silently
                // widen confinement to its whole parent directory (T-01).
                if (!\is_dir($resolved)) {
                    $problems[] = \sprintf(
                        'Key "allowed_roots.%s" must be a directory, not a file.',
                        (string) $index
                    );

                    continue;
                }

                $roots[] = $resolved;
            } catch (PathException $e) {
                foreach ($e->problems() as $problem) {
                    $problems[] = \sprintf('Key "allowed_roots.%s": %s', (string) $index, $problem);
                }
            }
        }

        return $roots;
    }

    private static function looksAbsolute(string $path): bool
    {
        $normalized = Path::normalize($path);

        if ($normalized === '') {
            return false;
        }

        if ($normalized[0] === '/') {
            return true;
        }

        return \strlen($normalized) >= 3 && $normalized[1] === ':' && $normalized[2] === '/';
    }

    /**
     * @param list<string> $roots
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     *
     * @return array<string, string|null>
     */
    private static function resolvePaths(string $projectRoot, array $roots, array $merged, array &$problems): array
    {
        $resolved = [];

        // `cache_dir` and `cli.binary` are the only paths allowed not to exist
        // yet (design doc 11 §5): one is created on first write, the other is
        // optional until `install-cli` runs.
        $resolved['cache_dir'] = self::resolveOne(
            $projectRoot,
            $roots,
            $merged['cache_dir'],
            'cache_dir',
            false,
            $problems
        );

        $binary = $merged['cli']['binary'] ?? null;
        $resolved['cli.binary'] = $binary === null
            ? null
            : self::resolveOne($projectRoot, $roots, $binary, 'cli.binary', false, $problems);

        if (
            $resolved['cache_dir'] !== null
            && Path::isSymlinkEscape($resolved['cache_dir'], $roots)
        ) {
            // T-14: a cache directory that resolves outside the roots would let
            // every artefact we write land somewhere we do not control.
            $problems[] = 'Key "cache_dir" resolves outside the allowed roots.';
        }

        return $resolved;
    }

    /**
     * @param list<string> $roots
     * @param mixed $value
     * @param list<string> $problems
     */
    private static function resolveOne(
        string $projectRoot,
        array $roots,
        $value,
        string $path,
        bool $mustExist,
        array &$problems
    ): ?string {
        if (!\is_string($value)) {
            return null;
        }

        try {
            return Path::resolve($projectRoot, $value, $roots, $mustExist);
        } catch (PathException $e) {
            foreach ($e->problems() as $problem) {
                $problems[] = \sprintf('Key "%s": %s', $path, $problem);
            }

            return null;
        }
    }

    // ------------------------------------------------------------- 7 enums

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $schema
     * @param list<string> $problems
     */
    private static function checkEnums(array $values, array $schema, string $prefix, array &$problems): void
    {
        foreach ($schema as $key => $spec) {
            if (!\array_key_exists($key, $values)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $value = $values[$key];

            if (
                \is_array($spec['enum']) && \is_string($value)
                && !\in_array($value, $spec['enum'], true)
            ) {
                $problems[] = \sprintf(
                    'Key "%s" must be one of: %s; got "%s".',
                    $path,
                    \implode(', ', $spec['enum']),
                    self::safe($value)
                );
            }

            if ($spec['type'] === 'map' && $spec['nested'] !== [] && \is_array($value)) {
                /** @var array<string, mixed> $child */
                $child = $value;
                /** @var array<string, array<string, mixed>> $childSchema */
                $childSchema = $spec['nested'];

                self::checkEnums($child, $childSchema, $path, $problems);
            }
        }
    }

    /**
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     */
    private static function checkCheckStore(array $merged, array &$problems): void
    {
        // The enum is validated in M1 so a config written today stays valid, but
        // only the file store is implemented (decision CSF-A14).
        if ($merged['check_store'] === 'apcu') {
            $problems[] = 'Key "check_store": \'apcu\' arrives in M3; use \'file\'.';
        }
    }

    /**
     * Contents of the list- and object-valued keys that `checkTypes` only checks
     * the outer shape of.
     *
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     */
    private static function checkContents(array $merged, string $prefix, array &$problems): void
    {
        // Called for the global map and for each bundle's *touched* keys, so
        // every member is optional here: a bundle may set `ignore` alone.
        if (isset($merged['ignore']) && \is_array($merged['ignore'])) {
            foreach ($merged['ignore'] as $index => $entry) {
                if (!\is_string($entry)) {
                    // `BundleConfig::ignore()` is documented `list<string>` and
                    // `Scanner\IgnoreFilter` passes it straight to `strpos()`,
                    // so a stray int here is a TypeError at scan time.
                    $problems[] = self::typeProblem(
                        self::join($prefix, 'ignore.' . (string) $index),
                        'a string',
                        $entry
                    );
                }
            }
        }

        // CSF-A9: the prepass is a fixed set of language-specific fast paths,
        // so an unrecognised name is a typo that would silently do nothing.
        if (isset($merged['scanner']['prepass']) && \is_array($merged['scanner']['prepass'])) {
            foreach ($merged['scanner']['prepass'] as $index => $entry) {
                if (\in_array($entry, ['twig', 'php'], true)) {
                    continue;
                }

                $problems[] = \sprintf(
                    'Key "%s" must be one of: twig, php.',
                    self::join($prefix, 'scanner.prepass.' . (string) $index)
                );
            }
        }

        // A wrong logger only shows up when something first tries to log, which
        // is usually while reporting a different error.
        if (
            \array_key_exists('logger', $merged)
            && $merged['logger'] !== null
            && !$merged['logger'] instanceof LoggerInterface
        ) {
            $problems[] = \sprintf(
                'Key "%s" must be null or a Psr\\Log\\LoggerInterface.',
                self::join($prefix, 'logger')
            );
        }
    }

    /**
     * @param array<string, mixed> $merged
     * @param list<string> $problems
     */
    private static function checkPreprocessor(array $merged, string $prefix, array &$problems): void
    {
        $value = $merged['preprocessor'];
        $path = $prefix === '' ? 'preprocessor' : $prefix . '.preprocessor';

        // A non-string that is not null is a type error, and checkTypes has
        // already said so — repeating it here would double every report.
        if ($value === null || !\is_string($value)) {
            return;
        }

        if ($value === 'scss') {
            if (!\class_exists('ScssPhp\\ScssPhp\\Compiler')) {
                $problems[] = \sprintf('Key "%s": \'scss\' requires the scssphp/scssphp package.', $path);
            }

            return;
        }

        if (!\class_exists($value)) {
            $problems[] = \sprintf('Key "%s": class "%s" does not exist.', $path, self::safe($value));

            return;
        }

        // The deferred note from TASK-0008 round 1, closed here (TASK-0015
        // acceptance 5). A class that exists but does not implement the
        // interface used to pass configuration and fail later inside the
        // compile transaction as a TypeError — an infrastructure error for what
        // is a plain configuration mistake. The message names the interface,
        // because "must implement the interface" without saying which one sends
        // the reader to the source.
        //
        // `is_subclass_of()` with an interface name is the right test rather
        // than `in_array(..., class_implements())`: it also accepts a class that
        // inherits the implementation from a parent.
        if (!\is_subclass_of($value, PreProcessorInterface::class)) {
            $problems[] = \sprintf(
                'Key "%s": class "%s" must implement %s.',
                $path,
                self::safe($value),
                PreProcessorInterface::class
            );
        }
    }

    // -------------------------------------------------------- 8 extensions

    /**
     * @param mixed $value
     * @param list<string> $problems
     *
     * @return list<string>
     */
    private static function normaliseExtensions($value, string $path, array &$problems): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $seen = [];

        foreach ($value as $index => $entry) {
            if (!\is_string($entry)) {
                $problems[] = \sprintf('Key "%s.%s" must be a string.', $path, (string) $index);

                continue;
            }

            $normalised = \strtolower($entry);

            if ($normalised !== '' && $normalised[0] === '.') {
                $problems[] = \sprintf(
                    'Key "%s": extension "%s" must not start with a dot.',
                    $path,
                    self::safe($entry)
                );

                continue;
            }

            if (!Regex::match('#^[a-z0-9.]+$#D', $normalised)) {
                $problems[] = \sprintf('Key "%s": extension "%s" is not valid.', $path, self::safe($entry));

                continue;
            }

            $seen[$normalised] = true;
        }

        return \array_keys($seen);
    }

    // ------------------------------------------------- 9 candidate syntax

    /**
     * Minimal syntactic check for `safelist`/`blocklist` (decision CSF-A1).
     *
     * `Config` may never depend on `Engine`, so this is not the real candidate
     * parser — it is the NFR-SEC-6 floor: bounded length, no whitespace, and
     * nothing that could break out of a CSS rule.
     *
     * @param mixed $value
     * @param list<string> $problems
     */
    private static function checkCandidateList($value, string $path, array &$problems): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $index => $entry) {
            $label = \sprintf('%s.%s', $path, (string) $index);

            if (!\is_string($entry)) {
                $problems[] = \sprintf('Key "%s" must be a string.', $label);

                continue;
            }

            $problem = self::candidateProblem($entry);

            if ($problem !== null) {
                $problems[] = \sprintf('Key "%s": %s', $label, $problem);
            }
        }
    }

    private static function candidateProblem(string $candidate): ?string
    {
        if ($candidate === '') {
            return 'an entry may not be empty.';
        }

        if (\strlen($candidate) > self::MAX_CANDIDATE_BYTES) {
            return \sprintf('an entry may not exceed %d bytes.', self::MAX_CANDIDATE_BYTES);
        }

        // Every byte below 0x21, plus 0x7F. Hand-listing the forbidden bytes is
        // what let this drift twice: the original list missed \f, and the first
        // fix for that missed DEL. A control character in a candidate is never
        // meaningful — it can only be a paste artefact or an injection attempt.
        for ($i = 0, $length = \strlen($candidate); $i < $length; ++$i) {
            if ($candidate[$i] < "\x21" || $candidate[$i] === "\x7F") {
                return 'an entry may not contain whitespace or control characters.';
            }
        }

        foreach ([';', '{', '}'] as $forbidden) {
            if (\strpos($candidate, $forbidden) !== false) {
                return \sprintf('an entry may not contain "%s".', $forbidden);
            }
        }

        if (\strpos($candidate, '/*') !== false) {
            // Described rather than quoted: the log-safety scrubber redacts any
            // token starting with "/" as a path, which would turn a quoted "/*"
            // into "<path>" and make the message useless.
            return 'an entry may not contain a CSS comment sequence.';
        }

        if (!self::bracketsBalanced($candidate)) {
            return 'brackets are unbalanced.';
        }

        return null;
    }

    private static function bracketsBalanced(string $candidate): bool
    {
        $square = 0;
        $round = 0;
        $length = \strlen($candidate);

        for ($i = 0; $i < $length; ++$i) {
            switch ($candidate[$i]) {
                case '[':
                    ++$square;

                    break;
                case ']':
                    --$square;

                    break;
                case '(':
                    ++$round;

                    break;
                case ')':
                    --$round;

                    break;
                default:
                    break;
            }

            if ($square < 0 || $round < 0) {
                return false;
            }
        }

        return $square === 0 && $round === 0;
    }

    // ----------------------------------------------------------- 6 bundles

    /**
     * @param list<string> $roots
     * @param array<string, mixed> $merged
     * @param array<string, string|null> $resolved
     * @param list<string> $problems
     *
     * @return array<string, BundleConfig>
     */
    private static function buildBundles(
        string $projectRoot,
        array $roots,
        array $merged,
        array $resolved,
        array &$problems
    ): array {
        $declared = \is_array($merged['bundles']) ? $merged['bundles'] : [];
        $bundles = [];

        if ($declared === []) {
            $name = self::routeBundleName($merged['public_route'], 'public_route', $problems);

            $bundles[$name === null ? 'styles' : $name] = self::buildBundle(
                $name === null ? 'styles' : $name,
                $projectRoot,
                $roots,
                $merged,
                $merged,
                $resolved,
                // No label: the implicit bundle's keys *are* the top-level keys,
                // so a problem must read `Key "sources.0"` rather than
                // `Key "public_route.sources.0"` — a key the user never wrote.
                '',
                $problems
            );

            return $bundles;
        }

        $bundleKeys = Schema::bundleKeys();

        foreach ($declared as $rawName => $overrides) {
            $name = (string) $rawName;

            if (!Regex::match('#^[a-z0-9_-]{1,40}$#D', $name)) {
                $problems[] = \sprintf(
                    'Bundle name "%s" must match ^[a-z0-9_-]{1,40}$.',
                    self::safe($name)
                );

                continue;
            }

            if (!\is_array($overrides)) {
                $problems[] = \sprintf('Bundle "%s" must be a map of overrides.', self::safe($name));

                continue;
            }

            $effective = self::applyBundleOverrides($name, $merged, $overrides, $bundleKeys, $problems);

            self::routeBundleName($effective['public_route'], \sprintf('bundles.%s.public_route', $name), $problems);

            $bundles[$name] = self::buildBundle(
                $name,
                $projectRoot,
                $roots,
                $merged,
                $effective,
                $resolved,
                \sprintf('bundles.%s', $name),
                $problems
            );
        }

        self::checkRoutesAreUnique($bundles, $problems);

        return $bundles;
    }

    /**
     * Two bundles may not answer on the same route.
     *
     * The route decides which bundle a request gets, so a duplicate means one
     * bundle is simply unreachable — and because the artefact name is derived
     * from the route's basename, two routes ending in the same file name also
     * collide in the cache directory even when their directories differ.
     *
     * @param array<string, BundleConfig> $bundles
     * @param list<string> $problems
     */
    private static function checkRoutesAreUnique(array $bundles, array &$problems): void
    {
        $seenRoute = [];
        $seenBasename = [];

        foreach ($bundles as $name => $bundle) {
            $route = $bundle->publicRoute();
            $basename = Str::afterLast($route, '/');

            if (isset($seenRoute[$route])) {
                $problems[] = \sprintf(
                    'Bundles "%s" and "%s" share the public_route "%s"; routes must be unique.',
                    self::safe($seenRoute[$route]),
                    self::safe((string) $name),
                    self::safe($route)
                );

                continue;
            }

            if (isset($seenBasename[$basename])) {
                $problems[] = \sprintf(
                    'Bundles "%s" and "%s" have different public_route values ending in "%s", '
                    . 'which would collide in the cache directory.',
                    self::safe($seenBasename[$basename]),
                    self::safe((string) $name),
                    self::safe($basename)
                );

                continue;
            }

            $seenRoute[$route] = (string) $name;
            $seenBasename[$basename] = (string) $name;
        }
    }

    /**
     * Apply one bundle's overrides to the global map and validate the result.
     *
     * A bundle override used to be copied straight into the effective map, which
     * meant it skipped every check the global map had already been through: a
     * bundle could carry `safelist => ['a b; } evil {']` and defeat the CSF-A1 /
     * NFR-SEC-6 candidate-syntax floor, or `engine => 'x'` and fail much later as
     * a `TypeError`. The overrides are user input exactly like the top-level map,
     * so they go through the same pipeline with `bundles.<name>.` labels.
     *
     * `merge()` does the structural half — unknown keys, nested maps merged
     * member by member rather than replaced — and the checks below do the rest.
     *
     * @param array<string, mixed> $merged Global effective map.
     * @param array<string, mixed> $overrides
     * @param list<string> $bundleKeys
     * @param list<string> $problems
     *
     * @return array<string, mixed>
     */
    private static function applyBundleOverrides(
        string $name,
        array $merged,
        array $overrides,
        array $bundleKeys,
        array &$problems
    ): array {
        $prefix = \sprintf('bundles.%s', $name);
        $defaults = Defaults::table();
        $settable = [];

        foreach ($overrides as $key => $value) {
            if (\in_array((string) $key, $bundleKeys, true)) {
                $settable[(string) $key] = $value;

                continue;
            }

            // Two different mistakes, two different messages. A key that exists
            // globally is a scoping error, and saying "cannot be set per
            // bundle" is the useful answer (CSF-A20). A key that exists nowhere
            // is a typo, and deserves the levenshtein hint instead of being
            // told it is merely misplaced.
            if (\array_key_exists($key, $defaults)) {
                $problems[] = \sprintf(
                    'Bundle "%s": key "%s" cannot be set per bundle.',
                    self::safe($name),
                    self::safe((string) $key)
                );

                continue;
            }

            $problems[] = self::unknownKeyProblem(
                (string) $key,
                $prefix . '.' . (string) $key,
                $bundleKeys
            );
        }

        $bundleSchema = \array_intersect_key(Schema::table(), \array_flip($bundleKeys));
        $bundleDefaults = \array_intersect_key($merged, \array_flip($bundleKeys));

        $overridden = self::merge($bundleDefaults, $settable, $bundleSchema, $prefix, $problems);

        // Validate only what this bundle actually set. Everything else was
        // inherited from the global map, which fromArray() has already checked
        // — re-checking it here reported one mistake once per bundle, so a
        // single bad `minify` produced three identical problems.
        //
        // The narrowing has to reach *inside* a nested map too: a bundle that
        // overrides `engine.prefix` inherits the rest of `engine`, and a plain
        // key-level intersect handed the whole merged map to checkTypes, so a
        // bad global `engine.important` came back once per bundle as well.
        $touched = self::narrowToTouched(
            \array_intersect_key($overridden, $settable),
            $settable,
            $bundleSchema
        );

        self::checkTypes($touched, $bundleSchema, $prefix, $problems);
        self::checkEnums($touched, $bundleSchema, $prefix, $problems);
        self::checkContents($touched, $prefix, $problems);

        if (\array_key_exists('preprocessor', $settable)) {
            self::checkPreprocessor($overridden, $prefix, $problems);
        }

        foreach (['input_extensions', 'source_extensions'] as $key) {
            if (\array_key_exists($key, $settable)) {
                $overridden[$key] = self::normaliseExtensions(
                    $overridden[$key],
                    $prefix . '.' . $key,
                    $problems
                );
            }
        }

        foreach (['safelist', 'blocklist'] as $key) {
            if (\array_key_exists($key, $settable)) {
                self::checkCandidateList($overridden[$key], $prefix . '.' . $key, $problems);
            }
        }

        return \array_replace($merged, $overridden);
    }

    /**
     * Restrict an effective bundle map to the members the bundle itself set,
     * recursing into nested maps.
     *
     * Only nested *maps* are narrowed. A list (`sources`, `safelist`) is
     * replaced wholesale by an override rather than merged, so if the bundle set
     * it at all, every element of it is the bundle's own.
     *
     * @param array<string, mixed> $effective Merged values, already key-intersected.
     * @param array<string, mixed> $settable The bundle's raw overrides.
     * @param array<string, array<string, mixed>> $schema
     *
     * @return array<string, mixed>
     */
    private static function narrowToTouched(array $effective, array $settable, array $schema): array
    {
        foreach ($effective as $key => $value) {
            $spec = $schema[$key] ?? null;

            if ($spec === null || $spec['type'] !== 'map' || $spec['nested'] === []) {
                continue;
            }

            if (!\is_array($value) || !isset($settable[$key]) || !\is_array($settable[$key])) {
                continue;
            }

            /** @var array<string, mixed> $child */
            $child = $value;
            /** @var array<string, mixed> $childSettable */
            $childSettable = $settable[$key];
            /** @var array<string, array<string, mixed>> $childSchema */
            $childSchema = $spec['nested'];

            $effective[$key] = self::narrowToTouched(
                \array_intersect_key($child, $childSettable),
                $childSettable,
                $childSchema
            );
        }

        return $effective;
    }

    /**
     * @param mixed $route
     * @param list<string> $problems
     */
    private static function routeBundleName($route, string $path, array &$problems): ?string
    {
        if (!\is_string($route)) {
            return null;
        }

        if (\strpos($route, '..') !== false || \strpos($route, '//') !== false) {
            // Described rather than quoted: LogSafety scrubs anything that looks
            // like a path out of a message, and a literal "//" in the text comes
            // out the other side as "<path>" — see the memory note
            // `log-safety-scrubber-mangles-quoted-punctuation`.
            $problems[] = \sprintf(
                'Key "%s" may not contain a parent-directory segment or a repeated slash.',
                $path
            );

            return null;
        }

        if (!Regex::match('#^[A-Za-z0-9_./-]+\.css$#D', $route)) {
            $problems[] = \sprintf('Key "%s" must be a .css path of [A-Za-z0-9_./-].', $path);

            return null;
        }

        $name = Str::afterLast(Str::removeSuffix($route, '.css'), '/');

        if (!Regex::match('#^[a-z0-9_-]{1,40}$#D', $name)) {
            $problems[] = \sprintf(
                'Key "%s": bundle name "%s" must match ^[a-z0-9_-]{1,40}$.',
                $path,
                self::safe($name)
            );

            return null;
        }

        return $name;
    }

    /**
     * @param list<string> $roots
     * @param array<string, mixed> $global
     * @param array<string, mixed> $effective
     * @param array<string, string|null> $resolved
     * @param list<string> $problems
     */
    private static function buildBundle(
        string $name,
        string $projectRoot,
        array $roots,
        array $global,
        array $effective,
        array $resolved,
        string $label,
        array &$problems
    ): BundleConfig {
        $inputLabel = self::join($label, 'input');

        $inputAbs = self::resolveOne(
            $projectRoot,
            $roots,
            $effective['input'],
            $inputLabel,
            true,
            $problems
        );

        // The entry stylesheet is read, so a directory here is a mistake. It
        // used to fail only incidentally, through the cache_dir placement rule,
        // with a message about a completely different key.
        if ($inputAbs !== null && !\is_file($inputAbs)) {
            $problems[] = \sprintf('Key "%s" must point to an existing file.', $inputLabel);

            $inputAbs = null;
        }

        $inputExtensions = self::normaliseExtensions(
            $effective['input_extensions'],
            self::join($label, 'input_extensions'),
            $problems
        );
        $sourceExtensions = self::normaliseExtensions(
            $effective['source_extensions'],
            self::join($label, 'source_extensions'),
            $problems
        );

        $inputRoots = [];

        if ($inputAbs !== null) {
            $inputRoots[] = self::rootEntry($projectRoot, \dirname($inputAbs), $inputExtensions);
        }

        if (\is_array($effective['watch'])) {
            foreach ($effective['watch'] as $index => $entry) {
                $watchLabel = self::join($label, 'watch.' . (string) $index);

                // Reported rather than skipped: a typo in a watch list would
                // otherwise silently stop watching that directory, and the
                // first sign of it is a stylesheet that never rebuilds
                // (design doc 11 — typos must fail loudly).
                if (!\is_string($entry)) {
                    $problems[] = self::typeProblem($watchLabel, 'a string', $entry);

                    continue;
                }

                $abs = self::resolveOne($projectRoot, $roots, $entry, $watchLabel, true, $problems);

                if ($abs !== null) {
                    $inputRoots[] = self::rootEntry($projectRoot, $abs, $inputExtensions);
                }
            }
        }

        $sourceRoots = [];

        if (\is_array($effective['sources'])) {
            foreach ($effective['sources'] as $index => $entry) {
                $sourceLabel = self::join($label, 'sources.' . (string) $index);
                $extensions = $sourceExtensions;
                $sourcePath = null;

                if (\is_string($entry)) {
                    $sourcePath = $entry;
                } elseif (\is_array($entry)) {
                    $sourcePath = self::sourceMapPath($entry, $sourceLabel, $problems);

                    if (\array_key_exists('extensions', $entry)) {
                        // checkTypes covers the top-level extension lists, but
                        // nothing sees this nested one — and normaliseExtensions
                        // turns a non-list into [] without complaining, which
                        // would silently scan nothing.
                        if (!\is_array($entry['extensions'])) {
                            $problems[] = self::typeProblem(
                                $sourceLabel . '.extensions',
                                'a list',
                                $entry['extensions']
                            );
                        } else {
                            $extensions = self::normaliseExtensions(
                                $entry['extensions'],
                                $sourceLabel . '.extensions',
                                $problems
                            );
                        }
                    }
                } else {
                    // A silently dropped source root means a whole directory
                    // stops being scanned, and nothing says so until a class
                    // goes missing from the stylesheet (design doc 11).
                    $problems[] = \sprintf(
                        'Key "%s" must be a string or a {path, extensions} map, %s given.',
                        $sourceLabel,
                        \get_debug_type($entry)
                    );
                }

                if ($sourcePath === null) {
                    continue;
                }

                $abs = self::resolveOne($projectRoot, $roots, $sourcePath, $sourceLabel, true, $problems);

                if ($abs !== null) {
                    $sourceRoots[] = self::rootEntry($projectRoot, $abs, $extensions);
                }
            }
        }

        // The cache must not sit inside anything we scan, or writing an artefact
        // would itself look like a source change and rebuild forever.
        self::checkCacheDirPlacement($resolved['cache_dir'], $inputRoots, $sourceRoots, $problems);

        $minify = $effective['minify'];

        if ($minify === null) {
            $minify = !((bool) $global['debug']);
        }

        return new BundleConfig([
            'name' => $name,
            'input' => $inputAbs ?? '',
            'input_rel' => $inputAbs === null ? '' : self::relative($projectRoot, $inputAbs),
            'public_route' => (string) $effective['public_route'],
            'input_roots' => $inputRoots,
            'source_roots' => $sourceRoots,
            'input_extensions' => $inputExtensions,
            'source_extensions' => $sourceExtensions,
            'ignore' => \is_array($effective['ignore']) ? \array_values($effective['ignore']) : [],
            'safelist' => \is_array($effective['safelist']) ? \array_values($effective['safelist']) : [],
            'blocklist' => \is_array($effective['blocklist']) ? \array_values($effective['blocklist']) : [],
            'minify' => (bool) $minify,
            'engine' => $effective['engine'],
            'preprocessor' => $effective['preprocessor'],
            'preprocessor_options' => \is_array($effective['preprocessor_options'])
                ? $effective['preprocessor_options']
                : [],
            'hash_input' => self::bundleHashInput(
                $inputAbs === null ? '' : self::relative($projectRoot, $inputAbs),
                $effective,
                $inputRoots,
                $sourceRoots,
                $minify
            ),
        ]);
    }

    /**
     * Validate the `{path, extensions}` form of a `sources` entry.
     *
     * @param array<array-key, mixed> $entry
     * @param list<string> $problems
     *
     * @return string|null The path, or null when the entry was rejected.
     */
    private static function sourceMapPath(array $entry, string $label, array &$problems): ?string
    {
        $known = ['path', 'extensions'];

        foreach (\array_keys($entry) as $key) {
            if (!\in_array((string) $key, $known, true)) {
                $problems[] = self::unknownKeyProblem((string) $key, $label . '.' . (string) $key, $known);
            }
        }

        if (!\array_key_exists('path', $entry)) {
            $problems[] = \sprintf('Key "%s" is missing the required "path" member.', $label);

            return null;
        }

        if (!\is_string($entry['path'])) {
            $problems[] = self::typeProblem($label . '.path', 'a string', $entry['path']);

            return null;
        }

        return $entry['path'];
    }

    /**
     * Join a bundle label onto a key path.
     *
     * The default bundle has no label of its own — its keys *are* the top-level
     * keys — so problems must read `Key "sources.0"`, not
     * `Key "public_route.sources.0"`, which named a key the user never wrote.
     */
    private static function join(string $label, string $key): string
    {
        return $label === '' ? $key : $label . '.' . $key;
    }

    /**
     * @param list<string> $extensions
     *
     * @return array{abs: string, rel: string, extensions: list<string>}
     */
    private static function rootEntry(string $projectRoot, string $abs, array $extensions): array
    {
        return [
            'abs' => $abs,
            'rel' => self::relative($projectRoot, $abs),
            'extensions' => $extensions,
        ];
    }

    /**
     * @param list<array{abs: string, rel: string, extensions: list<string>}> $inputRoots
     * @param list<array{abs: string, rel: string, extensions: list<string>}> $sourceRoots
     * @param list<string> $problems
     */
    private static function checkCacheDirPlacement(
        ?string $cacheDir,
        array $inputRoots,
        array $sourceRoots,
        array &$problems
    ): void {
        if ($cacheDir === null) {
            return;
        }

        $cacheDirPrefix = \rtrim($cacheDir, '/') . '/';

        foreach (\array_merge($inputRoots, $sourceRoots) as $root) {
            $rootPrefix = \rtrim($root['abs'], '/') . '/';
            $label = $root['rel'] === '' ? '.' : $root['rel'];

            $inside = \strncmp($cacheDir, $rootPrefix, \strlen($rootPrefix)) === 0;
            $equal = $cacheDir === $root['abs'];
            // The containment has to be checked both ways. `cache_dir: '.'`
            // *contains* every root rather than sitting inside one, and it is
            // just as broken: writing an artefact still looks like a source
            // change, so the bundle rebuilds forever.
            $contains = \strncmp($root['abs'], $cacheDirPrefix, \strlen($cacheDirPrefix)) === 0;

            if (!$inside && !$equal && !$contains) {
                continue;
            }

            $problem = $contains && !$inside && !$equal
                ? \sprintf('Key "cache_dir" may not contain a watched or scanned root ("%s").', $label)
                : \sprintf('Key "cache_dir" may not sit inside a watched or scanned root ("%s").', $label);

            if (!\in_array($problem, $problems, true)) {
                $problems[] = $problem;
            }

            return;
        }
    }

    private static function relative(string $projectRoot, string $abs): string
    {
        try {
            return Path::relative($projectRoot, $abs);
        } catch (PathException $e) {
            // Outside the project root but inside an allowed root: there is no
            // project-relative form, so the absolute one is all we have.
            return $abs;
        }
    }

    // --------------------------------------------------------- 10 assemble

    /**
     * @param list<string> $roots
     * @param array<string, mixed> $merged
     * @param array<string, string|null> $resolved
     *
     * @return array<string, mixed>
     */
    private static function assemble(string $projectRoot, array $roots, array $merged, array $resolved): array
    {
        $cacheDir = $resolved['cache_dir'] ?? ($projectRoot . '/' . $merged['cache_dir']);
        $binary = $resolved['cli.binary'];

        $allowedRootsRel = [];

        foreach ($roots as $root) {
            $allowedRootsRel[] = self::relative($projectRoot, $root);
        }

        $values = $merged;
        $values['project_root'] = $projectRoot;
        $values['cache_dir'] = $cacheDir;
        $values['cache_dir_rel'] = self::relative($projectRoot, $cacheDir);
        $values['allowed_roots'] = $roots;
        $values['allowed_roots_rel'] = $allowedRootsRel;
        $values['cli'] = [
            'binary' => $binary,
            'binary_rel' => $binary === null ? null : self::relative($projectRoot, $binary),
            'verify' => (bool) $merged['cli']['verify'],
            'timeout' => (int) $merged['cli']['timeout'],
        ];
        $values['minify'] = $merged['minify'] === null
            ? !((bool) $merged['debug'])
            : (bool) $merged['minify'];

        return $values;
    }

    // ------------------------------------------------------------- hashing

    /**
     * @param array<string, mixed> $effective
     * @param list<array{abs: string, rel: string, extensions: list<string>}> $inputRoots
     * @param list<array{abs: string, rel: string, extensions: list<string>}> $sourceRoots
     * @param mixed $minify
     *
     * @return array<string, mixed>
     */
    private static function bundleHashInput(
        string $inputRel,
        array $effective,
        array $inputRoots,
        array $sourceRoots,
        $minify
    ): array {
        $rootsRel = static function (array $roots): array {
            $out = [];

            foreach ($roots as $root) {
                $out[] = ['rel' => $root['rel'], 'extensions' => $root['extensions']];
            }

            return $out;
        };

        return [
            // The entry stylesheet itself, not just the directory it sits in.
            // `input_roots` records `dirname($input)`, so without this line
            // switching `assets/styles/main.css` to `assets/styles/other.css`
            // produced an identical hash and the cache never rebuilt (CSF-A4
            // flags `input` as hashed).
            'input' => $inputRel,
            'input_roots' => $rootsRel($inputRoots),
            'source_roots' => $rootsRel($sourceRoots),
            'ignore' => \is_array($effective['ignore']) ? \array_values($effective['ignore']) : [],
            'safelist' => \is_array($effective['safelist']) ? \array_values($effective['safelist']) : [],
            'blocklist' => \is_array($effective['blocklist']) ? \array_values($effective['blocklist']) : [],
            'minify' => (bool) $minify,
            'engine' => $effective['engine'],
            'preprocessor' => $effective['preprocessor'],
            'preprocessor_options' => \is_array($effective['preprocessor_options'])
                ? $effective['preprocessor_options']
                : [],
        ];
    }

    /**
     * The configuration fingerprint (decision CSF-A4, CSF-A3).
     *
     * Only `hash: true` keys take part, every path is project-relative so the
     * hash survives a move (ADR-014), and the whole structure is recursively
     * key-sorted so two configs that differ only in key order agree.
     *
     * @param array<string, mixed> $merged
     * @param array<string, mixed> $values
     * @param array<string, BundleConfig> $bundles
     */
    private static function computeHash(array $merged, array $values, array $bundles): string
    {
        $input = [];

        foreach (Schema::hashedKeys() as $key) {
            if ($key === 'bundles') {
                continue;
            }

            if (\strpos($key, '.') !== false) {
                [$parent, $child] = \explode('.', $key, 2);

                if ($parent === 'cli' && $child === 'binary') {
                    $input['cli.binary'] = $values['cli']['binary_rel'];

                    continue;
                }

                $input[$key] = $merged[$parent][$child] ?? null;

                continue;
            }

            switch ($key) {
                case 'allowed_roots':
                    $input[$key] = $values['allowed_roots_rel'];

                    break;
                case 'minify':
                    $input[$key] = $values['minify'];

                    break;
                    // Per-bundle path keys are represented by each bundle's own
                    // relative-path hash input instead of their raw global values.
                case 'input':
                case 'watch':
                case 'sources':
                case 'input_extensions':
                case 'source_extensions':
                case 'ignore':
                case 'safelist':
                case 'blocklist':
                    break;
                default:
                    $input[$key] = $merged[$key];

                    break;
            }
        }

        $perBundle = [];

        foreach ($bundles as $name => $bundle) {
            $perBundle[$name] = $bundle->hashInput();
        }

        $input['bundles'] = $perBundle;

        self::ksortRecursive($input);

        return Hash::sha256(Json::encode($input));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function ksortRecursive(array &$data): void
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                self::ksortRecursive($value);
            }
        }

        unset($value);

        \ksort($data);
    }
}
