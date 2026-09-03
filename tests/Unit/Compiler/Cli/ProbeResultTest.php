<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\Cli\ProbeResult;

final class ProbeResultTest extends TestCase
{
    private const DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private const BINARY = 'vendor/bin/tailwindcss';

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ProbeResult::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyFieldRoundTrips(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertSame(self::BINARY, $probe->binary());
        self::assertSame(1700000000, $probe->m());
        self::assertSame(104857600, $probe->s());
        self::assertSame(self::DIGEST, $probe->sha256());
        self::assertSame('4.1.18', $probe->version());
        self::assertTrue($probe->verified());
    }

    /** @test */
    public function verifiedIsCarriedAsGiven(): void
    {
        $probe = new ProbeResult(self::BINARY, 1, 2, self::DIGEST, '', false);

        self::assertFalse($probe->verified());
    }

    /**
     * Decision CCP-A3: a binary outside the project root has no project-relative
     * name, and null is the recorded answer rather than an error.
     *
     * @test
     */
    public function binaryMayBeNull(): void
    {
        $probe = new ProbeResult(null, 1700000000, 4096, self::DIGEST, '4.1.18', true);

        self::assertNull($probe->binary());
    }

    /** @test */
    public function versionMayBeEmpty(): void
    {
        $probe = new ProbeResult(self::BINARY, 1, 2, self::DIGEST, '', true);

        self::assertSame('', $probe->version());
    }

    /** @test */
    public function toArrayHasExactlyTheFiveManifestKeys(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertSame(
            ['binary', 'm', 's', 'sha256', 'version'],
            \array_keys($probe->toArray())
        );
    }

    /**
     * `verified` is a property of the run, not of the binary: persisting it
     * would let anyone who can write the cache directory assert that an
     * unrecognised binary had already passed verification (threat T-04).
     *
     * @test
     */
    public function toArrayOmitsVerified(): void
    {
        $probe = new ProbeResult(self::BINARY, 1, 2, self::DIGEST, '4.1.18', true);

        self::assertArrayNotHasKey('verified', $probe->toArray());
    }

    /** @test */
    public function toArrayCarriesTheValues(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', false);

        self::assertSame(
            [
                'binary' => self::BINARY,
                'm' => 1700000000,
                's' => 104857600,
                'sha256' => self::DIGEST,
                'version' => '4.1.18',
            ],
            $probe->toArray()
        );
    }

    /** @test */
    public function toArrayKeepsANullBinary(): void
    {
        $probe = new ProbeResult(null, 1, 2, self::DIGEST, '', true);

        $array = $probe->toArray();

        self::assertArrayHasKey('binary', $array);
        self::assertNull($array['binary']);
    }

    /** @test */
    public function matchesWhenAllThreeAgree(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertTrue($probe->matches(self::BINARY, 1700000000, 104857600));
    }

    /** @test */
    public function matchesIsFalseWhenTheNameDiffers(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertFalse($probe->matches('bin/tailwindcss', 1700000000, 104857600));
    }

    /** @test */
    public function matchesIsFalseWhenTheMtimeDiffers(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertFalse($probe->matches(self::BINARY, 1700000001, 104857600));
    }

    /** @test */
    public function matchesIsFalseWhenTheSizeDiffers(): void
    {
        $probe = new ProbeResult(self::BINARY, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertFalse($probe->matches(self::BINARY, 1700000000, 104857601));
    }

    /**
     * A probe with no name cannot be matched by name; an out-of-project binary
     * is re-identified by its digest instead (CCP-A3).
     *
     * @test
     */
    public function matchesIsFalseWhenTheProbeHasNoName(): void
    {
        $probe = new ProbeResult(null, 1700000000, 104857600, self::DIGEST, '4.1.18', true);

        self::assertFalse($probe->matches(self::BINARY, 1700000000, 104857600));
    }
}
