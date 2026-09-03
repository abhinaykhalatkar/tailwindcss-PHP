<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Engine\Import;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Import\FilesystemImportResolver;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tests\Support\TempTree;

/**
 * `@import` resolution against a real filesystem (CCP-A8).
 *
 * Integration rather than unit because the interesting behaviour *is* the
 * filesystem: confinement, the `.css`/partial spellings, and what happens when
 * a specifier points outside the project. A double would only restate the
 * implementation.
 *
 * The specifier comes out of a stylesheet — often one copied from elsewhere —
 * so `../../../etc/passwd` is an input this class must answer, not one it can
 * assume away (threats T-01, T-08).
 */
final class FilesystemImportResolverTest extends TestCase
{
    private const MAX_BYTES = 65536;

    /**
     * @var string
     */
    private $root = '';

    /**
     * @var string
     */
    private $outside = '';

    protected function setUp(): void
    {
        parent::setUp();

        $base = TempTree::make('tailwindphp-imports');
        $this->root = $base . '/project';
        $this->outside = $base . '/outside';

        \mkdir($this->root . '/assets/styles/partials', 0777, true);
        \mkdir($this->outside, 0777, true);

        \file_put_contents($this->root . '/assets/styles/main.css', '@import "./buttons.css";');
        \file_put_contents($this->root . '/assets/styles/buttons.css', '.btn{}');
        \file_put_contents($this->root . '/assets/styles/forms.css', '.form{}');
        \file_put_contents($this->root . '/assets/styles/partials/_colors.css', ':root{}');
        \file_put_contents($this->root . '/tokens.css', ':root{--x:1}');
        \file_put_contents($this->outside . '/secret.css', '.secret{}');

        TempTree::registerShutdownCleanup($base);
    }

    protected function tearDown(): void
    {
        TempTree::remove(\dirname($this->root));

        parent::tearDown();
    }

    /** @test */
    public function anExplicitRelativeSpecifierResolves(): void
    {
        $import = $this->resolver()->resolve('./buttons.css', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame('assets/styles/buttons.css', $import->relPath());
        self::assertSame('.btn{}', $import->css());
    }

    /**
     * A sibling named without `./` still resolves — the `.css` extension is
     * what marks it as a path rather than a package.
     *
     * @test
     */
    public function aBareSiblingWithTheCssExtensionResolves(): void
    {
        $import = $this->resolver()->resolve('forms.css', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame('assets/styles/forms.css', $import->relPath());
    }

    /** @test */
    public function aParentRelativeSpecifierResolvesWithinTheProject(): void
    {
        $import = $this->resolver()->resolve('../../tokens.css', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame('tokens.css', $import->relPath());
    }

    /**
     * `"./x"` with no extension: `.css` is appended.
     *
     * @test
     */
    public function theCssExtensionIsAppendedWhenMissing(): void
    {
        $import = $this->resolver()->resolve('./buttons', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame('assets/styles/buttons.css', $import->relPath());
    }

    /**
     * The `_partial.css` spelling is tried last, so it can never shadow a real
     * file of the same name.
     *
     * @test
     */
    public function theUnderscorePartialFormIsFound(): void
    {
        $import = $this->resolver()->resolve('./partials/colors', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame('assets/styles/partials/_colors.css', $import->relPath());
    }

    /**
     * A bare package name is the driver's business, not ours — trying to read
     * it off disk would at best fail and at worst pick up an unrelated file.
     *
     * @test
     *
     * @dataProvider packageSpecifiers
     */
    public function aBarePackageSpecifierIsLeftAlone(string $spec): void
    {
        self::assertNull($this->resolver()->resolve($spec, 'assets/styles/main.css'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function packageSpecifiers(): array
    {
        return [
            'the framework itself' => ['tailwindcss'],
            'a package subpath' => ['tailwindcss/utilities'],
            'a scoped package' => ['@acme/theme'],
        ];
    }

    /**
     * The confinement rule. A specifier that climbs out of the allowed roots
     * resolves to nothing, however it is spelled (T-01).
     *
     * @test
     *
     * @dataProvider escapingSpecifiers
     */
    public function aSpecifierThatLeavesTheAllowedRootsResolvesToNull(string $spec): void
    {
        self::assertNull($this->resolver()->resolve($spec, 'assets/styles/main.css'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingSpecifiers(): array
    {
        return [
            'a sibling directory of the project' => ['../../../outside/secret.css'],
            'far above the project' => ['../../../../../../etc/passwd.css'],
            'an absolute posix path' => ['/etc/passwd.css'],
        ];
    }

    /**
     * A wrapper is neither absolute nor traversing, so it would slip past a
     * naive check and hand the compiler the contents of an arbitrary stream
     * (T-08).
     *
     * @test
     *
     * @dataProvider wrapperSpecifiers
     */
    public function aStreamWrapperIsRefused(string $spec): void
    {
        self::assertNull($this->resolver()->resolve($spec, 'assets/styles/main.css'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wrapperSpecifiers(): array
    {
        return [
            'php filter' => ['php://filter/read=convert.base64-encode/resource=main.css'],
            'phar' => ['phar://x.phar/y.css'],
            'data' => ['data://text/plain;base64,LmZvbyB7fQ==.css'],
        ];
    }

    /** @test */
    public function aMissingFileResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve('./nope.css', 'assets/styles/main.css'));
    }

    /** @test */
    public function anEmptyOrNulBearingSpecifierResolvesToNull(): void
    {
        self::assertNull($this->resolver()->resolve('', 'assets/styles/main.css'));
        self::assertNull($this->resolver()->resolve("./butt\0ons.css", 'assets/styles/main.css'));
    }

    /**
     * The read is capped, so one enormous import cannot be turned into memory
     * exhaustion (T-05). The facade truncates silently, which is the documented
     * behaviour; what matters here is that the cap is honoured.
     *
     * @test
     */
    public function theReadIsCappedAtTheConfiguredSize(): void
    {
        \file_put_contents($this->root . '/assets/styles/huge.css', \str_repeat('a', 5000));

        $resolver = new FilesystemImportResolver($this->root, [$this->root], new Filesystem(), 100);
        $import = $resolver->resolve('./huge.css', 'assets/styles/main.css');

        self::assertNotNull($import);
        self::assertSame(100, \strlen($import->css()));
    }

    /**
     * An import from a file at the project root resolves against the root
     * itself — `dirname()` of a bare name is `.`, which must not become a
     * literal path segment.
     *
     * @test
     */
    public function anImportFromTheProjectRootResolves(): void
    {
        \file_put_contents($this->root . '/entry.css', '@import "./tokens.css";');

        $import = $this->resolver()->resolve('./tokens.css', 'entry.css');

        self::assertNotNull($import);
        self::assertSame('tokens.css', $import->relPath());
    }

    private function resolver(): FilesystemImportResolver
    {
        return new FilesystemImportResolver(
            $this->root,
            [$this->root],
            new Filesystem(),
            self::MAX_BYTES
        );
    }
}
