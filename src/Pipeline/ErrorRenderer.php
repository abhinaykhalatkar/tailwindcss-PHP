<?php

declare(strict_types=1);

namespace TailwindPhp\Pipeline;

use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * Turns a failed build into something a browser or a terminal can show
 * (design doc 15, FR-ERR-1/2/3, ADR-011, decision CCP-A1).
 *
 * It lives in `Pipeline` rather than `Http` because three callers need it — the
 * HTTP responder, the framework bridges and the console — and none of them
 * should own the wording.
 *
 * **The debug body is valid CSS.** That is the whole trick: a stylesheet that
 * fails to compile still parses, so the browser renders the page *and* shows a
 * banner, and DevTools carries the full text in the leading comment. A 500 with
 * an HTML error page would make some browsers drop the stylesheet without
 * telling anyone.
 *
 * **In production it says almost nothing.** One fixed comment — no message, no
 * code, no path — because the body is served to the public and a compiler error
 * routinely quotes a file path (threat T-06). The detail goes to the log.
 *
 * Exceptions arrive already scrubbed by `LogSafety`, so this class does not
 * re-scrub; what it must never do is reach for `getFile()` or
 * `getTraceAsString()` on the production path, where neither is scrubbed at all.
 */
final class ErrorRenderer
{
    /**
     * The entire production body (FR-ERR-2).
     */
    public const PRODUCTION_BODY = '/* tailwindcss-php: build failed */';

    private function __construct()
    {
    }

    /**
     * The debug overlay: a comment block, then a `html::before` rule
     * (decision HBC-A2 fixes the selector).
     *
     * @param DiagnosticBag|null $diagnostics First error goes in the banner,
     *                                        all of them into the comment.
     */
    public static function debugOverlayCss(
        \Throwable $e,
        string $bundle,
        string $driver,
        ?DiagnosticBag $diagnostics = null
    ): string {
        $errors = self::errorsIn($diagnostics);

        // The banner shows the *first* error; the comment shows all of them
        // (design doc 15). With no diagnostics at all — an infrastructure
        // failure rather than a CSS one — the exception message is what there
        // is to say.
        $headline = $errors === []
            ? $e->getMessage()
            : self::describe($errors[0], ' — ');

        $comment = "/* tailwindcss-php build failed\n";

        if ($errors === []) {
            $comment .= '   ' . self::commentSafe($e->getMessage()) . "\n";
        }

        foreach ($errors as $diagnostic) {
            $comment .= '   ' . self::commentSafe(self::describe($diagnostic, ': ')) . "\n";
        }

        $comment .= '   driver: ' . self::commentSafe($driver)
            . '  bundle: ' . self::commentSafe($bundle) . " */\n";

        return $comment
            . "html::before {\n"
            . '  content: "tailwindcss-php: ' . Escape::string($headline) . "\";\n"
            . "  position: fixed; inset: 0 0 auto 0; z-index: 2147483647;\n"
            . "  padding: 12px 16px; font: 14px/1.4 ui-monospace, monospace; white-space: pre-wrap;\n"
            . "  background: #7f1d1d; color: #fee2e2; border-bottom: 2px solid #fecaca;\n"
            . "}\n";
    }

    /**
     * Everything the public is told (FR-ERR-2).
     */
    public static function productionBody(): string
    {
        return self::PRODUCTION_BODY;
    }

    /**
     * The same failure for a terminal — `vendor/bin/tailwind build`.
     *
     * @param bool $ansi Colour the heading. False when the output is not a TTY
     *                   or `NO_COLOR` is set; the caller decides, because only
     *                   it knows where the text is going.
     */
    public static function consoleText(\Throwable $e, bool $ansi): string
    {
        $heading = 'tailwindcss-php: build failed';

        if ($ansi) {
            // 31 = red, 1 = bold, 0 = reset.
            $heading = "\033[1;31m" . $heading . "\033[0m";
        }

        // getMessage() only. getFile() and getTraceAsString() are not scrubbed
        // and would print absolute paths into a terminal that is routinely
        // pasted into an issue (T-06).
        return $heading . "\n" . $e->getMessage() . "\n";
    }

    /**
     * `E_CSS_SYNTAX<sep>message at file:line`, the shape design doc 15 shows.
     *
     * The separator differs by destination and that is deliberate: the comment
     * uses `: ` because it is read as a log line, the banner an em dash because
     * it is read as a sentence by someone looking at a red bar.
     */
    private static function describe(Diagnostic $diagnostic, string $separator): string
    {
        $text = $diagnostic->code() . $separator . $diagnostic->message();
        $file = $diagnostic->file();

        if ($file === null) {
            return $text;
        }

        $line = $diagnostic->line();

        return $text . ' at ' . $file . ($line === null ? '' : ':' . $line);
    }

    /**
     * Neutralise anything that would end the comment early.
     *
     * A message containing a comment terminator — star, slash — would close the
     * block and spill the rest of the text into the stylesheet as CSS: at best
     * a parse error, at worst a way to inject declarations through a filename.
     * Separating the two characters with a space reads the same and cannot
     * terminate.
     *
     * (This docblock spells the sequence out rather than quoting it, because
     * writing it literally would close *this* comment — the same bug, one level
     * up.)
     */
    private static function commentSafe(string $text): string
    {
        return \str_replace('*/', '* /', $text);
    }

    /**
     * @return list<Diagnostic>
     */
    private static function errorsIn(?DiagnosticBag $diagnostics): array
    {
        return $diagnostics === null ? [] : \array_values($diagnostics->errors());
    }
}
