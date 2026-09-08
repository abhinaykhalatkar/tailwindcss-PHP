<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Cache\Manifest;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Scanner\CandidateExtractor;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Support\Path;

/**
 * Show what the scanner found (design doc 21).
 *
 * The question this answers is "why is my class not in the stylesheet?", and
 * the three modes are the three reasons: it was never extracted (default list),
 * this file does not contain what you think (`--file`), or it was extracted and
 * the engine did not recognise it (`--unknown`).
 *
 * `--file` re-extracts **live** rather than reading the manifest, because the
 * interesting case is a file the scanner has not looked at since you edited it.
 * The path is confined to the project root first (threat T-02): this is a CLI
 * argument, but a `tailwind-php candidates --file=../../etc/passwd` in a
 * Makefile is still a way to read a file through a tool that prints what it
 * finds.
 */
final class CandidatesCommand extends AbstractCommand
{
    public function name(): string
    {
        return 'candidates';
    }

    public function description(): string
    {
        return 'List the utility candidates found in a project';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return ['file' => true, 'unknown' => false];
    }

    public function run(Input $input, Output $output): int
    {
        [$tailwind] = $this->facade($input);
        $bundle = $this->bundleName($input, $tailwind);
        $output = $this->scopedOutput($output, $tailwind);
        $config = $tailwind->config();

        $file = $input->value('file');

        if ($file !== null) {
            return $this->fromFile($file, $config->projectRoot(), (int) $config->limits()['max_file_size'], $output);
        }

        $manifest = Manifest::load($this->manifestPath($config->cacheDir(), $bundle), new Filesystem());

        if ($manifest === null) {
            $output->error('No manifest for bundle "' . $bundle . '". Run "tailwind-php build" first.');

            return 1;
        }

        if ($input->flag('unknown')) {
            return $this->unknown($manifest, $output);
        }

        $candidates = $this->union($manifest, $config->bundle($bundle)->safelist());

        if ($output->isJson()) {
            $output->json(['bundle' => $bundle, 'count' => \count($candidates), 'candidates' => $candidates]);

            return 0;
        }

        foreach ($candidates as $candidate) {
            $output->line($candidate);
        }

        return 0;
    }

    /**
     * Re-extract one file, now.
     */
    private function fromFile(string $file, string $projectRoot, int $maxBytes, Output $output): int
    {
        // T-02, resolved rather than merely compared. `Path::confine()` works on
        // the string, so a junction *inside* the project pointing outside it
        // passes — the path is textually within the root and the bytes come
        // from elsewhere. `Path::resolve()` follows the filesystem first, which
        // is the check that actually holds.
        $confined = Path::resolve($projectRoot, $file, [$projectRoot], true);

        $fs = new Filesystem();

        if (!$fs->isFile($confined)) {
            throw new ConsoleException(\sprintf('No such file: %s', $output->path($confined)));
        }

        $stat = $fs->stat($confined);

        if ($stat !== null && $stat['size'] > $maxBytes) {
            // `Filesystem::read()` truncates silently at the cap, so without
            // this the answer would be "these are the candidates" when it is
            // really "these are the candidates in the first N bytes".
            $output->warn(\sprintf(
                'Only the first %d bytes of %s were scanned (limits.max_file_size).',
                $maxBytes,
                $output->path($confined)
            ));
        }

        $candidates = (new CandidateExtractor())->extract($fs->read($confined, $maxBytes));
        \sort($candidates);

        if ($output->isJson()) {
            $output->json([
                'file' => $output->path($confined),
                'count' => \count($candidates),
                'candidates' => $candidates,
            ]);

            return 0;
        }

        foreach ($candidates as $candidate) {
            $output->line($candidate);
        }

        return 0;
    }

    /**
     * The utilities the engine did not recognise, with the files they came
     * from — the list worth acting on.
     */
    private function unknown(Manifest $manifest, Output $output): int
    {
        $rows = [];

        foreach ($manifest->warnings() as $warning) {
            if (($warning['code'] ?? '') !== 'W_UNKNOWN_UTILITY') {
                continue;
            }

            $rows[] = [
                'message' => (string) ($warning['message'] ?? ''),
                'file' => (string) ($warning['file'] ?? ''),
            ];
        }

        if ($output->isJson()) {
            $output->json(['count' => \count($rows), 'unknown' => $rows]);

            return 0;
        }

        foreach ($rows as $row) {
            $output->line($row['message'] . ($row['file'] === '' ? '' : '  (' . $row['file'] . ')'));
        }

        if ($rows === []) {
            $output->line('No unknown utilities recorded.');
        }

        return 0;
    }

    /**
     * The sorted union of everything the manifest recorded plus the safelist.
     *
     * @param list<string> $safelist
     *
     * @return list<string>
     */
    private function union(Manifest $manifest, array $safelist): array
    {
        $candidates = $safelist;

        foreach ($manifest->files() as $record) {
            if (isset($record['c']) && \is_array($record['c'])) {
                foreach ($record['c'] as $candidate) {
                    $candidates[] = (string) $candidate;
                }
            }
        }

        $candidates = \array_values(\array_unique($candidates));
        \sort($candidates);

        return $candidates;
    }

    private function manifestPath(string $cacheDir, string $bundle): string
    {
        return \rtrim(\str_replace('\\', '/', $cacheDir), '/') . '/' . $bundle . '.meta.json';
    }
}
