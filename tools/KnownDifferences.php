<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Tests\Conformance\Support\CorpusPaths;

/**
 * The known-differences allow-list: every corpus case the engine is allowed to
 * get wrong, and why (design doc 08 §Known differences policy; decisions ENG-A9,
 * ENG-A12).
 *
 * The truth is the data file `tests/Conformance/known-differences.php`, a plain
 * `return [...]` of entries:
 *
 *     candidate   the raw candidate (or, for a directive fixture, its key)
 *     corpus      `<group>/<name>`, the corpus entry it belongs to
 *     class       `deliberate` (the engine differs on purpose), `bug` (the engine
 *                 is wrong or incomplete) or `upstream` (the CLI itself is odd)
 *     reason      one sentence
 *     since       the pinned CLI release the difference was recorded against
 *
 * `ConformanceTest` (TASK-0047) reads it through {@see self::load()} and fails
 * both when a listed case now passes and when a failing case is not listed; the
 * console `report` command (M3) reuses the same class. The Markdown file
 * `docs/compat/KNOWN-DIFFERENCES.md` is only a rendering of the data, produced by
 * `tools/known-differences.php`.
 *
 * **The loader runs nothing it has not read first (T-08).** The file is checked
 * token by token — an optional `declare(strict_types=1);`, `return`, arrays,
 * constant strings, commas and `=>`, nothing else — and only then required, so a
 * data file cannot carry code. Every entry is validated; an error names the entry
 * by its position and a truncated candidate, never a path or a long value (T-06).
 *
 * No regular expressions (R1 applies to `tools/` too). Lives under `tools/`:
 * maintainer tooling, not autoloaded, not shipped.
 */
final class KnownDifferences
{
    public const CLASSES = ['deliberate', 'bug', 'upstream'];

    private const KEYS = ['candidate', 'corpus', 'class', 'reason', 'since'];

    private const MEANINGS = [
        'deliberate' => 'The engine differs on purpose, for a security or scope reason.',
        'bug' => 'The engine is wrong or incomplete and is to be fixed.',
        'upstream' => 'The CLI itself behaves oddly; the engine follows the documented behaviour.',
    ];

    private const MAX_CANDIDATE = 256;

    private const MAX_REASON = 300;

    private const MAX_VERSION = 20;

    private const NAME_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789-';

    private const VERSION_BYTES = '0123456789.';

    /**
     * Load and validate the data file.
     *
     * @return list<array{candidate: string, corpus: string, class: string, reason: string, since: string}>
     *
     * @throws \RuntimeException When the file cannot be read.
     * @throws \InvalidArgumentException When it is not plain data or an entry is malformed.
     */
    public static function load(string $file): array
    {
        $php = \is_file($file) ? \file_get_contents($file) : false;

        if ($php === false) {
            throw new \RuntimeException('The known-differences data file cannot be read.');
        }

        self::assertLiteralsOnly($php);

        /** @var mixed $loaded */
        $loaded = (static function (string $path) {
            return require $path;
        })($file);

        if (!\is_array($loaded)) {
            throw new \InvalidArgumentException('The known-differences data file must return an array.');
        }

        return self::validate($loaded);
    }

