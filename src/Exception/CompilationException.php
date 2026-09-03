<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * A compile could not produce a stylesheet (design doc 15 §Exception hierarchy).
 *
 * Carries the {@see DiagnosticBag} gathered before the failure plus the driver
 * that was in use, which is what the debug overlay and the `last_error` entry
 * in the manifest are rendered from.
 *
 * Not final: {@see DriverUnavailableException}, {@see CliProcessException} and
 * {@see CliTimeoutException} specialise it.
 */
class CompilationException extends \RuntimeException implements TailwindPhpException
{
    use ExceptionContext;

    /**
     * The two compiler drivers (design doc 06).
     */
    public const DRIVER_PHP = 'php';
    public const DRIVER_CLI = 'cli';

    /**
     * @var string
     */
    private $driver;

    /**
     * @var DiagnosticBag
     */
    private $diagnostics;

    /**
     * @param string $driver The driver in use: 'php' or 'cli'.
     * @param DiagnosticBag|null $diagnostics Diagnostics collected before the failure.
     * @param array<string, mixed> $context Extra log-safe detail from a subclass.
     */
    public function __construct(
        string $message,
        string $driver,
        ?DiagnosticBag $diagnostics = null,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        // The driver is library-supplied, never user text, so it is validated
        // rather than scrubbed: an unknown value is a bug in our own call site,
        // and validating keeps driver() returning the real name instead of a
        // redaction marker.
        if ($driver !== self::DRIVER_PHP && $driver !== self::DRIVER_CLI) {
            throw new \InvalidArgumentException(\sprintf('Unknown compiler driver "%s".', $driver));
        }

        parent::__construct(self::scrubText($message), 0, $previous);

        $this->driver = $driver;
        $this->diagnostics = $diagnostics ?? new DiagnosticBag();

        $this->initContext(\array_merge(['driver' => $driver], $context));
    }

    /**
     * The bag is mutable and the pipeline keeps adding to it after the exception
     * is constructed, so the diagnostics are projected on read rather than
     * snapshotted in the constructor.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        // A class method takes precedence over the trait's, so this replaces
        // ExceptionContext::context() for the whole compilation branch.
        $context = $this->context;
        $context['diagnostics'] = self::scrubArray($this->diagnostics->toArray());

        return $context;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function diagnostics(): DiagnosticBag
    {
        return $this->diagnostics;
    }
}
