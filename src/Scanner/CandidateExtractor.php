<?php

declare(strict_types=1);

namespace TailwindPhp\Scanner;

use TailwindPhp\Support\Regex;

/**
 * Reproduces Tailwind v4's byte-scanning extractor (design doc 09 §Candidate
 * extraction, corrected by design decisions CSF-A21/CSF-A22 in review
 * round 1).
 *
 * The main loop is a hand-written byte walk (design doc 12 threat T-12).
 * Every attempt is capped at {@see self::MAX_CANDIDATE_BYTES} bytes
 * regardless of why it is still going (CSF-A21), so a single confirmed-start
 * position costs at most a small constant amount of work. When an attempt is
 * *structurally* rejected (an unclosed bracket, whitespace inside one, a
 * disallowed bracket opening — see {@see self::consumeRun()}), scanning
 * resumes one byte after that attempt's start rather than wherever it gave
 * up, so a single hostile prefix cannot swallow every candidate after it.
 * That resume strategy is itself bounded by a global rescan budget (four
 * times the input length) so that a file built entirely from rejected
 * prefixes still costs O(n) overall: once the budget is spent, rejections
 * fall back to consuming their whole span, the same as before. The
 * per-candidate rejection checks that use a regex operate on an already
 * bounded (≤ 256 byte) string through {@see Regex}, the only place PCRE is
 * ever called from.
 *
 * Precision is deliberately traded for recall (design doc 09): an extra
 * false-positive candidate costs the compiler a microsecond, a missed real
 * class is a visible bug.
 */
final class CandidateExtractor implements ExtractorInterface
{
    private const MAX_CANDIDATE_BYTES = 256;

    /**
     * CSF-A21: total bytes re-scanned across every structurally-rejected
     * attempt is capped at this multiple of the input length.
     */
    private const RESCAN_BUDGET_MULTIPLIER = 4;

    /**
     * {@see self::tryOpenAtDepthZero()} outcomes.
     */
    private const OPEN_ALLOWED = 0;
    private const OPEN_BOUNDARY = 1;
    private const OPEN_REJECTED = 2;

    /**
     * Extensions that make a run "look like a file path" rather than a class
     * candidate (design doc 09 rejection rule 7). Not exhaustive by design —
     * a false negative here just means one extra harmless candidate.
     *
     * @var list<string>
     */
    private const KNOWN_FILE_EXTENSIONS = [
        'css', 'scss', 'js', 'mjs', 'ts', 'jsx', 'tsx', 'json', 'html', 'htm',
        'php', 'phtml', 'twig', 'vue', 'svelte', 'md', 'mdx', 'xml', 'svg',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'woff', 'woff2', 'ttf',
        'eot', 'otf', 'pdf', 'mp4', 'webm', 'mp3', 'gz', 'map',
    ];

