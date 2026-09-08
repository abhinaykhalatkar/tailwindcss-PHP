<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Console\ConfigLocator;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Tailwind;

/**
 * What every command shares (design doc 21).
 *
 * The **only** non-final class in the library (doc 18 permits exactly this
 * one): finding the configuration, picking a bundle and telling the output
 * where the project root is are the same three lines in six commands, and six
 * copies is six places for the `--config` order to drift.
 */
abstract class AbstractCommand implements CommandInterface
{
    /**
     * @var string|null Injected by tests; the real working directory otherwise.
     */
    private $cwd;

    /**
     * @param string|null $cwd Overridden in tests, which cannot `chdir()`
     *                         without disturbing every other test in the
     *                         process.
     */
    public function __construct(?string $cwd = null)
    {
        $this->cwd = $cwd;
    }

    /**
     * Most commands take no options of their own beyond the global ones.
     *
     * @return array<string, bool>
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Locate the configuration and wire the facade.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{0: Tailwind, 1: string}
     *
     * @throws ConsoleException
     */
    protected function facade(Input $input, array $overrides = []): array
    {
        return ConfigLocator::load($input->value('config'), $this->workingDirectory(), $overrides);
    }

    /**
     * The bundle to act on: `--bundle`, or the first one configured.
     *
     * @throws ConsoleException When `--bundle` names something that is not
     *                          configured — a typo here would otherwise build
     *                          the wrong stylesheet and report success.
     */
    protected function bundleName(Input $input, Tailwind $tailwind): string
    {
        $bundles = $tailwind->config()->bundles();
        $requested = $input->value('bundle');

        if ($requested === null) {
            foreach ($bundles as $name => $ignored) {
                return (string) $name;
            }

            throw new ConsoleException('No bundle is configured.');
        }

        if (!\array_key_exists($requested, $bundles)) {
            throw new ConsoleException(\sprintf(
                'Unknown bundle "%s". Configured: %s.',
                $requested,
                \implode(', ', \array_map('strval', \array_keys($bundles)))
            ));
        }

        return $requested;
    }

    /**
     * An output that prints project-relative paths (threat T-06).
     */
    protected function scopedOutput(Output $output, Tailwind $tailwind): Output
    {
        return $output->withProjectRoot($tailwind->config()->projectRoot());
    }

    protected function workingDirectory(): string
    {
        if ($this->cwd !== null) {
            return $this->cwd;
        }

        $cwd = \getcwd();

        if ($cwd === false) {
            throw new ConsoleException('The current working directory could not be determined.');
        }

        return \rtrim(\str_replace('\\', '/', $cwd), '/');
    }
}
