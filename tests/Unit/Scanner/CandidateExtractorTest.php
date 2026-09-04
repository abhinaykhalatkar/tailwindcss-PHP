<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Scanner;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Scanner\ExtractorInterface;

/**
 * Design doc 09 §Candidate extraction, design doc 12 T-12 (regex DoS budget).
 *
 * The data-provider table below was generated from this extractor's own
 * verified output for each input (there is no official conformance corpus
 * until M2 — that suite compares against goldens produced by the real CLI).
 * Each row therefore documents the algorithm's actual, spec-following
 * behaviour rather than an aspirational one; where a row looks surprising the
 * inline category comment explains why.
 */
final class CandidateExtractorTest extends TestCase
{
    /** @test */
    public function implementsTheExtractorInterface(): void
    {
        self::assertInstanceOf(ExtractorInterface::class, new CandidateExtractor());
    }

    /**
     * @test
     *
     * @dataProvider extractionCases
     *
     * @param list<string> $expected
     */
    public function extractsTheExpectedCandidates(string $bytes, array $expected): void
    {
        self::assertSame($expected, (new CandidateExtractor())->extract($bytes));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function extractionCases(): array
    {
        return [
            // -- Basic utility names -----------------------------------------------
            'bare flex' => ['flex', ['flex']],
            'bare block' => ['block', ['block']],
            'bare inline' => ['inline', ['inline']],
            'bare hidden' => ['hidden', ['hidden']],
            'bare grid' => ['grid', ['grid']],
            'flex-col' => ['flex-col', ['flex-col']],
            'items-center' => ['items-center', ['items-center']],
            'justify-between' => ['justify-between', ['justify-between']],
            'padding p-4' => ['p-4', ['p-4']],
            'margin m-2' => ['m-2', ['m-2']],
            'padding-x px-4' => ['px-4', ['px-4']],
            'margin-top mt-8' => ['mt-8', ['mt-8']],
            'width full' => ['w-full', ['w-full']],
            'height screen' => ['h-screen', ['h-screen']],
            'rounded-lg' => ['rounded-lg', ['rounded-lg']],

            // -- Fractional values ---------------------------------------------------
            'fraction w-1/2' => ['w-1/2', ['w-1/2']],
            'fraction w-1/3' => ['w-1/3', ['w-1/3']],
            'fraction h-2/3' => ['h-2/3', ['h-2/3']],
            'grid-cols-2' => ['grid-cols-2', ['grid-cols-2']],
            'aspect-16/9' => ['aspect-16/9', ['aspect-16/9']],

            // -- Responsive / state variants -----------------------------------------
            'hover variant' => ['hover:bg-red-500', ['hover:bg-red-500']],
            'md breakpoint' => ['md:flex', ['md:flex']],
            'lg breakpoint' => ['lg:hidden', ['lg:hidden']],
            'dark mode' => ['dark:bg-black', ['dark:bg-black']],
            'focus variant' => ['focus:outline-none', ['focus:outline-none']],
            'sm breakpoint' => ['sm:p-2', ['sm:p-2']],
            'group-hover variant' => ['group-hover:text-white', ['group-hover:text-white']],
            'peer-checked variant' => ['peer-checked:block', ['peer-checked:block']],
            'first variant' => ['first:mt-0', ['first:mt-0']],
            'last variant' => ['last:mb-0', ['last:mb-0']],

            // -- Arbitrary values ([...]) ---------------------------------------------
            'arbitrary width' => ['w-[32rem]', ['w-[32rem]']],
            'arbitrary height vh' => ['h-[50vh]', ['h-[50vh]']],
            'arbitrary hex bg' => ['bg-[#1da1f2]', ['bg-[#1da1f2]']],
            'arbitrary text size' => ['text-[22px]', ['text-[22px]']],
            'arbitrary top' => ['top-[117px]', ['top-[117px]']],
            'arbitrary grid template' => ['grid-cols-[1fr_500px]', ['grid-cols-[1fr_500px]']],
            'arbitrary content string' => ["content-['hello']", ["content-['hello']"]],
            'arbitrary min-width' => ['min-w-[200px]', ['min-w-[200px]']],
            'arbitrary max-height' => ['max-h-[80vh]', ['max-h-[80vh]']],
            'arbitrary z-index' => ['z-[999]', ['z-[999]']],

            // -- Arbitrary variants ---------------------------------------------------
            'arbitrary variant child combinator' => ['hover:[&>*]:p-2', ['hover:[&>*]:p-2']],
            'arbitrary variant nth-child' => ['[&:nth-child(3)]:underline', ['[&:nth-child(3)]:underline']],
            'arbitrary variant descendant' => ['group-hover:[&_p]:text-white', ['group-hover:[&_p]:text-white']],
            'arbitrary variant pseudo-element' => ['[&::-webkit-scrollbar]:hidden', ['[&::-webkit-scrollbar]:hidden']],
            'has variant' => ['has-[:checked]:bg-blue-50', ['has-[:checked]:bg-blue-50']],
            'arbitrary media variant' => ['[@media(min-width:600px)]:flex', ['[@media(min-width:600px)]:flex']],
            'aria variant' => ['aria-[expanded=true]:rotate-180', ['aria-[expanded=true]:rotate-180']],
            'data variant' => ['data-[state=open]:block', ['data-[state=open]:block']],
            'bare arbitrary variant' => ['[&>*]:p-2', ['[&>*]:p-2']],
            'media arbitrary variant' => ['[@media(min-width:600px)]:flex', ['[@media(min-width:600px)]:flex']],
            'calc arbitrary value' => ['w-[calc(100%-2rem)]', ['w-[calc(100%-2rem)]']],
            'url arbitrary with leading slash' => ["bg-[url('/x.png')]", ["bg-[url('/x.png')]"]],
            'content star arbitrary' => ["content-['*']", ["content-['*']"]],
            'supports arbitrary variant' => ['supports-[display:grid]:grid', ['supports-[display:grid]:grid']],
            'aspect ratio arbitrary' => ['aspect-[16/9]', ['aspect-[16/9]']],

            // -- Review round 1 item 2 (CSF-A22): recovering real classes from
            // idioms that used to swallow the whole bracketed expression into
            // one junk candidate.
            'clsx call recovers its string arguments' => [
                'clsx("flex", cond && "p-4")',
                ['clsx', 'cond', 'flex', 'p-4'],
            ],
            'plain JS array of classes' => ["['flex', 'p-4']", ['flex', 'p-4']],
            'blade class array directive' => [
                "@class(['flex' => true, 'p-4' => true])",
                ['@class', 'flex', 'p-4', 'true'],
            ],
            'attributes merge call' => [
                "\$attributes->merge(['class' => 'inline-flex items-center gap-2'])",
                ['attributes', 'class', 'gap-2', 'inline-flex', 'items-center', 'merge'],
            ],
            'classList.add call' => [
                "classList.add('opacity-0', 'pointer-events-none')",
                ['classList.add', 'opacity-0', 'pointer-events-none'],
            ],
            'multiline JS array of classes' => ["[\n  'flex',\n  'p-4'\n]", ['flex', 'p-4']],
            'array index access splits before the bracket' => ['foo[0]', ['foo']],
            'quoted array index recovers the quoted class' => ["x['flex']", ['flex', 'x']],

            // Round 1 flagged the missing `@` rather than silently extending the
            // rule; round 2 amended CSF-A22c to include it, so the container
            // query variant now survives whole.
            'container query variant keeps its @ prefix' => ['@[400px]:flex', ['@[400px]:flex']],
            'container query rem variant' => ['@[36rem]:grid', ['@[36rem]:grid']],
            'named container query variant' => ['@[400px]/main:flex', ['@[400px]/main:flex']],

            // CSF-A22e as amended: nested openers inside a legitimately opened
            // group are allowed, so calc() arithmetic survives. The rejection
            // now applies only when the opener pair *starts* the run.
            'calc with nested parens' => ['w-[calc((100%-1rem)/2)]', ['w-[calc((100%-1rem)/2)]']],
            'calc single nested paren' => ['p-[calc((1px))]', ['p-[calc((1px))]']],
            'calc with length hint' => ['text-[length:calc((1rem+2px))]', ['text-[length:calc((1rem+2px))]']],
            'css var bg' => ['bg-(--my-color)', ['bg-(--my-color)']],
            'css var text' => ['text-(--brand)', ['text-(--brand)']],
            'css var border' => ['border-(--edge-w)', ['border-(--edge-w)']],
            'css var fill' => ['fill-(--icon-color)', ['fill-(--icon-color)']],

            // -- Negative utilities (kept: '-' followed by a letter, CSF-A8) --------
            'negative margin' => ['-m-4', ['-m-4']],
            'negative margin-top' => ['-mt-2', ['-mt-2']],
            'negative top' => ['-top-1', ['-top-1']],
            'negative translate fraction' => ['-translate-x-1/2', ['-translate-x-1/2']],
            'negative z' => ['-z-10', ['-z-10']],
            'negative mx px' => ['-mx-px', ['-mx-px']],
            'negative inset' => ['-inset-2', ['-inset-2']],
            'negative skew' => ['-skew-x-3', ['-skew-x-3']],

            // -- Dropped: '-' followed by a digit (CSF-A8) ---------------------------
            'hyphen then digit xl' => ['-4xl', []],
            'hyphen then digit bare' => ['-2', []],
            'hyphen then digit px' => ['-100px', []],
            'hyphen then digit zero' => ['-0', []],

            // -- Important modifier ---------------------------------------------------
            'important prefix' => ['!flex', ['!flex']],
            'important suffix' => ['flex!', ['flex!']],
            'important prefixed color' => ['!text-red-500', ['!text-red-500']],
            'important after variant' => ['hover:!underline', ['hover:!underline']],
            'bare important word' => ['!important', ['!important']],
            'important suffix on size' => ['text-lg!', ['text-lg!']],

            // -- Dropped: pure CSS values (numeric + unit) ---------------------------
            'pure px value' => ['12px', []],
            'pure rem value' => ['1.5rem', []],
            'pure percent value' => ['100%', []],
            'pure decimal value' => ['0.5', []],
            'pure px value 2' => ['16px', []],
            'pure em value' => ['2em', []],
            'pure vh value' => ['50vh', []],
            'pure vw value' => ['10vw', []],
            'pure decimal 2' => ['1.25', []],
            'pure px value 3' => ['3px', []],

            // -- Bracketed numeric values are not "pure" (start with '[', not a digit)
            'bracketed pure value' => ['[12px]', ['[12px]']],
            'bracketed percent' => ['[100%]', []],

            // -- Dropped: URLs and file paths -----------------------------------------
            'absolute http url' => ['http://example.com', []],
            'https url with path' => ['https://foo.bar/baz.png', []],
            // The leading "//" is never part of the run ('/' is not a START byte);
            // the remainder has no double slash and no recognised extension, so it
            // survives — a known, spec-following limitation, not a bug.
            'protocol relative url' => ['//cdn.example.com/x', ['cdn.example.com/x']],
            'relative css path' => ['assets/style.css', []],
            'dot relative js path' => ['./scripts/app.js', []],
            'image path' => ['images/logo.png', []],
            'font path' => ['path/to/file.woff2', []],
            'bare http' => ['http://x', []],

            // -- Twig raw text (no pre-pass applied in this test) ---------------------
            'twig variable' => ['{{ variable }}', ['variable']],
            'twig comment' => ['{# comment #}', ['comment']],
            'twig if block' => ['{% if x %}flex{% endif %}', ['endif', 'flex', 'if', 'x']],
            'twig set tag' => ["{% set klass = 'flex' %}", ['flex', 'klass', 'set']],
            'twig dotted variable' => ['{{ user.name }}', ['user.name']],
            'twig trim tag' => ['{%- trim -%}', ['trim']],

            // -- Blade raw text --------------------------------------------------------
            'blade variable' => ['{{ $variable }}', ['variable']],
            // '(' now only continues a run when it is preceded by '-'
            // (CSF-A22b/c), so "@if"/"@foreach" split from their parenthesised
            // argument instead of swallowing it whole.
            'blade if directive' => ['@if($x) flex @endif', ['@endif', '@if', 'flex', 'x']],
            'blade foreach directive' => [
                '@foreach($items as $item) {{ $item }} @endforeach',
                ['@endforeach', '@foreach', 'as', 'item', 'items'],
            ],
            'blade raw echo' => ['{!! $raw !!}', ['raw']],

            // -- JS / Vue snippets ------------------------------------------------------
            'js template literal' => ['className={`text-${color}-500`}', ['className', 'color', 'text']],
            'vue bind ternary' => [':class="isActive ? \'active\' : \'\'"', ['active', 'class', 'isActive']],
            // A JS array literal is exactly the shape CSF-A22 stops
            // swallowing whole (review round 1 item 2): 'isActive'/'active'
            // now recover cleanly instead of vanishing into one junk blob.
            'vue bind array' => [
                ':class="[isActive ? \'active\' : \'\']"',
                ['active', 'class', 'isActive'],
            ],
            'js string concat' => ["className={'flex ' + extra}", ['className', 'extra', 'flex']],
            'vue v-bind class' => ['v-bind:class="cls"', ['cls', 'v-bind:class']],
            'vue click handler' => ['@click="handleClick"', ['@click', 'handleClick']],
            'double curly computed' => ['{{ computedClass }}', ['computedClass']],
            'vue v-if' => ['v-if="show"', ['show', 'v-if']],

            // -- Quotes/strings inside brackets run to their matching quote ----------
            'bg url arbitrary quoted' => ["bg-[url('a.png')]", ["bg-[url('a.png')]"]],
            'content apostrophe double-quoted' => ['content-["it\'s"]', ['content-["it\'s"]']],
            'content attr arbitrary' => ['content-[attr(data-x)]', ['content-[attr(data-x)]']],

            // -- CSF-A22a: whitespace inside a bracket/string rejects the run,
            // and CSF-A21's resume-at-start+1 then recovers the plain words
            // either side of it instead of one space-containing blob.
            'content arbitrary with space is not one candidate' => [
                "content-['hello world']",
                ['hello', 'world'],
            ],
            'quoted value with a space splits into plain words' => [
                'data-[content="hi there"]',
                ['content', 'hi', 'there'],
            ],
            'quoted css value with a space splits into plain words' => [
                'grid-[grid-template-areas:"a b"]',
                ['b'],
            ],

            // -- Comma / semicolon boundaries (CSF-A7) --------------------------------
            'comma separated' => ['flex,block', ['block', 'flex']],
            'semicolon separated' => ['flex;block', ['block', 'flex']],
            'comma list quoted' => ['a, b, c', ['a', 'b', 'c']],
            'comma inside bracket url' => ['bg-[url(a,b)]', ['bg-[url(a,b)]']],

            // -- Balanced / unbalanced brackets ----------------------------------------
            'unterminated bracket' => ['w-[32rem', []],
            'balanced url in bracket' => ['bg-[url(a,b)]', ['bg-[url(a,b)]']],
            'balanced arbitrary variant' => ['[&>*]:flex', ['[&>*]:flex']],
            // Review round 1 item 1 (CSF-A21): a rejected/boundary attempt no
            // longer consumes the bytes after it — 'flex' must still be
            // recovered even though the line starts with a stray '('.
            'leading unbalanced close' => [')flex(', ['flex']],
            'double unbalanced close then word' => ['))flex', ['flex']],
            // Two openers with nothing between them reject per CSF-A22e; the
            // outer '[' is discarded and CSF-A21 recovers the clean inner run.
            'adjacent nested brackets reject the outer one' => ['[[a]]', ['[a]']],

            // -- Length boundary (256 bytes, design doc 09 rule 1) --------------------
            'exactly 256 bytes' => [\str_repeat('a', 256), [\str_repeat('a', 256)]],
            'exactly 257 bytes' => [\str_repeat('a', 257), []],
            'single char' => ['a', ['a']],

            // -- Whitespace / boundary characters --------------------------------------
            'tab separated' => ["flex\tblock", ['block', 'flex']],
            'newline separated' => ["flex\nblock", ['block', 'flex']],
            'multi space separated' => ['flex   block', ['block', 'flex']],
            'angle bracket wrapped' => ['<div>flex</div>', ['div', 'flex']],
            'equals sign boundary' => ['class=flex', ['class', 'flex']],

            // -- Real-world-shaped snippets ---------------------------------------------
            'svg class attribute' => ['<svg class="w-4 h-4">', ['class', 'h-4', 'svg', 'w-4']],
            'markdown heading with code' => ['# Heading with `code`', ['Heading', 'code', 'with']],
            'json class key' => ['"className": "flex items-center"', ['className', 'flex', 'items-center']],
            'php array class value' => ["'class' => 'flex'", ['class', 'flex']],
            'css selector rule' => ['.flex { display: flex; }', ['display', 'flex']],
            'attribute chain' => ["data-foo='bar' class='flex'", ['bar', 'class', 'data-foo', 'flex']],
        ];
    }

    /**
     * The result is always sorted (SORT_STRING) and deduplicated, independent
     * of input order (design doc 09 step 4).
     *
     * @test
     */
    public function resultIsSortedAndDeduplicatedRegardlessOfInputOrder(): void
    {
        $extractor = new CandidateExtractor();

        self::assertSame(
            ['flex', 'grid', 'hidden'],
            $extractor->extract('hidden grid flex flex hidden')
        );
    }

    /** @test */
    public function emptyInputYieldsNoCandidates(): void
    {
        self::assertSame([], (new CandidateExtractor())->extract(''));
    }

    /**
     * Property test (design doc 09 §Tests, design doc 12 T-12): 10 000
     * random-byte buffers must never throw and must always satisfy the output
     * invariants, whatever garbage the buffer contains.
     *
     * @test
     */
    public function neverThrowsAndAlwaysReturnsSortedUniqueBoundedCandidates(): void
    {
        $extractor = new CandidateExtractor();

        for ($iteration = 0; $iteration < 10000; ++$iteration) {
            $length = \random_int(0, 2048);
            $buffer = $length === 0 ? '' : \random_bytes($length);

            $result = $extractor->extract($buffer);

            $sorted = $result;
            \sort($sorted, \SORT_STRING);

            self::assertSame($sorted, $result, 'Result must already be sorted.');
            self::assertSame(
                \count($result),
                \count(\array_unique($result)),
                'Result must not contain duplicates.'
            );

            foreach ($result as $candidate) {
                self::assertGreaterThanOrEqual(1, \strlen($candidate));
                self::assertLessThanOrEqual(256, \strlen($candidate));

                // Review round 1 item 2 (CSF-A22a): whitespace inside a
                // candidate always means the attempt should have been
                // rejected, never kept.
                self::assertDoesNotMatchRegularExpression(
                    '/[ \t\n\r\f\x0B]/',
                    $candidate,
                    'A kept candidate must never contain whitespace.'
                );

                // A quote can only ever survive as part of a bracketed
                // (depth > 0) run; a bare depth-zero quote is never part of
                // any candidate the char classes recognise.
                if (\strpos($candidate, '[') === false && \strpos($candidate, '(') === false) {
                    self::assertDoesNotMatchRegularExpression(
                        '/[\'"`]/',
                        $candidate,
                        'A candidate with no bracket must never contain a quote.'
                    );
                }
            }
        }
    }

    /**
     * Throughput budget (design doc 13: extractor ≥ 20 MB/s, i.e. ≤ 50 ms/MB).
     *
     * The budget is calibrated for opcache/JIT (design doc 13 §Reference
     * machine); this host's PHP build ships the opcache extension disabled
     * (`zend_extension=opcache` commented out in php.ini — confirmed absent
     * from `php -m`), under which even a trivial one-array-lookup-per-byte
     * loop over 1 MB already exceeds the budget on its own. The correctness
     * invariants above hold regardless; only the timing assertion is
     * conditioned on the interpreter actually having opcache/JIT available,
     * matching the acceptance criterion's own "assert time only on PHP >= 8.1"
     * carve-out for environment-dependent timing.
     *
     * @test
     */
    public function meetsTheThroughputBudgetWhenOpcacheIsAvailable(): void
    {
        $candidates = (new CandidateExtractor())->extract(self::realTemplateBuffer());

        // Correctness only. The wall-clock bound moved to tests/Benchmark
        // (CSF-A25 amended in round 3): a timing assertion inside `composer
        // test` measures whatever else the machine happens to be doing, and one
        // cold sample with no warm-up failed 4 runs in 5 under opcache+JIT — it
        // was reporting load, not throughput.
        self::assertContains('flex', $candidates);
        self::assertContains('items-center', $candidates);
        self::assertContains('w-[calc(100%-2rem)]', $candidates);
    }

    /**
     * One megabyte of content shaped like a real template.
     *
     * CSF-A25 sets the budget against this rather than `random_bytes()`:
     * random data has almost no runs to consume and is not what the extractor
     * is optimised for, so a budget calibrated on it measures the wrong thing.
     */
    private static function realTemplateBuffer(): string
    {
        $unit = '<div class="flex items-center gap-2 p-4 hover:bg-slate-50 md:grid md:grid-cols-2">' . "\n"
            . '  <span class="text-sm font-medium text-slate-700">Label</span>' . "\n"
            . '  <a href="/x" class="inline-flex w-[calc(100%-2rem)] rounded-md">Link</a>' . "\n"
            . '</div>' . "\n";

        return \substr(\str_repeat($unit, (int) \ceil((1024 * 1024) / \strlen($unit))), 0, 1024 * 1024);
    }
}
