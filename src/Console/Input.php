<?php

declare(strict_types=1);

namespace TailwindPhp\Console;

use TailwindPhp\Exception\ConsoleException;

/**
 * The parsed command line (design doc 21; ADR-008 — no `symfony/console`).
 *
 * Small on purpose. The grammar is `--key=value`, `--key value`, `--flag`,
 * short `-v`, positionals, and `--` to stop option parsing; that is everything
 * doc 21's command table uses, and every form it does not use is one fewer way
 * for two commands to disagree about what an argument meant.
 *
 * **An undeclared option is an error, not a silent no-op.** A typo like
 * `--forse` in a deploy script would otherwise run the command *without* the
 * flag the author believed they had passed — a build that quietly does not
 * rebuild. So {@see self::bind()} refuses anything the command did not declare
 * and names the closest thing it did.
 */
final class Input
{
    /**
     * Options every command accepts (doc 21 §Global options).
     *
     * @var array<string, bool> Name => whether it takes a value.
     */
    private const GLOBAL_OPTIONS = [
        'config' => true,
        'bundle' => true,
        'json' => false,
        'verbose' => false,
        'quiet' => false,
        'no-ansi' => false,
        'help' => false,
    ];

    /**
     * Short forms, expanded before anything else looks at them.
     *
     * @var array<string, string>
     */
    private const SHORT = [
        'v' => 'verbose',
        'q' => 'quiet',
        'h' => 'help',
    ];

    /**
     * @var list<string>
     */
    private $positionals;

    /**
     * @var array<string, string|bool>
     */
    private $options;

    /**
     * @param list<string> $positionals
     * @param array<string, string|bool> $options
     */
    private function __construct(array $positionals, array $options)
    {
        $this->positionals = $positionals;
        $this->options = $options;
    }

    /**
     * Parsing happens twice: once with only the global options known, to find
     * the command name, and once the {@see Application} has looked that command
     * up and can say which of *its* options take a value. Without the second
     * pass `build --driver cli` reads `--driver` as a flag and `cli` as a stray
     * positional — the command runs with the wrong driver and reports success.
     *
     * @param list<string> $argv The raw arguments **including** `$argv[0]`,
     *                           which is dropped.
     * @param array<string, bool> $declared The command's own options, when
     *                                      known: name => takes a value.
     *
     * @throws ConsoleException When an option that needs a value has none.
     */
    public static function fromArgv(array $argv, array $declared = []): self
    {
        $arguments = \array_slice($argv, 1);
        $positionals = [];
        $options = [];
        $literal = false;
        $count = \count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($literal || $argument === '' || $argument[0] !== '-' || $argument === '-') {
                $positionals[] = $argument;

                continue;
            }

            if ($argument === '--') {
                // Everything after this is a positional, whatever it looks
                // like — the only way to pass a value that begins with a dash.
                $literal = true;

                continue;
            }

            $name = self::nameOf($argument);
            $equals = \strpos($name, '=');

            if ($equals !== false) {
                $options[\substr($name, 0, $equals)] = \substr($name, $equals + 1);

                continue;
            }

            // `--key value` only when the option is declared to take one and
            // the next token is not itself an option; otherwise it is a flag.
            // This is why the option table is declared rather than inferred:
            // without it, `--force build` would swallow the command name.
            if (self::takesValue($name, $declared)) {
                if ($i + 1 >= $count || self::looksLikeOption($arguments[$i + 1])) {
                    throw new ConsoleException(\sprintf('The option "--%s" needs a value.', $name));
                }

                $options[$name] = $arguments[++$i];

                continue;
            }

            $options[$name] = true;
        }

        return new self($positionals, $options);
    }

    /**
     * The first positional — the command name, or `''` when none was given.
     */
    public function command(): string
    {
        return $this->positionals[0] ?? '';
    }

    /**
     * @return list<string> Positionals after the command name.
     */
    public function arguments(): array
    {
        return \array_values(\array_slice($this->positionals, 1));
    }

    /**
     * Check this input against what a command declares it accepts.
     *
     * @param array<string, bool> $declared Name => whether it takes a value.
     *
     * @throws ConsoleException When an option was not declared (exit 2).
     */
    public function bind(array $declared, string $command): void
    {
        $known = $declared + self::GLOBAL_OPTIONS;

        foreach (\array_keys($this->options) as $name) {
            if (\array_key_exists($name, $known)) {
                continue;
            }

            $suggestion = self::closest($name, \array_keys($known));

            throw new ConsoleException(
                \sprintf('Unknown option "%s".', self::spell($name))
                . ($suggestion === null ? '' : \sprintf(' Did you mean "--%s"?', $suggestion)),
                $command
            );
        }
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->options);
    }

    /**
     * @param string|null $default Returned when the option is absent, or when
     *                             it was given as a bare flag.
     */
    public function value(string $name, ?string $default = null): ?string
    {
        if (!isset($this->options[$name]) || $this->options[$name] === true) {
            return $default;
        }

        return (string) $this->options[$name];
    }

    /**
     * Whether a flag was given. A flag written `--flag=0` or `--flag=false` is
     * off: a deploy script that computes its arguments should not have to know
     * that only presence counts.
     */
    public function flag(string $name): bool
    {
        if (!\array_key_exists($name, $this->options)) {
            return false;
        }

        $value = $this->options[$name];

        if ($value === true) {
            return true;
        }

        return !\in_array(\strtolower((string) $value), ['0', 'false', 'no', 'off', ''], true);
    }

    public function isJson(): bool
    {
        return $this->flag('json');
    }

    public function isVerbose(): bool
    {
        return $this->flag('verbose');
    }

    public function isQuiet(): bool
    {
        return $this->flag('quiet');
    }

    public function wantsHelp(): bool
    {
        return $this->flag('help');
    }

    /**
     * @return array<string, string|bool> Every option as parsed, for tests and
     *                                    for `-v` diagnostics.
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Strip the leading dashes and expand a short name.
     */
    private static function nameOf(string $argument): string
    {
        if (\strncmp($argument, '--', 2) === 0) {
            return \substr($argument, 2);
        }

        $short = \substr($argument, 1);

        if (isset(self::SHORT[$short])) {
            return self::SHORT[$short];
        }

        // A single-dash long option is not a spelling of the double-dash one:
        // `-force` keeps its dash so it fails `bind()` as an unknown option
        // rather than quietly acting as `--force`. Accepting both spellings
        // would mean `-config=x` and `--config=x` had to stay in step forever.
        return $argument;
    }

    private static function looksLikeOption(string $argument): bool
    {
        return $argument !== '' && $argument !== '-' && $argument[0] === '-';
    }

    /**
     * @param array<string, bool> $declared The command's own options.
     */
    private static function takesValue(string $name, array $declared): bool
    {
        if (\array_key_exists($name, $declared)) {
            return $declared[$name];
        }

        return self::GLOBAL_OPTIONS[$name] ?? false;
    }

    /**
     * Print a name back the way it was written.
     */
    private static function spell(string $name): string
    {
        return $name !== '' && $name[0] === '-' ? $name : '--' . $name;
    }

    /**
     * The nearest declared name, if it is near enough to be worth suggesting.
     *
     * @param list<string> $candidates
     */
    private static function closest(string $name, array $candidates): ?string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;

        // Above 255 bytes `levenshtein()` returns -1, which would compare as
        // nearer than every real candidate.
        if (\strlen($name) > 255) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $distance = \levenshtein($name, $candidate);

            if ($distance >= 0 && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Three edits on a short word is not a typo, it is a different word;
        // suggesting one anyway sends people down the wrong path.
        return $bestDistance <= 3 ? $best : null;
    }
}
