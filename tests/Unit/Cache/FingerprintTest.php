<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Cache\Fingerprint;

final class FingerprintTest extends TestCase
{
    /**
     * A 64-character bare digest — the shape fromContentHash() documents as its
     * input (CSF-A3: bare hex in PHP, `sha256:`-prefixed only in the manifest).
     */
    private const BARE_DIGEST = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Fingerprint::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * A static helper with a private constructor: there is no instance state to
     * hold, and an instance would invite one.
     *
     * @test
     */
    public function cannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(Fingerprint::class);
        $constructor = $reflection->getConstructor();

        self::assertFalse($reflection->isInstantiable());
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    /**
     * @test
     *
     * @dataProvider clampedLengths
     */
    public function fromContentHashClampsTheRequestedLength(int $requested, int $expected): void
    {
        self::assertSame($expected, \strlen(Fingerprint::fromContentHash(self::BARE_DIGEST, $requested)));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function clampedLengths(): array
    {
        return [
            'negative' => [-8, 8],
            'zero' => [0, 8],
            'one below the floor' => [7, 8],
            'the floor' => [8, 8],
            'the ceiling' => [16, 16],
            'one above the ceiling' => [17, 16],
            'far above the ceiling' => [64, 16],
        ];
    }

    /** @test */
    public function fromContentHashReturnsExactlyTheRequestedLengthInRange(): void
    {
        for ($length = 8; $length <= 16; ++$length) {
            $fingerprint = Fingerprint::fromContentHash(self::BARE_DIGEST, $length);

            self::assertSame($length, \strlen($fingerprint));
            self::assertSame(\substr(self::BARE_DIGEST, 0, $length), $fingerprint);
            self::assertTrue(Fingerprint::isValid($fingerprint));
        }
    }

    /**
     * The cut is taken from the bare hex. Handing it a `sha256:`-prefixed digest
     * slices the prefix instead — which is exactly why the parameter is
     * documented as bare (CSF-A3) rather than being stripped here.
     *
     * @test
     */
    public function fromContentHashCutsFromTheBareHex(): void
    {
        self::assertSame('0123456789ab', Fingerprint::fromContentHash(self::BARE_DIGEST, 12));

        $prefixed = Fingerprint::fromContentHash('sha256:' . self::BARE_DIGEST, 8);

        self::assertSame('sha256:0', $prefixed);
        self::assertFalse(Fingerprint::isValid($prefixed));
    }

    /**
     * @test
     *
     * @dataProvider acceptedFingerprints
     */
    public function isValidAcceptsAWellFormedFingerprint(string $fingerprint): void
    {
        self::assertTrue(Fingerprint::isValid($fingerprint));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedFingerprints(): array
    {
        return [
            'eight characters' => ['0123abcd'],
            'sixteen characters' => ['0123456789abcdef'],
            'all digits' => ['00000000'],
            'all letters' => ['abcdefab'],
            'twelve characters' => ['0123456789ab'],
        ];
    }

    /**
     * The fingerprint arrives from the URL and becomes part of a filename, so
     * anything but anchored lowercase hex has to be refused (threat T-02).
     *
     * @test
     *
     * @dataProvider rejectedFingerprints
     */
    public function isValidRejectsAnythingElse(string $fingerprint): void
    {
        self::assertFalse(Fingerprint::isValid($fingerprint));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedFingerprints(): array
    {
        return [
            'empty' => [''],
            'seven characters' => ['0123abc'],
            'seventeen characters' => ['0123456789abcdef0'],
            'uppercase hex' => ['0123ABCD'],
            'mixed case hex' => ['0123abcD'],
            'non-hex letters' => ['zzzzzzzz'],
            'a slash' => ['0123/abc'],
            'a dot segment' => ['..0123ab'],
            'a NUL byte' => ["0123abcd\0"],
            'a leading newline' => ["\n0123abcd"],
            'a newline in the middle' => ["0123\nabcd0123"],
            'a newline between two valid halves' => ["0123abcd\n0123abcd"],
            'surrounding whitespace' => [' 0123abcd '],
        ];
    }

    /**
     * A regression test for a real defect, kept as its own method because the
     * reason it fails is not obvious from the value alone.
     *
     * In PCRE, `$` matches at the end of the subject **and immediately before a
     * final newline**, unless the `D` (PCRE_DOLLAR_ENDONLY) modifier is set. The
     * pattern was originally `#^[a-f0-9]{8,16}$#`, so `"0123abcd\n"` validated —
     * and this value becomes both a filename and the body of an `ETag` header,
     * where an embedded newline is response splitting rather than a typo.
     *
     * If this test ever fails, the `D` has been dropped from the pattern.
     *
     * @test
     */
    public function isValidRejectsATrailingNewline(): void
    {
        self::assertFalse(Fingerprint::isValid("0123abcd\n"));
        self::assertFalse(Fingerprint::isValid("0123456789abcdef\n"));
        self::assertFalse(Fingerprint::isValid("0123abcd\n\n"));
        self::assertFalse(Fingerprint::isValid("0123abcd\r\n"));
    }

    /** @test */
    public function toETagWrapsTheFingerprintInDoubleQuotes(): void
    {
        self::assertSame('"0123456789ab"', Fingerprint::toETag('0123456789ab'));
    }

    /** @test */
    public function toETagQuotesWhateverItIsGiven(): void
    {
        self::assertSame('""', Fingerprint::toETag(''));
        self::assertSame(
            '"' . self::BARE_DIGEST . '"',
            Fingerprint::toETag(self::BARE_DIGEST)
        );
    }

    /** @test */
    public function theEndToEndShapeIsAValidQuotedETag(): void
    {
        $fingerprint = Fingerprint::fromContentHash(self::BARE_DIGEST, 12);
        $etag = Fingerprint::toETag($fingerprint);

        self::assertSame(14, \strlen($etag));
        self::assertSame('"', $etag[0]);
        self::assertSame('"', $etag[13]);
        self::assertTrue(Fingerprint::isValid(\substr($etag, 1, -1)));
    }
}