    /**
     * @param array<array-key, mixed> $entries
     *
     * @return list<array{candidate: string, corpus: string, class: string, reason: string, since: string}>
     *
     * @throws \InvalidArgumentException Naming the entry that breaks a rule.
     */
    public static function validate(array $entries): array
    {
        if ($entries !== [] && \array_keys($entries) !== \range(0, \count($entries) - 1)) {
            throw new \InvalidArgumentException('The known-differences data must be a list of entries.');
        }

        $valid = [];
        $seen = [];

        foreach ($entries as $position => $entry) {
            $label = 'Known difference entry #' . $position;

            if (!\is_array($entry)) {
                throw new \InvalidArgumentException($label . ' is not an array.');
            }

            $keys = \array_keys($entry);

            \sort($keys);

            $expected = self::KEYS;

            \sort($expected);

            if ($keys !== $expected) {
                throw new \InvalidArgumentException($label . ' has missing or unknown keys.');
            }

            $candidate = self::text($label, 'candidate', $entry['candidate'], self::MAX_CANDIDATE);
            $label .= ' (' . self::printable($candidate, 40) . ')';

            if (\strpbrk($candidate, " \t\r\n\0\f\v") !== false) {
                throw new \InvalidArgumentException($label . ' has a candidate that is not a single token.');
            }

            $corpus = self::text($label, 'corpus', $entry['corpus'], 80);

            self::assertCorpus($label, $corpus);

            $class = self::text($label, 'class', $entry['class'], 20);

            if (!\in_array($class, self::CLASSES, true)) {
                throw new \InvalidArgumentException($label . ' has an unknown class.');
            }

            $reason = self::text($label, 'reason', $entry['reason'], self::MAX_REASON);

            if (\strpbrk($reason, "\r\n\0") !== false) {
                throw new \InvalidArgumentException($label . ' has a reason that is not one line.');
            }

            $since = self::text($label, 'since', $entry['since'], self::MAX_VERSION);

            if (\strspn($since, self::VERSION_BYTES) !== \strlen($since) || $since[0] === '.') {
                throw new \InvalidArgumentException($label . ' has a since that is not a version.');
            }

            if (isset($seen[$corpus . "\0" . $candidate])) {
                throw new \InvalidArgumentException($label . ' is listed twice for the same corpus entry.');
            }

            $seen[$corpus . "\0" . $candidate] = true;
            $valid[] = [
                'candidate' => $candidate,
                'corpus' => $corpus,
                'class' => $class,
                'reason' => $reason,
                'since' => $since,
            ];
        }

        return $valid;
    }

    /**
     * The entries by corpus entry, then by candidate: what a conformance run
     * looks a failing case up in.
     *
     * @param list<array{candidate: string, corpus: string, class: string, reason: string, since: string}> $entries
     *
     * @return array<string, array<string, array{
     *     candidate: string,
     *     corpus: string,
     *     class: string,
     *     reason: string,
     *     since: string
     * }>>
     */
    public static function index(array $entries): array
    {
        $index = [];

        foreach (self::sorted($entries) as $entry) {
            $index[$entry['corpus']][$entry['candidate']] = $entry;
        }

        return $index;
    }

