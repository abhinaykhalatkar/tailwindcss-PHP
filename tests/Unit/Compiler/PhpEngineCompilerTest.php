<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Compiler\PhpEngineCompiler;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Version;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * The real PHP engine behind the driver contract (TASK-0034 criterion 6;
 * design doc 06): it identifies as `php` at the engine's compatibility
 * version, produces the stylesheet for the candidates, reports input problems
 * as diagnostics, reads imports only through the resolver it is given and
 * reports what it resolved, and is deterministic, so a content hash is stable
 * and "nothing changed ⇒ no rebuild" holds.
 *
 * The M1 stub's placeholder assertions (`stub-m1`, the `--tw-stub-candidates`
 * property, "never consults the resolver") are replaced by the engine's real
 * behaviour; the candidate-escaping rows keep their intent: a hostile
 * candidate never breaks out of the stylesheet.
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
    public function itIdentifiesAsThePhpDriverAtTheEngineCompatVersion(): void
    {
        $compiler = new PhpEngineCompiler();

        self::assertSame('php', $compiler->name());
        self::assertSame(Version::COMPAT, $compiler->version());
    }

    /** @test */
    public function itCompilesTheCandidates(): void
    {
        $css = $this->compile(['flex', 'p-4', 'hover:underline'])->css();

        self::assertStringContainsString(".flex {\n    display: flex;", $css);
        self::assertStringContainsString('padding: calc(var(--spacing) * 4);', $css);
        self::assertStringContainsString('.hover\:underline:hover', $css);
        self::assertStringContainsString('@layer theme, base, components, utilities;', $css);
    }

    /**
     * @test
     */
    public function theSameInputProducesTheSameBytes(): void
    {
        $first = $this->compile(['flex', 'p-4']);
        $second = $this->compile(['flex', 'p-4']);
        $other = (new PhpEngineCompiler())->compile(
            '@import "tailwindcss";',
            new InMemoryImportResolver([]),
            ['flex', 'p-4'],
            new CompileOptions(false, false)
        );

        self::assertSame($first->css(), $second->css());
        self::assertSame($first->css(), $other->css(), 'a second instance gives the same bytes');
    }

    /** @test */
    public function aDifferentCandidateSetProducesDifferentBytes(): void
    {
        self::assertNotSame($this->compile(['flex'])->css(), $this->compile(['flex', 'p-4'])->css());
    }

    /** @test */
    public function minifyWritesTheCompactForm(): void
    {
        $plain = $this->compile(['flex'])->css();
        $minified = $this->compile(['flex'], true)->css();

        self::assertNotSame($plain, $minified);
        self::assertStringContainsString('.flex{display:flex}', $minified);
        self::assertLessThan(\strlen($plain), \strlen($minified));
    }

    /**
     * Candidates are scanned from templates, so they may hold anything. None of
     * these is a utility; none reaches the stylesheet, and the output parses
     * without a diagnostic.
     *
     * @test
     */
    public function aHostileCandidateCannotEscapeIntoTheStylesheet(): void
    {
        $hostile = [
            'x}body{background:red}',
            '"><script>alert(1)</script>',
            'bg-[url(javascript:alert(1))]',
            'p-[1px;color:red]',
            '[color:red}body{x:y]',
            'a"b',
        ];
        $result = $this->compile($hostile);
        $bag = new DiagnosticBag();

        Parser::parse($result->css(), $bag);

        self::assertSame([], $bag->toArray(), 'the output re-parses clean');
        self::assertSame($this->compile([])->css(), $result->css(), 'no candidate added anything');
        self::assertStringNotContainsString('script', $result->css());
        self::assertStringNotContainsString('javascript', $result->css());
    }

    /** @test */
    public function theResultCarriesTheDriverIdentityAndTheInlineSources(): void
    {
        $result = (new PhpEngineCompiler())->compile(
            "@import \"tailwindcss\";\n@source inline(\"underline\");",
            new InMemoryImportResolver([]),
            ['flex'],
            new CompileOptions(false, false)
        );

        self::assertSame('php', $result->driver());
        self::assertSame(Version::COMPAT, $result->driverVersion());
        self::assertFalse($result->hasErrors());
        self::assertSame([], $result->imports());
        self::assertSame(['underline'], $result->inlineSources());
        self::assertGreaterThanOrEqual(0.0, $result->durationMs());
        self::assertStringContainsString('text-decoration-line: underline;', $result->css());
    }

    /**
     * Imports are read through the resolver only, and the result names what it
     * resolved (project-relative, for the freshness snapshot).
     *
     * @test
     */
    public function importsGoThroughTheResolverAndAreReported(): void
    {
        $resolver = new InMemoryImportResolver(['a.css' => '.a{color:red}']);
        $result = (new PhpEngineCompiler())->compile(
            '@import "./a.css";',
            $resolver,
            [],
            new CompileOptions(false, false)
        );

        self::assertSame(['./a.css'], $resolver->resolved(), 'the specifier as written');
        self::assertSame(['a.css'], $result->imports());
        self::assertStringContainsString('.a {', $result->css());
    }

    /** @test */
    public function anInputProblemIsADiagnosticNotAnException(): void
    {
        $result = (new PhpEngineCompiler())->compile(
            '@import "tailwindcss"; @plugin "x"; .a { @apply not-a-utility; }',
            new InMemoryImportResolver([]),
            [],
            new CompileOptions(false, false)
        );
        $codes = [];

        foreach ($result->diagnostics()->all() as $diagnostic) {
            $codes[] = $diagnostic->code();
        }

        self::assertTrue($result->hasErrors());
        self::assertSame([Diagnostic::E_UNSUPPORTED_DIRECTIVE, Diagnostic::E_APPLY_UNKNOWN], $codes);
    }

    /**
     * Fail soft (M1 contract): whatever the engine or a resolver throws comes
     * back as one `E_BUILD_FAILED` naming the class only — never the message,
     * which may carry a path — and no CSS.
     *
     * @test
     */
    public function anExceptionInsideTheEngineIsOneBuildError(): void
    {
        $resolver = new class () implements \TailwindPhp\Engine\Import\ImportResolverInterface {
            public function resolve(string $spec, string $fromRelPath): ?\TailwindPhp\Engine\Import\ResolvedImport
            {
                throw new \RuntimeException('/secret/absolute/path/' . $spec);
            }
        };
        $options = new CompileOptions(false, false);
        $result = (new PhpEngineCompiler())->compile('@import "./a.css";', $resolver, [], $options);
        $diagnostics = $result->diagnostics()->all();

        self::assertSame('', $result->css());
        self::assertTrue($result->hasErrors());
        self::assertCount(1, $diagnostics);
        self::assertSame(Diagnostic::E_BUILD_FAILED, $diagnostics[0]->code());
        self::assertStringContainsString('RuntimeException', $diagnostics[0]->message());
        self::assertStringNotContainsString('secret', $diagnostics[0]->message());
        self::assertSame('php', $result->driver());
    }

    /**
     * T-06 (round 3): an anonymous class's name carries a NUL byte and the
     * absolute path of its file; the diagnostic names its first named parent.
     *
     * @test
     */
    public function anAnonymousExceptionIsNamedWithoutItsPath(): void
    {
        $resolver = new class () implements \TailwindPhp\Engine\Import\ImportResolverInterface {
            public function resolve(string $spec, string $fromRelPath): ?\TailwindPhp\Engine\Import\ResolvedImport
            {
                throw new class ('x') extends \RuntimeException {
                };
            }
        };
        $options = new CompileOptions(false, false);
        $result = (new PhpEngineCompiler())->compile('@import "./a.css";', $resolver, [], $options);
        $message = $result->diagnostics()->all()[0]->message();

        self::assertSame('The PHP engine stopped with RuntimeException.', $message);
        self::assertStringNotContainsString("\0", $message);
        self::assertStringNotContainsString('@anonymous', $message);
        self::assertStringNotContainsString(__FILE__, $message);
    }

    /**
     * @param list<string> $candidates
     */
    private function compile(array $candidates, bool $minify = false): CompileResult
    {
        return (new PhpEngineCompiler())->compile(
            '@import "tailwindcss";',
            new InMemoryImportResolver([]),
            $candidates,
            new CompileOptions($minify, false)
        );
    }
}
