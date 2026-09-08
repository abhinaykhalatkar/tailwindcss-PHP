<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Bridge\LegacyHandler;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Cache\ManifestValidator;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Tests\Support\TempTree;
use TailwindPhp\Tools\StaticSecurityChecks;

/**
 * **T-08 — code execution from the cache** (design doc 12, ADR-003).
 *
 * The cache directory is writable by the web server. On a shared host that
 * often means writable by more than the application. So the question this file
 * answers is: if an attacker can put arbitrary bytes into `<bundle>.meta.json`,
 * what is the worst that happens?
 *
 * The answer has to be "the bundle rebuilds". That holds only if two things are
 * true, and both are asserted here:
 *
 * 1. **The manifest is only ever JSON.** No `include`, `require`, `eval`,
 *    `unserialize` or variable-variable dispatch anywhere near cache code. This
 *    is checked by running the shared static engine from
 *    `tools/StaticSecurityChecks.php` — the same rules the CI job runs, so the
 *    two front ends cannot drift.
 * 2. **No input reaches the user as an exception.** `Manifest::load()` returns
 *    `null` and a diagnostic for every kind of garbage, including bytes crafted
 *    to look like PHP.
 *
 * @covers-requirement NFR-SEC-4
 */
final class T08CacheCodeExecutionTest extends TestCase
{
    /**
     * Payloads that would matter if the manifest were ever `include`d or
     * `unserialize`d rather than JSON-decoded.
     */
    private const HOSTILE_PAYLOADS = [
        'php open tag' => '<?php system("id"); ?>',
        'php short echo' => '<?= `id` ?>',
        'serialized object' => 'O:8:"stdClass":1:{s:1:"a";s:1:"b";}',
        'serialized array' => 'a:1:{s:4:"code";s:10:"phpinfo();";}',
        'json with php inside a string' => '{"schema":1,"bundle":"<?php phpinfo(); ?>"}',
        'json wrapping serialized data' => '{"schema":1,"input":"O:8:\\"stdClass\\":0:{}"}',
        'null byte' => "{\"schema\":1,\"input\":\"a\0b\"}",
        // 17 levels: one past the decoder's depth limit of 16.
        'deep nesting' => '{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":'
            . '{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":1'
            . '}}}}}}}}}}}}}}}}}',
        'empty' => '',
        'whitespace' => "   \n\t ",
        'bare scalar' => '42',
        'bare string' => '"hello"',
        'utf16 bom' => "\xFF\xFE{\"schema\":1}",
    ];

    protected function setUp(): void
    {
        parent::setUp();

        self::loadEngine();
    }

    /**
     * @test
     *
     * @dataProvider hostilePayloads
     */
    public function hostileBytesNeverEscapeAsAnException(string $bytes): void
    {
        $diagnostic = null;

        $result = Manifest::load('cache/app.meta.json', self::filesystemReturning($bytes), 16777216, $diagnostic);

        self::assertNull($result, 'A hostile manifest was accepted.');
        self::assertInstanceOf(Diagnostic::class, $diagnostic);
        self::assertSame(Diagnostic::W_MANIFEST_CORRUPT, $diagnostic->code());
    }

    /**
     * The bridge half of this threat (TASK-0018 acceptance 8).
     *
     * `Bridge\LegacyHandler` is the only class outside the console that loads a
     * PHP configuration file, and it looks in two places. Neither is the cache
     * directory — but the cache directory is the one an attacker who can write
     * to the server is most likely to reach, because it is world-writable on
     * plenty of shared hosts and its contents churn constantly.
     *
     * The control is structural: the handler goes through
     * {@see \TailwindPhp\Config\ConfigFile::load()}, the single `require` site
     * in `src/`, which refuses a path under the cache directory. This row
     * asserts the **outcome** rather than the code path — the planted files
     * write a marker if they ever execute, so it cannot pass merely because the
     * lookup happened to look elsewhere.
     *
     * @test
     */
    public function aConfigFilePlantedInTheCacheDirectoryIsNeverExecutedByTheBridge(): void
    {
        $root = TempTree::make('tailwindphp-t08-bridge');
        $previous = \getcwd();

        try {
            \mkdir($root . '/assets/styles', 0777, true);
            \mkdir($root . '/templates', 0777, true);
            \mkdir($root . '/cache/tailwind/config', 0777, true);
            \file_put_contents($root . '/assets/styles/main.css', "@import \"tailwindcss\";\n");
            \file_put_contents($root . '/templates/index.twig', "<div class=\"flex\"></div>\n");

            $marker = $root . '/pwned.txt';
            $payload = "<?php\n\n\\file_put_contents('" . \addslashes($marker) . "', 'executed');\n\nreturn [];\n";

            foreach (['cache/tailwind/tailwind.php', 'cache/tailwind/config/tailwind.php'] as $planted) {
                \file_put_contents($root . '/' . $planted, $payload);
            }

            \chdir($root);
            $handler = new LegacyHandler(false, 'assets/styles');

            self::assertNotSame('', $handler->getContent(), 'the handler should still have produced CSS');
            self::assertFileDoesNotExist($marker, 'a config file under the cache directory was executed');
        } finally {
            if ($previous !== false) {
                \chdir($previous);
            }

            TempTree::remove($root);
        }
    }

