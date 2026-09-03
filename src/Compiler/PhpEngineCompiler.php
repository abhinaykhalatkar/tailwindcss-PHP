<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;

/**
 * The pure-PHP engine — **an M1 stub** (design doc 23 §M1, digest §2.5).
 *
 * The real engine is M2. What M1 needs is something that satisfies
 * {@see CompilerInterface} deterministically, so the pipeline, the cache and
 * the lifecycle matrix can be built and tested end to end before a line of the
 * engine exists. The stub is what makes the restricted CI job — no `proc_open`,
 * no binary — pass on a real code path rather than a skipped one.
 *
 * Two properties are load-bearing and both are asserted by its test:
 *
 * - **Deterministic.** The same candidates produce the same bytes, so a
 *   content hash is stable and "nothing changed ⇒ no rebuild" is testable.
 * - **No I/O, no clock, no randomness** beyond the duration timer. Anything
 *   else would make a freshness test flap for reasons that have nothing to do
 *   with freshness.
 *
 * {@see self::version()} returns `stub-m1` (decision CSF-A19). It is part of
 * the config hash, so when the real engine lands its version changes and every
 * cache built by the stub is invalidated — which is exactly what should happen.
 */
final class PhpEngineCompiler implements CompilerInterface
{
    /**
     * Opaque version string (CSF-A19). Changing it invalidates every cache.
     */
    public const VERSION = 'stub-m1';

    private const HEADER = "/* tailwindcss-php stub */\n";

    public function name(): string
    {
        return 'php';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /**
     * @param list<string> $candidates Sorted and unique.
     */
    public function compile(
        string $entryCss,
        ImportResolverInterface $imports,
        array $candidates,
        CompileOptions $options
    ): CompileResult {
        $started = \hrtime(true);

        $css = self::HEADER;

        // Candidates land in the artefact so a lifecycle test can watch them
        // flow through the pipeline — but as *escaped string values*, never as
        // selectors.
        //
        // The earlier form interpolated each candidate straight into a selector,
        // on the grounds that a stub's input comes from a test. That was wrong
        // twice over: this compiler is what every host without `proc_open` runs,
        // so its output is real served CSS, and candidates come from scanning the
        // project's own templates rather than from a test. A candidate
        // containing `{`, `}` or `<` could close the rule and put whatever
        // followed into a stylesheet the browser then executes.
        //
        // One custom property instead, with `Escape::string()` doing the
        // quoting — the same helper the real engine uses (M2).
        if ($candidates !== []) {
            $quoted = [];

            foreach ($candidates as $candidate) {
                $quoted[] = '"' . Escape::string($candidate) . '"';
            }

            $css .= \sprintf(":root{--tw-stub-candidates:%s}\n", \implode(' ', $quoted));
        }

        if ($options->minify()) {
            // Not real minification — just a visible, deterministic difference,
            // so `minify` participating in the config hash is observable.
            $css = \str_replace("\n", '', $css);
        }

        return new CompileResult(
            $css,
            new DiagnosticBag(),
            $this->name(),
            $this->version(),
            (float) (\hrtime(true) - $started) / 1000000.0,
            [],
            []
        );
    }
}
