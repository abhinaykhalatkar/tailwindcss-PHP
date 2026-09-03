<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\Cli\Checksums;

final class ChecksumsTest extends TestCase
{
    /**
     * A syntactically valid digest that is deliberately in no table, so that a
     * false answer from `isKnown()` is a real answer and not a length rejection.
     */
    private const ABSENT_DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FAKE_DIGEST = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * The digest of the empty string — a real SHA-256, used wherever a test
     * needs an entry that is unmistakably well formed.
     */
    private const EMPTY_DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Checksums::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function theShippedTableLoads(): void
    {
        $checksums = Checksums::load();

        self::assertNotSame([], $checksums->versions());
    }

    /**
     * The constant and the resource must not drift: a DEFAULT_VERSION naming a
     * release the table does not pin would leave the CLI driver looking for
     * digests that are not there.
     *
     * @test
     */
    public function theDefaultVersionIsPresentInTheShippedTable(): void
    {
        $checksums = Checksums::load();

        self::assertNotNull($checksums->forVersion(Checksums::DEFAULT_VERSION));
    }

    /**
     * The shipped release pins one digest per supported platform; a missing key
     * means a platform silently loses verification (threat T-04).
     *
     * @test
     */
    public function theShippedTablePinsEverySupportedPlatform(): void
    {
        $checksums = Checksums::load();
        $platforms = $checksums->forVersion(Checksums::DEFAULT_VERSION);

        self::assertNotNull($platforms);
        self::assertSame(
            [
                'linux-x64',
                'linux-x64-musl',
                'linux-arm64',
                'macos-x64',
                'macos-arm64',
                'windows-x64.exe',
            ],
            \array_keys($platforms)
        );
    }

    /** @test */
    public function everyShippedDigestIsRecognised(): void
    {
        $checksums = Checksums::load();
        $platforms = $checksums->forVersion(Checksums::DEFAULT_VERSION);

        self::assertNotNull($platforms);

        foreach ($platforms as $digest) {
            self::assertTrue($checksums->isKnown($digest));
        }
    }

    /** @test */
    public function versionsListsEveryPinnedRelease(): void
    {
        $checksums = self::table();

        self::assertSame(['4.1.11', '4.1.18'], $checksums->versions());
    }

    /** @test */
    public function forVersionReturnsThePlatformMap(): void
    {
        $checksums = self::table();

        self::assertSame(
            ['linux-x64' => self::EMPTY_DIGEST],
            $checksums->forVersion('4.1.11')
        );
    }

    /** @test */
    public function forVersionReturnsNullForAnUnpinnedRelease(): void
    {
        $checksums = self::table();

        self::assertNull($checksums->forVersion('3.4.0'));
    }

    /** @test */
    public function isKnownAcceptsAnEntry(): void
    {
        $checksums = self::table();

        self::assertTrue($checksums->isKnown(self::EMPTY_DIGEST));
    }

    /** @test */
    public function isKnownRejectsAWellFormedDigestThatIsNotAnEntry(): void
    {
        $checksums = self::table();

        self::assertFalse($checksums->isKnown(self::ABSENT_DIGEST));
    }

    /** @test */
    public function isKnownRejectsAnEmptyString(): void
    {
        $checksums = self::table();

        self::assertFalse($checksums->isKnown(''));
    }

    /**
     * A prefix of a real entry: `hash_equals()` rejects it on length, and the
     * loop must not "helpfully" compare it against a truncated candidate.
     *
     * @test
     */
    public function isKnownRejectsATruncatedDigest(): void
    {
        $checksums = self::table();

        self::assertFalse($checksums->isKnown(\substr(self::EMPTY_DIGEST, 0, 32)));
    }

    /**
     * Decision CCP-A9: the fake binary's digest is injected, never switched on
     * through the environment — and injecting it must not mutate the receiver,
     * or a table handed to a collaborator could gain entries behind its back.
     *
     * @test
     */
    public function withAdditionalReturnsANewInstance(): void
    {
        $checksums = self::table();

        $extended = $checksums->withAdditional([self::FAKE_DIGEST => 'tests/Fixtures/bin/fake-tailwindcss']);

        self::assertNotSame($checksums, $extended);
    }

