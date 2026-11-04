<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Compiler\Cli\EnvironmentInterface;
use TailwindPhp\Compiler\Cli\ProbeResult;
use TailwindPhp\Compiler\Cli\Process;
use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Hash;
use TailwindPhp\Tests\Support\TempTree;

/**
 * Runs the pinned official Tailwind CLI for the maintainer tools
 * (decisions ENG-A1 and ENG-A11; threats T-03, T-04, T-05, T-18).
 *
 * Used by the golden generator and, later, by the data importers: both need
 * "what does the real binary print for this input", and neither should grow its
 * own answer to "which binary, and is it the right one".
 *
 * **It never downloads.** The binary is installed beforehand with the library's
 * own sanctioned command, which verifies the digest while streaming (T-18):
 *
 * ```
 * php bin/tailwind-php install-cli --version=4.1.18 --dest=tools/.cache/tailwindcss[.exe]
 * ```
 *
 * A missing binary is therefore an error that says so, not a reason to fetch one.
 *
 * **It verifies before every run (T-04)** with the same {@see BinaryVerifier}
 * and {@see Checksums} the library uses — and then asks one question the
 * library's verifier does not: is this digest the one pinned for the version the
 * goldens claim? A binary that is genuine but from another release would
 * silently produce goldens for that release, which is the quiet kind of wrong.
 * Re-verifying on every call is cheap, because the verifier reuses the digest it
 * computed while the file's mtime and size are unchanged. There is no
 * "unverified" switch.
 *
 * **Everything else is the library's machinery, not a copy of it:** the child is
 * started by {@see Process} (argv array, timeout, output cap, tree kill), with
 * the environment {@see EnvironmentInterface::childEnvironment()} builds, in a
 * private temporary project that is removed afterwards. argv holds the binary,
 * literal flags and paths this class generated — never anything from the caller
 * (T-03).
 *
 * Lives under `tools/`: maintainer tooling, not autoloaded, not shipped
 * (design doc 17). It leans on `tests/Support/TempTree` for directory removal,
 * so it needs the dev autoloader — which is what `composer update` installs.
 */
final class CliRunner
{
    /**
     * Seconds one invocation may take. Generous: the binary starts in well
     * under a second, and a loaded CI machine must not turn this into a flake.
     */
    public const DEFAULT_TIMEOUT = 120.0;

    /**
     * The three files this class owns in every temporary project.
     */
    private const RESERVED_NAMES = ['input.css', 'output.css', 'candidates.txt'];

    /**
     * Characters an extra file's name may contain.
     */
    private const NAME_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._-';

    private const MAX_NAME_BYTES = 128;

    /**
     * Longest stderr excerpt put into an error message.
     */
    private const MAX_MESSAGE_BYTES = 2000;

    /**
     * @var string
     */
    private $binaryPath;

    /**
     * @var string
     */
    private $version;

    /**
     * @var Checksums
     */
    private $checksums;

    /**
     * @var EnvironmentInterface
     */
    private $environment;

    /**
     * @var Process
     */
    private $process;

    /**
     * @var BinaryVerifier
     */
    private $verifier;

    /**
     * @var string
     */
    private $workRoot;

    /**
     * @var float
     */
    private $timeoutSec;

    /**
     * @var ProbeResult|null
     */
    private $lastProbe;

    /**
     * @param string $binaryPath Absolute path of the binary (or a `.cmd` shim).
     * @param string $version The release the goldens claim, e.g. `4.1.18`.
     * @param string $workRoot Directory the temporary projects are made in.
     */
    public function __construct(
        string $binaryPath,
        string $version,
        Checksums $checksums,
        EnvironmentInterface $environment,
        Process $process,
        FilesystemInterface $fs,
        string $workRoot,
        float $timeoutSec = self::DEFAULT_TIMEOUT
    ) {
        $this->binaryPath = $binaryPath;
        $this->version = $version;
        $this->checksums = $checksums;
        $this->environment = $environment;
        $this->process = $process;
        $this->verifier = new BinaryVerifier($checksums, $fs, \dirname($binaryPath));
        $this->workRoot = \rtrim(\str_replace('\\', '/', $workRoot), '/');
        $this->timeoutSec = $timeoutSec;
    }

    /**
     * The runner for a repository checkout, wired with the real collaborators.
     *
     * @throws \RuntimeException When no binary is installed under `tools/.cache/`.
     */
    public static function forRepository(string $repositoryRoot, string $version): self
    {
        $binary = self::locate($repositoryRoot);

        if ($binary === null) {
            throw new \RuntimeException(
                'No Tailwind binary is installed under tools/.cache/. ' . self::installHint($version)
            );
        }

        return new self(
            $binary,
            $version,
            Checksums::load(),
            new Environment(),
            new Process(),
            new Filesystem(),
            \sys_get_temp_dir()
        );
    }

    /**
     * The installed binary under `tools/.cache/`, or null.
     *
     * Resolved through {@see Environment::binaryPath()}, so it is confined to the
     * repository and symlinks that leave it are not followed.
     */
    public static function locate(string $repositoryRoot): ?string
    {
        $root = \rtrim(\str_replace('\\', '/', $repositoryRoot), '/');

        return (new Environment())->binaryPath(self::relativeBinaryPath(), $root, [$root]);
    }

