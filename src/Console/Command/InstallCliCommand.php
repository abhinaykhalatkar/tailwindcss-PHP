<?php

declare(strict_types=1);

namespace TailwindPhp\Console\Command;

use TailwindPhp\Compiler\Cli\Checksums;
use TailwindPhp\Compiler\Cli\Environment;
use TailwindPhp\Console\Input;
use TailwindPhp\Console\Output;
use TailwindPhp\Exception\ConsoleException;
use TailwindPhp\Support\Hash;
use TailwindPhp\Support\Path;

/**
 * Download the Tailwind standalone binary (design doc 06 §install-cli, doc 21;
 * threats T-04 and T-18; decisions HBC-A12, HBC-A13).
 *
 * This is the only file in `src/` permitted to contain network code, and the
 * only one that writes an executable. Both facts are enforced by
 * `tools/StaticSecurityChecks.php` rather than by convention, because the
 * consequence of getting either wrong is that the library executes a file
 * somebody else chose.
 *
 * The controls, in the order they apply:
 *
 * 1. **CLI only** (HBC-A13). A web request must never be able to reach this;
 *    the SAPI is a constructor argument so the refusal is testable, and
 *    anything but `cli` exits 3 *before* a socket is opened.
 * 2. **The URL is constructed, never accepted.** One template, one host, HTTPS
 *    only. The base is injectable for the tests and there is deliberately no
 *    CLI option for it — an operator who could point this at another host would
 *    be the vulnerability.
 * 3. **The version must be pinned** unless `--allow-unverified`, which writes
 *    nothing and only prints a warning (HBC-A12).
 * 4. **The digest is computed while streaming** and compared with
 *    {@see Hash::equals()}; a mismatch deletes the temporary file and exits 1,
 *    so a tampered download never becomes an executable even briefly.
 * 5. **The destination is confined** to the project root, so `--dest` cannot
 *    drop an executable into someone's `~/.local/bin`.
 *
 * The file is written `0600` while it is unverified and only becomes `0755`
 * after the digest matches — the window in which an executable bit exists on
 * unverified bytes is zero.
 */
final class InstallCliCommand extends AbstractCommand
{
    /**
     * The only host this command will ever talk to (doc 06 step 4).
     */
    private const RELEASE_BASE = 'https://github.com/tailwindlabs/tailwindcss/releases/download';

    /**
     * Where the binary goes unless `--dest` says otherwise.
     */
    private const DEFAULT_DEST = 'vendor/bin/tailwindcss';

    private const CONNECT_TIMEOUT = 15;
    private const TRANSFER_TIMEOUT = 60;
    private const MAX_REDIRECTS = 5;

    /**
     * @var string
     */
    private $sapi;

    /**
     * @var string
     */
    private $base;

    /**
     * @var Checksums|null
     */
    private $checksums;

    /**
     * @param string|null $cwd Overridden in tests.
     * @param string|null $sapi The SAPI name; `PHP_SAPI` in production. A
     *                          constructor argument so HBC-A13's refusal can be
     *                          tested without a web server.
     * @param string|null $base The release base URL. **Tests only** — there is
     *                          no CLI option for it, by design. An operator who
     *                          could redirect this at another host would be the
     *                          vulnerability, so it is reachable only by
     *                          constructing the object, which means already
     *                          being inside the process.
     * @param Checksums|null $checksums The pinned table; the shipped one by
     *                                  default. Injected by the T-18 suite for
     *                                  the same reason `Checksums::withAdditional()`
     *                                  exists — there is no "trust everything"
     *                                  switch an attacker could find.
     */
    public function __construct(
        ?string $cwd = null,
        ?string $sapi = null,
        ?string $base = null,
        ?Checksums $checksums = null
    ) {
        parent::__construct($cwd);

        $this->sapi = $sapi ?? \PHP_SAPI;
        $this->base = $base ?? self::RELEASE_BASE;
        $this->checksums = $checksums;
    }

    public function name(): string
    {
        return 'install-cli';
    }

