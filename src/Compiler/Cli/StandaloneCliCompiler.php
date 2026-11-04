<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Compiler\CompileOptions;
use TailwindPhp\Compiler\CompileResult;
use TailwindPhp\Compiler\CompilerInterface;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Diagnostic\Diagnostic;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Import\ImportResolverInterface;
use TailwindPhp\Exception\CacheWriteException;
use TailwindPhp\Exception\CliProcessException;
use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Bytes;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Path;
use TailwindPhp\Support\Regex;
use TailwindPhp\Support\Str;

/**
 * The official standalone binary, driven from PHP (design doc 06 §The CLI
 * driver; FR-COMP-3; ADR-002).
 *
 * The whole class is an exercise in handing a foreign program the smallest
 * possible surface. It gets a directory of our own making, three files we wrote,
 * an argv built from literals, and an environment of four entries. It gets no
 * configuration, no request data, and nothing it can use to find its way back
 * into the project.
 *
 * **argv is never assembled from user data (T-03).** Every element is either the
 * confined binary path, a literal flag, or an absolute path this class generated
 * a moment earlier. Candidates — the one input that genuinely comes from the
 * project's own templates — travel through a *file*, not the command line
 * (decision CCP-A10), which is what keeps the argument vector a fixed shape no
 * matter what a template contains.
 *
 * **The temp directory is the unit of cleanup.** It is created under the cache
 * directory with mode 0700, proven not to have been redirected by a symlink
 * before it is created (T-14), and removed in a `finally` — on success, on a
 * compile error and on an exception alike. Removal unlinks only the three names
 * this class writes and never recurses, because a recursive delete that follows
 * a link is how a cleanup routine becomes an arbitrary-deletion primitive.
 *
 * **Failure is split two ways (decision CCP-A11).** A non-zero exit whose stderr
 * carries parseable `Error:` lines is a problem with the *stylesheet*: it comes
 * back as `E_CLI_FAILED` diagnostics on the result, so the orchestrator raises
 * with every message at once rather than the first. Anything else — a crash, a
 * silent non-zero exit, a missing output file — is infrastructure, and throws.
 */
final class StandaloneCliCompiler implements CompilerInterface
{
    /**
     * The three names this class writes into its temp directory, and therefore
     * the only three it will ever unlink (see {@see self::removeTemporaryDirectory()}).
     */
    private const INPUT_NAME = 'input.css';
    private const CANDIDATES_NAME = 'candidates.txt';
    private const OUTPUT_NAME = 'output.css';

    /**
     * Cleanup retries. Windows does not release a killed child's handles the
     * moment `proc_terminate()` returns, so the first unlink after a timeout
     * fails with "Resource temporarily unavailable" and the directory would
     * leak under a predictable name (T-14).
     */
    private const CLEANUP_RETRIES = 25;
    private const CLEANUP_SLEEP_US = 20000;

    /**
     * @var list<string>
     */
    private const TEMPORARY_NAMES = [self::INPUT_NAME, self::CANDIDATES_NAME, self::OUTPUT_NAME];

    /**
     * Prepended to every `input.css` (decision CCP-A10, amended by CCP-A35).
     *
     * The binary must not walk the project: the scanner has already done that,
     * and a binary let loose on the tree is a binary reading files nobody
     * decided it should read. That confinement comes from `--cwd`, which points
     * at the private temporary directory holding only `input.css`,
     * `candidates.txt` and the output: Tailwind's automatic source detection
     * looks nowhere else. `candidates.txt` is named explicitly as well, so the
     * stylesheet is a function of exactly the candidate set the manifest hashed.
     * (`@source none;` was tried first and is rejected by Tailwind 4.1 with
     * "`@source` paths must be quoted", so it is not written.)
     */
    private const SOURCE_PREAMBLE = "@source \"./candidates.txt\";\n";

    /**
     * How deep `@import` inlining goes before it stops (CCP-A8).
     *
     * Not configurable, and not taken from `limits.max_import_depth`: this class
     * receives no configuration by design (T-03), and a hard bound is what stops
     * a deep chain from becoming stack recursion. Cycles are caught separately
     * and would otherwise never reach it.
     */
    private const MAX_IMPORT_DEPTH = 32;

