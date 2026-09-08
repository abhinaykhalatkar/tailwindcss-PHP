<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Cache\Manifest;
use TailwindPhp\Compiler\Cli\BinaryVerifier;
use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Console\ConfigLocator;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Scanner\FileFinder;
use TailwindPhp\Scanner\Root;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tailwind;

/**
 * Explain why this installation will or will not work (design doc 21 §doctor).
 *
 * Every row is a *check plus a remedy*. A diagnostic that only reports a state
 * leaves the reader to work out what to do about it, which for most of these
 * means searching the documentation for a setting they have never heard of —
 * so `[warn] cache_dir is inside the document root` is always followed by the
 * line that fixes it.
 *
 * **It performs no network request.** Doc 21 (Q17) settles this: a loopback
 * self-check tells you about the machine `doctor` runs on, which on a
 * containerised host is not the machine serving the site. The `curl` command is
 * printed instead, so the operator runs it from where it means something.
 *
 * Exit is **0** even when rows fail — this is a report, and a report that
 * breaks a deploy script by existing is one people stop running. `--strict`
 * turns any warning or failure into exit 1, for the pipelines that want it.
 */
final class DoctorCommand extends AbstractCommand
{
    /**
     * The PHP this library supports (ADR-007).
     */
    private const MINIMUM_PHP = '7.3.0';

    /**
     * Extensions worth reporting on, and what each one buys.
     *
     * @var array<string, array{0: bool, 1: string}> name => [required, why]
     */
    private const EXTENSIONS = [
        'json' => [true, 'manifests are JSON; the library cannot run without it'],
        'zlib' => [false, 'pre-compressed .css.gz artefacts (FR-CACHE-6)'],
        'apcu' => [false, 'in-memory check throttle (check_store: apcu)'],
        'mbstring' => [false, 'not used by this library; listed because hosts often assume it'],
    ];

    /**
     * @var int Rows that came back other than ok.
     */
    private $problems = 0;