    public function description(): string
    {
        return 'Download the verified Tailwind standalone binary';
    }

    /**
     * @return array<string, bool>
     */
    public function options(): array
    {
        return [
            'version' => true,
            'dest' => true,
            'platform' => true,
            'allow-unverified' => false,
        ];
    }

    public function run(Input $input, Output $output): int
    {
        // Step 1. Before anything else, and before any socket.
        if ($this->sapi !== 'cli') {
            $output->error('install-cli can only be run from the command line.');

            return 3;
        }

        $checksums = $this->checksums ?? Checksums::load();
        $platform = $this->platform($input);
        $version = $this->version($input, $checksums);
        $expected = $this->expectedDigest($checksums, $version, $platform, $input, $output);

        if ($expected === null) {
            return 2;
        }

        $root = $this->workingDirectory();
        $dest = $this->destination($input, $root);
        $output = $output->withProjectRoot($root);

        $url = $this->base . '/v' . $version . '/tailwindcss-' . $platform;
        $temporary = $dest . '.tmp';

        $this->ensureDirectory(\dirname($dest));

        $digest = $this->download($url, $temporary, $output);

        if ($digest === null) {
            return 3;
        }

        if (!Hash::equals($expected, $digest)) {
            // Deleted before anything reports success: an unverified binary
            // must not survive the process that fetched it.
            @\unlink($temporary);

            $output->error(\sprintf(
                'Checksum mismatch for tailwindcss-%s v%s. Expected %s, got %s. The download was deleted.',
                $platform,
                $version,
                $expected,
                $digest
            ));

            return 1;
        }

        if (!@\chmod($temporary, 0755) || !@\rename($temporary, $dest)) {
            @\unlink($temporary);
            $output->error('The verified binary could not be moved into place.');

            return 1;
        }

        $summary = [
            'version' => $version,
            'platform' => $platform,
            'dest' => $output->path($dest),
            'sha256' => $digest,
            'verified' => true,
        ];

        if ($output->isJson()) {
            $output->json($summary);

            return 0;
        }

        foreach ($summary as $key => $value) {
            $output->keyValue($key, $value);
        }

        return 0;
    }

    private function platform(Input $input): string
    {
        $requested = $input->value('platform', 'auto');

        if ($requested === null || $requested === '' || $requested === 'auto') {
            return (new Environment())->platform();
        }

        return $requested;
    }

    /**
     * The version to fetch: `--version`, or the newest the shipped table pins.
     *
     * @throws ConsoleException When nothing is pinned and none was named.
     */
    private function version(Input $input, Checksums $checksums): string
    {
        $requested = $input->value('version');

        if ($requested !== null && $requested !== '') {
            return \ltrim($requested, 'v');
        }

        $versions = $checksums->versions();

        if ($versions === []) {
            throw new ConsoleException('No versions are pinned in this release; pass --version=X.Y.Z.');
        }

        // The table is ordered newest-first by the generator, so the first entry
        // is the default rather than the result of a version sort this command
        // would have to keep in step with upstream's numbering.
        return $versions[0];
    }

    /**
     * Step 3: the digest this release pins for that version and platform.
     *
     * @return string|null Null when the caller should stop with exit 2.
     */
    private function expectedDigest(
        Checksums $checksums,
        string $version,
        string $platform,
        Input $input,
        Output $output
    ): ?string {
        $entry = $checksums->forVersion($version);

        if ($entry !== null && isset($entry[$platform])) {
            return $entry[$platform];
        }

        $what = $entry === null
            ? \sprintf('Version %s is not pinned in this release of tailwindcss-php.', $version)
            : \sprintf('Version %s does not pin a binary for platform "%s".', $version, $platform);

        if (!$input->flag('allow-unverified')) {
            $output->error(
                $what . ' Refusing to download an unverified binary. '
                . 'Upgrade tailwindcss-php, choose a pinned version, or pass --allow-unverified.'
            );

            return null;
        }

        // HBC-A12: the flag does not enable an unchecked download. It exists so
        // the refusal is explainable, and the red warning is the record —
        // `doctor` has no artefact to find because none is written.
        $output->error(
            $what . ' --allow-unverified does not download an unpinned binary: '
            . 'there would be nothing to compare it against. Install it by hand if you accept the risk.'
        );

        return null;
    }

