<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Engine\Compiler as Engine;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Engine\Version;

/**
 * The pure-PHP engine behind {@see CompilerInterface} (design doc 06, doc 07):
 * the driver for hosts without `proc_open` or the binary, and the default
 * where the binary is not wanted.
 *
 * It maps the pipeline's call onto {@see Engine::compile()}: the entry text,
 * the import resolver (the only way the engine reads a file — it confines
 * paths, T-01/T-08), the scanner's candidates and the output settings. The
 * engine's registries are built on first use and kept for the life of this
 * object. Input problems come back as diagnostics on the result; nothing the
 * stylesheet contains makes this throw.
 *
 * {@see self::version()} is {@see Version::COMPAT}: it is part of the cache's
 * environment fingerprint, so a change to the engine's output invalidates
 * every artefact it built (FR-FRESH-8). The duration is measured here, outside
 * the pure engine.
 */
final class PhpEngineCompiler implements CompilerInterface
{
    /**
     * @var Engine|null
     */
    private $engine;

    /**
     * The exception's class name, without what PHP appends to an anonymous
     * class's name (`@anonymous`, a NUL byte, the absolute file and line —
     * T-06): `class@anonymous` becomes its first named parent, or `class`.
     */
    private static function className(\Throwable $exception): string
    {
        $name = \get_class($exception);
        $at = \strpos($name, '@anonymous');

        if ($at === false) {
            return $name;
        }

        $parent = \get_parent_class($exception);

        return $parent !== false ? $parent : (string) \substr($name, 0, $at);
    }

    public function name(): string
    {
        return 'php';
    }

    public function version(): string
    {
        return Version::COMPAT;
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

        if ($this->engine === null) {
            $this->engine = new Engine();
        }

        // Relative imports resolve against the entry's directory (ADR-014).
        $entryPath = $options->entryRelPath();

        if ($entryPath === '') {
            $entryPath = CompileRequest::DEFAULT_ENTRY_PATH;
        }

        try {
            $output = $this->engine->compile(new CompileRequest(
                $entryCss,
                $imports,
                $candidates,
                $options->minify(),
                true,
                CompileRequest::DEFAULT_MAX_CANDIDATES,
                $entryPath
            ));
        } catch (\Throwable $exception) {
            // Fail soft (M1 contract): a bug in the engine or a resolver that
            // throws is one build error, never an exception for the host. The
            // class name only — a message may carry a path or input text (T-06).
            $failure = new Diagnostic(Diagnostic::ERROR, Diagnostic::E_BUILD_FAILED, \sprintf(
                'The PHP engine stopped with %s.',
                self::className($exception)
            ));

            return new CompileResult(
                '',
                new DiagnosticBag([$failure]),
                $this->name(),
                $this->version(),
                (float) (\hrtime(true) - $started) / 1000000.0,
                [],
                []
            );
        }

        return new CompileResult(
            $output->css,
            $output->diagnostics,
            $this->name(),
            $this->version(),
            (float) (\hrtime(true) - $started) / 1000000.0,
            $output->imports,
            $output->inlineCandidates
        );
    }
}
