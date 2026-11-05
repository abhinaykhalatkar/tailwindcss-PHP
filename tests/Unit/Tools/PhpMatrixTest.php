<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\PhpMatrix;

/**
 * `tools/php-matrix.php`'s class (TASK-0056), with an injected runner: nothing
 * is spawned and no real PHP or composer runs. Covered: discovery from a fake
 * scoop tree, the manifest rewrite, the argument validation, the PHPUnit
 * summary parsing, the table, the exit codes, and `vendor/` moved aside and
 * always put back — on success, on a failure and on a thrown exception.
 */
final class PhpMatrixTest extends TestCase
{
    /**
     * @var string
     */
    private $root = '';

    /**
     * @var list<array<int, mixed>>
     */
    private $calls = [];

    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3) . '/tools/PhpMatrix.php';
    }

    protected function setUp(): void
    {
        $this->root = \str_replace('\\', '/', TempTree::make('php-matrix-'));
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    // ------------------------------------------------------------- arguments

    /** @test */
    public function theDefaultsRunEveryVersionWithTheKitFromTheEnvironment(): void
    {
        self::assertSame(
            [
                'only' => null,
                'skipInstall' => false,
                'list' => false,
                'noKit' => false,
                'kit' => null,
                'restore' => false,
                'noLowest' => false,
            ],
            PhpMatrix::parseArguments([])
        );
    }

    /** @test */
    public function everyOptionIsParsed(): void
    {
        $this->put('kit/config/tailwind.php', '<?php return [];');
        $options = PhpMatrix::parseArguments([
            '--only=8.5,7.3,8.5',
            '--skip-install',
            '--no-kit',
            '--list',
            '--restore',
            '--no-lowest',
            '--kit=' . $this->root . '/kit/',
        ]);

        self::assertSame(['8.5', '7.3'], $options['only']);
        self::assertTrue($options['skipInstall'] && $options['noKit'] && $options['list'] && $options['restore']);
        self::assertTrue($options['noLowest']);
        self::assertSame($this->root . '/kit', $options['kit']);
    }

    /**
     * @test
     *
     * @dataProvider badArguments
     */
    public function aBadArgumentIsRefused(string $argument): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PhpMatrix::parseArguments([\str_replace('{root}', $this->root, $argument)]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badArguments(): array
    {
        return [
            'a patch version' => ['--only=7.3.33'],
            'an unknown minor' => ['--only=9.0'],
            'an empty list' => ['--only='],
            'a traversal in --only' => ['--only=../8.2'],
            'a missing kit' => ['--kit={root}/nope'],
            'a kit with a traversal' => ['--kit={root}/../x'],
            'a kit with a wrapper' => ['--kit=phar://{root}'],
            'an empty kit' => ['--kit='],
            'a control byte' => ["--only=8.2\n"],
            'a NUL byte' => ["--kit={root}\0"],
            'an unknown option' => ['--fast'],
            'a positional argument' => ['8.2'],
        ];
    }

    // ------------------------------------------------------------- discovery

    /** @test */
    public function discoveryAsksEachBinaryForItsVersion(): void
    {
        $this->put('scoop/apps/php73/current/php.exe', '');
        $this->put('scoop/apps/php82/current/php.exe', '');
        $this->put('scoop/apps/php84/current/php.exe', '');
        $this->put('scoop/apps/php99/current/php.exe', '');
        $this->put('scoop/apps/phpfoo/current/php.exe', '');
        $this->put('xampp/php.exe', '');

        $versions = [
            'php73' => '7.3.33',
            'php82' => '8.2.35',
            // Answers another minor than its directory says: refused.
            'php84' => '8.0.30',
            'xampp' => '8.2.12',
        ];
        $runner = function (array $argv, string $cwd, array $env, int $timeout) use ($versions): array {
            $this->calls[] = [$argv, $cwd, $env];

            foreach ($versions as $key => $version) {
                if (\strpos($argv[0], '/' . $key . '/') !== false) {
                    return ['exit' => 0, 'output' => $version, 'seconds' => 0.0];
                }
            }

            return ['exit' => 1, 'output' => '', 'seconds' => 0.0];
        };

        $found = PhpMatrix::discover($this->root . '/scoop/apps', $this->root . '/xampp/php.exe', $runner, $this->root);

        self::assertSame(['7.3', '8.2'], \array_keys($found['found']));
        self::assertSame('scoop', $found['found']['8.2']['source'], 'scoop is preferred over XAMPP for the same minor');
        self::assertNotNull($found['xampp']);
        self::assertSame('8.2.12', $found['xampp']['version']);
        self::assertCount(1, $found['refused']);
        self::assertStringContainsString('answers 8.0.30, not 8.4', $found['refused'][0]);
        $asked = \array_slice($this->calls[0][0], 1);
        self::assertSame(['-n', '-r', 'echo PHP_VERSION;'], $asked, 'no ini, one argument array');
    }

    /** @test */
    public function xamppFillsAMinorScoopLacks(): void
    {
        $this->put('xampp/php.exe', '');
        $runner = static function (): array {
            return ['exit' => 0, 'output' => '8.2.12', 'seconds' => 0.0];
        };

        $found = PhpMatrix::discover($this->root . '/scoop/apps', $this->root . '/xampp/php.exe', $runner, $this->root);

        self::assertSame('xampp', $found['found']['8.2']['source']);
    }

    // ----------------------------------------------------------------- floor

    /**
     * @test
     *
     * @dataProvider constraints
     */
    public function theFloorIsTheLowestVersionTheConstraintAllows(string $constraint, ?string $floor): void
    {
        self::assertSame($floor, PhpMatrix::floorOf($constraint));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function constraints(): array
    {
        return [
            'at least a minor' => ['>=8.2', '8.2.0'],
            'the old floor' => ['>=7.3', '7.3.0'],
            'at least a patch' => ['>=8.2.35', '8.2.35'],
            'a caret' => ['^8.2', '8.2.0'],
            'a tilde patch' => ['~8.3.1', '8.3.1'],
            'a wildcard' => ['8.2.*', '8.2.0'],
            'a spaced range' => ['>= 8.2 <9.0', '8.2.0'],
            'a comma range' => ['>=8.2,<9', '8.2.0'],
            'two lower bounds' => ['>=8.1 >=8.2.3', '8.2.3'],
            'a strict patch bound' => ['>8.2.35', '8.2.36'],
            'a strict minor bound' => ['>8.2', '8.2.1'],
            'a bounded range' => ['>=8.2 <8.4', '8.2.0'],
            'alternatives' => ['^8.3 || ^8.2', '8.2.0'],
            'single bar' => ['^8.4|^8.3', '8.3.0'],
            'anything' => ['*', '0.0.0'],
            'only an upper bound' => ['<9', '0.0.0'],
            'a branch' => ['dev-main', null],
            'empty' => ['', null],
            'a stability flag' => ['>=8.2@dev', null],
        ];
    }

    /** @test */
    public function aPatchBelowTheFloorIsUnsupported(): void
    {
        self::assertTrue(PhpMatrix::allows('>=8.2', '8.2.35'));
        self::assertTrue(PhpMatrix::allows('>=8.2.35', '8.2.35'));
        self::assertFalse(PhpMatrix::allows('>=8.2.35', '8.2.12'));
        self::assertFalse(PhpMatrix::allows('>=8.2', '8.1.34'));
    }

    /**
     * @test
     *
     * @dataProvider allowed
     */
    public function theWholeConstraintDecidesWhichVersionsAreSupported(
        string $constraint,
        string $version,
        bool $allowed
    ): void {
        self::assertSame($allowed, PhpMatrix::allows($constraint, $version));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function allowed(): array
    {
        return [
            'at the floor' => ['>=8.2', '8.2.0', true],
            'below the floor' => ['>=8.2', '8.1.34', false],
            'a strict bound excludes itself' => ['>8.2.35', '8.2.35', false],
            'a strict bound allows the next patch' => ['>8.2.35', '8.2.36', true],
            'above an upper bound' => ['>=8.2 <8.4', '8.4.27', false],
            'inside a bounded range' => ['>=8.2 <8.4', '8.3.36', true],
            'a comma range' => ['>=8.2,<8.4', '8.4.0', false],
            'an inclusive upper bound' => ['>=8.2 <=8.4.27', '8.4.27', true],
            'a caret up to the next major' => ['^8.2', '8.5.12', true],
            'a caret stops at the next major' => ['^8.2', '9.0.0', false],
            'a tilde patch stops at the next minor' => ['~8.2.35', '8.3.0', false],
            'a tilde patch' => ['~8.2.35', '8.2.40', true],
            'a tilde minor' => ['~8.2', '8.9.0', true],
            'a wildcard stops at the next minor' => ['8.2.*', '8.3.0', false],
            'a wildcard' => ['8.2.*', '8.2.35', true],
            'either alternative' => ['^8.3 || ^8.2', '8.2.35', true],
            'neither alternative' => ['^8.4 || ^8.3', '8.2.35', false],
            'an excluded version' => ['>=8.2 !=8.3.0', '8.3.0', false],
            'anything' => ['*', '7.3.33', true],
            'an exact version' => ['=8.2.35', '8.2.35', true],
            'another exact version' => ['8.2.35', '8.2.36', false],
            'only an upper bound' => ['<9', '8.5.12', true],
            'unreadable' => ['dev-main', '8.2.35', false],
        ];
    }

    /** @test */
    public function theFloorAnd84GetALowestCellBeforeTheirHighestOne(): void
    {
        $cells = PhpMatrix::cells(['8.2', '8.3', '8.4', '8.5'], '8.2.0', true);

        self::assertSame(
            [
                ['minor' => '8.2', 'lowest' => true],
                ['minor' => '8.2', 'lowest' => false],
                ['minor' => '8.3', 'lowest' => false],
                ['minor' => '8.4', 'lowest' => true],
                ['minor' => '8.4', 'lowest' => false],
                ['minor' => '8.5', 'lowest' => false],
            ],
            $cells
        );
        self::assertSame(
            [['minor' => '8.3', 'lowest' => true], ['minor' => '8.3', 'lowest' => false]],
            PhpMatrix::cells(['8.3'], '8.3.0', true),
            'a floor of 8.3 makes 8.3 the lowest cell'
        );
        self::assertCount(4, PhpMatrix::cells(['8.2', '8.3', '8.4', '8.5'], '8.2.0', false));
    }

    // -------------------------------------------------------------- manifest

    /** @test */
    public function theManifestCopyPrefixesTheAutoloadPathsAndDropsTheScripts(): void
    {
        $manifest = self::manifest();
        $copy = PhpMatrix::rewriteManifest($manifest);
        $json = (string) \json_encode($copy, \JSON_UNESCAPED_SLASHES);

        self::assertSame('../../src/', $copy->autoload->{'psr-4'}->{'TailwindPhp\\'});
        self::assertSame(['../../src/functions.php'], $copy->autoload->files);
        self::assertSame('../../tests/', $copy->{'autoload-dev'}->{'psr-4'}->{'TailwindPhp\\Tests\\'});
        self::assertSame(['../../lib/', '../../more/'], $copy->autoload->{'psr-4'}->{'Other\\'});
        self::assertObjectNotHasProperty('scripts', $copy);
        self::assertStringContainsString('"allow-plugins":{}', $json, 'an empty object stays an object');
        self::assertSame('dev-main', $copy->{'require-dev'}->{'example/tool'});

        // Nothing else changed.
        unset($manifest->scripts);
        $manifest->autoload = $copy->autoload;
        $manifest->{'autoload-dev'} = $copy->{'autoload-dev'};
        self::assertSame(\json_encode($manifest), \json_encode($copy));
        self::assertObjectHasProperty('scripts', self::manifest(), 'the input is not modified');
    }

    // ------------------------------------------------------------------- ini

    /** @test */
    public function theIniEnablesOnlyTheExtensionsThatShipADll(): void
    {
        $this->put('php85/ext/php_openssl.dll', '');
        $this->put('php85/ext/php_mbstring.dll', '');
        $this->put('php85/php.exe', '');

        $ini = PhpMatrix::ini($this->root . '/php85/php.exe');

        self::assertStringContainsString('extension_dir = "' . $this->root . '/php85/ext"', $ini);
        self::assertStringContainsString("memory_limit = -1\n", $ini);
        self::assertStringContainsString("extension = openssl\n", $ini);
        self::assertStringContainsString("extension = mbstring\n", $ini);
        self::assertStringNotContainsString('curl', $ini);
        self::assertStringNotContainsString('zip', $ini);
    }

    // ------------------------------------------------------- phpunit summary

    /**
     * @test
     *
     * @dataProvider summaries
     *
     * @param array{status: string, run: int, failures: int, errors: int, skipped: int} $expected
     */
    public function thePhpunitSummaryIsRead(string $output, int $exit, array $expected): void
    {
        $summary = PhpMatrix::testSummary($output, $exit, 12.5);

        self::assertSame($expected, [
            'status' => $summary['status'],
            'run' => $summary['run'],
            'failures' => $summary['failures'],
            'errors' => $summary['errors'],
            'skipped' => $summary['skipped'],
        ]);
        self::assertSame(12.5, $summary['seconds']);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: array<string, int|string>}>
     */
    public static function summaries(): array
    {
        return [
            'green' => [
                "...\n\nOK (7553 tests, 5191811 assertions)\n",
                0,
                ['status' => 'ok', 'run' => 7553, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
            ],
            'green with issues' => [
                "OK, but there were issues!\n"
                    . "Tests: 7553, Assertions: 5191811, PHPUnit Deprecations: 2502, Skipped: 30.\n",
                0,
                ['status' => 'ok', 'run' => 7553, 'failures' => 0, 'errors' => 0, 'skipped' => 30],
            ],
            'failures, coloured' => [
                "\x1b[37;41mFAILURES!\x1b[0m\n\x1b[37;41mTests: 7553\x1b[0m\x1b[37;41m, Assertions: 5\x1b[0m"
                    . "\x1b[37;41m, Failures: 9\x1b[0m\x1b[37;41m, Errors: 2\x1b[0m\x1b[37;41m, Skipped: 30\x1b[0m"
                    . "\x1b[37;41m.\x1b[0m\n",
                1,
                ['status' => 'failed', 'run' => 7553, 'failures' => 9, 'errors' => 2, 'skipped' => 30],
            ],
            'a crash before the summary' => [
                "PHP Fatal error: …\n",
                255,
                ['status' => 'not run', 'run' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
            ],
        ];
    }

    /** @test */
    public function thePhpunitVersionComesFromTheBanner(): void
    {
        $banner = "PHPUnit 9.6.20 by Sebastian Bergmann and contributors.\n";
        self::assertSame('PHPUnit 9.6.20', PhpMatrix::phpunitVersion($banner));
        self::assertSame('', PhpMatrix::phpunitVersion('no banner'));
    }

    // ------------------------------------------------------------------ table

    /** @test */
    public function theTableHasOneLinePerVersionAndTheFindingsBelow(): void
    {
        $rows = [
            self::row('8.2', '8.2.35', false, ['tests (Tests: 10, Failures: 1.):' . "\nThere was 1 failure:"]),
            self::row('8.3', '8.3.36', true, []),
        ];
        $rows[0]['lowest'] = true;
        $rows[1]['stan'] = 'ok';

        $expected = 'version             | resolved       | lint     | tests (run/fail/err/skip, s) | fixture   '
            . "| kit     | phpstan | result\n"
            . '8.2 (8.2.35) lowest | PHPUnit 9.6.20 | ok (480) | 10/1/0/2, 95                 | identical '
            . "| skipped |         | FAIL\n"
            . '8.3 (8.3.36)        | PHPUnit 9.6.20 | ok (480) | 10/1/0/2, 95                 | identical '
            . "| skipped | ok      | PASS\n"
            . "\n[8.2 lowest] tests (Tests: 10, Failures: 1.):\nThere was 1 failure:\n";

        self::assertSame($expected, PhpMatrix::render($rows));
        self::assertSame(1, PhpMatrix::exitCode($rows));
        self::assertSame(0, PhpMatrix::exitCode([$rows[1]]));
    }

    // ------------------------------------------------------------- whole run

    /** @test */
    public function aGreenRunMovesVendorAsideAndPutsItBack(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        $exit = $matrix->execute(['--only=8.2,8.3', '--no-kit'], $this->root . '/scoop/apps', null);

        self::assertSame(0, $exit, $out);
        self::assertFileExists($this->root . '/repo/vendor/original.txt', 'the original vendor/ is back');
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/vendor-current');
        $parked = $this->root . '/repo/.matrix/php82-lowest/vendor/installed-by-8.2.txt';
        self::assertFileExists($parked, 'each set is parked');
        self::assertFileExists($this->root . '/repo/.matrix/php82/vendor/installed-by-8.2.txt');
        self::assertFileExists($this->root . '/repo/.matrix/php83/vendor/installed-by-8.3.txt');
        self::assertStringContainsString('8.2 (8.2.35) lowest', $out);
        self::assertStringContainsString('PHPUnit 9.6.20', $out);
        self::assertStringNotContainsString($this->root . '/repo', $out, 'the repository root prints as "."');
    }

    /** @test */
    public function eachStepIsAnArgumentArrayWithTheCellsIni(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);
        $matrix->execute(['--only=8.2,8.3', '--no-kit'], $this->root . '/scoop/apps', null);

        $composer = $this->callsFor('composer.phar');
        $php82 = $this->root . '/scoop/apps/php82/current/php.exe';
        $lowest = $this->root . '/repo/.matrix/php82-lowest';

        self::assertCount(3, $composer, '8.2 lowest, 8.2, 8.3');
        self::assertSame(
            [
                $php82,
                PhpMatrix::COMPOSER,
                'update',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
                '--no-plugins',
                '--no-scripts',
                '--prefer-lowest',
                '--prefer-stable',
            ],
            $composer[0][0],
            'the floor resolves lowest first'
        );
        self::assertNotContains('--prefer-lowest', $composer[1][0], 'then 8.2 highest');
        self::assertNotContains('--prefer-lowest', $composer[2][0], '8.3 resolves highest only');
        self::assertSame($lowest, $composer[0][1]);
        self::assertSame($this->root . '/repo/.matrix/php82', $composer[1][1]);
        self::assertSame('composer.json', $composer[0][2]['COMPOSER']);
        self::assertSame($this->root . '/repo/vendor', $composer[0][2]['COMPOSER_VENDOR_DIR']);
        self::assertSame($lowest, $composer[0][2]['PHPRC']);
        self::assertSame('', $composer[0][2]['PHP_INI_SCAN_DIR']);
        self::assertSame('kept', $composer[0][2]['INHERITED'], 'the environment is passed on');
        self::assertArrayNotHasKey('COMPOSER_NO_SECURITY_BLOCKING', $composer[0][2], 'the advisory filter stays on');
        self::assertArrayNotHasKey('COMPOSER_NO_SECURITY_BLOCKING', $composer[2][2]);
        self::assertFileExists($lowest . '/php.ini');

        $manifest = \json_decode((string) \file_get_contents($lowest . '/composer.json'));
        self::assertInstanceOf(\stdClass::class, $manifest);
        self::assertSame('../../src/', $manifest->autoload->{'psr-4'}->{'TailwindPhp\\'});

        $phpunit = $this->callsFor('vendor/bin/phpunit');
        self::assertSame([$php82, 'vendor/bin/phpunit', '--testsuite', PhpMatrix::SUITES], $phpunit[0][0]);
        self::assertSame(PhpMatrix::TEST_TIMEOUT, $phpunit[0][3], 'the suites get the longer limit');
        self::assertSame(PhpMatrix::TIMEOUT, $composer[0][3]);
        self::assertSame($this->root . '/repo', $phpunit[0][1]);

        $lint = $this->callsFor('-l');
        $linted = \array_values(\array_unique(\array_map(static function (array $call): string {
            return (string) $call[0][2];
        }, $lint)));
        // src, tools and bin; not the fixer's install, not a .txt.
        self::assertSame(['bin/tool', 'src/A.php', 'tools/B.php'], $linted);

        self::assertCount(2, $this->callsFor('vendor/bin/phpstan'), 'PHPStan only on 8.3, both configs');
        self::assertCount(3, $this->callsFor('tools/compare-drivers.php'), 'the fixture per cell only: --no-kit');
    }

    /** @test */
    public function theKitIsComparedWhenGiven(): void
    {
        $this->repository();
        $this->put('kit/config/tailwind.php', '<?php return [];');
        $matrix = $this->matrix($this->runner([]), $out);
        $matrix->execute(['--only=8.3', '--kit=' . $this->root . '/kit'], $this->root . '/scoop/apps', null);

        $compare = $this->callsFor('tools/compare-drivers.php');
        self::assertCount(2, $compare);
        self::assertSame($this->root . '/kit', $compare[1][0][2]);
    }

    /** @test */
    public function aFailingCellFailsTheRunWithItsFinding(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner(['vendor/bin/phpunit' => [
            'exit' => 1,
            'output' => "PHPUnit 9.6.20 by Sebastian Bergmann\n\nThere was 1 failure:\n\n1) A::b\n"
                . "Failed asserting that false is true.\n"
                . $this->root . "/repo/tests/A.php:12\n\nFAILURES!\nTests: 3, Assertions: 3, Failures: 1.\n",
        ]]), $out);

        self::assertSame(1, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('[8.2] tests (Tests: 3, Assertions: 3, Failures: 1.):', $out);
        self::assertStringContainsString('Failed asserting that false is true.', $out);
        self::assertStringContainsString('./tests/A.php:12', $out);
        self::assertFileExists($this->root . '/repo/vendor/original.txt');

        $early = \strpos($out, '   finding: tests (Tests: 3, Assertions: 3, Failures: 1.):');
        self::assertNotFalse($early, 'the finding is printed as soon as the cell ends');
        self::assertLessThan(\strpos($out, "\nversion "), $early, 'before the final table');
    }

    /** @test */
    public function aVersionBelowTheFloorIsListedAndNeverRun(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        $exit = $matrix->execute(['--only=7.3,8.2', '--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null);

        self::assertSame(0, $exit, $out);
        $php73 = $this->root . '/scoop/apps/php73/current/php.exe';

        foreach ($this->calls as $call) {
            self::assertTrue($call[0][0] !== $php73 || ($call[0][1] ?? '') === '-n', '7.3 is only asked its version');
        }

        $line = "unsupported (composer.json requires php >=8.2), skipped: 7.3 (7.3.33)\n";
        self::assertSame(2, \substr_count($out, $line), 'before the cells and under the table');
        self::assertCount(1, $this->callsFor('composer.phar'));
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/php73');
    }

    /** @test */
    public function theWholeMatrixRunsTheSupportedVersionsWithTheirLowestCells(): void
    {
        $this->repository();
        $this->put('scoop/apps/php85/current/php.exe', '');
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(0, $matrix->execute(['--no-kit'], $this->root . '/scoop/apps', null), $out);
        $resolutions = \array_map(static function (array $call): string {
            return \basename((string) $call[1]) . (\in_array('--prefer-lowest', $call[0], true) ? ' lowest' : '');
        }, $this->callsFor('composer.phar'));

        self::assertSame(
            ['php82-lowest lowest', 'php82', 'php83', 'php84-lowest lowest', 'php84', 'php85'],
            $resolutions,
            '7.3 is skipped; 7.4, 8.0 and 8.1 are unsupported and not installed, so not missing'
        );
        self::assertStringContainsString('8.4 (8.4.27) lowest', $out);
    }

    /** @test */
    public function onlyUnsupportedVersionsIsAnEnvironmentError(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=7.3'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('No supported version selected (composer.json requires php >=8.2).', $out);
        self::assertCount(0, $this->callsFor('composer.phar'));
    }

    /** @test */
    public function aManifestWithoutAPhpConstraintIsAnEnvironmentError(): void
    {
        $this->repository();
        $this->put('repo/composer.json', '{"require": {"ext-json": "*"}}');
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('composer.json has no readable require.php constraint.', $out);
    }

    /** @test */
    public function aResolutionFailureIsAFindingWithoutARetry(): void
    {
        $this->repository();
        $failing = [PhpMatrix::COMPOSER => ['exit' => 2, 'output' => "Your requirements could not be resolved.\n"]];
        $matrix = $this->matrix($this->runner($failing), $out);

        $exit = $matrix->execute(['--only=8.2', '--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null);
        self::assertSame(1, $exit);
        self::assertCount(1, $this->callsFor('composer.phar'));
        self::assertCount(0, $this->callsFor('vendor/bin/phpunit'), 'nothing runs on an unresolved set');
        self::assertStringContainsString("composer update failed:\nYour requirements could not be resolved.", $out);
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
    }

    /** @test */
    public function aThrownExceptionStillPutsVendorBack(): void
    {
        $this->repository();
        $runner = $this->runner([]);
        $throwing = function (array $argv, string $cwd, array $env, int $timeout) use ($runner): array {
            if (($argv[1] ?? '') === 'vendor/bin/phpunit') {
                throw new \RuntimeException('the runner broke in ' . $this->root . '/repo');
            }

            return $runner($argv, $cwd, $env, $timeout);
        };
        $matrix = $this->matrix($throwing, $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertFileExists($this->root . '/repo/vendor/original.txt', 'the original vendor/ is back');
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/vendor-current');
        self::assertStringContainsString('Stopped: the runner broke in .', $out);
    }

    /** @test */
    public function anInterruptedRunIsRefusedUntilRestored(): void
    {
        $this->repository();
        // A crash left the 8.2 set in vendor/ and the original aside.
        \rename($this->root . '/repo/vendor', $this->root . '/repo/.matrix-original');
        $this->put('repo/vendor/installed-by-8.2.txt', '');
        \mkdir($this->root . '/repo/.matrix', 0o755, true);
        \rename($this->root . '/repo/.matrix-original', $this->root . '/repo/.matrix/vendor-current');

        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('--restore', $out);
        self::assertSame(0, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null));
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
        self::assertFileExists($this->root . '/repo/.matrix/vendor-interrupted/installed-by-8.2.txt');
        $again = $matrix->execute(['--restore'], $this->root . '/scoop/apps', null);
        self::assertSame(0, $again, 'nothing left to restore');
    }

    /** @test */
    public function skipInstallUsesTodaysVendorAndResolvesNothing(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        $exit = $matrix->execute(['--only=8.2', '--skip-install', '--no-kit'], $this->root . '/scoop/apps', null);
        self::assertSame(0, $exit);
        self::assertCount(0, $this->callsFor('composer.phar'));
        self::assertCount(1, $this->callsFor('vendor/bin/phpunit'), 'no lowest cell without a resolution');
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/vendor-current');
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
    }

    /** @test */
    public function aMissingVersionIsAnEnvironmentError(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.5'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('PHP 8.5 not found', $out);
    }

    /** @test */
    public function theListingNamesEveryMinor(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(0, $matrix->execute(['--list'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('7.3  7.3.33', $out);
        self::assertStringContainsString("/php73/current/php.exe (unsupported, skipped)\n", $out);
        self::assertStringContainsString("/php82/current/php.exe\n", $out);
        self::assertStringContainsString("8.5  missing\n", $out);
    }

    /** @test */
    public function aBadArgumentExitsTwoWithTheUsage(): void
    {
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=6.0'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('Usage:', $out);
    }

    // ------------------------------------------------------- round 2 (review)

    /** @test */
    public function inheritedComposerAndIniVariablesAreDroppedWhateverTheirCase(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out, [
            'Composer_No_Security_Blocking' => '1',
            'composer_no_security_blocking' => '1',
            'composer' => 'elsewhere.json',
            'Composer_Vendor_Dir' => 'C:/elsewhere/vendor',
            'phprc' => 'C:/elsewhere',
            'Php_Ini_Scan_Dir' => 'C:/elsewhere/conf.d',
        ]);

        $exit = $matrix->execute(['--only=8.2', '--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null);
        self::assertSame(0, $exit, $out);

        $composer = $this->callsFor('composer.phar');
        $phpunit = $this->callsFor('vendor/bin/phpunit');
        self::assertCount(1, $composer);
        self::assertCount(1, $phpunit);

        foreach ([$composer[0][2], $phpunit[0][2]] as $index => $env) {
            $counts = self::upperCaseNames($env);
            self::assertArrayNotHasKey('COMPOSER_NO_SECURITY_BLOCKING', $counts, 'no spelling survives');
            self::assertSame(1, $counts['PHPRC'], 'one PHPRC, the tool\'s');
            self::assertSame(1, $counts['PHP_INI_SCAN_DIR']);
            self::assertSame('', $env['PHP_INI_SCAN_DIR']);
            self::assertSame('kept', $env['INHERITED']);

            if ($index === 0) {
                self::assertSame(1, $counts['COMPOSER']);
                self::assertSame(1, $counts['COMPOSER_VENDOR_DIR']);
                self::assertSame('composer.json', $env['COMPOSER']);
                self::assertSame($this->root . '/repo/vendor', $env['COMPOSER_VENDOR_DIR']);
            } else {
                self::assertArrayNotHasKey('COMPOSER', $counts, 'the suites get no composer target');
                self::assertArrayNotHasKey('COMPOSER_VENDOR_DIR', $counts);
            }
        }
    }

    /** @test */
    public function aLockHeldByAnotherRunStopsTheRunAndRestoreAndIsNeverBroken(): void
    {
        $this->repository();
        $this->put('repo/.matrix/lock', "4242\n");
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('.matrix/lock (pid 4242)', $out);
        self::assertStringContainsString('--restore', $out);
        self::assertSame(2, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null));
        self::assertCount(0, $this->callsFor('composer.phar'));
        self::assertFileExists($this->root . '/repo/vendor/original.txt', 'vendor/ untouched');
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/vendor-current');
        self::assertSame("4242\n", (string) \file_get_contents($this->root . '/repo/.matrix/lock'), 'never broken');
    }

    /** @test */
    public function theLockIsHeldForTheWholeRunAndReleasedAfterwards(): void
    {
        $this->repository();
        $runner = $this->runner([]);
        $held = [];
        $watching = function (array $argv, string $cwd, array $env, int $timeout) use ($runner, &$held): array {
            if (($argv[1] ?? '') !== '-n') {
                $held[] = \is_file($this->root . '/repo/.matrix/lock');
            }

            return $runner($argv, $cwd, $env, $timeout);
        };
        $matrix = $this->matrix($watching, $out);

        self::assertSame(0, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null), $out);
        self::assertNotSame([], $held);
        self::assertNotContains(false, $held, 'every step runs under the lock');
        self::assertFileDoesNotExist($this->root . '/repo/.matrix/lock');
        self::assertSame(0, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null), 'released');
    }

    /** @test */
    public function aFailedRestoreExitsTwoAndKeepsTheOriginalAside(): void
    {
        $this->repository();
        $runner = $this->runner([]);
        $hijacking = function (array $argv, string $cwd, array $env, int $timeout) use ($runner): array {
            if (($argv[1] ?? '') === 'vendor/bin/phpunit' && \is_dir($this->root . '/repo/vendor')) {
                // Something else takes vendor/ during the run and leaves a file in its place.
                \rename($this->root . '/repo/vendor', $this->root . '/elsewhere-vendor');
                \file_put_contents($this->root . '/repo/vendor', 'not a directory');
            }

            return $runner($argv, $cwd, $env, $timeout);
        };
        $matrix = $this->matrix($hijacking, $out);

        $exit = $matrix->execute(['--only=8.2', '--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null);
        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('vendor/ is not a plain directory', $out);
        self::assertStringContainsString("vendor/ was not restored: fix the cause above, then run --restore.\n", $out);
        self::assertFileExists($this->root . '/repo/.matrix/vendor-current/original.txt', 'the original is kept aside');
        self::assertFileDoesNotExist($this->root . '/repo/.matrix/lock');
    }

    /** @test */
    public function aVendorThatIsNotAPlainDirectoryIsRefused(): void
    {
        $this->repository();
        TempTree::remove($this->root . '/repo/vendor');
        $this->put('repo/vendor', 'a file where vendor/ should be');
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('vendor/ is not a plain directory', $out);
        self::assertCount(0, $this->callsFor('composer.phar'));
        self::assertSame('a file where vendor/ should be', (string) \file_get_contents($this->root . '/repo/vendor'));
    }

    /** @test */
    public function aMatrixDirectoryThatIsNotAPlainDirectoryIsRefused(): void
    {
        $this->repository();
        $this->put('repo/.matrix', 'a file where .matrix/ should be');
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('.matrix/ is not a plain directory', $out);
        self::assertSame(2, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null));
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
    }

    /** @test */
    public function aSymlinkedVendorIsRefused(): void
    {
        $this->repository();
        TempTree::remove($this->root . '/repo/vendor');
        $this->put('real-vendor/kept.txt', '');

        if (!@\symlink($this->root . '/real-vendor', $this->root . '/repo/vendor')) {
            self::markTestSkipped('symlink() needs elevation on this host; the file form above covers the branch.');
        }

        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('vendor/ is not a plain directory', $out);
        self::assertFileExists($this->root . '/real-vendor/kept.txt');
    }

    /** @test */
    public function restoreRemovesALinkedLeftoverAsALinkAndNeverEmptiesItsTarget(): void
    {
        $this->repository();
        // An interrupted run: the 8.2 set in vendor/, the original aside.
        \rename($this->root . '/repo/vendor', $this->root . '/original-vendor');
        $this->put('repo/vendor/installed-by-8.2.txt', '');
        \mkdir($this->root . '/repo/.matrix', 0o755, true);
        \rename($this->root . '/original-vendor', $this->root . '/repo/.matrix/vendor-current');
        $this->put('outside/precious.txt', 'keep me');
        $this->put('outside-inner/precious.txt', 'keep me too');

        $rootLink = @\symlink($this->root . '/outside', $this->root . '/repo/.matrix/vendor-interrupted');

        if (!$rootLink) {
            self::markTestSkipped('symlink() needs elevation on this host; the plain-directory check covers junctions');
        }

        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(0, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null), $out);
        self::assertSame('keep me', (string) \file_get_contents($this->root . '/outside/precious.txt'));
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
        self::assertFileExists($this->root . '/repo/.matrix/vendor-interrupted/installed-by-8.2.txt');

        // Below the root too: a link inside a leftover is removed, its target left alone.
        \rename($this->root . '/repo/vendor', $this->root . '/original-vendor');
        $this->put('repo/vendor/installed-by-8.3.txt', '');
        \rename($this->root . '/original-vendor', $this->root . '/repo/.matrix/vendor-current');
        self::assertTrue(@\symlink(
            $this->root . '/outside-inner',
            $this->root . '/repo/.matrix/vendor-interrupted/inner'
        ));

        self::assertSame(0, $matrix->execute(['--restore'], $this->root . '/scoop/apps', null), $out);
        self::assertSame('keep me too', (string) \file_get_contents($this->root . '/outside-inner/precious.txt'));
        self::assertFileExists($this->root . '/repo/.matrix/vendor-interrupted/installed-by-8.3.txt');
    }

    /** @test */
    public function aKitFromTheEnvironmentThatDoesNotExistExitsTwoWithoutATrace(): void
    {
        $this->repository();
        $matrix = $this->matrix($this->runner([]), $out, [PhpMatrix::KIT_ENV => $this->root . '/nowhere']);

        self::assertSame(2, $matrix->execute(['--only=8.2'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString('TAILWINDPHP_MATRIX_KIT must name an existing directory', $out);
        self::assertStringNotContainsString('#0 ', $out, 'no stack trace');
        self::assertCount(0, $this->callsFor('composer.phar'));
        self::assertFileExists($this->root . '/repo/vendor/original.txt');
    }

    /** @test */
    public function anErrorFromTheRunnerIsOneScrubbedLineAndVendorIsRestored(): void
    {
        $this->repository();
        $runner = $this->runner([]);
        $failing = function (array $argv, string $cwd, array $env, int $timeout) use ($runner): array {
            if (($argv[1] ?? '') === 'vendor/bin/phpunit') {
                throw new \Error('Call to undefined function in ' . $this->root . "/repo/x.php\nsecond line");
            }

            return $runner($argv, $cwd, $env, $timeout);
        };
        $matrix = $this->matrix($failing, $out);

        self::assertSame(2, $matrix->execute(['--only=8.2', '--no-kit'], $this->root . '/scoop/apps', null));
        self::assertStringContainsString("Stopped: Call to undefined function in ./x.php\n", $out);
        self::assertStringNotContainsString('second line', $out);
        self::assertFileExists($this->root . '/repo/vendor/original.txt', 'the original vendor/ is back');
        self::assertDirectoryDoesNotExist($this->root . '/repo/.matrix/vendor-current');
        self::assertFileDoesNotExist($this->root . '/repo/.matrix/lock');
    }

    /** @test */
    public function anErrorDuringDiscoveryIsOneLineAndExitTwo(): void
    {
        $this->repository();
        $failing = static function (): array {
            throw new \Error('discovery broke');
        };
        $matrix = $this->matrix($failing, $out);

        self::assertSame(2, $matrix->execute(['--list'], $this->root . '/scoop/apps', null));
        self::assertSame("Stopped: discovery broke\n", $out);
    }

    /** @test */
    public function anUpperBoundListsTheVersionsAboveItAsUnsupported(): void
    {
        $this->repository();
        $manifest = self::manifest();
        $manifest->require->php = '>=8.2 <8.4';
        $this->put('repo/composer.json', (string) \json_encode($manifest, \JSON_UNESCAPED_SLASHES));
        $matrix = $this->matrix($this->runner([]), $out);

        self::assertSame(0, $matrix->execute(['--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null), $out);
        $cells = \array_map(static function (array $call): string {
            return \basename((string) $call[1]);
        }, $this->callsFor('composer.phar'));
        self::assertSame(['php82', 'php83'], $cells);
        self::assertStringContainsString(
            'unsupported (composer.json requires php >=8.2 <8.4), skipped: 7.3 (7.3.33), 8.4 (8.4.27)',
            $out
        );
        self::assertStringNotContainsString('8.5 not found', $out, 'a version above the bound is not missing');
    }

    /** @test */
    public function pathsArePrintedShortWhateverTheirCaseOrForm(): void
    {
        $this->repository();
        $repo = $this->root . '/repo';

        if (($repo[1] ?? '') !== ':') {
            self::markTestSkipped('The MSYS form exists for drive-letter paths only.');
        }

        $msys = '/' . \strtolower($repo[0]) . \substr($repo, 2);
        $matrix = $this->matrix($this->runner(['vendor/bin/phpunit' => [
            'exit' => 1,
            'output' => "PHPUnit 11.5.56 by Sebastian Bergmann\n\nThere were 2 failures:\n\n1) A::b\n"
                . \strtoupper($repo) . "/tests/A.php:12\n2) B::c\n" . $msys . "/tests/B.php:7\n"
                . \str_replace('/', '\\', \strtolower($repo)) . "\\tests\\C.php:3\n\nFAILURES!\n"
                . "Tests: 3, Assertions: 3, Failures: 2.\n",
        ]]), $out);

        $exit = $matrix->execute(['--only=8.2', '--no-lowest', '--no-kit'], $this->root . '/scoop/apps', null);
        self::assertSame(1, $exit);
        self::assertStringContainsString('./tests/A.php:12', $out);
        self::assertStringContainsString('./tests/B.php:7', $out);
        self::assertStringContainsString('.\\tests\\C.php:3', $out);
        self::assertStringNotContainsString(\strtoupper($repo), $out);
        self::assertStringNotContainsString($msys, $out);
    }

    // ---------------------------------------------------------- real runner

    /** @test */
    public function theRealRunnerKeepsStdoutAndStderrInterleavedInOneFile(): void
    {
        if (!\function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled on this host.');
        }

        $runner = PhpMatrix::processRunner($this->root . '/tmp');
        // Stderr written between two stdout writes must neither overwrite nor be overwritten
        // (a separate handle per stream would put both at offset 0 on Windows).
        $code = 'fwrite(STDOUT, "banner line\n"); fflush(STDOUT); fwrite(STDERR, "a deprecation on stderr\n");'
            . ' fflush(STDERR); fwrite(STDOUT, "summary line\n"); exit(3);';
        $result = $runner([\PHP_BINARY, '-n', '-r', $code], $this->root, [], 60);

        self::assertSame(3, $result['exit']);
        self::assertSame("banner line\na deprecation on stderr\nsummary line\n", $result['output']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A fake repository: a root manifest, sources to lint, the original
     * vendor/, and scoop PHPs 7.3 (below the manifest's floor), 8.2, 8.3 and 8.4.
     */
    private function repository(): void
    {
        $json = (string) \json_encode(self::manifest(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        $this->put('repo/composer.json', $json);
        $this->put('repo/src/A.php', '<?php');
        $this->put('repo/src/notes.txt', '');
        $this->put('repo/tools/B.php', '<?php');
        $this->put('repo/tools/php-cs-fixer/vendor/C.php', '<?php');
        $this->put('repo/bin/tool', '<?php');
        $this->put('repo/vendor/original.txt', '');

        foreach (['73', '82', '83', '84'] as $nn) {
            $this->put('scoop/apps/php' . $nn . '/current/php.exe', '');
            $this->put('scoop/apps/php' . $nn . '/current/ext/php_openssl.dll', '');
        }
    }

    /**
     * A runner answering every step green unless $overrides names it (by an
     * argv member), and installing a marker into vendor/ on composer update.
     *
     * @param array<string, array{exit: int, output: string}> $overrides
     */
    private function runner(array $overrides): callable
    {
        return function (array $argv, string $cwd, array $env, int $timeout) use ($overrides): array {
            $this->calls[] = [$argv, $cwd, $env, $timeout];
            $binary = (string) $argv[0];
            $app = \basename(\dirname($binary, 2));
            $minor = \substr($app, 3, 1) . '.' . \substr($app, 4, 1);

            if (($argv[1] ?? '') === '-n') {
                $versions = [
                    '7.3' => '7.3.33',
                    '8.2' => '8.2.35',
                    '8.3' => '8.3.36',
                    '8.4' => '8.4.27',
                    '8.5' => '8.5.12',
                ];

                return ['exit' => 0, 'output' => $versions[$minor] ?? '', 'seconds' => 0.0];
            }

            foreach ($overrides as $member => $result) {
                if (\in_array($member, $argv, true)) {
                    return $result + ['seconds' => 1.0];
                }
            }

            if (($argv[1] ?? '') === PhpMatrix::COMPOSER) {
                $vendor = (string) $env['COMPOSER_VENDOR_DIR'];
                @\mkdir($vendor, 0o755, true);
                \file_put_contents($vendor . '/installed-by-' . $minor . '.txt', '');
            }

            if (($argv[1] ?? '') === 'vendor/bin/phpunit') {
                $green = "PHPUnit 9.6.20 by Sebastian Bergmann\n\nOK (3 tests, 3 assertions)\n";

                return ['exit' => 0, 'output' => $green, 'seconds' => 2.0];
            }

            return ['exit' => 0, 'output' => '', 'seconds' => 1.0];
        };
    }

    /**
     * @param-out string $out
     */
    /**
     * @param array<string, string> $env Inherited variables besides the defaults.
     *
     * @param-out string $out
     */
    private function matrix(callable $runner, ?string &$out, array $env = []): PhpMatrix
    {
        $out = '';

        return new PhpMatrix($this->root . '/repo', $runner, static function (string $text) use (&$out): void {
            $out .= $text;
        }, $env + [
            'INHERITED' => 'kept',
            'COMPOSER' => 'leaked',
            'PHP_INI_SCAN_DIR' => 'C:/elsewhere',
            'COMPOSER_NO_SECURITY_BLOCKING' => '1',
        ]);
    }

    /**
     * How often each name occurs once upper-cased (Windows names are case-insensitive).
     *
     * @param mixed $env
     *
     * @return array<string, int>
     */
    private static function upperCaseNames($env): array
    {
        $counts = [];

        foreach (\is_array($env) ? \array_keys($env) : [] as $name) {
            $upper = \strtoupper((string) $name);
            $counts[$upper] = ($counts[$upper] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function callsFor(string $member): array
    {
        $calls = [];

        foreach ($this->calls as $call) {
            foreach ($call[0] as $argument) {
                if ($argument === $member || \substr($argument, -\strlen($member)) === $member) {
                    $calls[] = $call;

                    break;
                }
            }
        }

        return $calls;
    }

    /**
     * @param list<string> $findings
     *
     * @return array{
     *     minor: string, version: string, resolved: string, lowest: bool, lint: string,
     *     tests: array{
     *         status: string, run: int, failures: int, errors: int, skipped: int, seconds: float, summary: string
     *     },
     *     fixture: string, kit: string, stan: string, pass: bool, findings: list<string>
     * }
     */
    private static function row(string $minor, string $version, bool $pass, array $findings): array
    {
        return [
            'minor' => $minor,
            'version' => $version,
            'resolved' => 'PHPUnit 9.6.20',
            'lowest' => false,
            'lint' => 'ok (480)',
            'tests' => [
                'status' => 'failed',
                'run' => 10,
                'failures' => 1,
                'errors' => 0,
                'skipped' => 2,
                'seconds' => 95.4,
                'summary' => '',
            ],
            'fixture' => 'identical',
            'kit' => 'skipped',
            'stan' => '',
            'pass' => $pass,
            'findings' => $findings,
        ];
    }

    private static function manifest(): \stdClass
    {
        $manifest = \json_decode((string) \json_encode([
            'name' => 'abhinaykhalatkar/tailwindcss-php',
            'require' => ['php' => '>=8.2'],
            'require-dev' => [
                'phpunit/phpunit' => '^9.6 || ^10.5 || ^11.5',
                'example/tool' => 'dev-main',
            ],
            'autoload' => [
                'psr-4' => ['TailwindPhp\\' => 'src/', 'Other\\' => ['lib/', 'more/']],
                'files' => ['src/functions.php'],
            ],
            'autoload-dev' => ['psr-4' => ['TailwindPhp\\Tests\\' => 'tests/']],
            'bin' => ['bin/tailwind-php'],
            'scripts' => ['test' => '@php vendor/bin/phpunit'],
            'config' => ['sort-packages' => true, 'allow-plugins' => new \stdClass()],
        ]));
        self::assertInstanceOf(\stdClass::class, $manifest);

        return $manifest;
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o755, true);
        }

        \file_put_contents($path, $contents);
    }
}
