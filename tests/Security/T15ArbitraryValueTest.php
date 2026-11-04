<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Security;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Modifier;
use TailwindPhp\Engine\Candidate\Parser;
use TailwindPhp\Engine\Candidate\Value\ArbitraryValue;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Candidate\Value\VarValue;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser as CssParser;
use TailwindPhp\Engine\Css\Token;
use TailwindPhp\Engine\Css\Tokenizer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * Threat T-15 (design doc 12): candidate text must never reach the CSS output
 * in a form that ends its declaration or rule early, opens a comment, or
 * carries a script URL. Every path — arbitrary values, arbitrary properties,
 * arbitrary variants, modifiers, `(--var)` values — goes through
 * `ArbitraryValue`; these rows attack each of them. Also T-05: the 256-byte
 * and 8-variant caps hold before any scanning, and a pathological 256-byte
 * candidate costs no more than a plain one.
 *
 * The oracle is independent of the validator: the engine's own CSS tokenizer
 * and parser (`Engine\Css`, doc 12's verify line) must read every value the
 * candidate parser emits as exactly one clean declaration, with no bad-url or
 * bad-string token, no comment, no `expression()` and no script URL.
 *
 * @covers-requirement NFR-SEC-6
 */
final class T15ArbitraryValueTest extends TestCase
{
    /**
     * Every way a candidate can carry arbitrary text into CSS; `X` is the
     * attacking value.
     */
    private const PATHS = [
        'value' => 'bg-[X]',
        'typed value' => 'bg-[image:X]',
        'property' => '[background:X]',
        'modifier' => 'bg-red-500/[X]',
        'arbitrary variant' => '[X]:flex',
        'named arbitrary variant' => 'supports-[X]:flex',
        'var value' => 'bg-(--x,X)',
        'var modifier' => 'bg-red-500/(--x,X)',
    ];

    /**
     * TASK-0027 round-1 verifier probes: each bypassed the round-1 validator.
     *
     * @return array<string, array{0: string}>
     */
    public static function bypassProbes(): array
    {
        return [
            'quote inside an unquoted url hides a declaration' => ["url(a'b);color:red;x:url(')"],
            'quote inside an unquoted url hides a comment' => ["url(a'b)/*x')"],
            'paren inside an unquoted url' => ['url(a(b);x:y)'],
            'space inside an unquoted url' => ['url(a b);x:y'],
            'underscore before url becomes a space' => ['_url(javascript:alert(1))'],
            'word and underscore before url' => ['red_url(javascript:x)'],
            'underscore url after a colon' => ['x:_url(javascript:x)'],
            'underscore url inside supports' => ['@supports_(x:_url(javascript:x))'],
            'hex-escaped u' => ['\\75rl(javascript:x)'],
            'hex-escaped u with terminator' => ['\\75 rl(javascript:x)'],
            'escaped r' => ['u\\rl(javascript:x)'],
            'hex-escaped r' => ['u\\72l(javascript:x)'],
            'upper case escaped' => ['U\\52L(javascript:x)'],
            'escaped src' => ['\\73rc(javascript:x)'],
            'escaped expression' => ['\\65xpression(alert(1))'],
            'escaped newline in the scheme' => ['url(java\\a script:x)'],
            'escaped carriage return in the scheme' => ['url(java\\d script:x)'],
            'leading escaped control' => ['url(\\1 javascript:x)'],
            'escaped newline in a quoted url' => ["url('java\\a script:x')"],
            'escaped carriage return in image-set' => ["image-set('java\\d script:x' 1x)"],
            'escaped tab in the scheme' => ['url(java\\9 script:x)'],
            'escaped delete' => ['url(java\\7f script:x)'],
            'escaped nul' => ['url(\\0 javascript:x)'],
            'expression' => ['expression(alert(1))'],
            'expression any case' => ['EXPRESSION(alert(1))'],

            // Round-2 verifier: `#url(` / `@url(` are a hash / an at-keyword
            // followed by an ordinary block, not url tokens.
            'hash url hides a comment' => ['#url(x/*)'],
            'at-keyword url hides a comment' => ['@url(x/*)'],
            'upper-case hash url' => ['#URL(x/*)'],
            'hash url after a word' => ['a#url(x/*)'],
            'hash url after an underscore' => ['red_#url(x/*)'],
            'hash url opens a bracket' => ['#url([)'],
            'at-keyword url opens a bracket' => ['@url([)'],
            'hash url inside brackets' => ['[#url(x/*)]'],
            'at-keyword url inside brackets' => ['[@url(x/*)]'],
            'number before url' => ['1url(x/*)'],

            // Round-3 verifier: the url-token path jumped to the closing `)`
            // without checking the bytes of the url body, so a control byte,
            // DEL or a brace inside it reached CSS (DEL makes it a bad-url).
            'delete inside an unquoted url' => ["url(a\x7Fb)"],
            'nul inside an unquoted url' => ["url(a\x00b)"],
            'line feed inside an unquoted url' => ["url(a\nb)"],
            'unit separator inside an unquoted url' => ["url(a\x1Fb)"],
            'open brace inside an unquoted url' => ['url(a{b)'],
            'close brace inside an unquoted url' => ['url(a}b)'],
            'leading tab inside an unquoted url' => ["url(\ta)"],
            'trailing tab inside an unquoted url' => ["url(a\t)"],
            'tab after a space inside an unquoted url' => ["url(a \t)"],
            'tab ending a hex escape' => ["url(\\61\t)"],
            'tab ending a hex escape outside a url' => ["a\\61\tb"],
        ];
    }

    /**
     * A `(--var)` fallback that is safe as written but would become a url
     * once decoded must be refused on both var paths (validate → normalise
     * → validate, like the bracket paths).
     *
     * @test
     */
    public function varPathsValidateTheNormalisedFormToo(): void
    {
        $parser = new Parser(StaticRootSet::forTests());

        self::assertSame([], $parser->parse('bg-(--x,_url(javascript:x))'));
        self::assertSame([], $parser->parse('bg-red-500/(--x,_url(javascript:x))'));
        self::assertSame([], $parser->parse('supports-(--x,_url(javascript:x)):flex'));
        self::assertNotSame([], $parser->parse('bg-(--my_brand)'), 'underscores in a name stay allowed');
    }

    /**
     * Exhaustive over the characters of the round-2 bypass (`#`, `@`, `[`):
     * every string of up to five bytes over them plus `url(` and comment
     * bytes, and every `<prefix>url(<suffix>` shape the verifier used, must
     * agree with the independent CSS oracle whenever it is accepted.
     *
     * @test
     */
    public function hashAndAtKeywordShapesAgreeWithTheCssOracle(): void
    {
        $failures = [];
        $accepted = 0;
        $check = static function (string $raw) use (&$failures, &$accepted): void {
            $normalised = ArbitraryValue::validate($raw);

            if ($normalised === null) {
                return;
            }

            ++$accepted;

            if (!self::oracleSafe($normalised) && \count($failures) < 5) {
                $failures[] = $raw . ' → ' . $normalised;
            }
        };

        $alphabet = ['#', '@', '[', 'u', 'r', 'l', '(', '/', '*', ')'];
        $strings = [''];

        for ($length = 1; $length <= 5; ++$length) {
            $next = [];

            foreach ($strings as $prefix) {
                foreach ($alphabet as $byte) {
                    $next[] = $prefix . $byte;
                    $check($prefix . $byte);
                }
            }

            $strings = $next;
        }

        $suffixes = [''];
        $tail = ['x', '/', '*', '(', ')', '[', ']', "'", '_', ';'];

        for ($length = 1; $length <= 3; ++$length) {
            foreach ($suffixes as $suffix) {
                foreach ($tail as $byte) {
                    $suffixes[] = $suffix . $byte;
                }
            }

            $suffixes = \array_values(\array_unique($suffixes));
        }

        foreach (['', '#', '@', 'a#', 'red_#', '1', '-', '.', '_#', '[#', '[@'] as $prefix) {
            foreach ($suffixes as $suffix) {
                $check($prefix . 'url(' . $suffix);
                $check($prefix . 'url(' . $suffix . ')');
                $check($prefix . 'url(' . $suffix . ')]');
            }
        }

        self::assertSame([], $failures);
        self::assertGreaterThan(100, $accepted);
    }

    /**
     * @test
     *
     * @dataProvider bypassProbes
     */
    public function bypassProbesAreRejectedByTheValidator(string $value): void
    {
        self::assertNull(ArbitraryValue::validate($value));
    }

    /**
     * @test
     *
     * @dataProvider bypassProbes
     */
    public function bypassProbesNeverReachCssThroughAnyPath(string $value): void
    {
        $parser = new Parser(StaticRootSet::forTests());
        $unsafe = [];

        foreach (self::PATHS as $path => $template) {
            $raw = \str_replace('X', $value, $template);

            foreach ($parser->parse($raw) as $candidate) {
                foreach (self::emittedTexts($candidate) as $text) {
                    if (self::hasForbiddenByte($text) || !self::oracleSafe($text)) {
                        $unsafe[] = \sprintf('%s path: %s emitted %s', $path, $raw, $text);
                    }
                }
            }
        }

        // Most probes have no interpretation at all on any path; the ones
        // that do (the `(--var)` paths keep underscores) must emit safe text.
        self::assertSame([], $unsafe);
    }

    /**
     * The validator's result must itself be safe: `validate()` re-checks the
     * normalised form, and the independent CSS oracle agrees, for every
     * string over an alphabet built from the bypasses — up to four bytes
     * bare, up to three after `url(`, after `x_url(`, and inside `calc()`.
     *
     * @test
     */
    public function everyValidatedValueIsSafeForTheCssOracle(): void
    {
        $alphabet = ['u', 'r', 'l', '(', ')', "'", '_', ';', '/', '*', '\\', 'j', ' ', ':', 'a', "\x7F", '}', "\n"];
        $strings = [''];
        $accepted = 0;

        for ($length = 1; $length <= 4; ++$length) {
            $next = [];

            foreach ($strings as $prefix) {
                foreach ($alphabet as $byte) {
                    $next[] = $prefix . $byte;
                }
            }

            $strings = $next;

            foreach ($strings as $string) {
                // Wrapped shapes up to three bytes, bare strings up to four.
                $shapes = $length < 4
                    ? [$string, 'url(' . $string, 'x_url(' . $string . ')', 'calc(' . $string . ')']
                    : [$string];

                foreach ($shapes as $raw) {
                    $normalised = ArbitraryValue::validate($raw);

                    if ($normalised === null) {
                        continue;
                    }

                    ++$accepted;

                    if (
                        self::hasForbiddenByte($normalised)
                        || !ArbitraryValue::isSafe($normalised)
                        || !self::oracleSafe($normalised)
                    ) {
                        self::fail(\sprintf(
                            '%s normalised to %s, which the CSS oracle refuses',
                            \json_encode($raw, \JSON_UNESCAPED_SLASHES),
                            \json_encode($normalised, \JSON_UNESCAPED_SLASHES)
                        ));
                    }
                }
            }
        }

        self::assertGreaterThan(1000, $accepted);
    }
    /**
     * @test
     *
     * @dataProvider hostileValues
     */
    public function hostileArbitraryValuesAreRejected(string $value): void
    {
        self::assertNull(ArbitraryValue::validate($value));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileValues(): array
    {
        return [
            'closing bracket and braces' => [']{}'],
            'declaration injection' => [';color:red'],
            'rule break-out' => ['red}body{display:none'],
            'newline' => ["red\ncolor:blue"],
            'comment opener' => ['red/*'],
            'unterminated string' => ['"abc'],
            'javascript url' => ['url(javascript:alert(1))'],
            'data html url' => ['url("data:text/html,<script>x</script>")'],
            'closing brace inside brackets' => ['[}]'],
            '257 bytes' => [\str_repeat('a', 257)],
            'control byte' => ["a\x1Bb"],
            'open brace' => ['{'],
            'escaped closing brace' => ['\\}'],
            'brace in a string' => ['"}"'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider hostileCandidates
     */
    public function hostileCandidatesHaveNoInterpretation(string $raw): void
    {
        $parser = new Parser(StaticRootSet::forTests());

        self::assertSame([], $parser->parse($raw));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileCandidates(): array
    {
        return [
            'value: brace break-out' => ['bg-[red]{}]'],
            'value: declaration injection' => ['bg-[;color:red]'],
            'value: closing brace inside brackets' => ['bg-[}]'],
            'value: newline' => ["bg-[red\n]"],
            'value: comment' => ['bg-[red/*]'],
            'value: unterminated string' => ['content-["abc]'],
            'value: javascript url' => ['bg-[url(javascript:alert(1))]'],
            'value: data html url' => ['bg-[url("data:text/html,x")]'],
            'property: injection in the value' => ['[color:red;background:blue]'],
            'property: brace in the name' => ['[col{or:red]'],
            'property: injection in the name' => ['[color;x:red]'],
            'variant: brace break-out' => ['[&{}]:flex'],
            'variant: declaration injection' => ['[&;color:red]:flex'],
            'variant: comment' => ['[&/*]:flex'],
            'variant: javascript url' => ['[&:has(url(javascript:x))]:flex'],
            'named variant: injection' => ['data-[x;y]:flex'],
            'modifier: injection' => ['bg-red-500/[0.5;x]'],
            'modifier: brace' => ['bg-red-500/[}]'],
            'var value: injection' => ['bg-(--x;color:red)'],
            'var modifier: injection' => ['bg-red-500/(--x;y)'],
            'raw: 257 bytes' => ['p-' . \str_repeat('4', 255)],
            'raw: control byte' => ["p-4\x00"],
            'raw: brace outside brackets' => ['p-4{'],
            'raw: nine variants' => ['a:b:c:d:e:f:g:h:i:flex'],
        ];
    }

    /** @test */
    public function everyAcceptedValueIsSafe(): void
    {
        // Whatever the parser accepts is, by construction, safe to emit:
        // check that for a set of tricky but legitimate candidates.
        $parser = new Parser(StaticRootSet::forTests());
        $raws = [
            "content-['a;b']", "content-['}']", 'bg-[url(/a_b.png)]', 'w-[calc(100%-2rem)]',
            '[--x:{a}]', 'bg-[#fff]/[0.5]', '[&_p]:flex', 'grid-cols-[repeat(3,minmax(0,1fr))]',
        ];
        $checked = 0;

        foreach ($raws as $raw) {
            foreach ($parser->parse($raw) as $candidate) {
                $text = self::emitted($candidate);

                if ($text === null) {
                    continue;
                }

                ++$checked;
                self::assertTrue(ArbitraryValue::isSafe($text), $raw . ' produced ' . $text);
                self::assertFalse(\strpos($text, '{') !== false || \strpos($text, '}') !== false, $raw);
            }
        }

        self::assertGreaterThanOrEqual(5, $checked, 'the legitimate rows produce values');
    }

    /**
     * Normalisation only ever turns `_` into a space, drops the backslash of
     * `\_` and adds spaces inside math functions — none of which may create
     * what validation refuses. Checked exhaustively for every string of up to
     * four bytes over a hostile alphabet, bare and inside `calc()`.
     *
     * @test
     */
    public function normalisationNeverCreatesWhatValidationRefuses(): void
    {
        $alphabet = ['/', '*', '_', '\\', '(', ')', '[', ']', "'", 'a', '1', '-', '+', ','];
        $strings = [''];
        $accepted = 0;

        for ($length = 1; $length <= 4; ++$length) {
            $next = [];

            foreach ($strings as $prefix) {
                foreach ($alphabet as $byte) {
                    $next[] = $prefix . $byte;
                }
            }

            $strings = $next;

            foreach ($strings as $string) {
                foreach ([$string, 'calc(' . $string . ')'] as $raw) {
                    $normalised = ArbitraryValue::validate($raw);

                    if ($normalised === null) {
                        continue;
                    }

                    ++$accepted;

                    if (!ArbitraryValue::isSafe($normalised) || self::opensComment($normalised)) {
                        self::fail(\sprintf(
                            '%s normalised to unsafe %s',
                            \json_encode($raw, \JSON_UNESCAPED_SLASHES),
                            \json_encode($normalised, \JSON_UNESCAPED_SLASHES)
                        ));
                    }
                }
            }
        }

        self::assertGreaterThan(1000, $accepted);
    }

    /** @test */
    public function aPathological256ByteCandidateIsCheap(): void
    {
        $parser = new Parser(StaticRootSet::forTests());
        $candidates = [
            \str_repeat('[', 128) . \str_repeat(']', 128),
            'w-[' . \str_repeat('(', 126) . \str_repeat(')', 125) . ']',
            \str_repeat('a:', 8) . 'w-[' . \str_repeat('_', 234) . ']',
            \str_repeat('-', 256),
            \str_repeat('/', 256),
            \str_repeat(':', 256),
            'w-[' . \str_repeat('"\\"', 84) . ']',
        ];

        foreach ($candidates as $raw) {
            self::assertLessThanOrEqual(256, \strlen($raw));

            // CPU time with the cycle collector paused (as in T05): wall
            // clock on a loaded runner measured 5 ms for work that costs
            // 0.6 ms, and collector passes walk the test runner's heap.
            $collecting = \gc_enabled();
            \gc_disable();

            try {
                $started = self::cpuSeconds();

                for ($i = 0; $i < 200; ++$i) {
                    $parser->parse($raw);
                }

                $perCall = (self::cpuSeconds() - $started) / 200 * 1000;
            } finally {
                if ($collecting) {
                    \gc_enable();
                }
            }

            // Informational bound (ms of CPU per call) far above the real
            // cost; the structural guarantee is the single pass.
            self::assertLessThan(5.0, $perCall, \sprintf('%s… took %.3f ms', \substr($raw, 0, 20), $perCall));
        }
    }

    /**
     * User plus system CPU time of this process, in seconds.
     */
    private static function cpuSeconds(): float
    {
        $usage = \getrusage();

        if ($usage === false) {
            return \microtime(true);
        }

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
            + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    }

    /**
     * Whether $text contains a comment opener a CSS tokenizer would see: a
     * slash that is neither escaped (`\/` is an escaped slash) nor inside a
     * quoted string, followed by `*`.
     */
    private static function opensComment(string $text): bool
    {
        $length = \strlen($text);
        $quote = '';

        for ($i = 0; $i < $length; ++$i) {
            $c = $text[$i];

            if ($c === '\\') {
                ++$i;
            } elseif ($quote !== '') {
                $quote = $c === $quote ? '' : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '/' && $i + 1 < $length && $text[$i + 1] === '*') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every piece of text $candidate would put into CSS: its value, its
     * modifier, and the arbitrary part of each variant.
     *
     * @return list<string>
     */
    private static function emittedTexts(Candidate $candidate): array
    {
        $texts = [];
        $value = self::emitted($candidate);

        if ($value !== null) {
            $texts[] = $value;
        }

        if ($candidate->modifier !== null) {
            $texts[] = $candidate->modifier->kind === Modifier::VAR
                ? 'var(' . $candidate->modifier->value . ')'
                : $candidate->modifier->value;
        }

        foreach ($candidate->variants as $variant) {
            if ($variant->arbitrary !== null) {
                $texts[] = $variant->arbitrary;
            }

            if ($variant->modifier !== null) {
                $texts[] = $variant->modifier->value;
            }
        }

        return $texts;
    }

    /**
     * The independent check: Engine\Css reads `.a{x:<text>}` as exactly one
     * rule holding one declaration whose value is $text (a comment or an
     * early terminator would change it), and no token of $text is a bad-url,
     * a bad-string, `expression(` or a URL or string with a script scheme.
     */
    private static function oracleSafe(string $text): bool
    {
        $bag = new DiagnosticBag();
        $sheet = CssParser::parse('.a{x:' . $text . '}', $bag);

        if (!$bag->isEmpty() || \count($sheet->nodes) !== 1) {
            return false;
        }

        $rule = $sheet->nodes[0];

        if (!$rule instanceof Rule || $rule->selector !== '.a' || \count($rule->nodes) !== 1) {
            return false;
        }

        $declaration = $rule->nodes[0];

        if (!$declaration instanceof Declaration || $declaration->property !== 'x' || $declaration->important) {
            return false;
        }

        $tokens = Tokenizer::tokenize($text);

        // The parser trims surrounding whitespace by token (an escaped `\ `
        // survives); the value must be exactly the source from the first to
        // the last non-whitespace token. A comment the tokenizer skipped would
        // make the two differ, since the parser drops comments.
        $first = null;
        $last = null;

        foreach ($tokens as $token) {
            if ($token->type !== Token::WHITESPACE && $token->type !== Token::EOF) {
                $first = $first ?? $token->start;
                $last = $token->end;
            }
        }

        $expected = $first === null || $last === null
            ? ''
            : (string) \substr(Tokenizer::preprocess($text), $first, $last - $first);

        if ($declaration->value !== $expected) {
            return false;
        }

        foreach ($tokens as $token) {
            if ($token->type === Token::BAD_URL || $token->type === Token::BAD_STRING) {
                return false;
            }

            if ($token->type === Token::FUNCTION_TOKEN && \strtolower($token->value) === 'expression') {
                return false;
            }

            $urlLike = $token->type === Token::URL || $token->type === Token::STRING;

            if ($urlLike && self::hasScriptScheme($token->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The validator's own promise, checked independently of it: no byte below
     * 0x20, no DEL and no brace anywhere in emitted text.
     */
    private static function hasForbiddenByte(string $text): bool
    {
        $length = \strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $o = \ord($text[$i]);

            if ($o < 0x20 || $o === 0x7F || $text[$i] === '{' || $text[$i] === '}') {
                return true;
            }
        }

        return false;
    }

    /**
     * $url (escapes already decoded by the tokenizer) with C0 controls and
     * spaces dropped, as a WHATWG URL parser does, starts with a refused scheme.
     */
    private static function hasScriptScheme(string $url): bool
    {
        $plain = '';
        $length = \strlen($url);

        for ($i = 0; $i < $length; ++$i) {
            if (\ord($url[$i]) > 0x20 && \ord($url[$i]) !== 0x7F) {
                $plain .= $url[$i];
            }
        }

        $plain = \strtolower($plain);

        foreach (['javascript:', 'vbscript:', 'data:text/html'] as $scheme) {
            if (\strncmp($plain, $scheme, \strlen($scheme)) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text a candidate would put into CSS: the arbitrary value, the
     * variable reference, or a named value; null for a static candidate.
     */
    private static function emitted(Candidate $candidate): ?string
    {
        $value = $candidate->value;

        if ($value instanceof ArbitraryValue) {
            return $value->normalised;
        }

        if ($value instanceof VarValue) {
            return $value->name;
        }

        if ($value instanceof NamedValue) {
            return $value->name;
        }

        return null;
    }
}