    /**
     * Read cap for the produced stylesheet. Generous — this is a file we asked
     * for and a bound on our own memory, not a validation rule.
     */
    private const MAX_OUTPUT_BYTES = 67108864;

    private const DIRECTORY_MODE = 0700;
    private const FILE_MODE = 0600;

    /**
     * Cap on one diagnostic message built from the binary's stderr.
     *
     * The whole stream is already capped at 1 MiB by {@see Process}, but a single
     * 200 000-byte line inside that budget would still be stored whole, travel
     * into the manifest's `warnings[]` and be rendered in the debug overlay
     * (T-05, T-06). A compile error a person can act on fits in far less.
     */
    private const MAX_MESSAGE_BYTES = 1024;

    /**
     * One `@import`, quoted, with or without `url(...)` and with whatever media
     * or layer clause follows it. Unquoted `url(foo.css)` is deliberately not
     * matched: it is left for the binary rather than half-understood here.
     *
     * **Unanchored** (CCP-A31). It was previously pinned to statement start,
     * which reads well and is wrong: `.a{} @import "../../etc/passwd";` is a
     * perfectly valid stylesheet and the at-rule there was invisible to this
     * scan, so it travelled to the binary unconfined — the exact hole the
     * confinement exists to close. Anchoring was doing the job that
     * {@see self::withoutComments()} now does properly.
     */
    private const IMPORT_PATTERN = '#@import\s*(?:url\(\s*)?([\'"])([^\'"\r\n]*)\1\s*\)?\s*[^;\r\n]*;#i';

    /**
     * One `@source` directive (CCP-A30), unanchored for the same reason as
     * {@see self::IMPORT_PATTERN}: `.b{} @source "/";` points the binary at the
     * whole disk just as effectively from the middle of a line.
     */
    private const SOURCE_PATTERN = '#@source\s[^;\r\n]*;#i';

    /**
     * A CSS block comment, including an unterminated one at end of input.
     *
     * Removed before any at-rule scan (CCP-A31). Anchoring used to stand in for
     * comment handling — a commented-out `@import` was ignored because it was
     * rarely at statement start — which worked by accident and failed the moment
     * a comment preceded a real directive on the same line. Deleting comments
     * first makes both cases follow from one rule: what remains is code.
     *
     * The unterminated arm matters because a stylesheet ending mid-comment would
     * otherwise keep its `@import`s live, and a truncated file is exactly when
     * that is least likely to be intended.
     */
    private const COMMENT_PATTERN = '#/\*.*?(?:\*/|$)#s';

    /**
     * ANSI CSI sequences (`ESC [ … final`) and OSC strings (`ESC ] … BEL`, or
     * terminated by `ESC \`), which a colourising binary emits around its own
     * error text. Not a general terminal parser — the two forms a CLI actually
     * writes, removed so the `Error:` match sees the words.
     */
    private const ANSI_PATTERN = '#\x1b\[[0-9;?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)#';

    /**
     * A stderr line the binary meant as a compile error. Case-insensitive
     * because the casing is the binary's choice, not a contract; anchored, so
     * the `D` modifier is required (design doc 18).
     */
    private const ERROR_LINE_PATTERN = '#^\s*error:\s*(\S.*)$#Di';

    /**
     * @var string
     */
    private $binaryPath;

    /**
     * @var string
     */
    private $version;

    /**
     * @var string
     */
    private $cacheDir;

    /**
     * @var float
     */
    private $timeoutSec;

    /**
     * @var Process
     */
    private $process;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var AtomicWriter
     */
    private $writer;

    /**
     * @var EnvironmentInterface
     */
    private $environment;