    /**
     * The Markdown rendering: the same bytes for the same entries, whatever
     * order they were written in.
     *
     * @param array<array-key, mixed> $entries
     *
     * @throws \InvalidArgumentException When an entry or the version is malformed.
     */
    public static function render(array $entries, string $version): string
    {
        $entries = self::sorted(self::validate($entries));

        $length = \strlen($version);

        if ($version === '' || $length > self::MAX_VERSION || \strspn($version, self::VERSION_BYTES) !== $length) {
            throw new \InvalidArgumentException('The CLI version to print is not a version.');
        }

        $byClass = [];

        foreach (self::CLASSES as $class) {
            $byClass[$class] = [];
        }

        foreach ($entries as $entry) {
            $byClass[$entry['class']][] = $entry;
        }

        $text = "# Known differences\n\n"
            . 'Compared with the official Tailwind CLI ' . $version . '. This file is generated by '
            . '`php tools/known-differences.php` from `tests/Conformance/known-differences.php`; '
            . "do not edit it by hand.\n\n"
            . "| Class | Meaning | Entries |\n|-------|---------|---------|\n";

        foreach (self::CLASSES as $class) {
            $text .= '| ' . $class . ' | ' . self::MEANINGS[$class] . ' | ' . \count($byClass[$class]) . " |\n";
        }

        foreach (self::CLASSES as $class) {
            $text .= "\n## " . $class . "\n\n";

            if ($byClass[$class] === []) {
                $text .= "None.\n";

                continue;
            }

            $text .= "| Corpus | Candidate | Reason | Since |\n|--------|-----------|--------|-------|\n";

            foreach ($byClass[$class] as $entry) {
                $text .= '| ' . self::cell($entry['corpus']) . ' | ' . self::code($entry['candidate']) . ' | '
                    . self::cell($entry['reason']) . ' | ' . $entry['since'] . " |\n";
            }
        }

        return $text;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Refuse any file that is not `declare(strict_types=1); return [ …literals… ];`.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertLiteralsOnly(string $php): void
    {
        try {
            $tokens = \token_get_all($php, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new \InvalidArgumentException('The known-differences data file is not valid PHP.');
        }

        $significant = [];

        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = $token;
        }

        $position = 0;

        if (self::isToken($significant[$position] ?? null, \T_OPEN_TAG)) {
            ++$position;
        } else {
            throw new \InvalidArgumentException('The known-differences data file must start with <?php.');
        }

        // An optional `declare(strict_types=1);`.
        if (self::isToken($significant[$position] ?? null, \T_DECLARE)) {
            $declare = [\T_DECLARE, '(', \T_STRING, '=', \T_LNUMBER, ')', ';'];

            foreach ($declare as $expected) {
                if (!self::isToken($significant[$position] ?? null, $expected)) {
                    throw new \InvalidArgumentException('The known-differences data file has an unexpected declare.');
                }

                ++$position;
            }
        }

        if (!self::isToken($significant[$position] ?? null, \T_RETURN)) {
            throw new \InvalidArgumentException('The known-differences data file must return its data.');
        }

        ++$position;

        $allowed = ['[', ']', ',', \T_DOUBLE_ARROW, \T_CONSTANT_ENCAPSED_STRING];
        $count = \count($significant);

        for (; $position < $count - 1; ++$position) {
            $matches = false;

            foreach ($allowed as $expected) {
                if (self::isToken($significant[$position], $expected)) {
                    $matches = true;

                    break;
                }
            }

            if (!$matches) {
                throw new \InvalidArgumentException('The known-differences data file holds more than literals.');
            }
        }

        if ($position !== $count - 1 || !self::isToken($significant[$position], ';')) {
            throw new \InvalidArgumentException('The known-differences data file must end after the returned array.');
        }
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     * @param int|string $expected A token id or a single-character token.
     */
    private static function isToken($token, $expected): bool
    {
        if ($token === null) {
            return false;
        }

        if (\is_string($expected)) {
            return $token === $expected;
        }

        return \is_array($token) && $token[0] === $expected;
    }

    /**
     * @param mixed $value
     *
     * @throws \InvalidArgumentException
     */
    private static function text(string $label, string $field, $value, int $max): string
    {
        if (!\is_string($value) || $value === '' || \strlen($value) > $max) {
            throw new \InvalidArgumentException(
                $label . ' has a ' . $field . ' that is not non-empty text of a sane length.'
            );
        }

        return $value;
    }

    /**
     * `<group>/<name>`: a known corpus group and a plain lower-case name.
     */
    private static function assertCorpus(string $label, string $corpus): void
    {
        $parts = \explode('/', $corpus);

        if (
            \count($parts) !== 2
            || !\in_array($parts[0], CorpusPaths::groups(), true)
            || $parts[1] === ''
            || \strspn($parts[1], self::NAME_BYTES) !== \strlen($parts[1])
        ) {
            throw new \InvalidArgumentException($label . ' has a corpus that is not <group>/<name>.');
        }
    }

    /**
     * @param list<array{candidate: string, corpus: string, class: string, reason: string, since: string}> $entries
     *
     * @return list<array{candidate: string, corpus: string, class: string, reason: string, since: string}>
     */
    private static function sorted(array $entries): array
    {
        \usort($entries, static function (array $a, array $b): int {
            $byCorpus = \strcmp($a['corpus'], $b['corpus']);

            return $byCorpus !== 0 ? $byCorpus : \strcmp($a['candidate'], $b['candidate']);
        });

        return $entries;
    }

    private static function cell(string $text): string
    {
        return \str_replace('|', '\\|', $text);
    }

    /**
     * A code span: a longer fence when the text holds a backtick.
     */
    private static function code(string $text): string
    {
        $text = self::cell($text);

        return \strpos($text, '`') === false ? '`' . $text . '`' : '`` ' . $text . ' ``';
    }

    private static function printable(string $text, int $max): string
    {
        $text = \strlen($text) > $max ? \substr($text, 0, $max) . '...' : $text;
        $safe = '';

        for ($i = 0, $length = \strlen($text); $i < $length; ++$i) {
            $byte = \ord($text[$i]);
            $safe .= $byte >= 0x20 && $byte <= 0x7e ? $text[$i] : '?';
        }

        return $safe;
    }
}
