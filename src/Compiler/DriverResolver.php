<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler;

use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\EnvironmentInterface;
use TailwindPhp\Exception\DriverUnavailableException;

/**
 * Decides which compiler this request will use (design doc 06, ADR-002).
 *
 * Called on **every** request, so the warm path has to be nearly free. With a
 * cached probe it costs `function_exists()`, one `ini_get()` and a single
 * `stat()` — no read, no hash, no process. That budget is the reason
 * `BinaryVerifier` takes the previous probe: re-hashing a hundred-megabyte
 * binary per request would be the most expensive thing the library does.
 *
 * The three modes differ in what a problem *means*:
 *
 * - **`php`** — never looks at the CLI at all.
 * - **`cli`** — the operator asked for it explicitly, so anything missing is an
 *   error they need to see: {@see DriverUnavailableException} with the reason.
 * - **`auto`** — a fallback, not a failure. It reports *why* it fell back
 *   through {@see DriverResolution::reason()} and lets the pipeline log that
 *   once per process; logging here would repeat it on every request.
 *
 * `$cliConfig` is a plain array rather than a `BundleConfig` because `Config`
 * and this class are built in parallel and the layering forbids `Compiler`
 * depending on `Config` objects. Keys read:
 *
 * | `driver`        | `auto` \| `php` \| `cli`                      |
 * | `binary`        | configured `cli.binary`, or null              |
 * | `verify`        | bool; false means the operator opted out      |
 * | `projectRoot`   | absolute                                      |
 * | `allowedRoots`  | list of absolute confinement roots            |
 */
final class DriverResolver implements DriverResolverInterface
{
    /**
     * The three legal `cli.driver` values.
     *
     * Local constants rather than `Config\Driver`: this class is handed a
     * plain array and must not depend on the Config layer (the layering rule
     * that keeps the resolver usable from a test with no configuration at all).
     * They are the same three strings, and `DriverTest` is what pins that they
     * stay the same.
     */
    private const PHP = 'php';
    private const CLI = 'cli';
    private const AUTO = 'auto';

    /**
     * @var EnvironmentInterface
     */
    private $environment;

    /**
     * @var BinaryVerifier
     */
    private $verifier;

    /**
     * @var CompilerInterface
     */
    private $phpCompiler;

    /**
     * @var callable
     */
    private $cliCompilerFactory;

    /**
     * @param callable $cliCompilerFactory `fn(string $binaryPath, string $version): CompilerInterface`.
     *                                     Injected rather than constructed here so resolving a driver
     *                                     never costs the machinery of one that will not be used.
     */
    public function __construct(
        EnvironmentInterface $environment,
        BinaryVerifier $verifier,
        CompilerInterface $phpCompiler,
        callable $cliCompilerFactory
    ) {
        $this->environment = $environment;
        $this->verifier = $verifier;
        $this->phpCompiler = $phpCompiler;
        $this->cliCompilerFactory = $cliCompilerFactory;
    }

