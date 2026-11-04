<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Diagnostic;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Engine\Diagnostic\Diagnostic;

final class DiagnosticTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Diagnostic::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function everyFieldRoundTrips(): void
    {
        $diagnostic = new Diagnostic(
            Diagnostic::WARNING,
            Diagnostic::W_UNKNOWN_UTILITY,
            'No CSS was generated for this candidate.',
            'templates/home.php',
            42,
            'flex-nonsense'
        );

        self::assertSame(Diagnostic::WARNING, $diagnostic->level());
        self::assertSame(Diagnostic::W_UNKNOWN_UTILITY, $diagnostic->code());
        self::assertSame('No CSS was generated for this candidate.', $diagnostic->message());
        self::assertSame('templates/home.php', $diagnostic->file());
        self::assertSame(42, $diagnostic->line());
        self::assertSame('flex-nonsense', $diagnostic->candidate());
    }

    /** @test */
    public function optionalFieldsDefaultToNull(): void
    {
        $diagnostic = new Diagnostic(Diagnostic::INFO, Diagnostic::I_DRIVER_SELECTED, 'Using the php driver.');

        self::assertNull($diagnostic->file());
        self::assertNull($diagnostic->line());
        self::assertNull($diagnostic->candidate());
    }

    /**
     * @test
     *
     * @dataProvider levelPredicates
     */
    public function levelPredicatesAnswerForExactlyOneLevel(
        string $level,
        bool $expectedError,
        bool $expectedWarning
    ): void {
        $diagnostic = new Diagnostic($level, Diagnostic::I_DRIVER_SELECTED, 'message');

        self::assertSame($expectedError, $diagnostic->isError());
        self::assertSame($expectedWarning, $diagnostic->isWarning());
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function levelPredicates(): array
    {
        return [
            'error is an error' => [Diagnostic::ERROR, true, false],
            'warning is a warning' => [Diagnostic::WARNING, false, true],
            'info is neither' => [Diagnostic::INFO, false, false],
        ];
    }

    /** @test */
    public function anUnknownLevelIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown diagnostic level "fatal".');

        new Diagnostic('fatal', Diagnostic::E_CSS_SYNTAX, 'message');
    }

    /**
     * The codes are a published contract, so an ad-hoc one is a bug rather than
     * an extension point (design doc 15 §Diagnostics).
     *
     * @test
     */
    public function anUnknownCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown diagnostic code "E_MADE_UP".');

        new Diagnostic(Diagnostic::ERROR, 'E_MADE_UP', 'message');
    }

    /**
     * ADR-014: every path in an artefact is project-relative. An absolute one
     * would reach a log or the debug overlay verbatim (threat T-06), so the
     * producer is held to it here.
     *
     * @test
     *
     * @dataProvider absoluteFiles
     */
    public function anAbsoluteFileIsRejected(string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project-relative');

        new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'boom', $file);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function absoluteFiles(): array
    {
        return [
            'posix' => ['/etc/passwd'],
            'windows drive' => ['C:\\Windows\\system32'],
            'windows drive forward slash' => ['C:/Windows'],
            'unc' => ['\\\\server\\share\\x'],
            'root relative backslash' => ['\\etc\\passwd'],
        ];
    }

    /** @test */
    public function aRelativeFileIsAccepted(): void
    {
        $diagnostic = new Diagnostic(
            Diagnostic::ERROR,
            Diagnostic::E_CSS_SYNTAX,
            'boom',
            'assets/styles/main.css'
        );

        self::assertSame('assets/styles/main.css', $diagnostic->file());
    }

    /**
     * A non-ASCII filename is perfectly legal in an artefact — it is only the
     * *shape* of a path that is rejected, not its bytes.
     *
     * @test
     */
    public function aNonAsciiRelativeFileIsAccepted(): void
    {
        $file = "assets/caf\xC3\xA9.css";

        $diagnostic = new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'boom', $file);

        self::assertSame($file, $diagnostic->file());
    }

    /**
     * @test
     *
     * @dataProvider uncleanRelativeFiles
     */
    public function anUncleanRelativeFileIsRejected(string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project-relative');

        new Diagnostic(Diagnostic::ERROR, Diagnostic::E_CSS_SYNTAX, 'boom', $file);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function uncleanRelativeFiles(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'traversal mid-path' => ['assets/../../etc/passwd'],
            'wrapper' => ['file:///etc/passwd'],
            'phar wrapper' => ['phar://x.phar/a.css'],
            // Exempt from *scrubbing* (a URL in a sentence is fine) but never a
            // project-relative artefact path — checked independently.
            'http url' => ['http://example.com/a.css'],
            'https url' => ['https://example.com/a.css'],
            'HTTP uppercase' => ['HTTP://example.com/a.css'],
            'data wrapper' => ['data:text/css,body{}'],
            'drive relative' => ['C:x.css'],
            'leading space' => [' /leading/space'],
            'trailing space' => ['assets/a.css '],
            'embedded path behind a label' => ["\xC9:/x"],
            'empty' => [''],
        ];
    }

    /** @test */
    public function messageAndCandidateAreScrubbed(): void
    {
        $diagnostic = new Diagnostic(
            Diagnostic::WARNING,
            Diagnostic::W_UNKNOWN_UTILITY,
            'cannot import /etc/passwd',
            'assets/a.css',
            3,
            'bg-[url(/etc/passwd)]'
        );

        $candidate = $diagnostic->candidate();

        self::assertNotNull($candidate);
        self::assertStringNotContainsString('/etc/passwd', $diagnostic->message());
        self::assertStringNotContainsString('/etc/passwd', $candidate);
        self::assertStringNotContainsString('/etc/passwd', \implode('|', \array_map(
            static function ($value): string {
                return (string) $value;
            },
            $diagnostic->toArray()
        )));
    }

    /** @test */
    public function toArrayExposesEveryFieldAsAScalarOrNull(): void
    {
        $diagnostic = new Diagnostic(
            Diagnostic::ERROR,
            Diagnostic::E_CSS_SYNTAX,
            'Unexpected "}".',
            'assets/styles/main.css',
            14,
            null
        );

        self::assertSame(
            [
                'level' => Diagnostic::ERROR,
                'code' => Diagnostic::E_CSS_SYNTAX,
                'message' => 'Unexpected "}".',
                'file' => 'assets/styles/main.css',
                'line' => 14,
                'candidate' => null,
            ],
            $diagnostic->toArray()
        );
    }

    /**
     * The codes are a published contract: `doctor` prints them and integrators
     * match on them, so a rename is a breaking change (design doc 15).
     *
     * @test
     *
     * @dataProvider documentedCodes
     */
    public function documentedCodesKeepTheirValues(string $constant, string $expected): void
    {
        self::assertSame($expected, \constant(Diagnostic::class . '::' . $constant));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documentedCodes(): array
    {
        $codes = [
            'E_CSS_SYNTAX',
            'E_IMPORT_NOT_FOUND',
            'E_IMPORT_CYCLE',
            'E_IMPORT_DEPTH',
            'E_UNSUPPORTED_DIRECTIVE',
            'E_APPLY_UNKNOWN',
            'E_APPLY_DEPTH',
            'E_THEME_INVALID',
            'E_CLI_FAILED',
            'E_CLI_TIMEOUT',
            'E_BUILD_FAILED',
            'W_UNKNOWN_UTILITY',
            'W_UNKNOWN_VARIANT',
            'W_INVALID_ARBITRARY',
            'W_CANDIDATE_LIMIT',
            'W_FILE_TOO_LARGE',
            'W_BINARY_SKIPPED',
            'W_FILE_UNREADABLE',
            'W_ROOT_MISSING',
            'W_MANIFEST_CORRUPT',
            'W_CHECKSUM_UNKNOWN',
            'W_SOURCE_IGNORED',
            'W_INVALID_THEME_VALUE',
            'I_DRIVER_SELECTED',
            'I_PREFLIGHT_DISABLED',
        ];

        $cases = [];

        foreach ($codes as $code) {
            $cases[$code] = [$code, $code];
        }

        return $cases;
    }
}
