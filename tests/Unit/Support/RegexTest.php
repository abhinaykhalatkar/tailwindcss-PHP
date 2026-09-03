<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Regex;

final class RegexTest extends TestCase
{
    /**
     * @var string|false
     */
    private $backtrackLimit = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backtrackLimit = \ini_get('pcre.backtrack_limit');
    }

    protected function tearDown(): void
    {
        if ($this->backtrackLimit !== false) {
            \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Regex::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function matchReportsHitsAndMisses(): void
    {
        self::assertTrue(Regex::match('/^abc$/', 'abc'));
        self::assertFalse(Regex::match('/^abc$/', 'abd'));
    }

    /** @test */
    public function matchPopulatesCaptureGroups(): void
    {
        $matches = null;

        self::assertTrue(Regex::match('/^(\w+)-(\d+)$/', 'text-3', $matches));
        self::assertSame('text-3', $matches[0]);
        self::assertSame('text', $matches[1]);
        self::assertSame('3', $matches[2]);
    }

    /** @test */
    public function matchEmptiesCapturesOnAMiss(): void
    {
        $matches = ['stale'];

        self::assertFalse(Regex::match('/^zzz$/', 'abc', $matches));
        self::assertSame([], $matches);
    }

    /** @test */
    public function matchAllCountsEveryOccurrence(): void
    {
        $matches = null;

        self::assertSame(3, Regex::matchAll('/a/', 'banana', $matches));
        self::assertSame(['a', 'a', 'a'], $matches[0]);
    }

    /** @test */
    public function replaceSubstitutes(): void
    {
        self::assertSame('a-b-c', Regex::replace('/\s+/', '-', 'a b  c'));
    }

    /** @test */
    public function replaceHonoursTheLimit(): void
    {
        self::assertSame('a-b  c', Regex::replace('/\s+/', '-', 'a b  c', 1));
    }

    /** @test */
    public function replaceCallbackReceivesTheMatch(): void
    {
        $result = Regex::replaceCallback('/\d+/', static function (array $m): string {
            return '<' . $m[0] . '>';
        }, 'a1b22c');

        self::assertSame('a<1>b<22>c', $result);
    }

    /** @test */
    public function splitReturnsAList(): void
    {
        self::assertSame(['a', 'b', 'c'], Regex::split('/,/', 'a,b,c'));
    }

    /** @test */
    public function splitAcceptsNoEmpty(): void
    {
        self::assertSame(['a', 'b'], Regex::split('/,/', 'a,,b', -1, \PREG_SPLIT_NO_EMPTY));
    }

    /**
     * The capture and offset flags make preg_split return nested arrays, which
     * cannot be a list<string>; they are refused rather than silently mangled.
     *
     * @test
     *
     * @dataProvider unsupportedSplitFlags
     */
    public function splitRefusesFlagsItCannotRepresent(int $flags): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PREG_SPLIT_NO_EMPTY');

        Regex::split('/,/', 'a,b', -1, $flags);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function unsupportedSplitFlags(): array
    {
        return [
            'offset capture' => [\PREG_SPLIT_OFFSET_CAPTURE],
            'delim capture' => [\PREG_SPLIT_DELIM_CAPTURE],
            'both' => [\PREG_SPLIT_OFFSET_CAPTURE | \PREG_SPLIT_DELIM_CAPTURE],
        ];
    }

    /**
     * The error handler installed around a PCRE call must not swallow warnings
     * raised by the caller's own callback — an undefined-key notice is not a
     * regex failure and must keep its normal reporting path.
     *
     * @test
     */
    public function aWarningFromTheUserCallbackIsNotReportedAsARegexFailure(): void
    {
        $seen = [];

        // Returns void, like most application handlers. PHP only falls through
        // to its own handler on an exact false, so a wrapper that coerced this
        // to false would make the warning be reported twice.
        self::installHandler(static function (int $severity, string $message) use (&$seen): void {
            $seen[] = $message;
        });

        try {
            $result = Regex::replaceCallback('/\d/', static function (array $m): string {
                // Not a PCRE failure: it must not become "Regex failure ...".
                \trigger_error('callback warning', \E_USER_WARNING);

                return '[' . $m[0] . ']';
            }, 'a1b');
        } finally {
            \restore_error_handler();
        }

        self::assertSame('a[1]b', $result);
        self::assertSame(['callback warning'], $seen, 'The warning must arrive exactly once.');
    }

    /**
     * Install an error handler that does not return a bool.
     *
     * PHPStan's stub for `set_error_handler()` requires `callable(…): bool`,
     * but PHP's own contract is looser: any return other than an exact `false`
     * means "handled", so a handler that returns nothing is both legal and
     * common. That is the case under test, so the callable is passed through a
     * `callable` parameter rather than inline.
     */
    private static function installHandler(callable $handler): void
    {
        \set_error_handler($handler);
    }

    /** @test */
    public function quoteEscapesMetacharacters(): void
    {
        self::assertSame('a\\.b', Regex::quote('a.b'));
        self::assertSame('a\\/b', Regex::quote('a/b', '/'));
    }

    /**
     * An unterminated pattern cannot compile. PHP emits a warning and returns
     * false; the wrapper must turn that into an exception rather than let a
     * caller read it as "no match".
     *
     * @test
     */
    public function anInvalidPatternThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Regex failure for pattern/');

        Regex::match('/unterminated', 'subject');
    }

    /** @test */
    public function anInvalidPatternThrowsFromEveryWrapper(): void
    {
        $calls = [
            'match' => static function (): void {
                Regex::match('/unterminated', 'x');
            },
            'matchAll' => static function (): void {
                Regex::matchAll('/unterminated', 'x');
            },
            'replace' => static function (): void {
                Regex::replace('/unterminated', '', 'x');
            },
            'replaceCallback' => static function (): void {
                Regex::replaceCallback('/unterminated', static function (array $m): string {
                    return '';
                }, 'x');
            },
            'split' => static function (): void {
                Regex::split('/unterminated', 'x');
            },
        ];

        foreach ($calls as $name => $call) {
            $threw = false;

            try {
                $call();
            } catch (\RuntimeException $e) {
                $threw = true;
            }

            self::assertTrue($threw, \sprintf('Regex::%s() must throw on an invalid pattern.', $name));
        }
    }

    /**
     * Threat T-12: a catastrophic backtrack must surface as a failure, not as a
     * silent "no match". Reproduced by shrinking the backtrack limit rather than
     * by burning real CPU.
     *
     * @test
     */
    public function exceedingTheBacktrackLimitThrows(): void
    {
        \ini_set('pcre.backtrack_limit', '100');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Regex failure for pattern/');

        Regex::match('/^(a+)+$/', \str_repeat('a', 200) . 'b');
    }

    /**
     * Byte semantics (design doc 18): no wrapper may add the `u` modifier behind
     * the caller's back. A subject that is not valid UTF-8 must still match
     * byte-wise — with `/u` PCRE would raise PREG_BAD_UTF8_ERROR instead.
     *
     * @test
     */
    public function patternsRunOnBytesNotCodepoints(): void
    {
        $invalidUtf8 = "\xff\xfe";

        self::assertTrue(Regex::match('/^..$/', $invalidUtf8));
        self::assertSame(2, Regex::matchAll('/./', $invalidUtf8));
    }

    /**
     * The same subject *with* an explicit `u` modifier does raise a PCRE error,
     * which proves the previous test is meaningful and that the error check is
     * wired up.
     *
     * @test
     */
    public function anExplicitUnicodeModifierOnInvalidUtf8Throws(): void
    {
        $this->expectException(\RuntimeException::class);

        Regex::match('/^..$/u', "\xff\xfe");
    }
}
