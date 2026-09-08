<?php

declare(strict_types=1);

namespace TailwindPhp\Console;

use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Json;
use TailwindPhp\Support\Path;

/**
 * Everything the CLI prints (design doc 15 §console output, doc 21).
 *
 * Three rules, each of which exists because breaking it has a specific cost:
 *
 * - **`--json` means exactly one JSON object on stdout.** A script doing
 *   `tailwind-php build --json | jq` must not have to strip a progress line
 *   first, so in JSON mode every human-facing call here becomes a no-op and
 *   diagnostics go to stderr instead.
 * - **stdout is the answer, stderr is the commentary.** Warnings and errors go
 *   to stderr even when they are the only output, so `$(...)` captures the
 *   answer and nothing else.
 * - **No absolute path is ever printed** (threat T-06). Console output ends up
 *   in CI logs, screenshots and issue reports; {@see self::path()} makes
 *   everything project-relative on the way out.
 *
 * Colour is opt-out three ways — `--no-ansi`, `NO_COLOR`, and not being a
 * terminal — because the common case for a coloured byte in the wrong place is
 * a log file nobody can read.
 */
final class Output
{
    /**
     * SGR codes, by severity. Deliberately few: this is a build tool, not a
     * dashboard.
     */
    private const COLOURS = [
        'ok' => "\033[32m",
        'warn' => "\033[33m",
        'error' => "\033[31m",
        'reset' => "\033[0m",
    ];

    /**
     * @var resource
     */
    private $stdout;

    /**
     * @var resource
     */
    private $stderr;

    /**
     * @var bool
     */
    private $ansi;

    /**
     * @var bool
     */
    private $json;

    /**
     * @var bool
     */
    private $quiet;

    /**
     * @var string
     */
    private $projectRoot = '';

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdout, $stderr, bool $ansi, bool $json = false, bool $quiet = false)
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->ansi = $ansi;
        $this->json = $json;
        $this->quiet = $quiet;
    }

    /**
     * Build from an already-parsed command line.
     *
     * `getenv()` and `STDOUT` are read here rather than passed in because
     * `Console/` is the one place the static rules permit it (rule R3) — and
     * because a caller who had to supply them would get it wrong.
     *
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public static function fromInput(Input $input, $stdout = null, $stderr = null): self
    {
        $stdout = $stdout ?? \STDOUT;
        $stderr = $stderr ?? \STDERR;

        return new self(
            $stdout,
            $stderr,
            self::ansiIsUseful($input, $stdout),
            $input->isJson(),
            $input->isQuiet()
        );
    }

    /**
     * Paths printed by this instance are made relative to $root.
     */
    public function withProjectRoot(string $root): self
    {
        $clone = clone $this;
        $clone->projectRoot = $root;

        return $clone;
    }

    /**
     * A line of ordinary output. Silent under `--json` and `--quiet`.
     */
    public function line(string $text = ''): void
    {
        if ($this->json || $this->quiet) {
            return;
        }

        $this->write($this->stdout, $text . "\n");
    }

    /**
     * An aligned `key: value` pair — the shape doc 21 uses for every command's
     * summary.
     *
     * @param string|int|float|bool $value
     */
    public function keyValue(string $key, $value, int $width = 18): void
    {
        if (\is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        }

        $this->line(\str_pad($key, $width) . ' ' . $value);
    }

    /**
     * A section heading, for `doctor`.
     */
    public function section(string $title): void
    {
        $this->line('');
        $this->line($title);
    }

    /**
     * One `doctor` row: a status glyph, a label, and a remedy when the news is
     * bad. The remedy is the point — a check that only says "✘" makes the
     * reader search for what to do about it.
     */
    public function status(string $state, string $label, string $detail = '', string $remedy = ''): void
    {
        $glyph = ['ok' => '[ok]', 'warn' => '[warn]', 'error' => '[fail]'];

        $this->line(
            $this->colour($state, $glyph[$state] ?? '[ ]')
            . ' ' . $label
            . ($detail === '' ? '' : ': ' . $detail)
        );

        if ($remedy !== '') {
            $this->line('       → ' . $remedy);
        }
    }

    /**
     * A warning. Goes to stderr so it cannot pollute a captured answer, and is
     * printed even under `--quiet`: suppressing a warning is not what quiet is
     * for.
     */
    public function warn(string $text): void
    {
        $this->write($this->stderr, $this->colour('warn', 'warning: ') . $text . "\n");
    }

    /**
     * An error. Always printed, on stderr, whatever the mode.
     */
    public function error(string $text): void
    {
        $this->write($this->stderr, $this->colour('error', 'error: ') . $text . "\n");
    }

    /**
     * The single JSON object of `--json` mode.
     *
     * @param array<string, mixed> $data
     */
    public function json(array $data): void
    {
        $this->write($this->stdout, Json::encode($data, true) . "\n");
    }

    public function isJson(): bool
    {
        return $this->json;
    }

    /**
     * A path, made safe to print (T-06).
     *
     * Falls back to the basename when the path is outside the project root
     * entirely — that is the case where printing the whole thing would leak the
     * deployment layout, and the basename is the part the reader needs.
     */
    public function path(string $absolute): string
    {
        if ($this->projectRoot === '') {
            return \basename($absolute);
        }

        try {
            $relative = Path::relative($this->projectRoot, $absolute);
        } catch (PathException $e) {
            // Outside the project entirely — a configured binary in
            // `/usr/local/bin`, say. That is exactly the case where printing
            // the whole path would describe the machine, and the basename is
            // the part the reader actually needs.
            return \basename($absolute);
        }

        return $relative === '' ? '.' : $relative;
    }

    /**
     * ANSI is useful only on a terminal that has not asked us to stop.
     *
     * @param resource $stdout
     */
    private static function ansiIsUseful(Input $input, $stdout): bool
    {
        if ($input->flag('no-ansi')) {
            return false;
        }

        // https://no-color.org — set to anything at all means "no colour".
        $noColor = \getenv('NO_COLOR');

        if ($noColor !== false && $noColor !== '') {
            return false;
        }

        return \function_exists('stream_isatty') && \stream_isatty($stdout);
    }

    private function colour(string $state, string $text): string
    {
        if (!$this->ansi || !isset(self::COLOURS[$state])) {
            return $text;
        }

        return self::COLOURS[$state] . $text . self::COLOURS['reset'];
    }

    /**
     * @param resource $stream
     */
    private function write($stream, string $text): void
    {
        \fwrite($stream, $text);
    }
}
