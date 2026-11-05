<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\CharClass;
use TailwindPhp\Tests\Support\Tripwire;

/**
 * Threat T12 (design doc 12): the extractor is a hand-written byte loop with
 * no backtracking, so classic regex-DoS shapes must cost it nothing more
 * than their length. This runs unconditionally (not gated on opcache): the
 * point of the control is "no catastrophic blow-up", and a budget generous
 * enough to hold on an un-JIT-ed interpreter still catches an accidental
 * quadratic/exponential regression, which is what this test exists to catch.
 *
 * @covers-requirement NFR-SEC-7
 */
final class T12RegexDosTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider adversarialInputs
     */
    public function adversarialInputCompletesWellUnderBudget(string $bytes): void
    {
        $extractor = new CandidateExtractor();

        $started = Tripwire::cpu();
        $result = $extractor->extract($bytes);
        $elapsed = Tripwire::cpu() - $started;

        self::assertContainsOnly('string', $result);
        // 200 ms is generous for an un-JIT-ed interpreter (design doc 12
        // names a 10 ms budget on the reference machine); the point is
        // ruling out catastrophic backtracking, not re-asserting the tight
        // production budget covered by T05's opcache-gated test.
        Tripwire::assertRegexUnder(2.0, $elapsed, \sprintf('%s (%d bytes)', __FUNCTION__, \strlen($bytes)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function adversarialInputs(): array
    {
        return [
            'many nested opens, unclosed' => [\str_repeat('(', 5000)],
            'many nested opens, closed' => [\str_repeat('(', 2500) . \str_repeat(')', 2500)],
            'many nested brackets, unclosed' => [\str_repeat('[', 5000)],
            'long letter run with trailing bang' => [\str_repeat('a', 5000) . '!'],
            'long letter run with leading bang' => ['!' . \str_repeat('a', 5000)],
            'alternating open-close pairs' => [\str_repeat('()', 3000)],
            'deeply nested balanced brackets' => [\str_repeat('[', 1000) . 'x' . \str_repeat(']', 1000)],
            'many short candidates separated by commas' => [\implode(',', \array_fill(0, 5000, 'x'))],
            'quote that never closes inside a bracket' => ['[' . \str_repeat('a', 5000) . "'" . \str_repeat('b', 5000)],
            'hyphens and colons repeated' => [\str_repeat('-:', 5000)],
        ];
    }

    /** @test */
    public function veryLargeAdversarialInputStillCompletesInASingleDigitSecondBudget(): void
    {
        $extractor = new CandidateExtractor();
        $bytes = \str_repeat('(', 200000);

        $started = Tripwire::cpu();
        $result = $extractor->extract($bytes);
        $elapsed = Tripwire::cpu() - $started;

        self::assertSame([], $result);
        // A true O(n) scan of 200 000 bytes should finish in low tens of
        // milliseconds even uncompiled; a quadratic/exponential regression
        // would blow well past this on any hardware.
        Tripwire::assertRegexUnder(2.0, $elapsed, __FUNCTION__);
    }

    /**
     * Review round 1 item 1 (CSF-A21): the resume-at-start+1 strategy that
     * recovers real candidates from a hostile prefix is itself bounded by a
     * rescan budget (4x the input length) once it has spent that much extra
     * work, it falls back to consuming a rejected span whole for the rest of
     * the input — this is what stops "resume at start+1 forever" from
     * becoming its own O(n^2) blow-up on a file built entirely from
     * one-byte-apart rejections.
     *
     * @test
     */
    public function theRescanBudgetEngagesOnARepeatingHostilePattern(): void
    {
        $extractor = new CandidateExtractor();
        $hostile = \str_repeat('[a', 500000); // 1 MB of "[a[a[a..."

        // A benign input of the same size, timed in the same process moments
        // apart, is the yardstick. An absolute millisecond bound measures the
        // machine as much as the algorithm: this row failed twice on the
        // development host, at 5.2 s and 10.5 s, purely because other suites
        // were running alongside it — and a security gate that fails under load
        // is one people learn to re-run rather than read.
        $benign = \str_repeat('flex items-center ', 55556); // ~1 MB of ordinary classes

        $benignMs = self::timeExtraction($extractor, $benign);
        $hostileMs = self::timeExtraction($extractor, $hostile);

        self::assertContainsOnly('string', $extractor->extract($hostile));

        // Two bounds, both of which must hold. The ratio is the real assertion —
        // hostile input must not cost dramatically more than ordinary input of
        // the same size — and the absolute ceiling is the backstop for the case
        // where *both* somehow became catastrophic. `max(1.0, …)` keeps the
        // ratio meaningful when the benign run is too fast to measure.
        //
        // **Why 5 and not 3.** The rescan budget this row exists to prove is
        // documented as *4x the input length*, so up to roughly four times the
        // scanning work is the design, not a regression. Measured here over
        // three runs: 2.76x, 3.02x, 3.28x — a 3x bound would fail about half
        // the time, which is the flakiness this change is meant to remove. Five
        // sits above the documented budget with room for noise, and a genuine
        // O(n^2) blow-up is orders of magnitude away from it, not 60% away.
        self::assertLessThan(
            \max(1.0, $benignMs) * 5.0,
            $hostileMs,
            \sprintf(
                'A 1 MB hostile-prefix pattern took %.1f ms against %.1f ms for benign input of the same '
                . 'size; the rescan budget is not engaging.',
                $hostileMs,
                $benignMs
            )
        );

        self::assertLessThan(30000.0, $hostileMs, 'A 1 MB hostile-prefix pattern must not blow up.');
    }

    /**
     * One extraction, in milliseconds.
     */
    private static function timeExtraction(CandidateExtractor $extractor, string $bytes): float
    {
        $start = \microtime(true);
        $extractor->extract($bytes);

        return (\microtime(true) - $start) * 1000.0;
    }

    /**
     * design doc 12 T-12 (review round 1 item 1): doubling the input must not
     * roughly quadruple the time — that would mean the budget is not doing
     * its job. 2.5x is the review's own tolerance for measurement noise on an
     * un-JIT-ed interpreter; the point is ruling out O(n^2), not pinning an
     * exact constant. Measured at 256 KB/512 KB rather than 1 MB/2 MB (the
     * review's own wording) purely to keep this test's wall-clock cost sane
     * on an un-JIT-ed interpreter — the ratio a ~quadratic algorithm would
     * show does not depend on the absolute size chosen.
     *
     * @test
     *
     * @dataProvider ratioShapes
     */
    public function doublingTheInputDoesNotMoreThanQuadrupleTheTime(string $baseSize): void
    {
        $extractor = new CandidateExtractor();
        $doubleSize = $baseSize . $baseSize;

        // One untimed warm-up call per shape so a cold-cache stat/opcode
        // fetch on the first invocation cannot skew the very first timing.
        $extractor->extract(\substr($baseSize, 0, 1024));

        // Without opcache/JIT this host's timings carry enough noise that a
        // single sample occasionally nudges past the ratio by measurement
        // error alone, particularly for the cheapest shapes (a run of '('
        // never even enters consumeRun(), so its absolute time is tiny and
        // proportionally noisier). Genuine O(n^2) behaviour would blow past
        // 2.5x by a wide margin on every attempt, so taking the best of
        // several samples filters noise without hiding a real regression.
        $bestRatio = \INF;

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $start = \microtime(true);
            $extractor->extract($baseSize);
            $baseSizeMs = \max((\microtime(true) - $start) * 1000, 0.001);

            $start = \microtime(true);
            $extractor->extract($doubleSize);
            $doubleSizeMs = (\microtime(true) - $start) * 1000;

            $bestRatio = \min($bestRatio, $doubleSizeMs / $baseSizeMs);
        }

        self::assertLessThanOrEqual(
            2.5,
            $bestRatio,
            \sprintf('Best-of-three double/base size time ratio was %.2f — exceeds 2.5x.', $bestRatio)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ratioShapes(): array
    {
        $size = 256 * 1024;

        return [
            'many open parens' => [\substr(\str_repeat('(', $size), 0, $size)],
            'many open brackets' => [\substr(\str_repeat('[', $size), 0, $size)],
            'bracket-letter repeated' => [\substr(\str_repeat('[a', $size), 0, $size)],
            'quoted bracket-letter repeated' => [\substr(\str_repeat('["a', $size), 0, $size)],
            'hyphen-digit repeated' => [\substr(\str_repeat('-1', $size), 0, $size)],
            'random bytes' => [\random_bytes($size)],
        ];
    }
    /**
     * The P0 of review round 2: a long unbroken token followed by a quoted
     * array used to hang the request forever.
     *
     * Two defects compounded. An over-long run restarting at `start + 1` cost
     * 257 bytes of rescan budget for one byte of progress, so a 2 KB data URI
     * drained the whole budget; once exhausted, a rejected run consumed its
     * bytes — except `consumeRun()` returns `end === start` for a `[` followed
     * by a quote, so the cursor stopped moving and the loop spun.
     *
     * Both halves are asserted here: the classes after the blob are recovered
     * (so the scan got past it) and the whole thing returns promptly (so the
     * cursor always advanced).
     *
     * @param list<string> $expected
     *
     * @dataProvider hangRepros
     *
     * @test
     */
    public function alongTokenFollowedByAquotedArrayDoesNotHang(string $input, array $expected): void
    {
        $started = Tripwire::cpu();
        $candidates = (new CandidateExtractor())->extract($input);
        $elapsed = Tripwire::cpu() - $started;

        foreach ($expected as $class) {
            self::assertContains($class, $candidates, \sprintf('%s was lost after the long token.', $class));
        }

        // Generous next to the ~2 ms this actually takes: the failure mode is
        // an infinite loop, so any bound at all is the assertion.
        Tripwire::assertRegexUnder(2.0, $elapsed, \sprintf('%s (%d bytes)', __FUNCTION__, \strlen($input)));
    }

    /**
     * CSF-A21a as a property, not as a stopwatch.
     *
     * The wall-clock bound above proves the two known repros terminate; it says
     * nothing about shapes nobody thought of. The invariant that actually makes
     * a hang impossible is structural — `consumeRun()` never returns a position
     * before its start, and an accepted run always advances — so it is asserted
     * directly and exhaustively over the alphabet where the bug lived.
     *
     * Every string of length ≤ 3 over the structural bytes plus a letter,
     * probed at every start offset.
     *
     * @test
     */
    public function consumeRunAlwaysAdvancesOverTheStructuralAlphabet(): void
    {
        $extractor = new CandidateExtractor();
        $method = new \ReflectionMethod($extractor, 'consumeRun');

        $alphabet = ['[', ']', '(', ')', '"', "'", '@', '-', ':', '!', ' ', "\n", 'a'];
        $inputs = $alphabet;

        foreach ($alphabet as $b) {
            foreach ($alphabet as $c) {
                $inputs[] = $b . $c;

                foreach ($alphabet as $d) {
                    $inputs[] = $b . $c . $d;
                }
            }
        }

        $probes = 0;

        foreach ($inputs as $input) {
            $length = \strlen($input);

            for ($start = 0; $start < $length; ++$start) {
                // `consumeRun()` has a precondition: `extract()` only calls it
                // once the byte at $start is a START byte. Probing other offsets
                // reports 3 738 "violations" that the real loop can never reach
                // — measured, and the reason this filter is here rather than an
                // afterthought.
                if ((CharClass::TABLE[\ord($input[$start])] & CharClass::START) === 0) {
                    continue;
                }

                [$end, $accepted] = $method->invoke($extractor, $input, $length, $start);
                ++$probes;

                self::assertGreaterThanOrEqual($start, $end, \sprintf(
                    'consumeRun went backwards on %s at offset %d.',
                    \json_encode($input),
                    $start
                ));

                if ($accepted) {
                    self::assertGreaterThan($start, $end, \sprintf(
                        'An accepted run consumed nothing on %s at offset %d.',
                        \json_encode($input),
                        $start
                    ));
                }
            }
        }

        // Guards the guard: if the generator ever stopped producing inputs this
        // test would pass while probing nothing.
        self::assertGreaterThan(1000, $probes, 'The alphabet sweep probed far fewer positions than expected.');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function hangRepros(): array
    {
        return [
            'data uri then class array' => [
                "const ICON = 'data:image/png;base64," . \str_repeat('A', 2200) . "';\n"
                . "export const SIZES = ['flex', 'p-4', 'mt-2'];\n"
                . \str_repeat("// filler comment line\n", 200),
                ['flex', 'p-4', 'mt-2'],
            ],
            'bracket run then quoted array' => [
                \str_repeat('[', 300) . \str_repeat('a', 300) . "['x']",
                [],
            ],
            'three kilobyte token then class array' => [
                \str_repeat('Q', 3072) . " const C = ['flex', 'p-4'];",
                ['flex', 'p-4'],
            ],
        ];
    }
}