    /**
     * What to run to install the pinned binary.
     */
    public static function installHint(string $version): string
    {
        return \sprintf(
            'Install the pinned release once with: php bin/tailwind-php install-cli --version=%s --dest=%s '
            . '(this tool never downloads anything itself).',
            $version,
            self::relativeBinaryPath()
        );
    }

    public function version(): string
    {
        return $this->version;
    }

    /**
     * Check that the binary on disk is the one pinned for this version.
     *
     * @throws \RuntimeException When it cannot be read or is not that binary.
     */
    public function verify(): ProbeResult
    {
        try {
            $probe = $this->verifier->verify(
                $this->binaryPath,
                $this->lastProbe === null ? null : $this->lastProbe->toArray(),
                true
            );
        } catch (DriverUnavailableException $e) {
            throw new \RuntimeException(
                'The Tailwind binary could not be read. ' . self::installHint($this->version),
                0,
                $e
            );
        }

        $pinned = $this->checksums->forVersion($this->version);
        $expected = $pinned === null ? null : ($pinned[$this->environment->platform()] ?? null);

        if ($expected === null || !Hash::equals($expected, $probe->sha256())) {
            throw new \RuntimeException(\sprintf(
                'The binary (sha256 %s) does not match the digest pinned for %s on %s. '
                . 'Refusing to run it. %s',
                $probe->sha256(),
                $this->version,
                $this->environment->platform(),
                self::installHint($this->version)
            ));
        }

        $this->lastProbe = $probe;

        return $probe;
    }

    /**
     * Compile one input with the real binary.
     *
     * @param string $inputCss The `input.css` text.
     * @param string $candidates The contents of `candidates.txt` — for the
     *                           generator, one space-separated line.
     * @param array<string, string> $files Extra files for the project, by plain
     *                                     file name (partials an entry imports,
     *                                     or a source file for the extractor).
     * @param bool $minify Pass `--minify`.
     *
     * @return string The raw stylesheet, banner included.
     *
     * @throws \InvalidArgumentException When an extra file name is unacceptable.
     * @throws \RuntimeException When the binary is not the pinned one, exits
     *                           non-zero, or writes no stylesheet.
     */
    public function compile(string $inputCss, string $candidates, array $files, bool $minify): string
    {
        foreach (\array_keys($files) as $name) {
            self::assertFileName((string) $name);
        }

        $this->verify();

        $directory = $this->workRoot . '/golden-' . \bin2hex(\random_bytes(6));

        \mkdir($directory, 0777, true);

        try {
            \file_put_contents($directory . '/input.css', $inputCss);
            \file_put_contents($directory . '/candidates.txt', $candidates);

            foreach ($files as $name => $contents) {
                \file_put_contents($directory . '/' . $name, $contents);
            }

            $argv = [
                $this->binaryPath,
                '-i',
                $directory . '/input.css',
                '-o',
                $directory . '/output.css',
                '--cwd',
                $directory,
            ];

            if ($minify) {
                $argv[] = '--minify';
            }

            $result = $this->process->run(
                $argv,
                $directory,
                $this->environment->childEnvironment($directory),
                $this->timeoutSec
            );

            if ($result->exitCode() !== 0) {
                throw new \RuntimeException(\sprintf(
                    'The Tailwind binary exited with status %d: %s',
                    $result->exitCode(),
                    self::excerpt($result->stderr() !== '' ? $result->stderr() : $result->stdout())
                ));
            }

            $css = \is_file($directory . '/output.css') ? \file_get_contents($directory . '/output.css') : false;

            if (!\is_string($css) || $css === '') {
                throw new \RuntimeException('The Tailwind binary exited cleanly but wrote no stylesheet.');
            }

            return $css;
        } finally {
            TempTree::remove($directory);
        }
    }

    /**
     * Where the binary lives, relative to the repository root.
     */
    private static function relativeBinaryPath(): string
    {
        return 'tools/.cache/' . (\DIRECTORY_SEPARATOR === '\\' ? 'tailwindcss.exe' : 'tailwindcss');
    }

    /**
     * A name that is one plain file in the temporary project and nothing else
     * (T-01): no separator, no drive letter, no dot-file, no reserved name.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertFileName(string $name): void
    {
        $length = \strlen($name);

        if (
            $length === 0
            || $length > self::MAX_NAME_BYTES
            || $name[0] === '.'
            || \strspn($name, self::NAME_CHARACTERS) !== $length
            || \in_array($name, self::RESERVED_NAMES, true)
        ) {
            throw new \InvalidArgumentException(
                \sprintf('"%s" is not an acceptable file name for the temporary project.', \substr($name, 0, 40))
            );
        }
    }

    private static function excerpt(string $text): string
    {
        $text = \trim($text);

        if (\strlen($text) > self::MAX_MESSAGE_BYTES) {
            $text = \substr($text, 0, self::MAX_MESSAGE_BYTES) . ' [truncated]';
        }

        return $text === '' ? '(no output)' : $text;
    }
}
