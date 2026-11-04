<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\StaticSecurityChecks;

/**
 * The grep-based static checks of design doc 16 §Security suite, run as tests
 * so they hold on every developer machine and not only in the CI job
 * (design doc 20 §ci.yml runs the same engine through `tools/`).
 *
 * This class deliberately contains **no rule patterns of its own** — it loads
 * the shared engine from `tools/` and asserts on its output. Two front ends,
 * one set of rules; a rule that drifted between them would be worse than no
 * rule at all.
 *
 * @covers-requirement FR-SCAN-1
 */
final class StaticChecksTest extends TestCase
{
    /**
     * @var StaticSecurityChecks
     */
    private $checks;

    /**
     * @var list<string>
     */
    private $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::loadEngine();

        $this->checks = new StaticSecurityChecks();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            TempTree::remove($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    /**
     * @test
     *
     * @dataProvider rules
     */
    public function theCurrentTreeSatisfiesEveryRule(string $rule): void
    {
        $violations = $this->violationsFor($rule);

        self::assertSame([], $violations, self::describe($rule, $violations));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rules(): array
    {
        // Data providers run before setUp(), so the engine is loaded here too.
        self::loadEngine();

        return [
            'R1 PCRE calls are confined to Support\\Regex' => [StaticSecurityChecks::RULE_PCRE],
            'R2 no dynamic code loading' => [StaticSecurityChecks::RULE_CODE_LOADING],
            'R3 no superglobals outside Console and ServerRequest' => [StaticSecurityChecks::RULE_SUPERGLOBAL],
            'R4 no network outside install-cli' => [StaticSecurityChecks::RULE_NETWORK],
            'R5 every catch names a resolvable class' => [StaticSecurityChecks::RULE_CATCH],
            'R6 fixture manifests hold no absolute paths' => [StaticSecurityChecks::RULE_MANIFEST_PATH],
            'R7 the engine makes no filesystem, time or random call' => [StaticSecurityChecks::RULE_ENGINE_PURITY],
        ];
    }

    /** @test */
    public function everyRuleIsCoveredByTheProvider(): void
    {
        $covered = [];

        foreach (self::rules() as $case) {
            $covered[] = $case[0];
        }

        \sort($covered);
        $all = StaticSecurityChecks::rules();
        \sort($all);

        self::assertSame($all, $covered, 'A rule was added to the engine without a test.');
    }

    /** @test */
    public function theWholeTreePasses(): void
    {
        $violations = $this->checks->run(self::repositoryRoot() . '/src', self::manifestDirectory());

        self::assertSame([], $violations, self::describe('all rules', $violations));
        self::assertGreaterThan(0, $this->checks->scannedFiles(), 'The scan found no files to check.');
    }

    /**
     * Proves the rules actually fire. Without this the suite would pass just as
     * happily against an engine that returned an empty list unconditionally.
     *
     * @test
     */
    public function theEngineFlagsASyntheticViolation(): void
    {
        $dir = $this->makeTempDir();

        // Built rather than written literally so this file stays free of the
        // very tokens it is asserting about.
        $pcreCall = 'preg' . '_match';

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Evil;\n\n"
            . "final class Evil\n"
            . "{\n"
            . "    public function run(string \$s): void\n"
            . "    {\n"
            . "        \$value = \$_GET['x'];\n"
            . '        ' . $pcreCall . "('/a/', \$s);\n"
            . "        try {\n"
            . "            \$this->run(\$value);\n"
            . "        } catch (Exception \$e) {\n"
            . "        }\n"
            . "    }\n"
            . "}\n";

        \file_put_contents($dir . '/Evil.php', $source);

        $violations = $this->checks->run($dir, null);
        $found = [];

        foreach ($violations as $violation) {
            $found[] = $violation['rule'];
        }

        self::assertContains(StaticSecurityChecks::RULE_PCRE, $found, self::describe('synthetic', $violations));
        self::assertContains(StaticSecurityChecks::RULE_SUPERGLOBAL, $found);
        self::assertContains(StaticSecurityChecks::RULE_CATCH, $found);

        foreach ($violations as $violation) {
            self::assertSame('Evil.php', $violation['file']);
            self::assertGreaterThan(0, $violation['line']);
            self::assertNotSame('', $violation['text']);
        }
    }

    /**
     * The fixer rewrites every global call with a leading backslash, so
     * `\…match(` is the form this codebase actually contains — and on PHP 8
     * that is a single name token rather than a separator plus a `T_STRING`.
     * A rule matching only `T_STRING` would pass over every real call site.
     *
     * @test
     */
    public function theBackslashPrefixedCallFormIsAlsoFlagged(): void
    {
        $dir = $this->makeTempDir();

        $pcreCall = '\\' . 'preg' . '_match';

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Evil;\n\n"
            . "final class Evil\n"
            . "{\n"
            . "    public function run(string \$s): void\n"
            . "    {\n"
            . '        ' . $pcreCall . "('/a/', \$s);\n"
            . "        \\getenv('HOME');\n"
            . "        \\unserialize(\$s);\n"
            . "    }\n"
            . "}\n";

        \file_put_contents($dir . '/Evil.php', $source);

        $found = [];

        foreach ($this->checks->run($dir, null) as $violation) {
            $found[] = $violation['rule'];
        }

        self::assertContains(StaticSecurityChecks::RULE_PCRE, $found);
        self::assertContains(StaticSecurityChecks::RULE_SUPERGLOBAL, $found);
        self::assertContains(StaticSecurityChecks::RULE_CODE_LOADING, $found);
    }

    /**
     * A namespaced call is not the global function the rules are about.
     *
     * @test
     */
    public function aNamespacedCallOfTheSameNameIsNotFlagged(): void
    {
        $dir = $this->makeTempDir();

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Fine;\n\n"
            . "final class Fine\n"
            . "{\n"
            . "    public function run(string \$s): void\n"
            . "    {\n"
            . "        Helper\\getenv(\$s);\n"
            . "    }\n"
            . "}\n";

        \file_put_contents($dir . '/Fine.php', $source);

        self::assertSame([], $this->checks->run($dir, null));
    }

    /**
     * A docblock that merely mentions a forbidden token is not a violation —
     * the tree contains several such mentions, which is why the engine parses
     * instead of grepping.
     *
     * @test
     */
    public function mentionsInCommentsAndStringsAreNotViolations(): void
    {
        $dir = $this->makeTempDir();

        $pcreWord = 'preg' . '_match';

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Fine;\n\n"
            . "/**\n"
            . ' * Mentions ' . $pcreWord . "() and \$_GET and eval in prose only.\n"
            . " */\n"
            . "final class Fine\n"
            . "{\n"
            . "    public function run(): string\n"
            . "    {\n"
            . '        // ' . $pcreWord . "() again, in a line comment\n"
            . "        return 'a literal mentioning " . $pcreWord . " and \$_POST';\n"
            . "    }\n"
            . "}\n";

        \file_put_contents($dir . '/Fine.php', $source);

        self::assertSame([], $this->checks->run($dir, null));
    }

    /**
     * R7 (decision ENG-A8): the engine is a pure function of its inputs, so a
     * clean engine file is the baseline every other R7 row is measured against.
     *
     * @test
     */
    public function aPureEngineFileSatisfiesR7(): void
    {
        $dir = $this->makeTempDir();

        $this->writeSource($dir, 'Engine/Pure.php', self::engineSource('$n = \\strlen($s);'));

        self::assertSame([], $this->enginePurityViolations($dir));
    }

    /**
     * Every forbidden call is reported with the file, the line and the function
     * name, in both the fixer's backslash form and the bare form.
     *
     * @test
     *
     * @dataProvider forbiddenEngineCalls
     */
    public function anImpureCallInTheEngineIsFlagged(string $statement, string $function): void
    {
        $dir = $this->makeTempDir();

        $this->writeSource($dir, 'Engine/X.php', self::engineSource($statement));

        $violations = $this->enginePurityViolations($dir);

        self::assertCount(1, $violations, self::describe('R7', $violations));
        self::assertSame(StaticSecurityChecks::RULE_ENGINE_PURITY, $violations[0]['rule']);
        self::assertSame('Engine/X.php', $violations[0]['file']);
        self::assertSame(11, $violations[0]['line']);
        self::assertSame($function . '()', $violations[0]['text']);
    }

    /**
     * Names are assembled from halves so this file never spells a call the rule
     * is about, the same discipline the other synthetic fixtures follow.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forbiddenEngineCalls(): array
    {
        return [
            'backslash time' => ['\\' . 'ti' . 'me();', 'ti' . 'me'],
            'bare time' => ['ti' . 'me();', 'ti' . 'me'],
            'file read' => ['$d = \\file_get' . '_contents($s);', 'file_get' . '_contents'],
            'file existence' => ['$d = \\file' . '_exists($s);', 'file' . '_exists'],
            'path resolution' => ['$d = \\real' . 'path($s);', 'real' . 'path'],
            'clock' => ['$d = \\micro' . 'time(true);', 'micro' . 'time'],
            'formatted date' => ['$d = \\da' . 'te(\'Y\');', 'da' . 'te'],
            'random integer' => ['$d = \\random' . '_int(1, 2);', 'random' . '_int'],
            'mersenne' => ['$d = \\mt' . '_rand();', 'mt' . '_rand'],
            'unique id' => ['$d = \\uniq' . 'id();', 'uniq' . 'id'],
            'environment' => ['$d = \\get' . 'env(\'HOME\');', 'get' . 'env'],
            'sleep' => ['\\us' . 'leep(1);', 'us' . 'leep'],
        ];
    }

    /**
     * `FilesystemImportResolver` is the engine's one sanctioned reader. The
     * exemption names that single file, so a sibling in the same directory is
     * judged like any other engine file.
     *
     * @test
     */
    public function theImportResolverIsExemptButItsSiblingsAreNot(): void
    {
        $dir = $this->makeTempDir();
        $read = self::engineSource('$d = \\file_get' . '_contents($s);');

        $this->writeSource($dir, 'Engine/Import/FilesystemImportResolver.php', $read);

        self::assertSame([], $this->enginePurityViolations($dir));

        $this->writeSource($dir, 'Engine/Import/Sibling.php', $read);

        $violations = $this->enginePurityViolations($dir);

        self::assertCount(1, $violations, self::describe('R7', $violations));
        self::assertSame('Engine/Import/Sibling.php', $violations[0]['file']);
    }

    /**
     * Token based, not grep based: a method or static call, a method declared
     * with a forbidden name, a namespaced function, a comment and a string
     * literal all lack a call to the global function.
     *
     * @test
     */
    public function methodsNamespacedFunctionsCommentsAndStringsAreNotR7Matches(): void
    {
        $dir = $this->makeTempDir();
        $time = 'ti' . 'me';
        $file = 'fi' . 'le';

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace TailwindPhp\\Engine;\n\n"
            . "/**\n"
            . ' * Mentions \\' . $time . "() in prose only.\n"
            . " */\n"
            . "final class Clock\n"
            . "{\n"
            . '    public function ' . $time . "(): int\n"
            . "    {\n"
            . '        // \\' . $time . "() again, in a line comment\n"
            . '        $a = $this->' . $file . "(1);\n"
            . '        $b = self::' . $time . "();\n"
            . '        $c = Helper\\' . $time . "();\n"
            . '        $d = \'\\' . $time . "()';\n"
            . "        return 1;\n"
            . "    }\n\n"
            . '    private function ' . $file . "(int \$n): int\n"
            . "    {\n"
            . "        return \$n;\n"
            . "    }\n"
            . "}\n";

        $this->writeSource($dir, 'Engine/Clock.php', $source);

        self::assertSame([], $this->enginePurityViolations($dir));
    }

    /**
     * R7 is about the engine only; the library's clocks and filesystem facade
     * live elsewhere and are supposed to make exactly these calls.
     *
     * @test
     */
    public function theSameCallOutsideTheEngineIsNotAnR7Violation(): void
    {
        $dir = $this->makeTempDir();

        $this->writeSource($dir, 'Support/Clock.php', self::engineSource('ti' . 'me();'));

        self::assertSame([], $this->enginePurityViolations($dir));
    }

    /** @test */
    public function aQualifiedOrImportedCatchIsAccepted(): void
    {
        $dir = $this->makeTempDir();

        $source = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace Fine;\n\n"
            . "use RuntimeException;\n\n"
            . "final class Fine\n"
            . "{\n"
            . "    public function run(): void\n"
            . "    {\n"
            . "        try {\n"
            . "            \$this->run();\n"
            . "        } catch (\\Throwable \$e) {\n"
            . "        }\n\n"
            . "        try {\n"
            . "            \$this->run();\n"
            . "        } catch (RuntimeException \$e) {\n"
            . "        }\n"
            . "    }\n"
            . "}\n";

        \file_put_contents($dir . '/Fine.php', $source);

        self::assertSame([], $this->checks->run($dir, null));
    }

    /** @test */
    public function anAbsolutePathInAFixtureManifestIsFlagged(): void
    {
        $dir = $this->makeTempDir();
        $manifests = $dir . '/manifests';

        \mkdir($manifests);
        \file_put_contents(
            $manifests . '/bad.json',
            '{"files":{"a.css":{"path":"/var/www/app/a.css"}}}'
        );

        $violations = $this->checks->run($dir, $manifests);
        $found = [];

        foreach ($violations as $violation) {
            $found[] = $violation['rule'];
        }

        self::assertContains(StaticSecurityChecks::RULE_MANIFEST_PATH, $found);
    }

    /** @test */
    public function aRelativeFixtureManifestIsAccepted(): void
    {
        $dir = $this->makeTempDir();
        $manifests = $dir . '/manifests';

        \mkdir($manifests);
        \file_put_contents(
            $manifests . '/good.json',
            '{"files":{"a.css":{"path":"assets/styles/a.css","m":1,"s":2}}}'
        );

        self::assertSame([], $this->checks->run($dir, $manifests));
    }

    /** @test */
    public function aMissingManifestDirectoryIsNotAFailure(): void
    {
        $dir = $this->makeTempDir();

        self::assertSame([], $this->checks->run($dir, $dir . '/does-not-exist'));
    }

    /**
     * @param string $rule
     *
     * @return list<array{rule: string, file: string, line: int, text: string}>
     */
    private function violationsFor(string $rule): array
    {
        $matching = [];

        foreach ($this->checks->run(self::repositoryRoot() . '/src', self::manifestDirectory()) as $violation) {
            if ($violation['rule'] === $rule) {
                $matching[] = $violation;
            }
        }

        return $matching;
    }

    /**
     * @param list<array{rule: string, file: string, line: int, text: string}> $violations
     */
    private static function describe(string $rule, array $violations): string
    {
        if ($violations === []) {
            return '';
        }

        $lines = [\sprintf('Static security check "%s" found %d violation(s):', $rule, \count($violations))];

        foreach ($violations as $violation) {
            $lines[] = \sprintf(
                '  %s:%d  %s: %s',
                $violation['file'],
                $violation['line'],
                $violation['rule'],
                $violation['text']
            );
        }

        return \implode("\n", $lines);
    }

    /**
     * Load the shared rule engine by path.
     *
     * `tools/` is maintainer tooling and deliberately not autoloaded
     * (design doc 17), and this is done inside a method rather than at file
     * scope so the file declares a symbol without also having side effects.
     */
    private static function loadEngine(): void
    {
        require_once __DIR__ . '/../../tools/StaticSecurityChecks.php';
    }

    private static function repositoryRoot(): string
    {
        return \str_replace('\\', '/', \dirname(__DIR__, 2));
    }

    private static function manifestDirectory(): string
    {
        return self::repositoryRoot() . '/tests/Fixtures/manifests';
    }

    private function makeTempDir(): string
    {
        $dir = TempTree::make('tailwindphp-checks');

        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * A minimal engine-shaped class whose body is one caller-chosen statement.
     *
     * The statement always lands on line 11, which the R7 rows assert on.
     */
    private static function engineSource(string $statement): string
    {
        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace TailwindPhp\\Engine;\n\n"
            . "final class X\n"
            . "{\n"
            . "    public function run(string \$s): string\n"
            . "    {\n"
            . '        ' . $statement . "\n"
            . "        return \$s;\n"
            . "    }\n"
            . "}\n";
    }

    private function writeSource(string $root, string $relative, string $source): void
    {
        $path = $root . '/' . $relative;
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            \mkdir($directory, 0777, true);
        }

        \file_put_contents($path, $source);
    }

    /**
     * @return list<array{rule: string, file: string, line: int, text: string}>
     */
    private function enginePurityViolations(string $root): array
    {
        $matching = [];

        foreach ($this->checks->run($root, null) as $violation) {
            if ($violation['rule'] === StaticSecurityChecks::RULE_ENGINE_PURITY) {
                $matching[] = $violation;
            }
        }

        return $matching;
    }
}