    /**
     * @param array<string, mixed> $cliConfig
     * @param array<string, mixed>|null $cachedProbe
     *
     * @throws DriverUnavailableException When `driver: cli` cannot be honoured.
     */
    public function resolve(array $cliConfig, ?array $cachedProbe): DriverResolution
    {
        // `array_key_exists`, not `isset`: a `driver` explicitly set to null is a
        // mistake worth reporting, and `isset()` cannot tell it from an absent
        // key. Absent is the only case that legitimately means "use the default".
        if (!\array_key_exists('driver', $cliConfig)) {
            $driver = self::AUTO;
        } elseif (!\is_string($cliConfig['driver'])) {
            // A non-string used to fall through to `auto` exactly as an unknown
            // string did, with the same consequence: a config that meant
            // something else could end up executing the binary. The type is as
            // much a contract as the value.
            throw new \InvalidArgumentException('cli.driver must be a string: one of "php", "cli" or "auto".');
        } else {
            $driver = $cliConfig['driver'];
        }

        // Anything not exactly one of the three is a mistake, and the dangerous
        // reading is the permissive one: `'PHP'`, `'cli '` and `'nonsense'` used
        // to fall through to `auto`, so a config meaning "never spawn anything"
        // could quietly end up executing the binary. A typo must fail loudly.
        //
        // The offending value appears in neither the message nor a log: it comes
        // from configuration, and echoing it back is how a config value reaches
        // an error page (T-06).
        if ($driver !== self::PHP && $driver !== self::CLI && $driver !== self::AUTO) {
            throw new \InvalidArgumentException('cli.driver must be one of "php", "cli" or "auto".');
        }

        if ($driver === self::PHP) {
            return new DriverResolution($this->phpCompiler, null, null);
        }

        $explicit = $driver === self::CLI;

        // Checked cheapest first, and each result is used where it is computed
        // so nothing is looked up twice. `function_exists` and `ini_get` cost
        // nothing; locating the binary touches the filesystem, so on the common
        // shared-hosting case — `proc_open` disabled — this returns before any
        // I/O at all.
        if (!$this->environment->processFunctionAvailable()) {
            return $this->fallBackToPhp($explicit, 'proc_open is unavailable on this host');
        }

        $binary = $this->binaryPath($cliConfig);

        if ($binary === null) {
            return $this->fallBackToPhp($explicit, 'no Tailwind binary was found');
        }

        $probe = $this->verifier->verify($binary, $cachedProbe, $this->verifyEnabled($cliConfig));

        if (!$probe->verified()) {
            // Worth naming separately from "no binary": the pipeline turns this
            // into W_CHECKSUM_UNKNOWN so `doctor` can tell "nothing installed"
            // apart from "something installed that I will not run" (T-04).
            return $this->fallBackToPhp($explicit, 'the binary does not match a known release checksum');
        }

        $factory = $this->cliCompilerFactory;

        return new DriverResolution(
            $factory($binary, $probe->version()),
            $probe->toArray(),
            null
        );
    }

    /**
     * The CLI is unusable: either say so loudly or fall back quietly.
     *
     * `driver: cli` was asked for by name, so the operator needs to know why
     * they did not get it. `auto` promised a working stylesheet, not a
     * particular driver, so it reports the reason and carries on — the pipeline
     * logs it once per process rather than once per request.
     *
     * @throws DriverUnavailableException When the CLI was requested explicitly.
     */
    private function fallBackToPhp(bool $explicit, string $reason): DriverResolution
    {
        if ($explicit) {
            throw new DriverUnavailableException('cli', $reason);
        }

        return new DriverResolution(
            $this->phpCompiler,
            null,
            'auto: using PHP engine because ' . $reason
        );
    }

    /**
     * @param array<string, mixed> $cliConfig
     */
    private function binaryPath(array $cliConfig): ?string
    {
        $configured = isset($cliConfig['binary']) && \is_string($cliConfig['binary'])
            ? $cliConfig['binary']
            : null;

        $projectRoot = isset($cliConfig['projectRoot']) && \is_string($cliConfig['projectRoot'])
            ? $cliConfig['projectRoot']
            : '';

        /** @var list<string> $allowedRoots */
        $allowedRoots = isset($cliConfig['allowedRoots']) && \is_array($cliConfig['allowedRoots'])
            ? \array_values($cliConfig['allowedRoots'])
            : [$projectRoot];

        return $this->environment->binaryPath($configured, $projectRoot, $allowedRoots);
    }

    /**
     * @param array<string, mixed> $cliConfig
     */
    private function verifyEnabled(array $cliConfig): bool
    {
        // Defaults to **on**. An absent or malformed key must not be a way to
        // end up running an unverified binary (T-04).
        return !isset($cliConfig['verify']) || $cliConfig['verify'] !== false;
    }
}