    /**
     * @var list<array<string, mixed>> Collected for `--json`.
     */
    private $rows = [];

    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Check the environment, the configuration and the cache';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return ['docroot' => true, 'strict' => false];
    }

    public function run(Input $input, Output $output): int
    {
        $this->problems = 0;
        $this->rows = [];

        $output->section('PHP');
        $this->checkPhp($output);
        $this->checkExtensions($output);
        $this->checkFunctions($output);

        // The configuration is checked *after* the environment on purpose: when
        // `tailwind.php` is missing, the PHP rows above are still the useful
        // half of the answer, and reporting them beats bailing out.
        $tailwind = null;
        $configPath = null;

        $output->section('Configuration');

        try {
            [$tailwind, $configPath] = ConfigLocator::load($input->value('config'), $this->workingDirectory());
            $output = $this->scopedOutput($output, $tailwind);
            $this->ok($output, 'configuration', $output->path($configPath));
        } catch (\Throwable $e) {
            $this->fail($output, 'configuration', $e->getMessage(), 'Create a tailwind.php, or pass --config=PATH.');
        }

        if ($tailwind !== null && $configPath !== null) {
            $this->checkConfigLocation($output, $configPath);
            $this->checkCacheDir($output, $tailwind, $input->value('docroot'));
            $this->checkDriver($output, $tailwind);
            $this->checkCacheState($output, $tailwind, $input);
            $this->checkScanCost($output, $tailwind, $input);
        }

        $this->printHints($output, $tailwind);

        if ($output->isJson()) {
            $output->json(['problems' => $this->problems, 'checks' => $this->rows]);
        }

        return $input->flag('strict') && $this->problems > 0 ? 1 : 0;
    }

    private function checkPhp(Output $output): void
    {
        if (\version_compare(\PHP_VERSION, self::MINIMUM_PHP, '>=')) {
            $this->ok($output, 'php', \PHP_VERSION);

            return;
        }

        $this->fail(
            $output,
            'php',
            \PHP_VERSION,
            'This library needs PHP ' . self::MINIMUM_PHP . ' or newer (ADR-007).'
        );
    }

    private function checkExtensions(Output $output): void
    {
        foreach (self::EXTENSIONS as $name => $meta) {
            if (\extension_loaded($name)) {
                $this->ok($output, 'ext-' . $name, 'loaded');

                continue;
            }

            if ($meta[0]) {
                $this->fail($output, 'ext-' . $name, 'missing', 'Install it: ' . $meta[1] . '.');

                continue;
            }

            $this->warn($output, 'ext-' . $name, 'missing', 'Optional — ' . $meta[1] . '.');
        }
    }

    /**
     * The two host settings that silently disable a driver.
     */
    private function checkFunctions(Output $output): void
    {
        if ((new Environment())->processFunctionAvailable()) {
            $this->ok($output, 'proc_open', 'available');
        } else {
            $this->warn(
                $output,
                'proc_open',
                'disabled',
                'The CLI driver cannot run; the PHP engine still can. Check disable_functions in php.ini.'
            );
        }

        $openBasedir = (string) \ini_get('open_basedir');

        if ($openBasedir === '') {
            $this->ok($output, 'open_basedir', 'not set');

            return;
        }

        $this->warn(
            $output,
            'open_basedir',
            'set',
            'Every path the library touches — project root, cache dir, binary — must be inside it.'
        );
    }

    /**
     * Decision HBC-A24: the operator-side control for the T-08 residual.
     *
     * The loader refuses a config file inside the *configured* cache directory,
     * but it can only do that after executing it unless the directory is the
     * conventional one. A config file anywhere that looks like a cache is
     * therefore worth saying out loud, whether or not the loader would refuse
     * it.
     */
    private function checkConfigLocation(Output $output, string $configPath): void
    {
        $normalized = \strtolower(\str_replace('\\', '/', $configPath));

        foreach (['/cache/', '/var/cache/', '/tmp/', '/storage/framework/'] as $needle) {
            if (\strpos($normalized, $needle) === false) {
                continue;
            }

            $this->warn(
                $output,
                'config location',
                $output->path($configPath),
                'This file is `require`d. A configuration file under a writable cache directory turns '
                . 'a cache write into code execution (T-08). Move it to the project root or config/.'
            );

            return;
        }

        $this->ok($output, 'config location', 'outside any cache directory');
    }

    private function checkCacheDir(Output $output, Tailwind $tailwind, ?string $docroot): void
    {
        $cacheDir = $tailwind->config()->cacheDir();

        if (!\is_dir($cacheDir)) {
            $this->warn(
                $output,
                'cache_dir',
                $output->path($cacheDir) . ' (missing)',
                'It is created on the first build; check the parent directory is writable.'
            );
        } elseif (\is_writable($cacheDir)) {
            $this->ok($output, 'cache_dir', $output->path($cacheDir));
        } else {
            $this->fail(
                $output,
                'cache_dir',
                $output->path($cacheDir) . ' (not writable)',
                'The web server user must be able to write here, or nothing can be cached.'
            );
        }

        // Threat T-07: a cache directory the web server serves directly hands
        // out the manifest, which names every watched file.
        if ($docroot === null) {
            $this->warn(
                $output,
                'cache_dir exposure',
                'not checked',
                'Pass --docroot=PATH to check the cache directory is not web-served (T-07).'
            );

            return;
        }

        $realDoc = \realpath($docroot);
        $realCache = \realpath($cacheDir);

        if ($realDoc === false) {
            $this->warn($output, 'cache_dir exposure', 'docroot not found', 'Check the --docroot path.');

            return;
        }

        if ($realCache === false) {
            $this->ok($output, 'cache_dir exposure', 'cache directory does not exist yet');

            return;
        }

        $realDoc = \rtrim(\str_replace('\\', '/', $realDoc), '/');
        $realCache = \str_replace('\\', '/', $realCache);

        if (\strncmp($realCache, $realDoc . '/', \strlen($realDoc) + 1) === 0) {
            $this->warn(
                $output,
                'cache_dir exposure',
                'inside the document root',
                'Move it outside, or keep the .htaccess/index.html guards the library writes (T-07).'
            );

            return;
        }

        $this->ok($output, 'cache_dir exposure', 'outside the document root');
    }

    private function checkDriver(Output $output, Tailwind $tailwind): void
    {
        $output->section('Driver');

        $config = $tailwind->config();
        $cli = $config->cli();

        // T-04: an unverified binary is arbitrary code the library will execute.
        if (isset($cli['verify']) && $cli['verify'] === false) {
            $this->fail(
                $output,
                'cli.verify',
                'disabled',
                'The binary\'s checksum is not checked before it is executed. Re-enable it (T-04).'
            );
        } else {
            $this->ok($output, 'cli.verify', 'enabled');
        }

        $this->ok($output, 'driver (configured)', $config->driver());
        $this->ok($output, 'platform', (new Environment())->platform());

        $binary = (new Environment())->binaryPath(
            isset($cli['binary']) && \is_string($cli['binary']) ? $cli['binary'] : null,
            $config->projectRoot(),
            $config->allowedRoots()
        );

        if ($binary === null) {
            $remedy = 'Run "tailwind-php install-cli", or set driver: php to use the bundled engine.';

            // Only a hard failure when the project *asked* for the CLI driver;
            // on `auto` or `php` a missing binary is the ordinary case.
            if ($config->driver() === 'cli') {
                $this->fail($output, 'cli binary', 'not found', $remedy);
            } else {
                $this->warn($output, 'cli binary', 'not found', $remedy);
            }

            return;
        }

        $probe = (new BinaryVerifier(Checksums::load(), new Filesystem(), $config->projectRoot()))
            ->verify($binary, null, !(isset($cli['verify']) && $cli['verify'] === false));

        $this->ok($output, 'cli binary', $output->path($binary));
        $this->ok($output, 'cli version', $probe->version() === '' ? 'unknown' : $probe->version());

        if ($probe->verified()) {
            $this->ok($output, 'cli checksum', 'known');

            return;
        }

        $this->warn(
            $output,
            'cli checksum',
            'not in the shipped table',
            'The binary may be a version this release does not know. Re-run install-cli to get a verified one.'
        );
    }

    private function checkCacheState(Output $output, Tailwind $tailwind, Input $input): void
    {
        $output->section('Cache');

        $bundle = $this->bundleName($input, $tailwind);
        $path = \rtrim(\str_replace('\\', '/', $tailwind->config()->cacheDir()), '/')
            . '/' . $bundle . '.meta.json';

        $manifest = Manifest::load($path, new Filesystem());

        if ($manifest === null) {
            $this->warn(
                $output,
                'manifest',
                'none for "' . $bundle . '"',
                'Nothing has been built yet. Run "tailwind-php build".'
            );

            return;
        }

        $this->ok($output, 'manifest', $bundle);
        $this->ok($output, 'fingerprint', $manifest->fingerprint());
        $this->ok($output, 'built at', \gmdate('Y-m-d H:i:s', $manifest->builtAt()) . ' UTC');
        $this->ok($output, 'driver (used)', $manifest->driver());
        $this->ok($output, 'candidates', $manifest->candidatesCount());

        $warnings = $manifest->warnings();

        if ($warnings !== []) {
            $this->warn(
                $output,
                'build warnings',
                (string) \count($warnings),
                'Run "tailwind-php candidates --unknown" to see the unrecognised utilities.'
            );
        }

        $lastError = $manifest->lastError();

        if ($lastError !== null) {
            $this->fail(
                $output,
                'last error',
                (string) ($lastError['code'] ?? 'unknown') . ': ' . (string) ($lastError['message_short'] ?? ''),
                'The last build failed; the site is serving the previous stylesheet.'
            );
        }
    }

    /**
     * What a warm request will cost, in files.
     */
    private function checkScanCost(Output $output, Tailwind $tailwind, Input $input): void
    {
        $output->section('Freshness');

        $config = $tailwind->config();
        $bundle = $config->bundle($this->bundleName($input, $tailwind));
        $limits = $config->limits();

        // `BundleConfig` hands back plain arrays; `FileFinder` wants the `Root`
        // value object. The kinds matter to the scanner (`i` recompiles, `s`
        // only rescans), so they are preserved rather than flattened.
        $roots = [];

        foreach ($bundle->inputRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], 'i', $root['extensions']);
        }

        foreach ($bundle->sourceRoots() as $root) {
            $roots[] = new Root($root['abs'], $root['rel'], 's', $root['extensions']);
        }

        $finder = new FileFinder($config->cacheDir(), $bundle->ignore(), (int) $limits['max_files']);
        $snapshot = $finder->snapshot($roots);

        $this->ok($output, 'watched files', $snapshot->count());

        foreach ($snapshot->missingRoots() as $missing) {
            $this->warn(
                $output,
                'missing root',
                $missing,
                'Configured but not on disk; nothing will be scanned there.'
            );
        }

        if ($config->freeze()) {
            $this->ok($output, 'freeze', 'on — no freshness check is performed');

            return;
        }

        $interval = $config->checkInterval();

        if ($interval > 0) {
            $this->ok($output, 'check_interval', $interval . 's');

            return;
        }

        if ($snapshot->count() > 200) {
            $this->warn(
                $output,
                'check_interval',
                '0 (every request stats ' . $snapshot->count() . ' files)',
                'Set check_interval, or freeze: true in production, to skip the walk.'
            );

            return;
        }

        $this->ok($output, 'check_interval', '0 — every request checks');
    }

    /**
     * The two things doc 21 says to print rather than perform.
     */
    private function printHints(Output $output, ?Tailwind $tailwind): void
    {
        $output->section('Next steps');

        $route = 'assets/styles.css';

        if ($tailwind !== null) {
            foreach ($tailwind->config()->bundles() as $name => $ignored) {
                $route = $tailwind->config()->bundle((string) $name)->publicRoute();

                break;
            }
        }

        $output->line('  Serve check (run it from a machine that can reach the site):');
        $output->line('    curl -sSI https://example.test/' . $route);
        $output->line('  If your front controller does not already route it, add a rewrite so');
        $output->line('  /' . $route . ' reaches index.php.');
    }

    /**
     * @param string|int $detail
     */
    private function ok(Output $output, string $label, $detail): void
    {
        $output->status('ok', $label, (string) $detail);
        $this->rows[] = ['state' => 'ok', 'check' => $label, 'detail' => (string) $detail];
    }

    private function warn(Output $output, string $label, string $detail, string $remedy): void
    {
        $this->problems++;
        $output->status('warn', $label, $detail, $remedy);
        $this->rows[] = ['state' => 'warn', 'check' => $label, 'detail' => $detail, 'remedy' => $remedy];
    }

    private function fail(Output $output, string $label, string $detail, string $remedy): void
    {
        $this->problems++;
        $output->status('error', $label, $detail, $remedy);
        $this->rows[] = ['state' => 'error', 'check' => $label, 'detail' => $detail, 'remedy' => $remedy];
    }
}
