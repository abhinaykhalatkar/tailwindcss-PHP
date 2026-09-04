<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;

/**
 * A `CompilerInterface` that counts its calls and can be told to misbehave
 * (TASK-0015 acceptance 6).
 *
 * Most of the lifecycle matrix is an assertion about *how many times* the
 * compiler ran: a warm fresh request must not compile at all, a template edit
 * that changes no candidates must not compile, two concurrent requests must
 * compile exactly once between them. Counting is therefore the point of this
 * double rather than a convenience — {@see self::calls()} is what those rows
 * assert on.
 *
 * It decorates a real compiler instead of faking one, so the CSS flowing
 * through the pipeline is whatever the wrapped compiler genuinely produces
 * (in M1 that is the deterministic {@see \TailwindPhp\Compiler\PhpEngineCompiler}
 * stub). A test that needs a failure asks for one explicitly; nothing here
 * fails by default, so a row that goes red is a row that found something.
 */
final class CountingCompiler implements CompilerInterface
{
    /**
     * @var CompilerInterface
     */
    private $inner;

    /**
     * @var int
     */
    private $calls = 0;

    /**
     * @var \Throwable|null Thrown instead of compiling, when set.
     */
    private $failure;

    /**
     * @var list<Diagnostic> Returned as compile errors, when non-empty.
     */
    private $errors = [];

    public function __construct(CompilerInterface $inner)
    {
        $this->inner = $inner;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function version(): string
    {
        return $this->inner->version();
    }

    /**
     * Compile, counting the attempt.
     *
     * The counter increments **before** any configured failure, because the
     * question these tests ask is "did the pipeline reach the compiler?", and a
     * throwing compiler that was reached is still a compile attempt. Counting
     * after the throw would silently under-report exactly the case I3 exists to
     * pin.
     *
     * @param list<string> $candidates
     */
    public function compile(
        string $entryCss,
        ImportResolverInterface $imports,
        array $candidates,
        CompileOptions $options
    ): CompileResult {
        ++$this->calls;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        $result = $this->inner->compile($entryCss, $imports, $candidates, $options);

        if ($this->errors === []) {
            return $result;
        }

        return new CompileResult(
            $result->css(),
            new DiagnosticBag($this->errors),
            $result->driver(),
            $result->driverVersion(),
            $result->durationMs(),
            $result->imports(),
            $result->inlineSources()
        );
    }

    /**
     * How many times the pipeline reached the compiler.
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * Throw $failure on the next and every subsequent compile.
     */
    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    /**
     * Return $errors as compile diagnostics rather than throwing — the
     * `hasErrors()` path, which the orchestrator turns into a
     * `CompilationException` rather than an infrastructure failure.
     *
     * @param list<Diagnostic> $errors
     */
    public function failWithDiagnostics(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * Forget the call count, keeping any configured failure.
     */
    public function resetCalls(): void
    {
        $this->calls = 0;
    }
}