    /**
     * @param string $binaryPath Absolute, already confined by {@see Environment}.
     * @param string $version The binary's own version, from the probe; recorded
     *                        in the manifest and part of the cache fingerprint.
     * @param string $cacheDir Absolute; the temp directory is created inside it.
     * @param float $timeoutSec `cli.timeout` — the bound on one child (T-05).
     * @param FilesystemInterface $fs Reads the produced stylesheet.
     * @param string $projectRoot Absolute; used only to keep paths out of the
     *                            diagnostics the binary's stderr produces (ADR-014,
     *                            threat T-06).
     * @param AtomicWriter $writer Writes the two input files.
     * @param EnvironmentInterface $environment Supplies the child's entire
     *                                          environment. Injected rather than
     *                                          constructed here so a test can
     *                                          describe the child's world without
     *                                          reading the host's real variables,
     *                                          and so the one file allowed to call
     *                                          `getenv()` stays the only one that
     *                                          does (see {@see Environment::childEnvironment()}).
     */
    public function __construct(
        string $binaryPath,
        string $version,
        string $cacheDir,
        float $timeoutSec,
        Process $process,
        FilesystemInterface $fs,
        string $projectRoot,
        AtomicWriter $writer,
        EnvironmentInterface $environment
    ) {
        $this->binaryPath = $binaryPath;
        $this->version = $version;
        $this->cacheDir = $cacheDir;
        $this->timeoutSec = $timeoutSec;
        $this->process = $process;
        $this->fs = $fs;
        $this->projectRoot = $projectRoot;
        $this->writer = $writer;
        $this->environment = $environment;
    }

