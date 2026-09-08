<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

use TailwindPhp\Exception\PathException;
use TailwindPhp\Support\Path;

/**
 * What this host can actually do (design doc 06 §Environment; threats T-01, T-18).
 *
 * Two questions, asked on every request when the driver is `auto`, so both are
 * cheap and both are memoised per instance:
 *
 * - **Can we spawn at all?** Shared hosts routinely disable `proc_open`, and
 *   Suhosin has its own blacklist. Discovering that by catching a fatal is not
 *   an option, so it is checked before anything is attempted.
 * - **Where is the binary?** A configured path is re-confined here even though
 *   `Config` already confined it — this class is what hands a path to
 *   `proc_open`, and a control at the point of use survives a refactor that a
 *   control three layers up does not (T-01).
 *
 * No process is ever spawned by this class. Asking "is the binary usable" must
 * not itself cost a fork.
 */
final class Environment implements EnvironmentInterface
{
    /**
     * The platform keys `resources/checksums.json` is indexed by.
     */
    public const PLATFORM_LINUX_X64 = 'linux-x64';
    public const PLATFORM_LINUX_X64_MUSL = 'linux-x64-musl';
    public const PLATFORM_LINUX_ARM64 = 'linux-arm64';
    public const PLATFORM_MACOS_X64 = 'macos-x64';
    public const PLATFORM_MACOS_ARM64 = 'macos-arm64';
    public const PLATFORM_WINDOWS_X64 = 'windows-x64.exe';

    /**
     * Names the official binary ships under, in the order they are tried
     * (decision CCP-A22/A23).
     */
    private const VENDOR_NAMES = ['tailwindcss', 'tailwindcss.exe', 'tailwindcss.cmd'];

    /**
     * The child's `PATH` on POSIX. Deliberately not the parent's: a compiler
     * that inherited `PATH` would resolve helpers out of whatever the web server
     * happens to carry.
     */
    private const CHILD_PATH = '/usr/bin:/bin';

    /**
     * Machine variables the Windows child cannot run without.
     *
     * `SystemRoot` is not a convenience: `cmd.exe` cannot execute a batch file
     * without it and exits 1 with **empty stdout and stderr**, which is
     * indistinguishable from a binary that ran and produced nothing. Measured —
     * `PATH` alone, `PATH`+`PATHEXT`, `PATH`+`ComSpec`, `PATH`+System32, and a
     * bogus or empty `SystemRoot` all fail identically; only the real value
     * works. `.cmd` is an explicitly supported form (CCP-A22/A23), so this is
     * needed for a supported configuration.
     */
    private const WINDOWS_MACHINE_VARS = ['SystemRoot', 'PATHEXT'];

    /**
     * @var bool|null Memoised; null until first asked.
     */
    private $processAvailable;

    /**
     * @var string|null
     */
    private $platform;

    /**
     * Whether a child process can be started at all.
     *
     * Memoised per instance rather than per process: the answer cannot change
     * within a request, and a static cache would be state that survives between
     * tests (design doc 18 forbids static state).
     */
    public function processFunctionAvailable(): bool
    {
        if ($this->processAvailable !== null) {
            return $this->processAvailable;
        }

        $available = \function_exists('proc_open')
            && !$this->isDisabled('proc_open')
            && !$this->isSuhosinBlacklisted('proc_open');

        $this->processAvailable = $available;

        return $available;
    }

