<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Every `tools/*.php` entry script refuses to run outside the command line
 * (TASK-0061): under a web SAPI with `register_argc_argv` off it would see no
 * arguments and run its default action — `php-matrix.php` moves `vendor/`, the
 * generators write files. The guard is the first statement after `declare`, so
 * nothing before it can act; a new tool that forgets it fails here.
 *
 * Entry scripts are the kebab-case files; the PascalCase files are the classes
 * they require.
 */
final class EntryScriptGuardTest extends TestCase
{
    /**
     * The whole guard, line by line: an empty or different body does not count.
     */
    private const GUARD = ["if (\\PHP_SAPI !== 'cli') {", 'exit(2);', '}'];

    /** @test */
    public function everyEntryScriptRefusesANonCliSapiFirst(): void
    {
        $scripts = self::entryScripts();

        self::assertGreaterThanOrEqual(10, \count($scripts), 'the ten known entry scripts at least');

        foreach ($scripts as $script) {
            self::assertSame(
                self::GUARD,
                self::firstStatementsAfterDeclare($script, \count(self::GUARD)),
                \basename($script) . ': the whole SAPI guard must come first after declare'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function entryScripts(): array
    {
        $scripts = [];

        foreach ((array) \glob(\dirname(__DIR__, 3) . '/tools/*.php') as $path) {
            $name = \basename((string) $path);

            if ($name !== '' && \ctype_lower($name[0])) {
                $scripts[] = (string) $path;
            }
        }

        \sort($scripts);

        return $scripts;
    }

    /**
     * The first $count code lines after `declare(strict_types=1);` (blank and
     * `//` lines skipped).
     *
     * @return list<string>
     */
    private static function firstStatementsAfterDeclare(string $path, int $count): array
    {
        $lines = \explode("\n", \str_replace("\r\n", "\n", (string) \file_get_contents($path)));
        $seenDeclare = false;
        $statements = [];

        foreach ($lines as $line) {
            $line = \trim($line);

            if (!$seenDeclare) {
                $seenDeclare = $line === 'declare(strict_types=1);';

                continue;
            }

            if ($line === '' || \strncmp($line, '//', 2) === 0) {
                continue;
            }

            $statements[] = $line;

            if (\count($statements) === $count) {
                break;
            }
        }

        return $statements;
    }
}
