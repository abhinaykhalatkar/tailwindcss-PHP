<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The M1 stub engine (design doc 23 §M1, digest §2.5).
 *
 * The stub exists so the pipeline, the cache and the lifecycle matrix can be
 * built and tested before the real engine lands in M2 — and so the restricted
 * CI job, which has no `proc_open` and no binary, exercises a real code path
 * rather than a skipped one.
 *
 * Two properties make it usable for that, and both are asserted here rather
 * than assumed: it is **deterministic**, so a content hash is stable and
 * "nothing changed ⇒ no rebuild" is testable; and it performs **no I/O**, so a
 * freshness test cannot flap for reasons unrelated to freshness.
 */
final class PhpEngineCompilerTest extends TestCase
{
    /** @test */
    public function classIsFinalAndIsACompiler(): void
    {
        $reflection = new ReflectionClass(PhpEngineCompiler::class);

        self::assertTrue($reflection->isFinal());
        self::assertInstanceOf(CompilerInterface::class, new PhpEngineCompiler());
    }

    /** @test */
    public function itIdentifiesAsThePhpDriver(): void
    {
        $compiler = new PhpEngineCompiler();

        self::assertSame('php', $compiler->name());
        self::assertSame('stub-m1', $compiler->version());
        self::assertSame(PhpEngineCompiler::VERSION, $compiler->version());
    }

    /**
     * The same input must produce the same bytes, or the content hash moves on
     * every build and nothing downstream can be cached.
     *
     * @test
     */
    public function theSameInputProducesTheSameBytes(): void
    {
        $first = $this->compile(['flex', 'p-4']);
        $second = $this->compile(['flex', 'p-4']);

        self::assertSame($first->css(), $second->css());
        self::assertNotSame('', $first->css(), 'An empty stylesheet would make the cache untestable.');
    }

    /**
     * Candidates must be visible in the output, so a lifecycle test can watch
     * them travel from the scanner through the pipeline into the artefact.
     *
     * @test
     */
    public function everyCandidateAppearsInTheOutput(): void
    {
        $css = $this->compile(['flex', 'p-4', 'text-sm'])->css();

        // As quoted string *values*, not as selectors — the next test is why
        // that distinction matters.
        self::assertStringContainsString('"flex"', $css);
        self::assertStringContainsString('"p-4"', $css);
        self::assertStringContainsString('"text-sm"', $css);
    }

    /**
     * A candidate cannot break out of the value it is written into.
     *
     * This compiler is what every host without `proc_open` runs, so its output
     * is real served CSS, and candidates come from scanning the project's
     * templates rather than from a test. Interpolating one into a selector let a
     * candidate containing `{` or `}` close the rule and inject arbitrary CSS
     * into a stylesheet the browser then executes.
     *
     * @test
     */
    public function acandidateCannotEscapeIntoTheStylesheet(): void
    {
        $css = $this->compile(['a{}body{display:none}', 'b"c', 'd\\e'])->css();

        // The exact bytes, because the whole property is about *where* the
        // candidate's characters end up rather than whether they appear.
        //
        // Note what this does and does not claim. `{` and `}` are still present
        // — inside a CSS string literal they are inert text, and stripping them
        // would be theatre. What must never happen is a candidate *terminating*
        // its string, because everything after that point would be parsed as
        // syntax. So the quote is escaped to `\"` and the backslash to `\\`,
        // and the braces are left exactly where a parser will ignore them.
        self::assertSame(
            "/* tailwindcss-php stub */\n"
            . ':root{--tw-stub-candidates:"a{}body{display:none}" "b\\"c" "d\\\\e"}' . "\n",
            $css
        );
    }

    /**
     * The escaping above is load-bearing, not incidental: an unescaped quote
     * would close the string and hand the rest of the candidate to the parser as
     * CSS. Asserted structurally so it fails if the emitted shape changes.
     *
     * @test
     */
    public function aquoteInAcandidateCannotCloseTheString(): void
    {
        $css = $this->compile(['x"; } body { display:none; } .y{'])->css();

        // Every `"` is either one of the two delimiters or backslash-escaped, so
        // removing the escaped ones must leave exactly the opening and closing
        // delimiter.
        self::assertSame(
            2,
            \substr_count(\str_replace('\\"', '', $css), '"'),
            'A candidate introduced an unescaped quote, which closes the string early.'
        );
    }

    /** @test */
    public function adifferentCandidateSetProducesDifferentBytes(): void
    {
        self::assertNotSame(
            $this->compile(['flex'])->css(),
            $this->compile(['flex', 'p-4'])->css()
        );
    }

    /**
     * `minify` is part of the config hash, so its effect has to be observable
     * or a test cannot tell that flipping it invalidated the cache.
     *
     * @test
     */
    public function minifyChangesTheOutput(): void
    {
        $plain = $this->compile(['flex'], false)->css();
        $minified = $this->compile(['flex'], true)->css();

        self::assertNotSame($plain, $minified);
        self::assertStringNotContainsString("\n", $minified);
    }

    /** @test */
    public function theResultCarriesTheDriverIdentityAndNoDiagnostics(): void
    {
        $result = $this->compile(['flex']);

        self::assertSame('php', $result->driver());
        self::assertSame('stub-m1', $result->driverVersion());
        self::assertFalse($result->hasErrors());
        self::assertTrue($result->diagnostics()->isEmpty());
        self::assertSame([], $result->imports());
        self::assertSame([], $result->inlineSources());
        self::assertGreaterThanOrEqual(0.0, $result->durationMs());
    }

    /** @test */
    public function anEmptyCandidateSetStillProducesNonEmptyCss(): void
    {
        $css = $this->compile([])->css();

        self::assertNotSame('', $css);
    }

    /**
     * No filesystem, no clock, no randomness beyond the duration timer.
     *
     * Asserted by handing it a resolver that records every call: the stub must
     * not consult imports at all, which is the observable half of "does no
     * I/O". The determinism tests above cover the rest — a clock or a random
     * source would show up there as differing bytes.
     *
     * @test
     */
    public function itNeverConsultsTheImportResolver(): void
    {
        $resolver = new InMemoryImportResolver(['a.css' => '.a{}']);

        (new PhpEngineCompiler())->compile(
            '@import "./a.css";',
            $resolver,
            ['flex'],
            new CompileOptions(false, false)
        );

        self::assertSame([], $resolver->resolved(), 'The stub resolved an import it should have ignored.');
    }

    /**
     * @param list<string> $candidates
     */
    private function compile(array $candidates, bool $minify = false): \TailwindPhp\Compiler\CompileResult
    {
        return (new PhpEngineCompiler())->compile(
            '@import "tailwindcss";',
            new InMemoryImportResolver([]),
            $candidates,
            new CompileOptions($minify, false)
        );
    }
}