    /**
     * The row that can actually fail (round-2 item 3).
     *
     * The row above passes for a weak reason: the handler's two candidates are
     * `config/tailwind.php` and `<dirname($path)>/tailwind.php`, so a file under
     * `cache/tailwind/` is never *looked at*, and removing the guard entirely
     * leaves it green. What exercises the guard is a `$path` that puts the
     * candidate inside the cache directory — a project whose stylesheets live
     * at `cache/tailwind/styles` makes `cache/tailwind/tailwind.php` the file
     * beside them.
     *
     * That is contrived as a project layout and exactly right as a test: it is
     * the one arrangement in which `ConfigFile::load()`'s cache-directory
     * refusal is the only thing standing between a planted file and `require`.
     *
     * @test
     */
    public function theCacheDirectoryGuardIsWhatStopsAPlantedConfig(): void
    {
        $root = TempTree::make('tailwindphp-t08-guard');
        $previous = \getcwd();

        try {
            \mkdir($root . '/cache/tailwind/styles', 0777, true);
            \mkdir($root . '/templates', 0777, true);
            \file_put_contents($root . '/cache/tailwind/styles/main.css', "@import \"tailwindcss\";\n");
            \file_put_contents($root . '/templates/index.twig', "<div class=\"flex\"></div>\n");

            $marker = $root . '/pwned.txt';
            \file_put_contents(
                $root . '/cache/tailwind/tailwind.php',
                "<?php\n\n\\file_put_contents('" . \addslashes($marker) . "', 'executed');\n\nreturn [];\n"
            );

            \chdir($root);

            $thrown = null;

            try {
                new LegacyHandler(false, 'cache/tailwind/styles');
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(
                ConfigurationException::class,
                $thrown,
                'a config file inside the cache directory must be refused, not loaded'
            );
            self::assertStringContainsString('cache', (string) $thrown->getMessage());
            self::assertFileDoesNotExist($marker, 'the planted config executed');
        } finally {
            if ($previous !== false) {
                \chdir($previous);
            }

            TempTree::remove($root);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostilePayloads(): array
    {
        $cases = [];

        foreach (self::HOSTILE_PAYLOADS as $name => $payload) {
            $cases[$name] = [$payload];
        }

        return $cases;
    }

    /**
     * The other half of the threat: an attacker with write access can also just
     * *delete* the manifest. That must degrade to a rebuild, not to an error
     * page — and it must not be reported as corruption, or every cold deploy
     * would log a false alarm.
     *
     * @test
     */
    public function deletingTheManifestIsNotAnAttackVector(): void
    {
        $diagnostic = null;

        $result = Manifest::load(
            'cache/app.meta.json',
            self::filesystemReturning('', false),
            16777216,
            $diagnostic
        );

        self::assertNull($result);
        self::assertNull($diagnostic, 'A deleted manifest must read as a cold cache, not a corrupt one.');
    }

    /**
     * Structured fuzzing: valid JSON of arbitrary shape, which is the harder
     * case. Malformed bytes die in the decoder; a well-formed document with the
     * wrong types has to be stopped by the validator, and a missed type check
     * there is what would let a forged `input` or `fingerprint` through.
     *
     * @test
     */
    public function fuzzedJsonDocumentsNeverThrow(): void
    {
        \mt_srand(20260903);

        for ($i = 0; $i < 400; ++$i) {
            $document = \json_encode(self::randomValue(0));

            self::assertIsString($document);

            $diagnostic = null;
            $result = null;

            try {
                $result = Manifest::load(
                    'cache/app.meta.json',
                    self::filesystemReturning($document),
                    16777216,
                    $diagnostic
                );
            } catch (\Throwable $e) {
                self::fail(\sprintf(
                    'Manifest::load() threw %s on iteration %d: %s',
                    \get_class($e),
                    $i,
                    $e->getMessage()
                ));
            }

            if ($result !== null) {
                // If a fuzzed document ever validates, it must at least be a
                // real manifest — re-validating its own output proves the
                // round trip rather than a hole in the checks.
                ManifestValidator::validate($result->toArray());
            }
        }
    }

    /**
     * The validator is the component that says no. If a fuzzed document reached
     * it and it threw anything other than `ManifestCorruptException`, the
     * pipeline's `catch` would miss it and the exception would reach the user.
     *
     * @test
     */
    public function theValidatorOnlyEverThrowsManifestCorrupt(): void
    {
        \mt_srand(20260904);

        $examined = 0;
        $rejected = 0;

        for ($i = 0; $i < 400; ++$i) {
            $value = self::randomValue(0);

            if (!\is_array($value)) {
                continue;
            }

            ++$examined;

            try {
                ManifestValidator::validate($value);
            } catch (ManifestCorruptException $e) {
                ++$rejected;

                continue;
            } catch (\Throwable $e) {
                self::fail(\sprintf(
                    'ManifestValidator::validate() threw %s on iteration %d: %s',
                    \get_class($e),
                    $i,
                    $e->getMessage()
                ));
            }
        }

        // Guards the fuzzer itself: if the generator stopped producing arrays,
        // or the validator started accepting everything, the loop above would
        // pass while testing nothing.
        self::assertGreaterThan(50, $examined, 'The fuzzer produced almost no array documents.');
        self::assertSame($examined, $rejected, 'A random document was accepted as a valid manifest.');
    }

    /**
     * The static rule, run from the test suite as well as from CI. `src/` holds
     * exactly one documented `require` — the config file loader — and the
     * engine's allowlist names it; anything new fails here.
     *
     * @test
     */
    public function noSourceFileLoadsCodeDynamically(): void
    {
        $checks = new StaticSecurityChecks();
        $violations = $checks->run(self::repositoryRoot() . '/src', null);

        $codeLoading = [];

        foreach ($violations as $violation) {
            if ($violation['rule'] === StaticSecurityChecks::RULE_CODE_LOADING) {
                $codeLoading[] = \sprintf('%s:%d %s', $violation['file'], $violation['line'], $violation['text']);
            }
        }

        self::assertSame([], $codeLoading, 'Dynamic code loading appeared in src/.');
    }

    /**
     * Belt and braces over the rule above, aimed squarely at the cache: read the
     * `src/Cache` sources as text and assert the dangerous constructs are simply
     * absent. A rule can be given an allowlist entry by accident; this cannot.
     *
     * @test
     */
    public function theCacheNamespaceContainsNoCodeLoadingConstructs(): void
    {
        $forbidden = ['include', 'include_once', 'require', 'require_once', 'eval', 'unserialize', 'create_function'];
        $found = [];

        foreach (\glob(self::repositoryRoot() . '/src/Cache/*.php') ?: [] as $file) {
            $source = (string) \file_get_contents($file);
            $tokens = \token_get_all($source);

            foreach ($tokens as $token) {
                if (!\is_array($token)) {
                    continue;
                }

                $id = $token[0];
                $text = \strtolower(\ltrim($token[1], '\\'));

                $isKeyword = $id === \T_INCLUDE || $id === \T_INCLUDE_ONCE
                    || $id === \T_REQUIRE || $id === \T_REQUIRE_ONCE || $id === \T_EVAL;

                if ($isKeyword || ($id === \T_STRING && \in_array($text, $forbidden, true))) {
                    $found[] = \basename($file) . ':' . $token[2] . ' ' . $text;
                }
            }
        }

        self::assertSame([], $found, 'A code-loading construct appeared in src/Cache.');
    }

    /**
     * A recursive generator of arbitrary JSON values, bounded so it terminates.
     *
     * @return mixed
     */
    private static function randomValue(int $depth)
    {
        $choices = $depth >= 3 ? 5 : 7;

        switch (\mt_rand(0, $choices)) {
            case 0:
                return null;
            case 1:
                return \mt_rand(0, 1) === 1;
            case 2:
                return \mt_rand(-1000000, 1000000);
            case 3:
                return \mt_rand(-100000, 100000) / 7;
            case 4:
                return self::randomString();
            case 5:
                return [];
            case 6:
                $list = [];

                for ($i = 0, $n = \mt_rand(1, 4); $i < $n; ++$i) {
                    $list[] = self::randomValue($depth + 1);
                }

                return $list;
            default:
                $map = [];

                for ($i = 0, $n = \mt_rand(1, 5); $i < $n; ++$i) {
                    $map[self::randomKey()] = self::randomValue($depth + 1);
                }

                return $map;
        }
    }

    private static function randomKey(): string
    {
        // Half real manifest keys, half noise: a fuzzer that never produces a
        // recognised key only ever exercises the "missing key" branch.
        $known = [
            'schema', 'package_version', 'config_hash', 'bundle', 'driver', 'driver_version',
            'driver_probe', 'built_at', 'build_ms', 'content_hash', 'fingerprint', 'minified',
            'candidates_hash', 'candidates_count', 'input', 'imports', 'roots', 'files',
            'safelist_hash', 'inline_sources', 'warnings', 'last_error',
            'level', 'code', 'message', 'file', 'line', 'candidate', 'k', 'm', 's', 'c',
        ];

        if (\mt_rand(0, 1) === 1) {
            return $known[\mt_rand(0, \count($known) - 1)];
        }

        return self::randomString();
    }

    private static function randomString(): string
    {
        $alphabet = "abcdef0123456789:/.\\-_ \t\n\"'<>?\$*";
        $length = \mt_rand(0, 24);
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $out .= $alphabet[\mt_rand(0, \strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function loadEngine(): void
    {
        require_once __DIR__ . '/../../tools/StaticSecurityChecks.php';
    }

    private static function repositoryRoot(): string
    {
        return \str_replace('\\', '/', \dirname(__DIR__, 2));
    }

    /**
     * A filesystem that hands back one fixed body, so a payload can be fed to
     * `Manifest::load()` without touching the disk.
     *
     * Anonymous rather than a named class: one class per file is the house rule
     * (design doc 14), and hand-written rather than a mock because the suite has
     * to run identically on PHPUnit 9 and 11 (design doc 16).
     */
    private static function filesystemReturning(string $body, bool $exists = true): FilesystemInterface
    {
        return new class ($body, $exists) implements FilesystemInterface {
            /**
             * @var string
             */
            private $body;

            /**
             * @var bool
             */
            private $exists;

            public function __construct(string $body, bool $exists)
            {
                $this->body = $body;
                $this->exists = $exists;
            }

            public function exists(string $path): bool
            {
                return $this->exists;
            }

            public function isFile(string $path): bool
            {
                return $this->exists;
            }

            public function isDir(string $path): bool
            {
                return false;
            }

            /**
             * The return type stays nullable rather than being narrowed to
             * `array`: return-type covariance is PHP 7.4 and the floor is 7.3
             * (design doc 14). The absent case is real — see
             * deletingTheManifestIsNotAnAttackVector().
             *
             * @return array{mtime: int, size: int}|null
             */
            public function stat(string $path): ?array
            {
                if (!$this->exists) {
                    return null;
                }

                return ['mtime' => 1756857600, 'size' => \strlen($this->body)];
            }

            public function read(string $path, int $maxBytes): string
            {
                return \substr($this->body, 0, $maxBytes);
            }

            public function mkdir(string $dir, int $mode = 0755): void
            {
            }

            public function touch(string $path, ?int $mtime = null): void
            {
            }

            public function unlink(string $path): bool
            {
                return true;
            }

            public function clearStatCache(string $path): void
            {
            }
        };
    }
}
