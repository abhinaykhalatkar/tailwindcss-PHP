<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\DriverResolution;
use TailwindPhp\Engine\Import\ImportResolverInterface;

final class DriverResolutionTest extends TestCase
{
    /**
     * The shape design doc 06 stores in the manifest's `driver_probe`.
     */
    private const PROBE = [
        'binary' => 'vendor/bin/tailwindcss',
        'm' => 1725000000,
        's' => 55123456,
        'sha256' => 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
        'version' => '4.1.18',
    ];

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(DriverResolution::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyFieldRoundTripsThroughItsGetter(): void
    {
        $compiler = self::fakeCompiler('cli', '4.1.18');
        $resolution = new DriverResolution($compiler, self::PROBE, 'auto: the CLI binary verified');

        self::assertSame($compiler, $resolution->compiler());
        self::assertSame(self::PROBE, $resolution->probe());
        self::assertSame('auto: the CLI binary verified', $resolution->reason());
    }

    /** @test */
    public function compilerReturnsTheGivenImplementation(): void
    {
        $resolution = new DriverResolution(self::fakeCompiler('php', 'compat-4.1'), null, null);

        self::assertInstanceOf(CompilerInterface::class, $resolution->compiler());
        self::assertSame('php', $resolution->compiler()->name());
        self::assertSame('compat-4.1', $resolution->compiler()->version());
    }

    /**
     * An explicit `driver: php` never inspects the CLI and needs no
     * explanation, so both side channels are legitimately absent.
     *
     * @test
     */
    public function probeAndReasonMayBothBeNull(): void
    {
        $resolution = new DriverResolution(self::fakeCompiler('php', 'compat-4.1'), null, null);

        self::assertNull($resolution->probe());
        self::assertNull($resolution->reason());
    }

    /** @test */
    public function aProbeMayBeCarriedWithoutAReason(): void
    {
        $resolution = new DriverResolution(self::fakeCompiler('cli', '4.1.18'), self::PROBE, null);

        self::assertSame(self::PROBE, $resolution->probe());
        self::assertNull($resolution->reason());
    }

    /**
     * `auto` falling back to the PHP engine is the case the reason exists for:
     * there is nothing to cache, but the developer still has to be told why.
     *
     * @test
     */
    public function aReasonMayBeCarriedWithoutAProbe(): void
    {
        $resolution = new DriverResolution(
            self::fakeCompiler('php', 'compat-4.1'),
            null,
            'auto: using PHP engine because proc_open is disabled'
        );

        self::assertNull($resolution->probe());
        self::assertSame('auto: using PHP engine because proc_open is disabled', $resolution->reason());
    }

    /** @test */
    public function anEmptyProbeIsNotConfusedWithNull(): void
    {
        $resolution = new DriverResolution(self::fakeCompiler('cli', '4.1.18'), [], null);

        self::assertSame([], $resolution->probe());
        self::assertNotNull($resolution->probe());
    }

    /** @test */
    public function thereAreNoSetters(): void
    {
        $reflection = new ReflectionClass(DriverResolution::class);
        $names = [];

        foreach ($reflection->getMethods() as $method) {
            $names[] = $method->getName();
        }

        \sort($names);

        self::assertSame(['__construct', 'compiler', 'probe', 'reason'], $names);
    }

    /**
     * A hand-written stub rather than a generated mock: the suite builds its own
     * doubles so a PHPUnit major version cannot change their behaviour.
     */
    private static function fakeCompiler(string $name, string $version): CompilerInterface
    {
        return new class ($name, $version) implements CompilerInterface {
            /**
             * @var string
             */
            private $name;

            /**
             * @var string
             */
            private $version;

            public function __construct(string $name, string $version)
            {
                $this->name = $name;
                $this->version = $version;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function version(): string
            {
                return $this->version;
            }

            /**
             * @param list<string> $candidates
             */
            public function compile(
                string $entryCss,
                ImportResolverInterface $imports,
                array $candidates,
                CompileOptions $options
            ): CompileResult {
                throw new \LogicException('This double resolves a driver; it never compiles.');
            }
        };
    }
}
