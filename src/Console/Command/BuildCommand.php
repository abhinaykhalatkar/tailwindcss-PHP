<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Cache\Manifest;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Pipeline\BuildResult;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tailwind;

/**
 * Build a bundle from the command line (design doc 21).
 *
 * Without `--force` this is **exactly a production request**: the same
 * freshness decision, the same cache, so a deploy step that runs it twice does
 * the work once and the second run says "up to date". `debug` is forced off
 * regardless of what the project configured, because a deploy must not ship the
 * overlay-producing, never-cached variant just because someone left
 * `debug: true` in `tailwind.php`.
 *
 * **`--force` moves the manifest aside** (decision HBC-A23). The manifest *is*
 * the freshness record, so taking it away makes the next resolve a cold one
 * (NO_CACHE → COMPILE) without touching a single artefact. It is renamed rather
 * than deleted because a forced build that **fails** must leave the project no
 * worse off than before: with the record gone, live requests fall from "serve
 * the previous stylesheet" to "serve the constant 500 body", which made a
 * failed forced build strictly worse than a failed unforced one.
 *
 * The alternatives were worse still — `debug: true` changes what gets built,
 * and reaching into the pipeline to skip a check would put a second freshness
 * path next to the one doc 04 defines.
 */
final class BuildCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'build';
    }

    public function description(): string
    {
        return 'Compile a bundle (no-op when it is already fresh)';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return [
            'driver' => true,
            'minify' => false,
            'no-minify' => false,
            'force' => false,
        ];
    }

    public function run(Input $input, Output $output): int
    {
        [$tailwind] = $this->facade($input, $this->overrides($input));
        $bundle = $this->bundleName($input, $tailwind);
        $output = $this->scopedOutput($output, $tailwind);

        $forced = $input->flag('force');
        $aside = $forced ? $this->setAsideManifest($tailwind->config()->cacheDir(), $bundle) : null;
        $started = \microtime(true);

        try {
            $result = $tailwind->resolve($bundle);
        } catch (\Throwable $e) {
            $this->restoreManifest($aside);

            // `consoleText` is the same renderer the overlay uses, so the
            // wording a developer sees here matches what the browser showed.
            $output->error(ErrorRenderer::consoleText($e, false));

            return 1;
        }

        $elapsed = (\microtime(true) - $started) * 1000.0;

        // Only these three mean the CLI produced what it was asked for.
        // `stale-fallback` and `error` are the pipeline keeping a *site*
        // working, which is the right thing for a request and the wrong thing
        // for a deploy step: a build that reports success while serving
        // yesterday's stylesheet is how a broken release ships.
        if (!\in_array($result->source(), ['compiled', 'cached', 'touched'], true)) {
            $this->restoreManifest($aside);
            $output->error($this->failureReason($tailwind, $bundle, $result));

            return 1;
        }

        $this->discardAside($aside);

        $summary = $this->summarise($tailwind, $bundle, $result, $elapsed, $output);

        if ($output->isJson()) {
            $output->json($summary);

            return 0;
        }

        foreach ($summary as $key => $value) {
            $output->keyValue($key, \is_array($value) ? \count($value) : $value);
        }

        foreach ($result->diagnostics()->all() as $diagnostic) {
            $output->warn($diagnostic->code() . ': ' . $diagnostic->message());
        }

        return 0;
    }

    /**
     * The configuration a CLI build runs under.
     *
     * @return array<string, mixed>
     */
    private function overrides(Input $input): array
    {
        $overrides = ['debug' => false];

        $driver = $input->value('driver');

        if ($driver !== null) {
            $overrides['driver'] = $driver;
        }

        if ($input->flag('minify')) {
            $overrides['minify'] = true;
        }

        if ($input->flag('no-minify')) {
            $overrides['minify'] = false;
        }

        if ($input->flag('force')) {
            // Belt and braces alongside moving the manifest aside: with the
            // stamp gone and the interval at zero there is no path on which a
            // forced build silently serves a cached answer.
            $overrides['freeze'] = false;
            $overrides['check_interval'] = 0;
        }

        return $overrides;
    }

    /**
     * Move the freshness record aside so the next resolve is a cold one
     * (decision HBC-A23).
     *
     * Renamed rather than deleted, and that distinction is the whole point:
     * deleting it meant a **failed** forced build left the project with no
     * manifest at all, so live requests fell from "serve the previous
     * stylesheet" to "serve the constant 500 body" — a forced rebuild that
     * failed was strictly worse for the site than an unforced one. The aside
     * stays inside the cache directory, and is restored if anything goes wrong.
     *
     * A concurrent request during the window compiles under the lock (CCP-A19),
     * which is the ordinary cold-cache behaviour.
     *
     * @return array{0: string, 1: string}|null The pair of paths, or null when
     *                                          there was nothing to move.
     *
     * @throws ConsoleException When the manifest exists and cannot be moved —
     *                          continuing would produce a "forced" build that
     *                          quietly served the cache.
     */
    private function setAsideManifest(string $cacheDir, string $bundle): ?array
    {
        $manifest = \rtrim(\str_replace('\\', '/', $cacheDir), '/') . '/' . $bundle . '.meta.json';

        if (!\is_file($manifest)) {
            return null;
        }

        $aside = $manifest . '.force-' . \getmypid();

        // Documented `@` case (doc 18): a concurrent request may have replaced
        // the manifest between the check and here.
        if (!@\rename($manifest, $aside)) {
            throw new ConsoleException('Could not set the manifest aside to force a rebuild.');
        }

        return [$manifest, $aside];
    }

    /**
     * Put the previous manifest back after a failed forced build.
     *
     * @param array{0: string, 1: string}|null $aside
     */
    private function restoreManifest(?array $aside): void
    {
        if ($aside === null || !\is_file($aside[1])) {
            return;
        }

        // If the failed build wrote a manifest of its own, the previous one is
        // still the better answer for live requests: it names an artefact that
        // exists.
        @\unlink($aside[0]);
        @\rename($aside[1], $aside[0]);
    }

    /**
     * @param array{0: string, 1: string}|null $aside
     */
    private function discardAside(?array $aside): void
    {
        if ($aside !== null && \is_file($aside[1])) {
            @\unlink($aside[1]);
        }
    }

    /**
     * Why the build did not produce a usable stylesheet.
     *
     * Prefers the manifest's `last_error`, which is what the pipeline recorded
     * at the point of failure, over anything this command could infer.
     */
    private function failureReason(Tailwind $tailwind, string $bundle, BuildResult $result): string
    {
        $cause = $result->cause();

        if ($cause !== null) {
            return ErrorRenderer::consoleText($cause, false);
        }

        $manifest = Manifest::load(
            \rtrim(\str_replace('\\', '/', $tailwind->config()->cacheDir()), '/') . '/' . $bundle . '.meta.json',
            new Filesystem()
        );

        $lastError = $manifest === null ? null : $manifest->lastError();

        if ($lastError !== null) {
            return \sprintf(
                'The build failed (%s): %s',
                (string) ($lastError['code'] ?? 'unknown'),
                (string) ($lastError['message_short'] ?? '')
            );
        }

        return \sprintf('The build did not produce a stylesheet (source: %s).', $result->source());
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(
        Tailwind $tailwind,
        string $bundle,
        BuildResult $result,
        float $elapsed,
        Output $output
    ): array {
        $path = (string) $result->path();
        $gzPath = $result->gzPath();
        $size = \is_file($path) ? (int) \filesize($path) : 0;

        $manifest = Manifest::load(
            \rtrim(\str_replace('\\', '/', $tailwind->config()->cacheDir()), '/') . '/' . $bundle . '.meta.json',
            new Filesystem()
        );

        return [
            'bundle' => $bundle,
            'source' => $result->source(),
            // The driver that **ran**, not the one configured: with
            // `driver: auto` the configured value is "auto", which tells the
            // reader nothing about what actually compiled their stylesheet.
            'driver' => $manifest === null ? $tailwind->config()->driver() : $manifest->driver(),
            'fingerprint' => $result->fingerprint(),
            'output' => $output->path($path),
            // Raw byte counts rather than a humanised string: this line is read
            // by `--json` consumers as often as by people, and "12.3 KB" is not
            // something a script can compare against the last deploy.
            'size' => $size,
            'gzip' => $gzPath !== null && \is_file($gzPath) ? (int) \filesize($gzPath) : 0,
            'duration' => \sprintf('%.1f ms', $elapsed),
            'warnings' => \count($result->diagnostics()->all()),
        ];
    }
}