    /**
     * {@inheritDoc}
     */
    public function extract(string $bytes): array
    {
        $length = \strlen($bytes);
        $candidates = [];
        $i = 0;
        $rescanBudget = self::RESCAN_BUDGET_MULTIPLIER * $length;
        $budgetExhausted = false;
        $table = CharClass::TABLE;

        while ($i < $length) {
            if (($table[\ord($bytes[$i])] & CharClass::START) === 0) {
                ++$i;

                continue;
            }

            $start = $i;
            [$end, $accepted, $sawLetter, $overLong] = $this->consumeRun($bytes, $length, $start);

            if ($accepted) {
                if ($sawLetter) {
                    $candidate = $this->applyRejectionRules(\substr($bytes, $start, $end - $start));

                    if ($candidate !== null) {
                        $candidates[$candidate] = true;
                    }
                }

                // Accepted runs are non-empty by construction, but the max()
                // costs nothing and makes the advance invariant hold on every
                // path rather than on every path anyone remembered to check.
                $i = \max($end, $start + 1);

                continue;
            }

            if ($overLong) {
                // CSF-A21b. An over-long run is a base64 blob, a JWT, a minified
                // bundle — something with no terminator, not a near-miss
                // candidate. Restarting at `start + 1` inside one would reject
                // once per byte, each rejection costing 257 bytes of budget for
                // one byte of progress: a 2 KB data URI drains a 4×-input budget
                // on its own, and item 1's hang was waiting on the other side of
                // it. Resuming at the next structural byte costs one rejection
                // for the whole blob instead.
                //
                // It still draws on the budget, which is not what the rule said
                // and is needed anyway: `[a[a[a…` is *all* structure, so the
                // boundary is two bytes along and a 257-byte scan buys two bytes
                // of progress — quadratic, and measured at 21.7 s for 1 MB when
                // this branch was exempt. Charging it means a hostile repeat
                // exhausts the budget and falls through to consuming its bytes,
                // while a blob (one rejection, no boundaries inside) barely
                // touches it. Both shapes stay linear.
                if (!$budgetExhausted) {
                    $rescanBudget -= ($end - $start);

                    if ($rescanBudget < 0) {
                        $budgetExhausted = true;
                    }

                    $i = \max($this->nextBoundary($bytes, $length, $start), $start + 1);

                    continue;
                }

                $i = \max($end, $start + 1);

                continue;
            }

            // CSF-A21: resume right after the start byte instead of wherever
            // the failed attempt gave up, bounded by a global budget so a
            // file built entirely from rejected prefixes still costs O(n).
            if (!$budgetExhausted) {
                $rescanBudget -= ($end - $start);

                if ($rescanBudget < 0) {
                    $budgetExhausted = true;
                }

                $i = $start + 1;

                continue;
            }

            // The budget is spent, so a rejected run consumes its bytes. `max()`
            // is the hard invariant, not a precaution: `consumeRun()` returns
            // `end === start` for a run rejected on its very first byte (a `[`
            // followed by a quote), and `$i = $end` then re-examined the same
            // byte forever. That was a hung request, not a slow one.
            $i = \max($end, $start + 1);
        }

        $result = \array_keys($candidates);
        \sort($result, \SORT_STRING);

        return $result;
    }

    /**
     * Advance from a confirmed start byte to the end of one attempt.
     *
     * The tuple is: where the attempt stopped; whether it ended at an ordinary
     * boundary rather than a structural rejection (CSF-A21/CSF-A22); whether it
     * contained a letter; and whether the rejection was the over-long one, which
     * resumes at the next structural byte rather than at start + 1 (CSF-A21b).
     *
     * @return array{0: int, 1: bool, 2: bool, 3: bool}
     */
    private function consumeRun(string $bytes, int $length, int $start): array
    {
        $i = $start;
        $depth = 0;
        $inString = false;
        $quoteByte = 0;
        $sawLetter = false;
        $table = CharClass::TABLE;

        while ($i < $length) {
            // CSF-A21: over-long is a structural rejection, checked before
            // anything else so a pathological attempt never scans further
            // than this regardless of what it contains.
            if ($i - $start > self::MAX_CANDIDATE_BYTES) {
                return [$i, false, $sawLetter, true];
            }

            $ord = \ord($bytes[$i]);

            // Inlined rather than routed through Bytes::isAsciiAlpha(): this
            // runs once per byte of the input, and passing a one-character
            // string just to re-derive the ordinal already in hand would add
            // a function call to the hottest loop in the library for no
            // benefit.
            if (($ord >= 65 && $ord <= 90) || ($ord >= 97 && $ord <= 122)) {
                $sawLetter = true;
            }

            $flags = $table[$ord];

            // CSF-A22a: whitespace ends a run at every depth. Tailwind never
            // allows whitespace inside a candidate (arbitrary values use `_`
            // instead), so mid-bracket or mid-string whitespace means this
            // was never a real candidate to begin with.
            if (($flags & CharClass::WHITESPACE) !== 0) {
                if ($depth > 0 || $inString) {
                    return [$i, false, $sawLetter, false];
                }

                return [$i, true, $sawLetter, false];
            }

            if ($inString) {
                if ($ord === $quoteByte) {
                    $inString = false;
                }

                ++$i;

                continue;
            }

            if ($depth > 0) {
                if (($flags & CharClass::CLOSE) !== 0) {
                    --$depth;
                    ++$i;

                    continue;
                }

                if (($flags & CharClass::OPEN) !== 0) {
                    // CSF-A22e, as amended in round 2. The adjacent-opener
                    // rejection exists to split `clsx(['flex'])`-style source
                    // apart, and that shape is recognisable because the pair
                    // *starts* the run. Applying it at any depth also rejected
                    // legitimate arbitrary values whose nesting is the whole
                    // point — `w-[calc((100%-1rem)/2)]`,
                    // `text-[length:calc((1rem+2px))]` — so inside a group that
                    // opened legitimately, nested openers are allowed and only
                    // the 256-byte cap bounds them.
                    if ($i - 1 === $start && CharClass::is(\ord($bytes[$i - 1]), CharClass::OPEN)) {
                        return [$i, false, $sawLetter, false];
                    }

                    ++$depth;
                    ++$i;

                    continue;
                }

                if (($flags & CharClass::QUOTE) !== 0) {
                    $inString = true;
                    $quoteByte = $ord;
                    ++$i;

                    continue;
                }

                // Anything else continues inside the bracket.
                ++$i;

                continue;
            }

            // Depth zero.
            if (($flags & CharClass::CLOSE) !== 0) {
                // Unbalanced closer: an ordinary boundary, not consumed.
                return [$i, true, $sawLetter, false];
            }

            if (($flags & CharClass::OPEN) !== 0) {
                $outcome = $this->tryOpenAtDepthZero($bytes, $i, $start, $length, $ord);

                if ($outcome === self::OPEN_REJECTED) {
                    return [$i, false, $sawLetter, false];
                }

                if ($outcome === self::OPEN_BOUNDARY) {
                    return [$i, true, $sawLetter, false];
                }

                ++$depth;
                ++$i;

                continue;
            }

            // Depth zero: only a CONTINUE byte extends the run (`,` is
            // excluded from CONTINUE at every depth per CSF-A7, so it falls
            // through to the boundary below without special-casing it here).
            if (($flags & CharClass::CONTINUE) !== 0) {
                ++$i;

                continue;
            }

            return [$i, true, $sawLetter, false];
        }

        if ($depth > 0 || $inString) {
            return [$length, false, $sawLetter, false];
        }

        return [$length, true, $sawLetter, false];
    }

