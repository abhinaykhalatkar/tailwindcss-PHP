<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;

/**
 * One `tailwind-php` subcommand (design doc 21).
 *
 * `options()` is not documentation: {@see Input::bind()} refuses anything a
 * command does not declare, so the list here is what makes `--forse` an error
 * rather than a flag that silently did nothing.
 */
interface CommandInterface
{
    /**
     * The word the user types, e.g. `build`.
     */
    public function name(): string;

    /**
     * One line for the usage screen.
     */
    public function description(): string;

    /**
     * @return array<string, bool> Option name (no dashes) => whether it takes
     *                             a value. Global options are added by
     *                             `Input`; a command lists only its own.
     */
    public function options(): array;

    /**
     * @return int Exit code: 0 ok, 1 the operation failed, 2 usage or
     *             configuration, 3 the environment prevents the operation.
     */
    public function run(Input $input, Output $output): int;
}
