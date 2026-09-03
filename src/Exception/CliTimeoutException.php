<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * The standalone Tailwind binary was still running after `cli.timeout` seconds
 * and was terminated (design doc 15 §Exception hierarchy).
 *
 * Bounding the subprocess is part of keeping one request's work finite
 * (threat T-05); a hung compiler must not hold a web worker open.
 */
final class CliTimeoutException extends CompilationException
{
    /**
     * @var float
     */
    private $secondsWaited;

    /**
     * @param float $secondsWaited The timeout that elapsed before termination.
     */
    public function __construct(
        float $secondsWaited,
        ?DiagnosticBag $diagnostics = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            \sprintf('The Tailwind CLI timed out after %.3f s and was terminated.', $secondsWaited),
            'cli',
            $diagnostics,
            ['seconds_waited' => $secondsWaited],
            $previous
        );

        $this->secondsWaited = $secondsWaited;
    }

    public function secondsWaited(): float
    {
        return $this->secondsWaited;
    }
}