    /**
     * Whether a `[` or `(` byte at depth zero actually opens a new depth
     * level (design decision CSF-A22c/CSF-A22d).
     *
     * `(` only continues a run when the byte immediately before it is `-`
     * (`bg-(--x)`); anything else (a JS function call, say) ends the run
     * here without consuming the paren. `[` also continues when the run is
     * still empty — it is the run's own first byte, as in an arbitrary
     * variant like `[&:nth-child(3)]:underline` — or when the previous byte
     * is `:` (`hover:[&>*]:p-2`). A `[` opened any way other than via `-` is
     * rejected outright if a quote follows it immediately (`['`, `["`, or
     * `:['`): that shape is what a JS array literal or an object property
     * access looks like at the byte level, not a Tailwind arbitrary value,
     * and rejecting it lets CSF-A21's resume-at-start+1 recover the quoted
     * class names it was hiding (e.g. `x['flex']` recovers `flex`).
     */
    private function tryOpenAtDepthZero(string $bytes, int $i, int $start, int $length, int $ord): int
    {
        $emptyRun = $i === $start;
        $prevByte = $emptyRun ? '' : $bytes[$i - 1];

        if ($ord === 91) { // '['
            $openedViaHyphen = !$emptyRun && $prevByte === '-';
            // $prevByte is only meaningful once $emptyRun is known false —
            // guaranteed by this point, since the first two disjuncts having
            // failed already rules $emptyRun out.
            // `@` joins `-` and `:` (CSF-A22c as amended in round 2): the
            // container-query variants `@[400px]:flex` and `@[36rem]/main:grid`
            // open their bracket straight after the `@`, and without it the run
            // was split and the `@` lost.
            $eligible = $emptyRun || $openedViaHyphen || $prevByte === ':' || $prevByte === '@';

            if (!$eligible) {
                return self::OPEN_BOUNDARY;
            }

            if (!$openedViaHyphen && $i + 1 < $length && CharClass::is(\ord($bytes[$i + 1]), CharClass::QUOTE)) {
                return self::OPEN_REJECTED;
            }

            return self::OPEN_ALLOWED;
        }

        // '(' — only eligible right after '-'.
        return (!$emptyRun && $prevByte === '-') ? self::OPEN_ALLOWED : self::OPEN_BOUNDARY;
    }

