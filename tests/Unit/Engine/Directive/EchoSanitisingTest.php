<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Directive;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Directive\Processor;

/**
 * T-06 (TASK-0053 item 3): author text a diagnostic echoes cannot break a log
 * line, a terminal or `json_encode`. Every class of unsafe byte — C0 controls,
 * newline, ESC, DEL, invalid UTF-8, the bidi controls — is fed through every
 * kind of echo the engine writes: the directive handlers (`Processor::echo`),
 * the import handler's own echo, the parser's snippet and the tokenizer's
 * snippet (the last two through `Diagnostic`'s backstop). The message must come
 * out printable, valid UTF-8 and within the 64-byte echo.
 */
final class EchoSanitisingTest extends TestCase
{
    /**
     * The bidi controls and a C1 control, none of which may survive.
     */
    private const UNSAFE = [
        "\xe2\x80\xaa", "\xe2\x80\xab", "\xe2\x80\xac", "\xe2\x80\xad", "\xe2\x80\xae",
        "\xe2\x81\xa6", "\xe2\x81\xa7", "\xe2\x81\xa8", "\xe2\x81\xa9", "\xc2\x9b",
    ];

    /**
     * @test
     *
     * @dataProvider sitesAndBytes
     */
    public function anEchoIsPrintableWhateverTheAuthorWrote(string $css, int $minimum): void
    {
        $run = new ProcessorHarness($css);
        $messages = $run->messages();

        self::assertGreaterThanOrEqual($minimum, \count($messages), 'the site reports: ' . \json_encode($messages));

        foreach ($messages as $message) {
            self::assertPrintable($message);
        }

        self::assertStringContainsString('?', \implode(' ', $messages), 'the unsafe text was replaced, not dropped');
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function sitesAndBytes(): array
    {
        $bytes = [
            'SOH' => "\x01",
            'newline' => "\n",
            'ESC' => "\x1b[2J",
            'DEL' => "\x7f",
            'invalid UTF-8' => "\xff\xc3",
            'C1 control' => "\xc2\x9b",
            'RLO U+202E' => "\xe2\x80\xae",
            'LRI U+2066' => "\xe2\x81\xa6",
        ];
        $sites = [
            '@utility name' => ['@utility x%sy { color: red; }', 1],
            '@custom-variant name' => ['@custom-variant v%sw (&:hover);', 1],
            '@import spec (the import handler)' => ['@import "./a%sb.css";', 1],
            'an invalid declaration (the parser)' => ['.a { color red%s; }', 1],
            'an unterminated string (the tokenizer)' => [".a { content: \"x%s\n}", 1],
        ];
        $cases = [];

        foreach ($sites as $site => [$template, $minimum]) {
            foreach ($bytes as $name => $byte) {
                // A newline ends a string before the byte: nothing to echo.
                $inString = \strpos($site, 'unterminated') !== false || \strpos($site, '@import') !== false;

                if ($name === 'newline' && $inString) {
                    continue;
                }

                $cases[$site . ', ' . $name] = [\sprintf($template, $byte), $minimum];
            }
        }

        return $cases;
    }

    /**
     * The 64-byte echo holds when the text is all bidi controls: each becomes
     * one `?` before the cut, so the echo is 64 `?` and the ellipsis.
     *
     * @test
     */
    public function theEchoCapHoldsAfterReplacing(): void
    {
        $echo = Processor::echo(\str_repeat("\xe2\x80\xae", 200));

        self::assertSame(\str_repeat('?', 64) . '…', $echo);
        self::assertPrintable($echo);
    }

    /**
     * The parser's snippet is made printable before the tokenizer's 64-byte
     * cut too, so 100 bidi controls echo as 61 `?` and the ellipsis, not as
     * the 20 whole sequences that fit 61 raw bytes.
     *
     * @test
     */
    public function theParserSnippetIsReplacedBeforeItsCut(): void
    {
        $run = new ProcessorHarness('.a { color red' . \str_repeat("\xe2\x80\xae", 100) . '; }');
        $message = $run->messages()[0];

        self::assertStringContainsString('near: color red' . \str_repeat('?', 52) . '...', $message);
        self::assertPrintable($message);
    }

    /** @test */
    public function printableTextIsEchoedAsWritten(): void
    {
        self::assertSame('caf' . "\xc3\xa9" . ' (&:hover)', Processor::echo('caf' . "\xc3\xa9" . ' (&:hover)'));
    }

    /**
     * The backstop: a producer that echoes raw text still yields a printable
     * message, never a longer one, and the candidate field is cleaned too.
     *
     * @test
     */
    public function aDiagnosticIsPrintableWhoeverBuiltIt(): void
    {
        $raw = "bad \x1b[31m\xe2\x80\xae\xff\ntext";
        $diagnostic = new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, $raw, null, 1, "c\x00\xe2\x81\xa9");

        self::assertSame('bad ?[31m???text', $diagnostic->message());
        self::assertSame('c??', $diagnostic->candidate());
        self::assertPrintable($diagnostic->message());
    }

    private static function assertPrintable(string $message): void
    {
        self::assertNotFalse(\json_encode($message), 'valid UTF-8: ' . \bin2hex($message));

        for ($i = 0, $length = \strlen($message); $i < $length; ++$i) {
            $byte = \ord($message[$i]);

            self::assertFalse(
                $byte < 0x20 || $byte === 0x7F,
                \sprintf('control byte 0x%02x in %s', $byte, \bin2hex($message))
            );
        }

        foreach (self::UNSAFE as $unsafe) {
            self::assertStringNotContainsString($unsafe, $message);
        }
    }
}
