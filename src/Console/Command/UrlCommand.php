<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Cache\Manifest;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Support\Filesystem;

/**
 * Print the URL a page should link to (design doc 21; FR-HTTP-8).
 *
 * Useful in a deploy step that renders templates before the first request has
 * arrived, and in a shell one-liner that wants the current fingerprint.
 *
 * Without a manifest and without `--build` this exits **1** rather than
 * printing the plain route: a script asking for the URL wants the
 * fingerprinted one, and a silent fall back to `assets/styles.css` would put an
 * unfingerprinted link into a template that a CDN then caches forever.
 */
final class UrlCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'url';
    }

    public function description(): string
    {
        return 'Print the fingerprinted stylesheet URL';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return ['prefix' => true, 'build' => false];
    }

    public function run(Input $input, Output $output): int
    {
        [$tailwind] = $this->facade($input);
        $bundle = $this->bundleName($input, $tailwind);
        $output = $this->scopedOutput($output, $tailwind);

        $build = $input->flag('build');
        $url = $tailwind->url((string) $input->value('prefix', ''), [
            'bundle' => $bundle,
            'build' => $build,
        ]);

        // Decided from the manifest, not from the URL's shape. Under
        // `fingerprint: false` the plain route **is** the answer (HBC-A15), and
        // comparing the string made the two cases indistinguishable — a
        // correctly-configured project got exit 1 for a correct URL.
        if (!$build && !$this->hasManifest($tailwind, $bundle)) {
            $output->error(
                'No manifest for bundle "' . $bundle . '". Run "tailwind-php build" first, or pass --build.'
            );

            return 1;
        }

        if ($output->isJson()) {
            $output->json(['bundle' => $bundle, 'url' => $url]);

            return 0;
        }

        $output->line($url);

        return 0;
    }

    private function hasManifest(\TailwindPhp\Tailwind $tailwind, string $bundle): bool
    {
        $path = \rtrim(\str_replace('\\', '/', $tailwind->config()->cacheDir()), '/')
            . '/' . $bundle . '.meta.json';

        return Manifest::load($path, new Filesystem()) !== null;
    }
}
