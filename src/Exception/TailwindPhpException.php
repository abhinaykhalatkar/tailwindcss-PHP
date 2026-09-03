<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * Marker interface implemented by every exception this library throws
 * (design doc 15 §Exception hierarchy).
 *
 * Integrators can catch this one type to isolate our failures from their own.
 * It extends \Throwable so that a catch block typed against it is still a valid
 * catch and static analysis keeps the getMessage()/getPrevious() surface.
 */
interface TailwindPhpException extends \Throwable
{
    /**
     * Structured, log-safe detail about the failure.
     *
     * Contains only scalars, null and nested arrays of those, and never an
     * absolute path — the array is designed to be handed straight to a PSR-3
     * logger or a log aggregator (design doc 15 §Logging, threat T-06).
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
