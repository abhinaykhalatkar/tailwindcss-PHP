<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Http\HttpDate;
use TailwindPhp\Tests\Support\Tripwire;

/**
 * RFC 7231 §7.1.1.1 date handling (TASK-0016 acceptance 3).
 *
 * Two properties matter and everything below is one of them:
 *
 * - **What we emit is always IMF-fixdate in GMT.** That is the only form RFC
 *   7231 permits a sender to generate, and `Last-Modified` is compared verbatim
 *   by some caches — a locale-dependent day name or a non-GMT offset is a cache
 *   miss at best.
 * - **What we accept is only what we can trust.** `parse()` returns `null`
 *   rather than guessing, because the value comes from a request header
 *   (`If-Modified-Since`) and a wrong guess means serving a 304 for content
 *   that did change. The two obsolete formats are permitted to return null by
 *   the acceptance criteria, and this pins which behaviour was chosen.
 */
final class HttpDateTest extends TestCase
{
    /**
     * A known instant, chosen because it is a Sunday: the day name is the part
     * a locale-dependent implementation gets wrong first.
     */
    private const EPOCH_SUNDAY = 784111777;
    private const EPOCH_SUNDAY_TEXT = 'Sun, 06 Nov 1994 08:49:37 GMT';

    /** @test */
    public function formatProducesImfFixdateInGmt(): void
    {
        self::assertSame(self::EPOCH_SUNDAY_TEXT, HttpDate::format(self::EPOCH_SUNDAY));
    }

    /**
     * The format must not follow the ambient timezone. A server in Berlin and a
     * server in Denver must emit the same bytes for the same instant, or the
     * header stops being comparable.
     *
     * @test
     */
    public function formatIsIndependentOfTheDefaultTimezone(): void
    {
        $original = \date_default_timezone_get();

        try {
            \date_default_timezone_set('America/Denver');
            $denver = HttpDate::format(self::EPOCH_SUNDAY);

            \date_default_timezone_set('Asia/Tokyo');
            $tokyo = HttpDate::format(self::EPOCH_SUNDAY);

            self::assertSame(self::EPOCH_SUNDAY_TEXT, $denver);
            self::assertSame(self::EPOCH_SUNDAY_TEXT, $tokyo);
        } finally {
            \date_default_timezone_set($original);
        }
    }

    /** @test */
    public function parseReadsThePreferredForm(): void
    {
        self::assertSame(self::EPOCH_SUNDAY, HttpDate::parse(self::EPOCH_SUNDAY_TEXT));
    }

    /** @test */
    public function formatAndParseRoundTrip(): void
    {
        foreach ([0, 1, self::EPOCH_SUNDAY, 1700000000, 2147483647] as $timestamp) {
            self::assertSame($timestamp, HttpDate::parse(HttpDate::format($timestamp)), \sprintf(
                'Round trip failed for %d.',
                $timestamp
            ));
        }
    }

    /**
     * Anything that is not the preferred form returns null rather than a guess.
     *
     * The two obsolete forms (RFC 850 and asctime) are in this list on purpose:
     * the acceptance criteria permit returning null for them, and a header we
     * cannot read confidently must produce a full 200, never a 304 for content
     * that changed.
     *
     * @dataProvider unparseable
     *
     * @test
     */
    public function parseReturnsNullRatherThanGuessing(string $value): void
    {
        self::assertNull(HttpDate::parse($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unparseable(): array
    {
        return [
            'empty' => [''],
            'rfc 850 obsolete form' => ['Sunday, 06-Nov-94 08:49:37 GMT'],
            'asctime obsolete form' => ['Sun Nov  6 08:49:37 1994'],
            'not a date at all' => ['tomorrow'],
            'missing GMT' => ['Sun, 06 Nov 1994 08:49:37'],
            'non-GMT zone' => ['Sun, 06 Nov 1994 08:49:37 CET'],
            'wrong day name for the date' => ['Mon, 06 Nov 1994 08:49:37 GMT'],
            'impossible month' => ['Sun, 06 Xxx 1994 08:49:37 GMT'],
            'impossible day' => ['Sun, 32 Nov 1994 08:49:37 GMT'],
            'impossible hour' => ['Sun, 06 Nov 1994 99:49:37 GMT'],
            'single digit day unpadded' => ['Sun, 6 Nov 1994 08:49:37 GMT'],
            'trailing newline' => ["Sun, 06 Nov 1994 08:49:37 GMT\n"],
            'leading space' => [' Sun, 06 Nov 1994 08:49:37 GMT'],
            'nul byte' => ["Sun, 06 Nov 1994 08:49:37 GMT\0"],
        ];
    }

    /**
     * A header value is attacker-controlled, so parsing must not be a way to
     * spend the request's time budget.
     *
     * @test
     */
    public function parsingALongHostileValueIsCheap(): void
    {
        $started = Tripwire::cpu();

        for ($i = 0; $i < 200; ++$i) {
            self::assertNull(HttpDate::parse(\str_repeat('Sun, 06 Nov 1994 08:49:37 GMT, ', 300)));
        }

        Tripwire::assertUnder(5.0, Tripwire::cpu() - $started, __FUNCTION__);
    }
}