    /**
     * Step 5: `--dest`, confined to the project (threat T-02).
     *
     * @throws ConsoleException When it would land outside the project.
     */
    private function destination(Input $input, string $root): string
    {
        $dest = $input->value('dest', self::DEFAULT_DEST);
        $dest = $dest === null || $dest === '' ? self::DEFAULT_DEST : $dest;
        $normalized = \str_replace('\\', '/', $dest);

        if ($normalized[0] !== '/' && !(isset($normalized[1]) && $normalized[1] === ':')) {
            $normalized = \rtrim($root, '/') . '/' . $normalized;
        }

        // The parent must exist for a real resolution, so the check is against
        // the directory; the filename itself is ours.
        $parent = Path::confine(\dirname($normalized), $root);

        return $parent . '/' . \basename($normalized);
    }

    private function ensureDirectory(string $dir): void
    {
        if (\is_dir($dir)) {
            return;
        }

        // Documented `@` case (doc 18): a concurrent run may have created it.
        if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new ConsoleException('Could not create the destination directory.');
        }
    }

    /**
     * Steps 5 and 6: fetch to a private temporary file, hashing as it streams.
     *
     * @return string|null The digest, or null when the environment prevented
     *                     the download (exit 3).
     */
    private function download(string $url, string $temporary, Output $output): ?string
    {
        $handle = @\fopen($temporary, 'wb');

        if ($handle === false) {
            throw new ConsoleException('Could not open the temporary file for writing.');
        }

        // 0600 while the contents are unverified: no other user can read it,
        // and nobody at all can execute it.
        @\chmod($temporary, 0600);

        $ok = \function_exists('curl_init')
            ? $this->downloadWithCurl($url, $handle, $output)
            : $this->downloadWithStream($url, $handle, $output);

        \fclose($handle);

        if (!$ok) {
            @\unlink($temporary);

            return null;
        }

        return Hash::sha256File($temporary);
    }

    /**
     * @param resource $handle
     */
    private function downloadWithCurl(string $url, $handle, Output $output): bool
    {
        $curl = \curl_init($url);

        if ($curl === false) {
            $output->error('cURL could not be initialised.');

            return false;
        }

        \curl_setopt($curl, \CURLOPT_FILE, $handle);
        \curl_setopt($curl, \CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($curl, \CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        \curl_setopt($curl, \CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        \curl_setopt($curl, \CURLOPT_TIMEOUT, self::TRANSFER_TIMEOUT);
        \curl_setopt($curl, \CURLOPT_FAILONERROR, true);
        // Never negotiable. A download whose certificate is not checked is a
        // download from whoever is on the path (T-18).
        \curl_setopt($curl, \CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($curl, \CURLOPT_SSL_VERIFYHOST, 2);

        $ok = \curl_exec($curl) !== false;

        if (!$ok) {
            $output->error('Download failed: ' . \curl_error($curl));
        }

        \curl_close($curl);

        return $ok;
    }

    /**
     * The fallback for hosts built without cURL.
     *
     * @param resource $handle
     */
    private function downloadWithStream(string $url, $handle, Output $output): bool
    {
        if (!\ini_get('allow_url_fopen')) {
            $output->error(
                'Neither cURL nor allow_url_fopen is available. Download the binary manually from '
                . self::RELEASE_BASE . ' and point cli.binary at it.'
            );

            return false;
        }

        $context = \stream_context_create([
            'http' => [
                'timeout' => self::TRANSFER_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => self::MAX_REDIRECTS,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $source = @\fopen($url, 'rb', false, $context);

        if ($source === false) {
            $output->error('Download failed: the release could not be opened.');

            return false;
        }

        \stream_copy_to_stream($source, $handle);
        \fclose($source);

        return true;
    }
}