    /**
     * The first structural byte strictly after $start (CSF-A21b).
     *
     * "Structural" means whitespace, a quote, or a bracket — the places a real
     * candidate could plausibly begin. Restarting there is what makes an
     * over-long run cost one rejection instead of one per byte: inside a 2 KB
     * base64 blob there is no structure, so the scan steps over the whole thing
     * and resumes where the file becomes code again.
     *
     * Returns $length when nothing structural remains, which ends the scan.
     */
    private function nextBoundary(string $bytes, int $length, int $start): int
    {
        $table = CharClass::TABLE;

        for ($i = $start + 1; $i < $length; ++$i) {
            $class = $table[\ord($bytes[$i])];

            if (($class & (CharClass::WHITESPACE | CharClass::QUOTE | CharClass::OPEN | CharClass::CLOSE)) !== 0) {
                return $i;
            }
        }

        return $length;
    }

    /**
     * Design doc 09 rejection rules, in order (cheap checks first); CSF-A8
     * corrects the fifth rule from "keep" to "drop".
     *
     * The caller only reaches this method when the run contained a letter
     * (rule 2) and consumeRun() already bounds its length to
     * self::MAX_CANDIDATE_BYTES, so both are re-checked here only as a
     * defensive assertion, not because either is expected to fire — the trim
     * loop below only ever removes `-`, `:` or `/` from the end, none of
     * which is a letter, so a run that contained one before trimming still
     * contains it afterwards.
     */
    private function applyRejectionRules(string $run): ?string
    {
        if ($run === '' || \strlen($run) > self::MAX_CANDIDATE_BYTES) {
            return null;
        }

        if (
            self::isAsciiDigit($run[0])
            && \strpos($run, ':') === false
            && \strpos($run, '-') === false
        ) {
            return null;
        }

        $run = self::trimTrailingPunctuation($run);

        if ($run === '') {
            return null;
        }

        if ($run[0] === '-' && \strlen($run) >= 2 && self::isAsciiDigit($run[1])) {
            return null;
        }

        if (\strpos($run, '//') !== false || \strncmp($run, 'http', 4) === 0) {
            return null;
        }

        if (self::looksLikeFilePath($run)) {
            return null;
        }

        // design doc 09 rule 8 also names a bare hex colour ("#fff") as a pure
        // value to drop, but '#' is not in CharClass::START, so a candidate
        // can never begin with one — there is no run for that branch to ever
        // see. Only the numeric-plus-unit form is reachable, and gating on the
        // digit check skips a Regex::match() call for the common case of a
        // run that starts with a letter.
        if (self::isAsciiDigit($run[0]) && \strpos($run, '[') === false && self::isPureCssValue($run)) {
            return null;
        }

        return $run;
    }

    private static function isAsciiDigit(string $byte): bool
    {
        $ordinal = \ord($byte);

        return $ordinal >= 48 && $ordinal <= 57;
    }

    private static function trimTrailingPunctuation(string $run): string
    {
        while ($run !== '') {
            $last = $run[\strlen($run) - 1];

            if ($last !== '-' && $last !== ':' && $last !== '/') {
                break;
            }

            $run = (string) \substr($run, 0, -1);
        }

        return $run;
    }

    private static function looksLikeFilePath(string $run): bool
    {
        if (\strpos($run, '/') === false) {
            return false;
        }

        $lastDot = \strrpos($run, '.');

        if ($lastDot === false) {
            return false;
        }

        $extension = \strtolower(\substr($run, $lastDot + 1));

        return \in_array($extension, self::KNOWN_FILE_EXTENSIONS, true);
    }

    private static function isPureCssValue(string $run): bool
    {
        return Regex::match('/^[0-9]+(\.[0-9]+)?[a-zA-Z%]*$/D', $run);
    }
}
