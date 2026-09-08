<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;

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

        $plain = $tailwind->config()->bundle($bundle)->publicRoute();

        if (!$build && \substr($url, -\strlen($plain)) === $plain) {
            // The helper fell back to the plain route, which means there is no
            // manifest to take a fingerprint from.
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
}
