<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Cache\AtomicWriter;
use TailwindPhp\Cache\CacheStore;
use TailwindPhp\Config\Config;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Support\Filesystem;

/**
 * Delete a bundle's cached artefacts (design doc 21; FR-CACHE-7, HBC-A11).
 *
 * The removal itself is {@see CacheStore::clear()}, which already knows the
 * whole list — manifest, stamp, stable copies, every fingerprinted pair, and
 * the `.cli-*` temp directories a died-mid-compile leaves behind — and, more
 * importantly, already knows what **not** to touch: the directory itself, the
 * lock file, and the `.htaccess`/`index.html` guards that keep the cache from
 * being served directly (threat T-07). Re-implementing that list here would be
 * a second place for it to go wrong.
 */
final class ClearCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'clear';
    }

    public function description(): string
    {
        return 'Delete cached stylesheets and manifests';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return ['all' => false];
    }

    public function run(Input $input, Output $output): int
    {
        [$tailwind] = $this->facade($input);
        $config = $tailwind->config();
        $output = $this->scopedOutput($output, $tailwind);

        $bundles = $input->flag('all')
            ? \array_map('strval', \array_keys($config->bundles()))
            : [$this->bundleName($input, $tailwind)];

        $store = self::store($config);
        $removed = 0;
        $cleared = [];

        foreach ($bundles as $bundle) {
            $count = $store->clear($bundle);
            $removed += $count;
            $cleared[$bundle] = $count;
        }

        if ($output->isJson()) {
            $output->json(['removed' => $removed, 'bundles' => $cleared]);

            return 0;
        }

        foreach ($cleared as $bundle => $count) {
            $output->keyValue($bundle, $count . ' file' . ($count === 1 ? '' : 's'));
        }

        $output->line();
        $output->keyValue('removed', $removed);

        return 0;
    }

    /**
     * Build the store from configuration.
     *
     * The facade owns an identical one but does not expose it, and
     * `src/Tailwind.php` belongs to another task this round — see the report;
     * a `Tailwind::cache()` accessor would remove this duplication.
     */
    private static function store(Config $config): CacheStore
    {
        return new CacheStore(
            $config->cacheDir(),
            $config->allowedRoots(),
            $config->projectRoot(),
            $config->cacheDirMode(),
            $config->cacheFileMode(),
            $config->gzip(),
            new Filesystem(),
            new AtomicWriter($config->projectRoot())
        );
    }
}