    public function name(): string
    {
        return 'cli';
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * @param list<string> $candidates Sorted and unique.
     *
     * @throws CliProcessException When the binary could not be run, exited
     *                             non-zero without saying why, or produced no
     *                             stylesheet.
     * @throws \TailwindPhp\Exception\CliTimeoutException When it outlived `cli.timeout`.
     */
    public function compile(
        string $entryCss,
        ImportResolverInterface $imports,
        array $candidates,
        CompileOptions $options
    ): CompileResult {
        $started = \hrtime(true);
        $directory = $this->createTemporaryDirectory();

        try {
            /** @var array<string, true> $inlined */
            $inlined = [];
            $notices = [];

            // Comments go first, and everything downstream depends on it
            // (CCP-A31). Both scans below are unanchored, so a directive is found
            // wherever it sits — which is correct, because CSS lets it sit
            // anywhere — and that is only safe once commented-out text is no
            // longer there to be found. One rule instead of two half-rules: what
            // survives this line is code.
            $body = self::withoutComments($entryCss);

            // `@source` in the entry would point the binary at the whole disk
            // (the preamble only names `candidates.txt`), so it is removed
            // and reported rather than honoured (CCP-A30).
            $body = $this->stripSourceDirectives($body, $notices);
            // Relative imports resolve against the entry's directory (ADR-014).
            $entryRel = $options->entryRelPath();
            $input = self::SOURCE_PREAMBLE . $this->inlineImports($body, $imports, $entryRel, 0, $inlined, $notices);

            // Candidates go to disk on **one space-separated line** (CCP-A10),
            // never into argv: the argument vector stays a fixed shape whatever
            // a template contains (T-03). The line is terminated, because a
            // newline-less last line is a text file some tools decline to read
            // — and one trailing newline still means one line.
            $this->write(
                $directory . '/' . self::CANDIDATES_NAME,
                \implode(' ', self::printableCandidates($candidates)) . "\n"
            );
            $this->write($directory . '/' . self::INPUT_NAME, $input);

            $result = $this->process->run(
                $this->argvFor($directory, $options),
                $directory,
                $this->environmentFor($directory),
                $this->timeoutSec
            );

            return $this->resultFor($directory, $result, \array_keys($inlined), $started, $notices);
        } finally {
            // Success, compile error and exception alike: nothing this class
            // created outlives the call.
            $this->removeTemporaryDirectory($directory);
        }
    }

    /**
     * Whether this file is reachable under another name — i.e. a hard link.
     *
     * A file the binary genuinely created has exactly one name. More than one
     * means the name in our temp directory is a second door onto a file that
     * lives somewhere else, and reading it serves that file's bytes as CSS.
     *
     * Fails **closed**: if the link count cannot be determined the file is
     * treated as suspect, because the alternative is serving whatever it points
     * at on the strength of a `stat()` that did not work.
     */
    private static function hasOtherNames(string $path): bool
    {
        \clearstatcache(true, $path);

        $stat = @\stat($path);

        if (!\is_array($stat) || !isset($stat['nlink'])) {
            return true;
        }

        return (int) $stat['nlink'] > 1;
    }

    /**
     * Whether a specifier must not be forwarded to the binary (CCP-A29,
     * amended).
     *
     * **This is deliberately wider than `FilesystemImportResolver`'s test**, and
     * the earlier docblock claiming parity was wrong. The two answer different
     * questions. The resolver asks "is this something I should try to resolve?"
     * and may reasonably say no to `/etc/passwd` — it is not a project-relative
     * import. This asks "may the binary be handed this?", and for `/etc/passwd`
     * the answer is emphatically no. Making them identical would mean every
     * specifier the resolver declines gets forwarded, which is how an absolute
     * path reaches a program running with `--cwd` set and no confinement at all.
     *
     * So: anything that looks like a filesystem location in any spelling, plus
     * the three degenerate forms, is refused. What passes through is only a bare
     * package specifier — `tailwindcss`, `tailwindcss/utilities`,
     * `@tailwindcss/typography` — which is the binary's own business to resolve
     * and the one thing it does better than we do.
     */
    private static function looksLikeAPath(string $specifier): bool
    {
        // Degenerate: not a path we would resolve, and emphatically not one to
        // forward. A wrapper (`php://`, `data://`) is a path in the only sense
        // that matters — it names something the binary would go and read.
        if ($specifier === '' || Path::hasNul($specifier) || Path::isWrapper($specifier)) {
            return true;
        }

        // Relative, POSIX-absolute, or UNC/Windows-absolute.
        if (
            \strncmp($specifier, './', 2) === 0
            || \strncmp($specifier, '../', 3) === 0
            || $specifier[0] === '/'
            || $specifier[0] === '\\'
        ) {
            return true;
        }

        // A drive letter, with or without a separator: `C:/x`, `C:x` (which is
        // drive-relative and just as much a filesystem location).
        if (\strlen($specifier) >= 2 && $specifier[1] === ':' && Bytes::isAsciiAlpha($specifier[0])) {
            return true;
        }

        // A backslash anywhere makes it a Windows path spelling — `..\..\secret`
        // has no leading `./` to catch it.
        if (\strpos($specifier, '\\') !== false) {
            return true;
        }

        return \substr($specifier, -4) === '.css';
    }

    /**
     * Candidates with control bytes dropped, so `candidates.txt` stays one line.
     *
     * A candidate carrying LF would otherwise be written verbatim and split the
     * file, which breaks the one-line contract (CCP-A10) and lets a crafted
     * template decide how the binary reads the rest. NUL and the other C0 bytes
     * go for the same reason. Nothing legitimate is lost: a Tailwind candidate is
     * a class name, and no class name contains a control byte — so this rejects
     * inputs that could not have been real candidates in the first place.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private static function printableCandidates(array $candidates): array
    {
        $safe = [];

        foreach ($candidates as $candidate) {
            $length = \strlen($candidate);
            $clean = true;

            for ($i = 0; $i < $length; ++$i) {
                $byte = \ord($candidate[$i]);

                if ($byte < 0x20 || $byte === 0x7F) {
                    $clean = false;

                    break;
                }
            }

            if ($clean && $candidate !== '') {
                $safe[] = $candidate;
            }
        }

        return $safe;
    }

    /**
     * Remove every `@source` directive from the entry stylesheet (CCP-A30).
     *
     * The temporary `--cwd` is what stops the binary walking the project on its
     * own — the scanner has already done that, and a binary let loose on the
     * tree reads files nobody decided it should read. But a `@source "/"` in the
     * entry would name a root outside that directory and silently win.
     * Rather than let that happen quietly, the directives are stripped and each
     * one is reported: honouring them is an M2 feature, and an operator who wrote
     * one deserves to be told it did nothing.
     *
     * @param list<Diagnostic> $notices Appended to, one per directive removed.
     */
    private function stripSourceDirectives(string $css, array &$notices): string
    {
        // A counter, not a list: the directives themselves were collected and
        // then never read, and the message is deliberately identical for each —
        // echoing the operator's own `@source "/"` back into a diagnostic would
        // put a configured path somewhere it can be rendered (T-06).
        $removed = 0;

        $stripped = Regex::replaceCallback(
            self::SOURCE_PATTERN,
            function (array $matches) use (&$removed): string {
                ++$removed;

                return '';
            },
            $css
        );

        for ($i = 0; $i < $removed; ++$i) {
            $notices[] = new Diagnostic(
                Diagnostic::WARNING,
                Diagnostic::W_SOURCE_IGNORED,
                '@source in the entry stylesheet is honoured from M2; configure `sources` in M1.'
            );
        }

        return $stripped;
    }

    /**
     * The stylesheet with its comments removed (CCP-A31).
     *
     * Not a CSS parser, and it does not need to be. A comment-opening sequence
     * appearing inside a string literal would be treated as a comment here too,
     * and that is harmless: this text is only ever scanned for at-rules and then
     * handed to the binary. The one thing that must not happen — a real directive
     * escaping the scan because a comment sat in front of it — is exactly what
     * this prevents.
     */
    private static function withoutComments(string $css): string
    {
        return Regex::replace(self::COMMENT_PATTERN, '', $css);
    }

    /**
     * `<cacheDir>/.cli-<pid>-<8 hex>`, proven to be where it looks like it is
     * before anything is written into it.
     *
     * The pid keeps two concurrent workers apart and the random suffix keeps the
     * name unguessable, so nothing can be waiting at the path we are about to
     * create. `isSymlinkEscape()` then walks the path through the real
     * filesystem, resolving to the nearest existing ancestor, and `confine()` is
     * the lexical half of the same question. `mkdir` without `recursive`
     * finishes the argument: it fails rather than creating a chain of parents
     * somewhere unexpected.
     *
     * **What this does not do**, corrected after review measured it: it does not
     * refuse a cache directory that is itself a junction or symlink. Both sides
     * of the comparison resolve through the same link, so they agree and nothing
     * is flagged. Confining the *cache directory* is `CacheStore`'s job — it is
     * the class that is handed `allowedRoots` and can say where the cache is
     * permitted to be. This method's guarantee is narrower and worth stating
     * exactly: the temp directory is inside whatever the cache directory turns
     * out to be, and is not a link pointing somewhere else.
     *
     * @throws CliProcessException When the path cannot be vouched for or created.
     */
    private function createTemporaryDirectory(): string
    {
        $candidate = \rtrim(Path::normalize($this->cacheDir), '/')
            . '/.cli-' . (int) \getmypid()
            . '-' . \bin2hex(\random_bytes(4));

        if (Path::isSymlinkEscape($candidate, [$this->cacheDir])) {
            throw new CliProcessException(-1, '');
        }

        try {
            $directory = Path::confine($candidate, $this->cacheDir);
        } catch (PathException $e) {
            throw new CliProcessException(-1, '', null, $e);
        }

        // Suppressed and immediately checked: a missing or unwritable cache
        // directory raises a warning and returns false, and the return value is
        // the answer we act on (design doc 18 §Design rules).
        if (!@\mkdir($directory, self::DIRECTORY_MODE)) {
            throw new CliProcessException(-1, '');
        }

        return $directory;
    }

    /**
     * Write one of our input files through the atomic writer, so a reader — the
     * binary — never sees a partial one (T-13).
     *
     * @throws CliProcessException
     */
    private function write(string $path, string $bytes): void
    {
        try {
            $this->writer->write($path, $bytes, self::FILE_MODE);
        } catch (CacheWriteException $e) {
            // Re-cast rather than propagated: {@see CompilerInterface} promises
            // a CompilationException for infrastructure failures, and a cache
            // exception escaping a compile would be a surprise to every caller.
            throw new CliProcessException(-1, '', null, $e);
        }
    }

    /**
     * The argument vector, in full (T-03).
     *
     * Seven elements, or eight with `--minify`, and every one of them is either
     * the confined binary path, a literal flag or an absolute path this class
     * generated. **Nothing configurable is ever added here** — not a config
     * path, not a content glob, not a candidate.
     *
     * @return list<string>
     */
    private function argvFor(string $directory, CompileOptions $options): array
    {
        $argv = [
            $this->binaryPath,
            '-i',
            $directory . '/' . self::INPUT_NAME,
            '-o',
            $directory . '/' . self::OUTPUT_NAME,
            '--cwd',
            $directory,
        ];

        if ($options->minify()) {
            $argv[] = '--minify';
        }

        return $argv;
    }

    /**
     * The child's entire environment — it inherits nothing implicitly.
     *
     * Delegated rather than built here: the contents are platform knowledge
     * (which machine variables Windows cannot start a `.cmd` without, where the
     * PHP directory goes on `PATH`), and that belongs with the rest of the
     * platform detection in `Environment`, which is also the one file allowlisted
     * to read `getenv()`. Keeping it there means this class has no reason to
     * touch the ambient environment at all.
     *
     * @return array<string, string>
     */
    private function environmentFor(string $directory): array
    {
        return $this->environment->childEnvironment($directory);
    }

    /**
     * Turn one finished process into a result — or into an exception (CCP-A11).
     *
     * @param list<string> $imports
     * @param list<Diagnostic> $notices Collected while building input.css.
     *
     * @throws CliProcessException
     */
    private function resultFor(
        string $directory,
        ProcessResult $result,
        array $imports,
        float $started,
        array $notices
    ): CompileResult {
        $stderr = $result->stderr();

        if ($result->exitCode() !== 0) {
            $failures = \array_values(\array_merge($notices, $this->errorDiagnosticsFrom($stderr, $directory)));

            if ($failures === []) {
                // A non-zero exit that says nothing parseable is a crash, a
                // missing shared library, a signal — infrastructure, not a
                // stylesheet the author can fix.
                throw new CliProcessException($result->exitCode(), $stderr);
            }

            return $this->resultWith('', new DiagnosticBag($failures), $imports, $started);
        }

        $output = $directory . '/' . self::OUTPUT_NAME;

        if (!$this->fs->isFile($output)) {
            // Exit zero and no stylesheet: the binary is not doing what this
            // class thinks it is, and an empty artefact must not be cached.
            throw new CliProcessException($result->exitCode(), $stderr);
        }

        // The binary is a foreign program writing into a directory it was handed,
        // so what sits at `output.css` when it finishes is its choice, not ours.
        // A link planted there makes this read return a file from anywhere the
        // web user can reach, and those bytes would be served as CSS and cached
        // (T-14, T-06). Confinement that stopped at directory creation would be
        // decoration; it has to hold at the moment of the read.
        //
        // Three checks, because one is not enough and I measured why:
        //
        // - `is_link()` catches a symlink, and **only** a symlink. On Windows it
        //   returns *false* for a hard link (measured: `mklink /H` then
        //   `is_link()` → false, and the read returned the target's bytes).
        // - `isSymlinkEscape()` walks the real path, which catches a symlink
        //   pointing out of the directory — but a hard link has no separate
        //   target to resolve, so its real path is itself and this sees nothing.
        // - The link **count** is what actually identifies a hard link: a file
        //   with another name elsewhere has `nlink > 1` (measured: 2 for a hard
        //   link, 1 for a file the binary genuinely wrote).
        if (\is_link($output) || Path::isSymlinkEscape($output, [$directory]) || self::hasOtherNames($output)) {
            throw new CliProcessException($result->exitCode(), $stderr);
        }

        // The notices ride the success path too: a stripped `@source` or a
        // refused `@import` does not stop the compile, but silently dropping the
        // author's directive and then saying nothing is how a person spends an
        // afternoon wondering why their stylesheet has no effect.
        return $this->resultWith(
            $this->fs->read($output, self::MAX_OUTPUT_BYTES),
            new DiagnosticBag(\array_values($notices)),
            $imports,
            $started
        );
    }

    /**
     * @param list<string> $imports
     */
    private function resultWith(string $css, DiagnosticBag $bag, array $imports, float $started): CompileResult
    {
        return new CompileResult(
            $css,
            $bag,
            $this->name(),
            $this->version,
            (float) (\hrtime(true) - $started) / 1000000.0,
            $imports,
            // `@source inline(...)` discovery is M2 (decision CCP-A12).
            []
        );
    }

    /**
     * One `E_CLI_FAILED` error per parseable stderr line (CCP-A11).
     *
     * Every line is reported rather than only the first, because one compile
     * should tell the author about every problem in the stylesheet. The text is
     * scrubbed by {@see Diagnostic} on construction, so nothing raw from a
     * subprocess reaches the manifest or the overlay (T-06).
     *
     * @return list<Diagnostic>
     */
    private function errorDiagnosticsFrom(string $stderr, string $directory): array
    {
        $diagnostics = [];

        foreach (\explode("\n", \str_replace("\r\n", "\n", $stderr)) as $line) {
            $matches = [];

            // Scrubbed *before* the match, not after: a binary that colourises
            // its output writes `\e[31mError:\e[0m …`, and matching first would
            // classify a perfectly ordinary compile error as an infrastructure
            // failure and throw instead of returning diagnostics (CCP-A11).
            if (!Regex::match(self::ERROR_LINE_PATTERN, self::readableLine($line), $matches)) {
                continue;
            }

            $diagnostics[] = new Diagnostic(
                Diagnostic::ERROR,
                Diagnostic::E_CLI_FAILED,
                Str::truncateBytes(
                    $this->withoutLocalPaths(\rtrim($matches[1]), $directory),
                    self::MAX_MESSAGE_BYTES
                )
            );
        }

        return $diagnostics;
    }

    /**
     * One line of the binary's stderr, made safe to match and to store.
     *
     * Two separate problems, both of which showed up against a real binary:
     *
     * - **Escape sequences.** A colourising binary writes `\e[31mError:\e[0m`.
     *   Left in, the anchored `Error:` match fails and a plain compile error is
     *   misclassified as an infrastructure failure — so this runs *before* the
     *   match, not after it.
     * - **Control bytes.** A lone CR rewrites the start of the line on a terminal
     *   and lets stderr forge what looks like a separate log entry; the other C0
     *   bytes and DEL are no better in a manifest or an overlay (T-06).
     *
     * Replaced with a space rather than removed, so that two words separated only
     * by a control byte do not silently become one.
     */
    private static function readableLine(string $line): string
    {
        $withoutEscapes = Regex::replace(self::ANSI_PATTERN, '', $line);
        $length = \strlen($withoutEscapes);
        $out = '';

        for ($i = 0; $i < $length; ++$i) {
            $byte = \ord($withoutEscapes[$i]);
            $out .= $byte < 0x20 || $byte === 0x7F ? ' ' : $withoutEscapes[$i];
        }

        return \rtrim($out);
    }

    /**
     * Drop our own absolute prefixes from a line of the binary's stderr.
     *
     * The binary only knows the paths we gave it, so its messages point at
     * `<tmp>/input.css` — which is both meaningless to the reader and the server
     * layout in a log (ADR-014, T-06). Best-effort by nature: the binary may
     * print a path in a different form, which is why {@see Diagnostic} scrubs
     * afterwards rather than trusting this.
     */
    private function withoutLocalPaths(string $line, string $directory): string
    {
        return \str_replace(
            [$directory . '/', $directory, $this->projectRoot . '/', $this->projectRoot],
            '',
            $line
        );
    }

    /**
     * Inline every `@import` the resolver vouches for, and leave the rest alone.
     *
     * The recursion, the depth bound and the cycle set live here because the
     * resolver answers one specifier at a time and cannot see the shape of the
     * walk (CCP-A8). Three rules:
     *
     * - **A specifier the resolver returns null for is left in place.** That is
     *   a bare package name such as `"tailwindcss"`, which the binary resolves
     *   itself, or a path outside the permitted roots, which nobody should
     *   resolve. Either way the text is untouched rather than dropped.
     * - **A file already inlined is skipped, not inlined again.** That is what
     *   makes `a → b → a` terminate, and it is a set rather than a stack because
     *   re-emitting the same stylesheet twice is not something the compile
     *   wants either way. A cycle is not an error: it is a common shape in
     *   hand-written CSS and one the binary would tolerate.
     * - **Past {@see self::MAX_IMPORT_DEPTH} the import is dropped.** Leaving it
     *   would hand the binary a relative path that means nothing from the temp
     *   directory.
     *
     * @param string $fromRelPath Project-relative path of the importing file.
     *                            The entry's own path is not part of
     *                            {@see CompilerInterface::compile()}'s contract,
     *                            so it is '' and its relative specifiers resolve
     *                            against the project root; nested files use the
     *                            path the resolver returned for them.
     * @param array<string, true> $seen Relative paths already inlined, in order.
     * @param list<Diagnostic> $notices Appended to for each refused path import.
     */
    private function inlineImports(
        string $css,
        ImportResolverInterface $imports,
        string $fromRelPath,
        int $depth,
        array &$seen,
        array &$notices
    ): string {
        return Regex::replaceCallback(
            self::IMPORT_PATTERN,
            function (array $matches) use ($imports, $fromRelPath, $depth, &$seen, &$notices): string {
                $specifier = $matches[2];

                if ($specifier === '') {
                    return $matches[0];
                }

                $resolved = $imports->resolve($specifier, $fromRelPath);

                if ($resolved === null) {
                    // A bare package specifier ("tailwindcss",
                    // "tailwindcss/utilities") is the binary's to resolve, and
                    // passing it through is the whole point of not inlining it.
                    if (!self::looksLikeAPath($specifier)) {
                        return $matches[0];
                    }

                    // A *path* the resolver refused is a different matter
                    // (CCP-A29). Handing it to the binary verbatim would let it
                    // resolve `../../../../etc/passwd` from `--cwd` with no
                    // confinement at all, and the bytes it inlined would become
                    // served CSS. The confinement this class performs would be
                    // decoration if the one specifier that failed it were then
                    // forwarded. So it is removed and reported.
                    $notices[] = new Diagnostic(
                        Diagnostic::ERROR,
                        Diagnostic::E_IMPORT_NOT_FOUND,
                        \sprintf('@import "%s" could not be resolved inside the project.', Escape::string($specifier))
                    );

                    return '';
                }

                if ($depth >= self::MAX_IMPORT_DEPTH) {
                    return '';
                }

                $relPath = $resolved->relPath();

                if (isset($seen[$relPath])) {
                    return '';
                }

                $seen[$relPath] = true;

                return $this->inlineImports($resolved->css(), $imports, $relPath, $depth + 1, $seen, $notices);
            },
            $css
        );
    }

    /**
     * Remove the temp directory without following anything.
     *
     * The three names this class writes are unlinked and nothing else is: no
     * `scandir` walk, no recursion, no `rmdir` of a child. That is a deliberate
     * refusal to be a general-purpose deleter — a recursive cleanup that follows
     * a directory junction (which reports `is_dir() === true` on Windows) empties
     * whatever the junction points at. `unlink()` removes a symlink itself and
     * never its target, so the link check is about being explicit rather than
     * about safety.
     *
     * A leftover the binary created keeps `rmdir` from succeeding and the
     * directory survives. That is the intended trade: an orphaned directory
     * under the cache is a housekeeping problem, and following links out of it
     * would be a security one.
     */
    private function removeTemporaryDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        // Retried, because of the **timeout** path specifically. Windows does
        // not release the killed child's handles the instant `proc_terminate()`
        // returns, so the first `unlink`/`rmdir` fails with "Resource
        // temporarily unavailable" and the directory leaks under a predictable
        // name in a writable location (T-14). Measured: without retries,
        // `T05CliTimeoutTest::atimedOutCompileStillRemovesItsTemporaryDirectory`
        // fails every run on Windows.
        //
        // The same reason `AtomicWriter` retries its rename (design doc 17).
        for ($attempt = 0; $attempt < self::CLEANUP_RETRIES; ++$attempt) {
            foreach (self::TEMPORARY_NAMES as $name) {
                $path = $directory . '/' . $name;

                if (!\is_link($path) && !\is_file($path)) {
                    continue;
                }

                // Suppressed and not checked (design doc 18 §Design rules): the
                // file is ours and already on its way out. A complaint about
                // failing to remove it would bury whatever else is being
                // reported — the retry is what handles the transient case.
                @\unlink($path);
            }

            // Same reasoning; the loop is what makes a transient failure
            // recoverable, and a permanent one is still tolerated.
            if (@\rmdir($directory) || !\is_dir($directory)) {
                return;
            }

            \usleep(self::CLEANUP_SLEEP_US);
        }
    }
}
