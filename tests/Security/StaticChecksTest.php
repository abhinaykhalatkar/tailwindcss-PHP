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
}
