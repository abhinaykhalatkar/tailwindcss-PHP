<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

/**
 * What the host can do, as a contract rather than a concrete lookup.
 *
 * {@see Environment} answers these from `ini_get()`, `function_exists()` and
 * the filesystem — none of which a test can set. `proc_open` being disabled is
 * the single most important branch in {@see \TailwindPhp\Compiler\DriverResolver}
 * (it is the ordinary state on shared hosting, and the reason `auto` exists),
 * so it has to be reachable in a test without a second PHP installation.
 *
 * The seam is here rather than a test-only flag on `Environment`, because a
 * production class should not carry a switch that exists only to be flipped by
 * a test.
 */
interface EnvironmentInterface
{
    /**
     * Whether a child process can be started, polled, killed and reaped — every
     * one of {@see Environment::PROCESS_FUNCTIONS} present, not in
     * `disable_functions`, not on a Suhosin blacklist.
     */
    public function processFunctionAvailable(): bool;

    /**
     * The first of {@see Environment::PROCESS_FUNCTIONS} this host lacks, or
     * null when {@see self::processFunctionAvailable()} is true — so a reason or
     * a report can name the function rather than guess `proc_open`.
     */
    public function missingProcessFunction(): ?string;

    /**
     * @param string|null $configured `cli.binary` from the config, or null.
     * @param list<string> $allowedRoots Absolute confinement roots.
     *
     * @return string|null Absolute, confined path; null when there is nothing
     *                     usable. "No binary" is an ordinary answer, not an error.
     */
    public function binaryPath(?string $configured, string $projectRoot, array $allowedRoots): ?string;

    /**
     * The platform key `resources/checksums.json` is indexed by.
     */
    public function platform(): string;

    /**
     * The child's entire environment, given the directory it will run in.
     *
     * On this interface rather than inlined in the compiler because it is the
     * one piece of child setup that is genuinely platform knowledge, and because
     * a test needs to substitute it without reading the host's real variables.
     *
     * @return array<string, string> Complete — the child inherits nothing else.
     */
    public function childEnvironment(string $tmpDir): array;
}
