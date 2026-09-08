<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Pipeline;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Pipeline\ErrorRenderer;

/**
 * The failure bodies of design doc 15 (FR-ERR-1/2/3, ADR-011, CCP-A1).
 *
 * The debug overlay is asserted **byte for byte** against one fixture. That is
 * deliberate: it is CSS served to a browser, so a stray quote or a missing
 * escape is not a formatting nit but a broken stylesheet — and a body that
 * merely "contains" the right words can still be unparseable.
 *
 * @covers-requirement FR-ERR-1 FR-ERR-3
 */
final class ErrorRendererTest extends TestCase
{
    /** @test */
    public function theDebugOverlayIsByteExact(): void
    {
        $expected = "/* tailwindcss-php build failed\n"
            . "   E_CSS_SYNTAX: Unexpected \"}\" at assets/styles/main.css:14\n"
            . "   driver: php  bundle: styles */\n"
            . "html::before {\n"
            . '  content: "tailwindcss-php: E_CSS_SYNTAX — Unexpected \\"}\\" '
            . "at assets/styles/main.css:14\";\n"
            . "  position: fixed; inset: 0 0 auto 0; z-index: 2147483647;\n"
            . "  padding: 12px 16px; font: 14px/1.4 ui-monospace, monospace; white-space: pre-wrap;\n"
            . "  background: #7f1d1d; color: #fee2e2; border-bottom: 2px solid #fecaca;\n"
            . "}\n";

        self::assertSame($expected, ErrorRenderer::debugOverlayCss(
            new \RuntimeException('compilation failed'),
            'styles',
            'php',
            new DiagnosticBag([$this->syntaxError()])
        ));
    }

    /**
     * A message carrying a comment terminator would close the block early and
     * spill the rest into the stylesheet as CSS — at worst a way to inject
     * declarations through a filename.
     *
     * @test
     */
    public function aCommentTerminatorInTheMessageIsNeutralised(): void
    {
        $overlay = ErrorRenderer::debugOverlayCss(
            new \RuntimeException('boom */ html { display: none } /*'),
            'styles',
            'php'
        );

        $commentEnd = \strpos($overlay, '*/');

        self::assertNotFalse($commentEnd);
        // The first terminator must be the one that closes our own block, at
        // the end of the comment — not one from the message.
        self::assertSame($commentEnd, \strpos($overlay, "*/\nhtml::before {"));
        // The message itself survives (the scrubber, which runs first, redacts
        // the slash-led token as path-like; the neutraliser then guarantees no
        // second terminator regardless of what the scrubber left).
        self::assertStringContainsString('html { display: none }', $overlay);
        self::assertSame(1, \substr_count($overlay, '*/'));
    }

    /**
     * With no diagnostics — an infrastructure failure rather than a CSS one —
     * the exception message is what there is to say.
     *
     * @test
     */
    public function theExceptionMessageIsUsedWhenThereAreNoDiagnostics(): void
    {
        $overlay = ErrorRenderer::debugOverlayCss(
            new \RuntimeException('proc_open is disabled'),
            'styles',
            'cli'
        );

        self::assertStringContainsString('   proc_open is disabled', $overlay);
        self::assertStringContainsString('content: "tailwindcss-php: proc_open is disabled"', $overlay);
        self::assertStringContainsString('driver: cli  bundle: styles', $overlay);
    }

    /**
     * First error in the banner, every error in the comment (design doc 15).
     *
     * @test
     */
    public function theBannerShowsTheFirstErrorAndTheCommentShowsThemAll(): void
    {
        $bag = new DiagnosticBag([
            $this->syntaxError(),
            new Diagnostic(Diagnostic::ERROR, Diagnostic::E_IMPORT_NOT_FOUND, 'Missing partial', 'a.css', 2),
            // A warning is not an error and belongs in neither place.
            new Diagnostic(Diagnostic::WARNING, Diagnostic::W_UNKNOWN_UTILITY, 'Unknown utility'),
        ]);

        $overlay = ErrorRenderer::debugOverlayCss(new \RuntimeException('x'), 'styles', 'php', $bag);

        self::assertStringContainsString('content: "tailwindcss-php: E_CSS_SYNTAX —', $overlay);
        self::assertStringContainsString('E_IMPORT_NOT_FOUND: Missing partial at a.css:2', $overlay);
        self::assertStringNotContainsString(
            'E_IMPORT_NOT_FOUND —',
            $overlay,
            'Only the first error belongs in the banner.'
        );
        self::assertStringNotContainsString('Unknown utility', $overlay, 'A warning is not a build failure.');
    }

    /**
     * The production body is served to the public, so it says nothing at all:
     * no message, no code, no path (FR-ERR-2, threat T-06).
     *
     * @test
     */
    public function theProductionBodyIsOneFixedComment(): void
    {
        self::assertMatchesRegularExpression(
            '#^/\* tailwindcss-php: build failed \*/$#D',
            ErrorRenderer::productionBody()
        );
        self::assertSame(ErrorRenderer::PRODUCTION_BODY, ErrorRenderer::productionBody());
    }

    /**
     * The console text is routinely pasted into an issue, so it must not carry
     * the server layout — which `getFile()` and `getTraceAsString()` would.
     *
     * @test
     */
    public function theConsoleTextCarriesNoAbsolutePath(): void
    {
        $text = ErrorRenderer::consoleText(new \RuntimeException('compilation failed'), false);

        self::assertStringContainsString('tailwindcss-php: build failed', $text);
        self::assertStringContainsString('compilation failed', $text);
        self::assertStringNotContainsString(__FILE__, $text);
        self::assertDoesNotMatchRegularExpression('#[A-Za-z]:[\\\\/]#', $text);
        self::assertStringNotContainsString('#0 ', $text, 'A stack trace leaked into the console text.');
    }

    /** @test */
    public function theConsoleTextColoursOnlyWhenAsked(): void
    {
        $plain = ErrorRenderer::consoleText(new \RuntimeException('x'), false);
        $coloured = ErrorRenderer::consoleText(new \RuntimeException('x'), true);

        self::assertStringNotContainsString("\033[", $plain);
        self::assertStringContainsString("\033[1;31m", $coloured);
        self::assertStringContainsString("\033[0m", $coloured);
    }

    private function syntaxError(): Diagnostic
    {
        return new Diagnostic(
            Diagnostic::ERROR,
            Diagnostic::E_CSS_SYNTAX,
            'Unexpected "}"',
            'assets/styles/main.css',
            14
        );
    }
}
