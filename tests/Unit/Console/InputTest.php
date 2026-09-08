<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Console\Input;
use TailwindPhp\Exception\ConsoleException;

/**
 * Command-line parsing (TASK-0019 acceptance 2; doc 21, ADR-008).
 *
 * The rows that matter most are the ones about an option the command did not
 * declare. A CLI that ignores `--forse` runs the build *without* the force the
 * author believed they had asked for, and reports success — the failure is
 * invisible until a deploy ships a stale stylesheet.
 *
 * @covers-requirement FR-CONSOLE-1
 */
final class InputTest extends TestCase
{
    /**
     * @dataProvider optionForms
     *
     * @param list<string> $argv
     * @param string|bool $expected
     *
     * @test
     */
    public function everyOptionFormIsUnderstood(array $argv, string $name, $expected): void
    {
        $input = Input::fromArgv($argv);

        self::assertSame($expected, \is_bool($expected) ? $input->flag($name) : $input->value($name));
    }

    /**
     * @return array<string, array{list<string>, string, mixed}>
     */
    public static function optionForms(): array
    {
        return [
            'equals form' => [['bin', 'build', '--config=a.php'], 'config', 'a.php'],
            'space form' => [['bin', 'build', '--config', 'a.php'], 'config', 'a.php'],
            'bare flag' => [['bin', 'build', '--json'], 'json', true],
            'short flag' => [['bin', 'build', '-v'], 'verbose', true],
            'short quiet' => [['bin', 'build', '-q'], 'quiet', true],
            'short help' => [['bin', '-h'], 'help', true],
            'empty value' => [['bin', 'build', '--config='], 'config', ''],
            'value with an equals in it' => [['bin', 'build', '--config=a=b.php'], 'config', 'a=b.php'],
            'absent flag' => [['bin', 'build'], 'json', false],
        ];
    }

    /**
     * @test
     */
    public function theCommandIsTheFirstPositional(): void
    {
        self::assertSame('build', Input::fromArgv(['bin', 'build', '--json'])->command());
        self::assertSame('', Input::fromArgv(['bin'])->command());
        self::assertSame('', Input::fromArgv(['bin', '--help'])->command());
    }

    /**
     * @test
     */
    public function remainingPositionalsAreArguments(): void
    {
        $input = Input::fromArgv(['bin', 'candidates', 'one', 'two']);

        self::assertSame(['one', 'two'], $input->arguments());
    }

    /**
     * `--` is the only way to pass a value that starts with a dash, so it has
     * to stop option parsing completely.
     *
     * @test
     */
    public function theTerminatorStopsOptionParsing(): void
    {
        $input = Input::fromArgv(['bin', 'candidates', '--', '--not-an-option', '-x']);

        self::assertSame(['--not-an-option', '-x'], $input->arguments());
        self::assertFalse($input->has('not-an-option'));
    }

    /**
     * A value-taking option must not swallow the next token when that token is
     * itself an option — otherwise `--config --json` silently sets the config
     * file to "--json".
     *
     * @test
     */
    public function anOptionDoesNotSwallowAnotherOption(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--config', '--json']);

        self::assertTrue($input->flag('json'));
        self::assertNull($input->value('config'));
    }

    /**
     * And a flag must not swallow the command's arguments.
     *
     * @test
     */
    public function aFlagDoesNotSwallowAPositional(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--force', 'extra']);

        self::assertTrue($input->flag('force'));
        self::assertSame(['extra'], $input->arguments());
    }

    /**
     * @dataProvider falseyValues
     *
     * @test
     */
    public function aFlagGivenAFalseyValueIsOff(string $value): void
    {
        self::assertFalse(Input::fromArgv(['bin', 'build', '--force=' . $value])->flag('force'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function falseyValues(): array
    {
        return [
            'zero' => ['0'],
            'false' => ['false'],
            'no' => ['no'],
            'off' => ['off'],
            'empty' => [''],
            'mixed case' => ['FALSE'],
        ];
    }

    /**
     * @test
     */
    public function aFlagGivenATruthyValueIsOn(): void
    {
        self::assertTrue(Input::fromArgv(['bin', 'build', '--force=1'])->flag('force'));
        self::assertTrue(Input::fromArgv(['bin', 'build', '--force=yes'])->flag('force'));
    }

    /**
     * The row this class exists for.
     *
     * @test
     */
    public function anUndeclaredOptionIsRefusedWithASuggestion(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--forse']);

        try {
            $input->bind(['force' => false], 'build');
            self::fail('expected a ConsoleException');
        } catch (ConsoleException $e) {
            self::assertSame(2, $e->exitCode());
            self::assertStringContainsString('--forse', $e->getMessage());
            self::assertStringContainsString('--force', $e->getMessage());
        }
    }

    /**
     * A name nothing resembles gets no suggestion: pointing at an unrelated
     * option is worse than admitting there is no near match.
     *
     * @test
     */
    public function aWildlyWrongOptionGetsNoSuggestion(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--zzzzzzzzzzzz']);

        try {
            $input->bind(['force' => false], 'build');
            self::fail('expected a ConsoleException');
        } catch (ConsoleException $e) {
            self::assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }

    /**
     * Global options are accepted by every command without being declared.
     *
     * @test
     */
    public function globalOptionsAreAlwaysAllowed(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--json', '--bundle=app', '-v', '--no-ansi']);

        $input->bind([], 'build');

        self::assertTrue($input->isJson());
        self::assertTrue($input->isVerbose());
        self::assertSame('app', $input->value('bundle'));
    }

    /**
     * A declared option is allowed, obviously — the control that stops the rows
     * above passing on a `bind()` that refuses everything.
     *
     * @test
     */
    public function aDeclaredOptionIsAccepted(): void
    {
        $input = Input::fromArgv(['bin', 'build', '--force', '--driver=php']);

        $input->bind(['force' => false, 'driver' => true], 'build');

        self::assertTrue($input->flag('force'));
        self::assertSame('php', $input->value('driver'));
    }

    /**
     * @test
     */
    public function aLoneDashIsAPositional(): void
    {
        self::assertSame(['-'], Input::fromArgv(['bin', 'candidates', '-'])->arguments());
    }
}
