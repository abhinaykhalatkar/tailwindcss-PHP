<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Pipeline\ErrorRenderer;

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
 * **`--force` deletes the manifest.** That is the mechanism, and it is worth
 * stating plainly: the manifest *is* the freshness record, so removing it makes
 * the next resolve a cold one (NO_CACHE → COMPILE) without touching a single
 * artefact. The alternatives were worse — `debug: true` would change what gets
 * built, and reaching into the pipeline to skip a check would put a second
 * freshness path next to the one doc 04 defines.
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

        if ($input->flag('force')) {
            $this->forget($tailwind->config()->cacheDir(), $bundle);
        }

        $started = \microtime(true);

        try {
            $result = $tailwind->resolve($bundle);
        } catch (\Throwable $e) {
            // `consoleText` is the same renderer the overlay uses, so the
            // wording a developer sees here matches what the browser showed.
            $output->error(ErrorRenderer::consoleText($e, false));

            return 1;
        }

        $elapsed = (\microtime(true) - $started) * 1000.0;

        if ($result->isInline()) {
            // The pipeline failed soft; for a CLI build that is still a
            // failure — nothing usable was written.
            $output->error('The build failed. Run with --verbose, or check the manifest\'s last_error.');

            return 1;
        }

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
            // Belt and braces alongside deleting the manifest: with the stamp
            // gone and the interval at zero there is no path on which a
            // forced build silently serves a cached answer.
            $overrides['freeze'] = false;
            $overrides['check_interval'] = 0;
        }

        return $overrides;
    }

    /**
     * Make the next resolve a cold one by removing the freshness record.
     *
     * @throws ConsoleException When the manifest exists and cannot be removed —
     *                          silently continuing would produce a "build"
     *                          that did nothing.
     */
    private function forget(string $cacheDir, string $bundle): void
    {
        $manifest = \rtrim(\str_replace('\\', '/', $cacheDir), '/') . '/' . $bundle . '.meta.json';

        if (!\is_file($manifest)) {
            return;
        }

        // Documented `@` case (doc 18): a concurrent request may have replaced
        // the manifest between the check and here, and that is not an error.
        if (!@\unlink($manifest) && \is_file($manifest)) {
            throw new ConsoleException('Could not remove the manifest to force a rebuild.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(
        \TailwindPhp\Tailwind $tailwind,
        string $bundle,
        \TailwindPhp\Pipeline\BuildResult $result,
        float $elapsed,
        Output $output
    ): array {
        $path = (string) $result->path();
        $gzPath = $result->gzPath();
        $size = \is_file($path) ? (int) \filesize($path) : 0;

        return [
            'bundle' => $bundle,
            'source' => $result->source(),
            'driver' => $tailwind->config()->driver(),
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
