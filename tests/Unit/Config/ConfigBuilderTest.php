<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Config\ConfigBuilder;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Tests\Support\TempTree;

/**
 * The validation table of design doc 11 §Resolution and validation, one row per
 * rule.
 *
 * Two properties are asserted throughout and matter more than any single rule:
 * a bad config reports **every** problem at once rather than the first, and
 * types are checked strictly so a quoted integer is a failure rather than a
 * silent coercion.
 */
final class ConfigBuilderTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempTree::make('tailwindphp-cfg');

        \mkdir($this->root . '/assets/styles', 0777, true);
        \mkdir($this->root . '/templates', 0777, true);
        \file_put_contents($this->root . '/assets/styles/main.css', '@import "tailwindcss";');
        \file_put_contents($this->root . '/templates/a.twig', '<div class="p-4"></div>');
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);

        parent::tearDown();
    }

    // ------------------------------------------------------------ happy path

    /** @test */
    public function aMinimalConfigBuilds(): void
    {
        $config = $this->build([]);

        self::assertSame($this->root, $config->projectRoot());
        self::assertSame('cache/tailwind', $config->cacheDirRel());
        self::assertSame('auto', $config->driver());
        self::assertFalse($config->debug());
        self::assertSame(['styles'], \array_keys($config->bundles()));
    }

    /** @test */
    public function defaultsAreAppliedForKeysTheUserOmits(): void
    {
        $config = $this->build(['gzip' => false]);

        self::assertFalse($config->gzip());
        self::assertSame(12, $config->fingerprintLength());
        self::assertSame(3, $config->keepVersions());
        self::assertSame('flock', $config->lock());
    }

    /**
     * Nested maps merge key by key so a user can set one member without
     * restating the others; lists replace wholesale so a shorter list is
     * actually shorter.
     *
     * @test
     */
    public function nestedMapsMergeWhileListsReplace(): void
    {
        $config = $this->build([
            'cli' => ['timeout' => 45],
            'input_extensions' => ['css'],
        ]);

        $cli = $config->cli();

        self::assertSame(45, $cli['timeout']);
        self::assertTrue($cli['verify'], 'The untouched member must keep its default.');
        self::assertSame(['css'], $config->bundle('styles')->inputExtensions());
    }

    // ------------------------------------------------------- unknown keys

    /** @test */
    public function anUnknownKeyIsRejectedWithASuggestion(): void
    {
        $problems = $this->problemsFor(['cach_dir' => 'x']);

        self::assertCount(1, $problems);
        self::assertStringContainsString('cach_dir', $problems[0]);
        self::assertStringContainsString('did you mean "cache_dir"', $problems[0]);
    }

    /** @test */
    public function anUnknownNestedKeyNamesItsFullPath(): void
    {
        $problems = $this->problemsFor(['cli' => ['timeoutt' => 5]]);

        self::assertStringContainsString('cli.timeoutt', $problems[0]);
        self::assertStringContainsString('timeout', $problems[0]);
    }

    /** @test */
    public function anUnknownKeyWithNoCloseMatchGetsNoSuggestion(): void
    {
        $problems = $this->problemsFor(['completely_unrelated_option' => 1]);

        self::assertStringContainsString('completely_unrelated_option', $problems[0]);
        self::assertStringNotContainsString('did you mean', $problems[0]);
    }

    // -------------------------------------------------------------- types

    /**
     * @test
     *
     * @dataProvider badTypes
     *
     * @param array<string, mixed> $user
     */
    public function aWrongTypeIsRejected(array $user, string $needle): void
    {
        $problems = $this->problemsFor($user);

        self::assertNotSame([], $problems);
        self::assertStringContainsString($needle, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badTypes(): array
    {
        return [
            // The headline case: no juggling, a quoted int is a mistake.
            'int given as a string' => [['fingerprint_length' => '12'], 'must be an integer'],
            'bool given as an int' => [['debug' => 1], 'must be a boolean'],
            'bool given as a string' => [['freeze' => 'yes'], 'must be a boolean'],
            'string given as an int' => [['cache_dir' => 5], 'must be a string'],
            'list given as a map' => [['sources' => ['a' => 'templates']], 'must be a list'],
            'map given as a list' => [['cli' => ['a', 'b']], 'cli'],
            'null for a non-nullable key' => [['debug' => null], 'may not be null'],
            'nested int as a string' => [['limits' => ['max_files' => '10']], 'must be an integer'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider outOfRange
     *
     * @param array<string, mixed> $user
     */
    public function anIntegerOutsideItsRangeIsRejected(array $user): void
    {
        $problems = $this->problemsFor($user);

        self::assertStringContainsString('must be between', \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function outOfRange(): array
    {
        return [
            'fingerprint_length too small' => [['fingerprint_length' => 7]],
            'fingerprint_length too large' => [['fingerprint_length' => 17]],
            'negative check_interval' => [['check_interval' => -1]],
            'cli timeout zero' => [['cli' => ['timeout' => 0]]],
        ];
    }

    /** @test */
    public function aNullableKeyAcceptsNull(): void
    {
        $config = $this->build(['preprocessor' => null, 'logger' => null]);

        self::assertNull($config->bundle('styles')->preprocessor());
        self::assertNull($config->logger());
    }

    // -------------------------------------------------------------- enums

    /**
     * @test
     *
     * @dataProvider badEnums
     *
     * @param array<string, mixed> $user
     */
    public function anInvalidEnumValueIsRejected(array $user, string $needle): void
    {
        $problems = $this->problemsFor($user);

        self::assertStringContainsString($needle, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function badEnums(): array
    {
        return [
            'driver' => [['driver' => 'node'], 'driver'],
            'lock' => [['lock' => 'semaphore'], 'lock'],
            'check_store' => [['check_store' => 'redis'], 'check_store'],
            'log_level' => [['log_level' => 'verbose'], 'log_level'],
        ];
    }

    /**
     * The enum stays valid so a config written for M3 still parses, but the
     * store is not implemented yet (decision CSF-A14).
     *
     * @test
     */
    public function apcuCheckStoreIsRejectedWithAForwardLookingMessage(): void
    {
        $problems = $this->problemsFor(['check_store' => 'apcu']);

        self::assertStringContainsString('M3', \implode(' | ', $problems));
    }

    // ------------------------------------------------------------- routes

    /**
     * @test
     *
     * @dataProvider badRoutes
     */
    public function anInvalidPublicRouteIsRejected(string $route): void
    {
        $problems = $this->problemsFor(['public_route' => $route]);

        self::assertNotSame([], $problems, \sprintf('Route "%s" should have been rejected.', $route));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badRoutes(): array
    {
        return [
            'traversal' => ['assets/../../styles.css'],
            'double slash' => ['assets//styles.css'],
            'not css' => ['assets/styles.txt'],
            'no extension' => ['assets/styles'],
            'uppercase bundle name' => ['assets/Styles.css'],
            'bundle name too long' => ['assets/' . \str_repeat('a', 41) . '.css'],
            'illegal character' => ['assets/sty les.css'],
        ];
    }

    /** @test */
    public function theBundleNameComesFromTheRouteBasename(): void
    {
        $config = $this->build(['public_route' => 'build/app-main.css']);

        self::assertSame(['app-main'], \array_keys($config->bundles()));
        self::assertSame('app-main', $config->bundle('app-main')->name());
    }

    // --------------------------------------------------------- extensions

    /** @test */
    public function extensionsAreLowercasedAndDeduplicated(): void
    {
        $config = $this->build(['input_extensions' => ['CSS', 'css', 'Scss']]);

        self::assertSame(['css', 'scss'], $config->bundle('styles')->inputExtensions());
    }

    /**
     * @test
     *
     * @dataProvider badExtensions
     */
    public function anInvalidExtensionIsRejected(string $extension, string $needle): void
    {
        $problems = $this->problemsFor(['input_extensions' => [$extension]]);

        self::assertStringContainsString($needle, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badExtensions(): array
    {
        return [
            'leading dot' => ['.css', 'must not start with a dot'],
            'slash' => ['a/b', 'is not valid'],
            'space' => ['c ss', 'is not valid'],
        ];
    }

    /** @test */
    public function aTwoSegmentExtensionIsLegal(): void
    {
        $config = $this->build(['source_extensions' => ['blade.php']]);

        self::assertSame(['blade.php'], $config->bundle('styles')->sourceExtensions());
    }

    // ------------------------------------------------ safelist / blocklist

    /**
     * @test
     *
     * @dataProvider badCandidates
     */
    public function anInvalidSafelistEntryIsRejected(string $entry, string $needle): void
    {
        $problems = $this->problemsFor(['safelist' => [$entry]]);

        self::assertStringContainsString($needle, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badCandidates(): array
    {
        return [
            'empty' => ['', 'may not be empty'],
            'whitespace' => ['bg-red-500 p-4', 'whitespace'],
            'newline' => ["bg-red\n", 'whitespace'],
            'semicolon' => ['bg-red;color:red', '";"'],
            'brace' => ['bg-red{}', '"{"'],
            // Described, not quoted: a quoted "/*" is redacted to "<path>" by
            // the log-safety scrubber, which would hide what was wrong.
            'comment' => ['bg-red/*x*/', 'CSS comment sequence'],
            'unbalanced bracket' => ['w-[32rem', 'unbalanced'],
            'too long' => [\str_repeat('a', 257), '256 bytes'],
        ];
    }

    /** @test */
    public function legitimateArbitraryValuesAreAccepted(): void
    {
        $config = $this->build([
            'safelist' => ['w-[32rem]', 'hover:[&>*]:p-2', 'bg-(--brand)', '-m-4', '!p-0'],
        ]);

        self::assertCount(5, $config->bundle('styles')->safelist());
    }

    // -------------------------------------------------------------- paths

    /** @test */
    public function aMissingInputIsRejected(): void
    {
        $problems = $this->problemsFor(['input' => 'assets/styles/nope.css']);

        self::assertStringContainsString('does not exist', \implode(' | ', $problems));
    }

    /** @test */
    public function theCacheDirMayNotSitInsideAScannedRoot(): void
    {
        $problems = $this->problemsFor(['cache_dir' => 'templates/cache']);

        self::assertStringContainsString('may not sit inside', \implode(' | ', $problems));
    }

    /** @test */
    public function theCacheDirNeedNotExistYet(): void
    {
        $config = $this->build(['cache_dir' => 'var/cache/tw']);

        self::assertSame('var/cache/tw', $config->cacheDirRel());
    }

    /**
     * Decision CSF-A2: an allowed root is its own confinement root, so it may
     * legitimately live outside the project.
     *
     * @test
     */
    public function anAllowedRootOutsideTheProjectIsAccepted(): void
    {
        $outside = TempTree::make('tailwindphp-outside');

        try {
            $config = $this->build(['allowed_roots' => [$outside]]);

            self::assertCount(2, $config->allowedRoots());
        } finally {
            TempTree::remove($outside);
        }
    }

    /** @test */
    public function aMissingAllowedRootIsRejected(): void
    {
        $problems = $this->problemsFor(['allowed_roots' => [$this->root . '/nope']]);

        self::assertStringContainsString('allowed_roots.0', \implode(' | ', $problems));
    }

    // ------------------------------------------------------------ derived

    /** @test */
    public function minifyDefaultsToTheOppositeOfDebug(): void
    {
        self::assertTrue($this->build([])->bundle('styles')->minify());
        self::assertFalse($this->build(['debug' => true])->bundle('styles')->minify());
    }

    /** @test */
    public function anExplicitMinifyWins(): void
    {
        self::assertFalse($this->build(['minify' => false])->bundle('styles')->minify());
        self::assertTrue($this->build(['debug' => true, 'minify' => true])->bundle('styles')->minify());
    }

    // ------------------------------------------------------------ bundles

    /** @test */
    public function bundlesOverrideTheTopLevelDefaults(): void
    {
        \mkdir($this->root . '/assets/admin', 0777, true);
        \file_put_contents($this->root . '/assets/admin/admin.css', '@import "tailwindcss";');

        $config = $this->build([
            'bundles' => [
                'site' => [],
                'admin' => ['input' => 'assets/admin/admin.css', 'public_route' => 'assets/admin.css'],
            ],
        ]);

        self::assertSame(['site', 'admin'], \array_keys($config->bundles()));
        self::assertSame('assets/styles/main.css', $config->bundle('site')->inputRel());
        self::assertSame('assets/admin/admin.css', $config->bundle('admin')->inputRel());
    }

    /**
     * Decision CSF-A20: only keys that describe what a bundle *is* may be
     * overridden; cache, locking and driver settings are global.
     *
     * @test
     *
     * @dataProvider globalOnlyKeys
     *
     * @param mixed $value
     */
    public function aGlobalOnlyKeyCannotBeSetPerBundle(string $key, $value): void
    {
        $problems = $this->problemsFor(['bundles' => ['site' => [$key => $value]]]);

        self::assertStringContainsString('cannot be set per bundle', \implode(' | ', $problems));
        self::assertStringContainsString($key, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function globalOnlyKeys(): array
    {
        return [
            'cache_dir' => ['cache_dir', 'other/cache'],
            'driver' => ['driver', 'php'],
            'debug' => ['debug', true],
            'lock' => ['lock', 'none'],
            'gzip' => ['gzip', false],
            'limits' => ['limits', ['max_files' => 5]],
        ];
    }

    /** @test */
    public function anInvalidBundleNameIsRejected(): void
    {
        $problems = $this->problemsFor(['bundles' => ['Site Name' => []]]);

        self::assertStringContainsString('Bundle name', \implode(' | ', $problems));
    }

    /** @test */
    public function anUnknownBundleThrowsWhenRequested(): void
    {
        $config = $this->build([]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unknown bundle "nope"');

        $config->bundle('nope');
    }

    // ------------------------------------------------- collect-all-problems

    /**
     * The property that makes the whole validator worth having: a config with
     * four mistakes reports four, so the user fixes their file once.
     *
     * @test
     */
    public function everyProblemIsReportedAtOnce(): void
    {
        $problems = $this->problemsFor([
            'fingerprint_length' => '12',
            'driver' => 'node',
            'lock' => 'semaphore',
            'cach_dir' => 'typo',
            'safelist' => ['bad candidate'],
        ]);

        self::assertGreaterThanOrEqual(5, \count($problems), \implode("\n", $problems));

        $joined = \implode(' | ', $problems);

        foreach (['fingerprint_length', 'driver', 'lock', 'cach_dir', 'safelist'] as $expected) {
            self::assertStringContainsString($expected, $joined);
        }
    }

    /** @test */
    public function theExceptionMessageListsEveryProblem(): void
    {
        try {
            $this->build(['driver' => 'node', 'lock' => 'semaphore']);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertCount(2, $e->problems());
            self::assertStringContainsString('Invalid configuration:', $e->getMessage());
        }
    }

    /**
     * A NUL byte used to reach `realpath()`, which raises a `ValueError` on PHP
     * 8 — an uncaught error of a completely different family from the
     * `ConfigurationException` every caller is told to expect.
     *
     * @test
     */
    public function aProjectRootWithANulByteIsAConfigurationProblemNotAFatal(): void
    {
        $problems = $this->problemsFor(['project_root' => $this->root . "\0evil"]);

        self::assertNotSame([], $problems);
        self::assertStringContainsString('project_root', \implode(' | ', $problems));
    }

    /**
     * A relative root resolves against the current working directory, which
     * differs between php-fpm, the CLI and cron — so every confinement boundary
     * in the library would depend on which SAPI happened to run. These were
     * accepted, and `'rel1'` silently resolved against the CWD.
     *
     * @test
     *
     * @dataProvider relativeProjectRoots
     */
    public function aRelativeProjectRootIsRejected(string $projectRoot): void
    {
        $problems = \implode(' | ', $this->problemsFor(['project_root' => $projectRoot]));

        self::assertStringContainsString('must be an absolute path', $problems);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function relativeProjectRoots(): array
    {
        return [
            'a bare directory name' => ['rel1'],
            'the current directory' => ['.'],
            'the parent directory' => ['..'],
            'a normalising relative path' => ['assets/../'],
            'a relative subpath' => ['assets/styles'],
        ];
    }

    /** @test */
    public function aProjectRootThatIsAFileIsRejectedForBeingAFile(): void
    {
        \file_put_contents($this->root . '/a-file.txt', 'x');

        $problems = \implode(' | ', $this->problemsFor(['project_root' => $this->root . '/a-file.txt']));

        // Not just "some problem": it used to fail downstream with a confusing
        // message about `input` instead.
        self::assertStringContainsString('must be a directory', $problems);
    }

    /**
     * The containment check has to run both ways. `cache_dir: '.'` *contains*
     * every scanned root rather than sitting inside one, and it is equally
     * broken: writing an artefact still looks like a source change.
     *
     * @test
     *
     * @dataProvider cacheDirsThatOverlapAScannedRoot
     */
    public function aCacheDirThatOverlapsAScannedRootIsRejected(string $cacheDir): void
    {
        $problems = \implode(' | ', $this->problemsFor(['cache_dir' => $cacheDir]));

        self::assertStringContainsString('cache_dir', $problems);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function cacheDirsThatOverlapAScannedRoot(): array
    {
        return [
            'the project root itself' => ['.'],
            'an ancestor of the input root' => ['assets'],
        ];
    }

    /** @test */
    public function anInputThatIsADirectoryIsRejected(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['input' => 'templates']));

        // It used to fail only incidentally, via the cache_dir placement rule,
        // naming a key the user had not touched.
        self::assertStringContainsString('Key "input" must point to an existing file.', $problems);
    }

    /**
     * @test
     *
     * @dataProvider unvalidatedContents
     *
     * @param array<string, mixed> $user
     */
    public function listAndObjectContentsAreValidated(array $user, string $expected): void
    {
        self::assertStringContainsString($expected, \implode(' | ', $this->problemsFor($user)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unvalidatedContents(): array
    {
        return [
            'prepass entry is unknown' => [['scanner' => ['prepass' => ['nope']]], 'scanner.prepass.0'],
            'ignore entry is not a string' => [['ignore' => [123]], 'ignore.0'],
            'logger is not a PSR logger' => [['logger' => 'not-a-logger'], 'logger'],
            'allowed_roots entry is a file' => [['allowed_roots' => ['assets/styles/main.css']], 'allowed_roots.0'],
        ];
    }

    /**
     * The duplicate-route messages echo the route and its basename, both of
     * which are user-supplied, so they get the same escaping as every other
     * quoted value (T-06).
     *
     * @test
     */
    public function theDuplicateRouteMessageCannotForgeALogLine(): void
    {
        $forged = "x.css\n2026-01-01 FAKE";

        $problems = \implode(' | ', $this->problemsFor(['bundles' => [
            'a' => ['public_route' => $forged],
            'b' => ['public_route' => $forged],
        ]]));

        self::assertStringNotContainsString("\n", $problems, 'A newline survived into a problem message.');
        self::assertStringContainsString('\\n', $problems, 'The newline should appear as an escape.');
    }

    /** @test */
    public function theSameBasenameMessageCannotForgeALogLine(): void
    {
        \mkdir($this->root . '/other');

        $problems = \implode(' | ', $this->problemsFor(['bundles' => [
            'a' => ['public_route' => "assets/x.css\nFAKE"],
            'b' => ['public_route' => "other/x.css\nFAKE"],
        ]]));

        self::assertStringNotContainsString("\n", $problems);
    }

    /** @test */
    public function everyDuplicateRouteProblemIsBounded(): void
    {
        \mkdir($this->root . '/other');

        $problems = $this->problemsFor(['bundles' => [
            'a' => ['public_route' => \str_repeat('q', 5000) . '/x.css'],
            'b' => ['public_route' => 'other/x.css'],
        ]]);

        self::assertNotSame([], $problems);

        foreach ($problems as $problem) {
            self::assertLessThan(300, \strlen($problem), 'A problem line quoted a huge value in full.');
        }
    }

    /**
     * A bad *inherited* nested member belongs to the key the user wrote. A plain
     * key-level intersect handed the whole merged `engine` map to the type check,
     * so one bad global member came back once per bundle as well.
     *
     * @test
     */
    public function aGlobalNestedMapProblemIsNotRepeatedUnderEveryBundle(): void
    {
        $problems = $this->problemsFor([
            'engine' => ['important' => 'yes'],
            'bundles' => [
                'a' => ['engine' => ['prefix' => 'tw'], 'public_route' => 'a.css'],
                'b' => ['engine' => ['prefix' => 'tx'], 'public_route' => 'b.css'],
            ],
        ]);

        $aboutImportant = [];

        foreach ($problems as $problem) {
            if (\strpos($problem, 'important') !== false) {
                $aboutImportant[] = $problem;
            }
        }

        self::assertSame(['Key "engine.important" must be a boolean, got string.'], $aboutImportant);
    }

    /**
     * The narrowing must not hide a bundle's *own* bad nested member.
     *
     * @test
     */
    public function aBundlesOwnNestedMemberIsStillValidated(): void
    {
        $problems = \implode(' | ', $this->problemsFor([
            'bundles' => ['a' => ['engine' => ['important' => 'yes'], 'public_route' => 'a.css']],
        ]));

        self::assertStringContainsString('Key "bundles.a.engine.important" must be a boolean', $problems);
    }

    /** @test */
    public function twoBundlesMayNotShareARoute(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['bundles' => [
            'a' => ['public_route' => 'assets/x.css'],
            'b' => ['public_route' => 'assets/x.css'],
        ]]));

        self::assertStringContainsString('"a"', $problems);
        self::assertStringContainsString('"b"', $problems);
    }

    /**
     * Different directories, same file name: the artefact is keyed by the
     * route's basename, so these collide in the cache directory.
     *
     * @test
     */
    public function twoBundlesMayNotHaveRoutesWithTheSameBasename(): void
    {
        \mkdir($this->root . '/other');

        $problems = \implode(' | ', $this->problemsFor(['bundles' => [
            'a' => ['public_route' => 'assets/x.css'],
            'b' => ['public_route' => 'other/x.css'],
        ]]));

        self::assertStringContainsString('"a"', $problems);
        self::assertStringContainsString('"b"', $problems);
    }

    /**
     * `LogSafety` scrubs anything path-shaped out of a message, so a literal
     * "//" in the text came out as "<path>" and the rule became unreadable.
     * The message describes the rule instead of quoting it.
     *
     * @test
     */
    public function theRouteRuleMessageSurvivesTheLogSafetyScrubber(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['public_route' => 'a//b.css']));

        self::assertStringNotContainsString('<path>', $problems);
        self::assertStringContainsString('repeated slash', $problems);
    }

    /**
     * Modes are written as octal everywhere a user meets them, so bounds of
     * "0 and 511" are numbers they cannot match against the 0755 they typed.
     * The *received* value stays decimal — printing a plain `755` typo as octal
     * would misrepresent what they wrote.
     *
     * @test
     */
    public function aFileModeOutOfRangeIsReportedInOctal(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['cache_dir_mode' => 99999]));

        self::assertStringContainsString('0000 and 0777', $problems);
        self::assertStringContainsString('got 99999', $problems);
        self::assertStringNotContainsString('511', $problems);
    }

    /**
     * The whitespace rule listed its forbidden bytes by hand and missed `\f`.
     * Every byte below 0x21 is refused now: a control character in a candidate
     * can only be a paste artefact or an injection attempt (CSF-A1, NFR-SEC-6).
     *
     * @test
     *
     * @dataProvider controlCharacters
     */
    public function aCandidateMayNotContainAnyControlCharacter(string $byte): void
    {
        self::assertNotSame([], $this->problemsFor(['safelist' => ['a' . $byte . 'b']]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function controlCharacters(): array
    {
        return [
            'space' => [' '],
            'tab' => ["\t"],
            'newline' => ["\n"],
            'carriage return' => ["\r"],
            'form feed' => ["\f"],
            'vertical tab' => ["\x0B"],
            'nul' => ["\0"],
            'escape' => ["\x1B"],
            // 0x7F is *above* the "< 0x21" scan, so the first fix for the
            // missing \f let DEL straight through.
            'delete' => ["\x7F"],
        ];
    }

    /**
     * A malformed `sources` or `watch` entry is reported, never skipped.
     *
     * These used to return null out of `resolveOne()` without a problem, so a
     * typo silently removed a whole root from the scan: nothing failed, and the
     * only symptom was a class quietly missing from the stylesheet. Design doc
     * 11 requires typos to fail loudly.
     *
     * @test
     *
     * @dataProvider malformedRootEntries
     *
     * @param array<string, mixed> $user
     */
    public function aMalformedSourceOrWatchEntryIsReported(array $user, string $expectedLabel): void
    {
        $problems = $this->problemsFor($user);

        self::assertNotSame([], $problems, 'The malformed entry was silently dropped.');
        self::assertStringContainsString($expectedLabel, \implode(' | ', $problems));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedRootEntries(): array
    {
        return [
            'source is an integer' => [['sources' => [123]], 'sources.0'],
            'source is null' => [['sources' => [null]], 'sources.0'],
            'source map has no path' => [['sources' => [['extensions' => ['php']]]], 'sources.0'],
            'source map path is an integer' => [['sources' => [['path' => 123]]], 'sources.0.path'],
            'source map extensions is a string' => [
                ['sources' => [['path' => 'templates', 'extensions' => 'php']]],
                'sources.0.extensions',
            ],
            'source map has an unknown key' => [
                ['sources' => [['path' => 'templates', 'exts' => ['php']]]],
                'sources.0.exts',
            ],
            'watch entry is an integer' => [['watch' => [123]], 'watch.0'],
        ];
    }

    /**
     * The implicit bundle's keys *are* the top-level keys, so its problems must
     * name the key the user actually wrote. They used to be prefixed with
     * `public_route.`, pointing at a key that appears nowhere in the config.
     *
     * @test
     */
    public function theDefaultBundleReportsProblemsUnderTheTopLevelKeyNames(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['sources' => [123], 'watch' => [456]]));

        self::assertStringContainsString('Key "sources.0"', $problems);
        self::assertStringContainsString('Key "watch.0"', $problems);
        self::assertStringNotContainsString('public_route.sources', $problems);
        self::assertStringNotContainsString('public_route.watch', $problems);
    }

    /**
     * `input` is flagged `hash: true` by CSF-A4, but the hash was built from
     * `input_roots`, which records only `dirname($input)`. Two entry stylesheets
     * in the same directory therefore produced an identical config hash, so
     * switching between them never invalidated the cache.
     *
     * @test
     */
    public function theConfigHashDistinguishesTwoInputsInTheSameDirectory(): void
    {
        \file_put_contents($this->root . '/assets/styles/other.css', '@import "tailwindcss";');

        $first = $this->build(['input' => 'assets/styles/main.css'])->configHash();
        $second = $this->build(['input' => 'assets/styles/other.css'])->configHash();

        self::assertNotSame($first, $second, 'Changing the entry stylesheet must change the config hash.');
    }

    /** @test */
    public function theConfigHashDistinguishesTwoInputsDeclaredOnABundle(): void
    {
        \file_put_contents($this->root . '/assets/styles/other.css', '@import "tailwindcss";');

        $first = $this->build(['bundles' => ['app' => ['input' => 'assets/styles/main.css']]])->configHash();
        $second = $this->build(['bundles' => ['app' => ['input' => 'assets/styles/other.css']]])->configHash();

        self::assertNotSame($first, $second);
    }

    /**
     * The other half of the property: the hash is a cache key, so building the
     * same config twice must produce the same value or every request rebuilds.
     *
     * @test
     */
    public function theConfigHashIsStableAcrossBuilds(): void
    {
        self::assertSame(
            $this->build(['input' => 'assets/styles/main.css'])->configHash(),
            $this->build(['input' => 'assets/styles/main.css'])->configHash()
        );
    }

    /**
     * Bundle overrides are user input exactly like the top-level map, so they
     * go through the same validation with `bundles.<name>.` labels.
     *
     * They used to be copied into the effective map unchecked, which skipped
     * every rule the global map had already passed — including the CSF-A1 /
     * NFR-SEC-6 candidate-syntax floor, so a bundle `safelist` could carry
     * `'a b; } evil {'` straight through to the compiler.
     *
     * @test
     *
     * @dataProvider rejectedBundleOverrides
     *
     * @param array<string, mixed> $overrides
     */
    public function aBundleOverrideIsValidatedLikeTheGlobalMap(array $overrides, string $expectedLabel): void
    {
        $problems = $this->problemsFor(['bundles' => ['app' => $overrides]]);

        self::assertNotSame([], $problems, 'The bundle override was accepted unchecked.');
        self::assertStringContainsString(
            $expectedLabel,
            \implode(' | ', $problems),
            'The problem must name the bundle key the user actually wrote.'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rejectedBundleOverrides(): array
    {
        return [
            'safelist injection' => [['safelist' => ['a b; } evil {']], 'bundles.app.safelist.0'],
            'blocklist injection' => [['blocklist' => ['a;b']], 'bundles.app.blocklist.0'],
            'input is an integer' => [['input' => 123], 'bundles.app.input'],
            'input is null' => [['input' => null], 'bundles.app.input'],
            'engine is a string' => [['engine' => 'x'], 'bundles.app.engine'],
            'engine has an unknown member' => [['engine' => ['prefx' => 'tw']], 'bundles.app.engine.prefx'],
            'engine member is mistyped' => [['engine' => ['prefix' => 123]], 'bundles.app.engine.prefix'],
            'preprocessor class is missing' => [['preprocessor' => 'No\\Such\\Klass'], 'bundles.app.preprocessor'],
            'preprocessor is an integer' => [['preprocessor' => 123], 'bundles.app.preprocessor'],
            'preprocessor is an array' => [['preprocessor' => []], 'bundles.app.preprocessor'],
            'minify is a string' => [['minify' => 'yes'], 'bundles.app.minify'],
            'public_route is an integer' => [['public_route' => 123], 'bundles.app.public_route'],
            'public_route is null' => [['public_route' => null], 'bundles.app.public_route'],
            'sources is a bare string' => [['sources' => 'templates'], 'bundles.app.sources'],
            'ignore is a bare string' => [['ignore' => 'vendor'], 'bundles.app.ignore'],
            'input_extensions is a string' => [['input_extensions' => 'css'], 'bundles.app.input_extensions'],
        ];
    }

    /**
     * `ignore` is `bundle: true`, but the content checks only ever ran on the
     * global map. `BundleConfig::ignore()` is documented `list<string>` and
     * `Scanner\IgnoreFilter` hands it straight to `strpos()`, so a stray int
     * became a `TypeError` at scan time — the same failure shape as round-1
     * must-fix 2.
     *
     * @test
     */
    public function aBundleIgnoreListIsValidatedLikeTheGlobalOne(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['bundles' => ['a' => ['ignore' => [123]]]]));

        self::assertStringContainsString('Key "bundles.a.ignore.0" must be a string', $problems);
    }

    /**
     * A problem in an *inherited* value belongs to the key the user wrote, not
     * to every bundle that inherits it. One bad global `minify` used to be
     * reported once globally and again under each bundle label.
     *
     * @test
     */
    public function aGlobalProblemIsNotRepeatedUnderEveryBundle(): void
    {
        $problems = $this->problemsFor([
            'minify' => 'yes',
            'bundles' => [
                'a' => ['public_route' => 'a.css'],
                'b' => ['public_route' => 'b.css'],
            ],
        ]);

        $aboutMinify = [];

        foreach ($problems as $problem) {
            if (\strpos($problem, 'minify') !== false) {
                $aboutMinify[] = $problem;
            }
        }

        self::assertSame(['Key "minify" must be a boolean, got string.'], $aboutMinify);
    }

    /**
     * Two different mistakes deserve two different messages: a key that exists
     * globally is misplaced, a key that exists nowhere is a typo and keeps the
     * levenshtein hint.
     *
     * @test
     */
    public function anUnknownBundleKeyKeepsTheDidYouMeanHint(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['bundles' => ['a' => ['inpt' => 'x.css']]]));

        self::assertStringContainsString('did you mean "input"?', $problems);
        self::assertStringNotContainsString('cannot be set per bundle', $problems);
    }

    /** @test */
    public function aGloballyValidKeyStillSaysItCannotBeSetPerBundle(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['bundles' => ['a' => ['cache_dir' => 'x']]]));

        self::assertStringContainsString('cannot be set per bundle', $problems);
        self::assertStringNotContainsString('did you mean', $problems);
    }

    /**
     * Problem messages reach logs, so a config value carrying a newline can
     * forge a log entry that looks like ours (T-06, design doc 15). Control
     * bytes are escaped and the value is capped.
     *
     * @test
     */
    public function anEchoedValueCannotForgeALogLine(): void
    {
        $problems = \implode(' | ', $this->problemsFor([
            'driver' => "cli\n2026-01-01 FAKE LOG LINE",
        ]));

        self::assertStringNotContainsString("\n", $problems, 'A newline survived into a problem message.');
        self::assertStringContainsString('\\n', $problems, 'The newline should appear as an escape.');
    }

    /** @test */
    public function anEchoedValueIsTruncated(): void
    {
        $problems = \implode(' | ', $this->problemsFor(['driver' => \str_repeat('x', 5000)]));

        self::assertLessThan(300, \strlen($problems), 'A huge value was echoed back in full.');
    }

    /**
     * A nested map override merges member by member, like the global map: a
     * bundle that sets `engine.prefix` must keep the other engine defaults
     * rather than blanking them.
     *
     * @test
     */
    public function aNestedBundleOverrideMergesWithTheDefaults(): void
    {
        $config = $this->build(['bundles' => ['app' => ['engine' => ['prefix' => 'tw']]]]);

        $engine = $config->bundle('app')->engine();

        self::assertSame('tw', $engine['prefix']);
        self::assertArrayHasKey('important', $engine, 'The untouched engine members were dropped.');
        self::assertArrayHasKey('preflight', $engine);
    }

    /**
     * A key that is valid globally but not per bundle keeps its own message,
     * rather than being reported as an unknown key (CSF-A20).
     *
     * @test
     */
    public function aKeyThatCannotBeSetPerBundleSaysSo(): void
    {
        $problems = $this->problemsFor(['bundles' => ['app' => ['cache_dir' => 'elsewhere']]]);

        self::assertStringContainsString('cannot be set per bundle', \implode(' | ', $problems));
    }

    /**
     * A regression test for a real defect, kept separate because the reason
     * these values were accepted is not visible in the values themselves.
     *
     * In PCRE, `$` matches at the end of the subject **and immediately before a
     * final newline**, unless the `D` (PCRE_DOLLAR_ENDONLY) modifier is set.
     * Every anchored pattern here was written without it, so `"assets/app.css\n"`
     * passed the route rule and `"app\n"` passed the bundle-name rule. The route
     * reaches a header context and the bundle name reaches a filename, which is
     * why a trailing newline is response splitting rather than a cosmetic slip.
     *
     * If any of these start passing again, the `D` has been dropped.
     *
     * @test
     *
     * @dataProvider trailingNewlineValues
     *
     * @param array<string, mixed> $user
     */
    public function aTrailingNewlineNeverSatisfiesAnAnchoredRule(array $user): void
    {
        self::assertNotSame([], $this->problemsFor($user), 'A value with a trailing newline was accepted.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function trailingNewlineValues(): array
    {
        return [
            'route' => [['public_route' => "assets/app.css\n"]],
            'bundle name' => [['bundles' => ["app\n" => []]]],
            'input extension' => [['input_extensions' => ["css\n"]]],
            'source extension' => [['source_extensions' => ["twig\n"]]],
        ];
    }

    // ------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $user
     */
    private function build(array $user): \TailwindPhp\Config\Config
    {
        // Defaulted, not forced: a test that is *about* project_root has to be
        // able to set its own.
        if (!\array_key_exists('project_root', $user)) {
            $user['project_root'] = $this->root;
        }

        return ConfigBuilder::fromArray($user);
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return list<string>
     */
    private function problemsFor(array $user): array
    {
        try {
            $this->build($user);
        } catch (ConfigurationException $e) {
            return $e->problems();
        }

        return [];
    }
}