    /** @test */
    public function withAdditionalRecognisesTheInjectedDigest(): void
    {
        $checksums = self::table();

        $extended = $checksums->withAdditional([self::FAKE_DIGEST => 'tests/Fixtures/bin/fake-tailwindcss']);

        self::assertTrue($extended->isKnown(self::FAKE_DIGEST));
    }

    /** @test */
    public function withAdditionalLeavesTheOriginalUnchanged(): void
    {
        $checksums = self::table();

        $checksums->withAdditional([self::FAKE_DIGEST => 'tests/Fixtures/bin/fake-tailwindcss']);

        self::assertFalse($checksums->isKnown(self::FAKE_DIGEST));
    }

    /**
     * An injected digest belongs to no release, so it must not appear as one.
     *
     * @test
     */
    public function withAdditionalDoesNotInventAVersion(): void
    {
        $checksums = self::table();

        $extended = $checksums->withAdditional([self::FAKE_DIGEST => null]);

        self::assertSame(['4.1.11', '4.1.18'], $extended->versions());
    }

    /** @test */
    public function withAdditionalRejectsAKeyThatIsNotADigest(): void
    {
        $checksums = self::table();

        $this->expectException(\RuntimeException::class);

        $checksums->withAdditional(['not-a-digest' => 'x']);
    }

    /** @test */
    public function fromTableRejectsAVersionThatIsNotAnObject(): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::fromTable(['4.1.11' => self::EMPTY_DIGEST]);
    }

    /** @test */
    public function fromTableRejectsAValueThatIsNotADigest(): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::fromTable(['4.1.11' => ['linux-x64' => 'nope']]);
    }

    /**
     * The keys are as much a contract as the digests are.
     *
     * A hand-edited or generated table with a key like `"not-a-version"` or
     * `"../../etc\n"` used to load silently, and `versions()` then reported
     * releases that never existed.
     *
     * @dataProvider malformedVersionKeys
     *
     * @test
     */
    public function fromTableRejectsAVersionKeyThatIsNotAversion(string $version): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::fromTable([$version => ['linux-x64' => self::EMPTY_DIGEST]]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedVersionKeys(): array
    {
        return [
            'words' => ['not-a-version'],
            'pre-release suffix' => ['0.0.1-retired'],
            'traversal' => ['../../etc'],
            'trailing newline' => ["4.1.11\n"],
            'two parts' => ['4.1'],
            'empty' => [''],
        ];
    }

    /**
     * A platform key outside the known set is a typo, or a table from somewhere
     * else — either way not something to accept digests under.
     *
     * @test
     */
    public function fromTableRejectsAnUnknownPlatformKey(): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::fromTable(['4.1.11' => ['solaris-sparc' => self::EMPTY_DIGEST]]);
    }

    /**
     * `versions()` is typed `list<string>`, and a caller trusting that should
     * not get integers back because a key looked numeric.
     *
     * @test
     */
    public function versionsIsAlwaysAlistOfStrings(): void
    {
        $versions = Checksums::fromTable([
            '4.1.11' => ['linux-x64' => self::EMPTY_DIGEST],
            '4.1.18' => ['linux-x64' => self::EMPTY_DIGEST],
        ])->versions();

        self::assertSame(['4.1.11', '4.1.18'], $versions);
        self::assertSame(\range(0, \count($versions) - 1), \array_keys($versions));
    }

    /**
     * Uppercase hex is a different byte string, and `hash_equals()` would never
     * match it against the lowercase form the manifest stores.
     *
     * @test
     */
    public function fromTableRejectsAnUppercaseDigest(): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::fromTable(['4.1.11' => ['linux-x64' => \strtoupper(self::EMPTY_DIGEST)]]);
    }

    /** @test */
    public function loadRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);

        Checksums::load(__DIR__ . '/no-such-checksum-table.json');
    }

    /**
     * Two releases, so `versions()` and `forVersion()` have something to
     * distinguish; the digests are real SHA-256 values of trivial inputs.
     */
    private static function table(): Checksums
    {
        return Checksums::fromTable([
            '4.1.11' => ['linux-x64' => self::EMPTY_DIGEST],
            '4.1.18' => [
                'linux-x64' => 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
                'windows-x64.exe' => '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae',
            ],
        ]);
    }
}
