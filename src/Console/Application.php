<?php

declare(strict_types=1);

namespace TailwindPhp\Console;

use TailwindPhp\Console\Command\BuildCommand;
use TailwindPhp\Console\Command\CandidatesCommand;
use TailwindPhp\Console\Command\ClearCommand;
use TailwindPhp\Console\Command\CommandInterface;
use TailwindPhp\Console\Command\DoctorCommand;
use TailwindPhp\Console\Command\InstallCliCommand;
use TailwindPhp\Console\Command\UrlCommand;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Exception\LogSafety;
use TailwindPhp\Version;

/**
 * The `tailwind-php` entry point (design doc 21; ADR-008 — hand-rolled, because
 * a console framework is a dependency the whole library exists without).
 *
 * Its only real job is turning failures into exit codes a script can branch on:
 *
 * | code | meaning |
 * |------|---------|
 * | 0 | it worked |
 * | 1 | the operation failed — a build error, a missing manifest |
 * | 2 | usage or configuration — a typo, a missing `tailwind.php` |
 * | 3 | the environment prevents it — no network, wrong SAPI |
 *
 * The distinction between 1 and 2 is what lets CI tell "the stylesheet did not
 * compile" from "you called this wrong", and 3 exists so that a deploy script
 * can retry a download without retrying a syntax error.
 *
 * **No stack trace unless `-v`.** A trace names every directory on the machine
 * (threat T-06), and console output ends up in issue reports; the message is
 * scrubbed through {@see LogSafety} on the way out.
 */
final class Application
{
    /**
     * `levenshtein()` refuses strings longer than this and returns -1.
     */
    private const MAX_SUGGESTION_BYTES = 255;

    /**
     * @var array<string, CommandInterface>
     */
    private $commands = [];

    /**
     * @param list<CommandInterface>|null $commands Injected by tests; the
     *                                              default set is built here so
     *                                              `bin/tailwind-php` stays a
     *                                              one-liner.
     */
    public function __construct(?array $commands = null)
    {
        if ($commands === null) {
            $commands = [
                new BuildCommand(),
                new ClearCommand(),
                new DoctorCommand(),
                new InstallCliCommand(),
                new UrlCommand(),
                new CandidatesCommand(),
            ];
        }

        foreach ($commands as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /**
     * @param list<string> $argv
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function run(array $argv, $stdout = null, $stderr = null): int
    {
        try {
            $input = Input::fromArgv($argv);
        } catch (ConsoleException $e) {
            $output = new Output($stdout ?? \STDOUT, $stderr ?? \STDERR, false);
            $output->error($e->getMessage());

            return $e->exitCode();
        }

        $output = Output::fromInput($input, $stdout, $stderr);
        $name = $input->command();

        if ($name === '' || $input->wantsHelp()) {
            $this->usage($output, $name);

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $output->error($this->unknownCommandMessage($name));

            return 2;
        }

        $command = $this->commands[$name];

        try {
            // Re-parsed now that the command is known: only it can say which of
            // its options take a value, and the first pass had to guess. See
            // Input::fromArgv().
            $input = Input::fromArgv($argv, $command->options());
            $input->bind($command->options(), $name);

            return $command->run($input, $output);
        } catch (ConsoleException $e) {
            $output->error($e->getMessage());

            return $e->exitCode();
        } catch (ConfigurationException $e) {
            // Exit 2, not 1: this is "you called this wrong", and a CI script
            // branches on the difference. Without this arm the same T-08
            // refusal exits 2 when the post-build gate catches it and 1 when
            // the pre-load gate does — the code would describe which guard
            // fired rather than what went wrong.
            //
            // `PathException` extends this one, so a path that will not resolve
            // inside its root lands here too, which is right: that is a
            // configuration problem, not the operation failing.
            $output->error(LogSafety::scrubText($e->getMessage()));

            return 2;
        } catch (\Throwable $e) {
            // Scrubbed: the message may quote a path, and this one is going to
            // a terminal that may be a CI log (T-06).
            $output->error(LogSafety::scrubText($e->getMessage()));

            if ($input->isVerbose()) {
                $output->error(\get_class($e));
            }

            return 1;
        }
    }

    /**
     * The usage screen. Lists every command, because a CLI whose help omits a
     * command has a command nobody uses.
     */
    private function usage(Output $output, string $name): void
    {
        $output->line('tailwind-php ' . Version::VERSION);
        $output->line();
        $output->line('Usage: tailwind-php <command> [options]');
        $output->line();
        $output->line('Commands:');

        foreach ($this->commands as $command) {
            $output->line('  ' . \str_pad($command->name(), 14) . $command->description());
        }

        $output->line();
        $output->line('Global options:');
        $output->line('  --config=PATH  the configuration file to use');
        $output->line('  --bundle=NAME  the bundle to act on');
        $output->line('  --json         machine-readable output on stdout');
        $output->line('  -v, --verbose  more detail');
        $output->line('  -q, --quiet    only warnings and errors');
        $output->line('  --no-ansi      never colour the output');

        if ($name !== '' && !isset($this->commands[$name])) {
            $output->line();
        }
    }

    /**
     * "did you mean" over the command names — a mistyped command is nearly
     * always a near miss, and the alternative is the user re-reading `--help`
     * to find a letter.
     */
    private function unknownCommandMessage(string $name): string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        // `levenshtein()` returns -1 above 255 bytes, which compares as nearer
        // than everything — so without this guard every long unknown command
        // was confidently reported as meaning "build".
        if (\strlen($name) <= self::MAX_SUGGESTION_BYTES) {
            foreach (\array_keys($this->commands) as $candidate) {
                $distance = \levenshtein($name, $candidate);

                if ($distance >= 0 && $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $candidate;
                }
            }
        }

        $message = \sprintf('Unknown command "%s".', $name);

        if ($best !== null && $bestDistance <= 3) {
            $message .= \sprintf(' Did you mean "%s"?', $best);
        }

        return $message . ' Run "tailwind-php --help" for the list.';
    }
}