    /**
     * Locate the Tailwind binary, or answer null.
     *
     * @param string|null $configured `cli.binary` from the config, or null.
     * @param list<string> $allowedRoots Absolute confinement roots.
     *
     * @return string|null Absolute path, confined; null when there is nothing
     *                     usable. Never throws — "no binary" is an ordinary
     *                     answer that makes `auto` fall back to the PHP engine.
     */
    public function binaryPath(?string $configured, string $projectRoot, array $allowedRoots): ?string
    {
        if ($configured !== null && $configured !== '') {
            return $this->usableBinary($configured, $projectRoot, $allowedRoots);
        }

        foreach (self::VENDOR_NAMES as $name) {
            $found = $this->usableBinary('vendor/bin/' . $name, $projectRoot, $allowedRoots);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * `uname`-derived, matching the release asset names.
     *
     * Best-effort by nature: musl cannot be detected reliably from PHP, so the
     * glibc build is assumed and a wrong guess shows up as an unknown checksum
     * rather than as a crash.
     */
    public function platform(): string
    {
        if ($this->platform !== null) {
            return $this->platform;
        }

        $this->platform = $this->detectPlatform();

        return $this->platform;
    }

    /**
     * The child's entire environment — it inherits nothing implicitly.
     *
     * `HOME` and `TMPDIR` point at the temp directory so anything the binary
     * decides to cache lands somewhere we are about to delete rather than in the
     * web user's home. `NO_COLOR` keeps ANSI escapes out of the stderr we turn
     * into diagnostics.
     *
     * On Windows the PHP directory is prepended to `PATH` so a `.cmd` shim can
     * find `php` (CCP-A23), and two machine variables are carried through — see
     * {@see self::WINDOWS_MACHINE_VARS} for why `SystemRoot` is not optional.
     *
     * **Why `getenv()` lives here.** This is the only place in `src/` that reads
     * it, and `Compiler/Cli/Environment.php` is allowlisted in
     * `tools/StaticSecurityChecks.php` for exactly that reason. The two names
     * read are machine variables set by the operating system at boot; no request
     * can influence them, which is the distinction T-09 draws (it is about
     * request-derived state, not about the environment as such). Nothing here is
     * ever interpolated into a command line — the values become environment
     * entries for the child and nothing else.
     *
     * @return array<string, string>
     */
    public function childEnvironment(string $tmpDir): array
    {
        // Platform-split rather than appended: `/usr/bin:/bin` means nothing on
        // Windows, where `PATH_SEPARATOR` is `;` and those are not directories.
        // Harmless, but a child's environment should not carry entries that were
        // never going to apply to it.
        $path = \DIRECTORY_SEPARATOR === '\\'
            ? \dirname(\PHP_BINARY)
            : self::CHILD_PATH;

        $environment = [
            'PATH' => $path,
            'HOME' => $tmpDir,
            'TMPDIR' => $tmpDir,
            'NO_COLOR' => '1',
        ];

        if (\DIRECTORY_SEPARATOR !== '\\') {
            return $environment;
        }

        // Windows spells the temporary directory `TEMP`/`TMP`, not `TMPDIR`, and
        // the official binary is a Bun executable that unpacks its native
        // modules there before it can start: without these two it fails with
        // "LoadLibrary failed: The specified module could not be found"
        // (measured against tailwindcss-windows-x64.exe 4.1.18; CCP-A36). They
        // point at the same private directory as `TMPDIR`, so nothing the
        // child writes lands outside the space this class controls.
        $environment['TEMP'] = $tmpDir;
        $environment['TMP'] = $tmpDir;

        foreach (self::WINDOWS_MACHINE_VARS as $name) {
            $value = \getenv($name);

            // A missing value is skipped rather than passed as '': an empty
            // SystemRoot fails exactly like an absent one (measured), so an
            // empty entry would only disguise the cause.
            if (\is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * The absolute path of a Windows system tool.
     *
     * Spawning bare `taskkill` would resolve it through the child's `PATH`, and
     * a `PATH` an attacker can influence turns "kill the process tree" into
     * "run whatever is called taskkill.exe first". The tool lives in
     * `%SystemRoot%\System32` on every supported Windows, and `SystemRoot` is a
     * machine variable no request can set — which is why this is the same
     * allowlisted `getenv()` site as {@see self::childEnvironment()}.
     *
     * @return string|null Null when `SystemRoot` is unset, in which case the
     *                     caller must not fall back to a bare name.
     */
    public static function systemTool(string $name): ?string
    {
        if (\DIRECTORY_SEPARATOR !== '\\') {
            return null;
        }

        $root = \getenv('SystemRoot');

        if (!\is_string($root) || $root === '') {
            return null;
        }

        $path = $root . '\\System32\\' . $name;

        return \is_file($path) ? $path : null;
    }

    /**
     * Resolve, confine and check one candidate.
     *
     * @param list<string> $allowedRoots
     */
    private function usableBinary(string $candidate, string $projectRoot, array $allowedRoots): ?string
    {
        try {
            // The single implementation of "inside somewhere we may read",
            // including the symlink walk. `Config` confined the configured path
            // already; doing it again here is the control at the point of use.
            $absolute = Path::resolve($projectRoot, $candidate, $allowedRoots, true);
        } catch (PathException $e) {
            return null;
        }

        if (!\is_file($absolute)) {
            return null;
        }

        // Windows has no executable bit — `is_executable()` answers by
        // extension there and would reject a perfectly good `.cmd` shim, so the
        // check is skipped and the extension is what carries the meaning
        // (decision CCP-A23).
        if (\DIRECTORY_SEPARATOR !== '\\' && !\is_executable($absolute)) {
            return null;
        }

        return $absolute;
    }

    /**
     * Is $function listed in `disable_functions`?
     *
     * The directive is a comma-separated list that may carry spaces, so it is
     * split and trimmed rather than searched for a substring — `proc_open` is
     * a substring of nothing else here, but `exec` is a substring of
     * `proc_open`'s neighbours and the same helper serves both.
     */
    private function isDisabled(string $function): bool
    {
        $directive = \ini_get('disable_functions');

        if (!\is_string($directive) || $directive === '') {
            return false;
        }

        foreach (\explode(',', $directive) as $entry) {
            if (\strtolower(\trim($entry)) === $function) {
                return true;
            }
        }

        return false;
    }

    /**
     * Suhosin keeps its own blacklist, and a function on it fails at call time
     * however healthy `function_exists()` looks.
     */
    private function isSuhosinBlacklisted(string $function): bool
    {
        if (!\extension_loaded('suhosin')) {
            return false;
        }

        $blacklist = \ini_get('suhosin.executor.func.blacklist');

        if (!\is_string($blacklist) || $blacklist === '') {
            return false;
        }

        foreach (\explode(',', $blacklist) as $entry) {
            if (\strtolower(\trim($entry)) === $function) {
                return true;
            }
        }

        return false;
    }

    private function detectPlatform(): string
    {
        if (\DIRECTORY_SEPARATOR === '\\') {
            return self::PLATFORM_WINDOWS_X64;
        }

        $system = \strtolower(\php_uname('s'));
        $machine = \strtolower(\php_uname('m'));
        $arm = $machine === 'arm64' || $machine === 'aarch64';

        if (\strpos($system, 'darwin') !== false) {
            return $arm ? self::PLATFORM_MACOS_ARM64 : self::PLATFORM_MACOS_X64;
        }

        if ($arm) {
            return self::PLATFORM_LINUX_ARM64;
        }

        // musl is not distinguishable from PHP with any reliability, so the
        // glibc build is the answer and a musl host simply reports an unknown
        // checksum — a fallback, not a failure.
        return self::PLATFORM_LINUX_X64;
    }
}
