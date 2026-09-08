<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Console;

use TailwindPhp\Console\Command\AbstractCommand;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;

/**
 * A command that does nothing but return, or throw, what a test asked for.
 *
 * Hand-written rather than mocked (doc 16): three fields and one method is less
 * code than the mock configuration would be, and it reads as what it is. It
 * lives in its own file because the project allows one class per file, and it
 * is not named `*Test` so PHPUnit does not try to collect it.
 */
final class FakeCommand extends AbstractCommand
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $code;

    /**
     * @var \Throwable|null
     */
    private $throws;

    public function __construct(string $name, int $code, ?\Throwable $throws = null)
    {
        // A working directory it will never use: these commands do no I/O.
        parent::__construct('.');

        $this->name = $name;
        $this->code = $code;
        $this->throws = $throws;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'a test command called ' . $this->name;
    }

    /**
     * @throws \Throwable When the test asked for a failure.
     */
    public function run(Input $input, Output $output): int
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->code;
    }
}
