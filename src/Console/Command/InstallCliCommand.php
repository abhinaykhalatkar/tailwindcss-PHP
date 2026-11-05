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
     * The most this will ever write, in bytes.
     *
     * The real binary is tens of megabytes; 512 MiB is far above any plausible
     * release and far below "fill the disk". Without it a redirect to a
     * generator streams until the volume is full, and the checksum that would
     * have rejected the bytes is only computed at the end.
     */
    private const MAX_BYTES = 536870912;

    /**
     * How much is read per iteration by the stream fallback.
     */
    private const CHUNK_BYTES = 65536;

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

        // Created only if it is missing, and removed again when the download
        // fails: a failed install must not leave an empty `vendor/bin` behind
        // for the next tool to trip over.
        $created = $this->ensureDirectory(\dirname($dest));

        $digest = $this->download($url, $temporary, $output);

        if ($digest === null) {
            $this->removeIfCreated($created);

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

        // `chmod` without `@`: doc 18 allows suppression only around
        // rename/unlink/flock/mkdir. A false return on Windows is expected —
        // there is no POSIX mode there — so it is reported and not treated as
        // fatal; on a POSIX host it means the binary would not be executable,
        // which is worth stopping for.
        if (!\chmod($temporary, 0755) && \DIRECTORY_SEPARATOR !== '\\') {
            @\unlink($temporary);
            $output->error('The verified binary could not be made executable.');

            return 1;
        }

        if (!@\rename($temporary, $dest)) {
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

        // Resolved through the filesystem, not compared as a string (T-14).
        // `Path::confine()` is lexical, so with `vendor/bin` a junction to a
        // directory outside the project the executable lands outside while the
        // path still *reads* as being inside. `Path::resolve()` follows the
        // link first, which is the check that actually holds.
        $parent = Path::resolve($root, \dirname($normalized), [$root], false);

        return $parent . '/' . \basename($normalized);
    }

    /**
     * @return string|null The directory this call created, so a failed download
     *                     can undo it.
     */
    private function ensureDirectory(string $dir): ?string
    {
        if (\is_dir($dir)) {
            return null;
        }

        // Documented `@` case (doc 18): a concurrent run may have created it.
        if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            throw new ConsoleException('Could not create the destination directory.');
        }

        return $dir;
    }

    private function removeIfCreated(?string $dir): void
    {
        if ($dir === null) {
            return;
        }

        // Only ever the one directory this run made, and only when it is still
        // empty. Emptiness is checked rather than left to `rmdir` refusing,
        // because `rmdir` is not in doc 18's suppression allowlist and a
        // warning here would be noise on a path that is already reporting a
        // failure.
        $entries = \scandir($dir);

        if ($entries !== false && \count($entries) <= 2) {
            \rmdir($dir);
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
        if (!\is_writable(\dirname($temporary))) {
            throw new ConsoleException('The destination directory is not writable.');
        }

        // Checked rather than silenced: doc 18 permits `@` only around
        // rename/unlink/flock/mkdir, and a failure to open here is worth
        // reporting rather than swallowing.
        $handle = \fopen($temporary, 'wb');

        if ($handle === false) {
            throw new ConsoleException('Could not open the temporary file for writing.');
        }

        // 0600 while the contents are unverified: no other user can read it,
        // and nobody at all can execute it. Windows has no POSIX mode, so a
        // false return is expected there and is not a failure — the executable
        // bit is what matters, and it is not set until the digest matches.
        if (!\chmod($temporary, 0600) && \DIRECTORY_SEPARATOR !== '\\') {
            \fclose($handle);
            @\unlink($temporary);

            throw new ConsoleException('The temporary file could not be made private.');
        }

        // Hashed **as it arrives** rather than re-read afterwards: the bytes
        // pass through once, so a 200 MB body never sits in memory and never
        // has to be read twice. `Support\Hash` has no incremental API — it
        // hashes a string, a file or a whole stream — so the running context
        // lives here.
        $context = \hash_init('sha256');
        $written = 0;

        $ok = \function_exists('curl_init')
            ? $this->downloadWithCurl($url, $handle, $context, $written, $output)
            : $this->downloadWithStream($url, $handle, $context, $written, $output);

        \fclose($handle);

        // `fclose()` reports true whatever its final flush did, so it proves
        // nothing; the size on disk does. Both paths count every byte they
        // hashed into $written.
        if ($ok && !$this->writtenInFull($temporary, $written, $output, $url)) {
            $ok = false;
        }

        if (!$ok) {
            @\unlink($temporary);

            return null;
        }

        return \hash_final($context);
    }

    /**
     * Whether the closed temporary file holds exactly the $written bytes that
     * were hashed; one error line when it does not (the caller then deletes it).
     */
    private function writtenInFull(string $temporary, int $written, Output $output, string $url): bool
    {
        \clearstatcache(true, $temporary);
        $size = \is_file($temporary) ? \filesize($temporary) : false;

        if ($size === $written) {
            return true;
        }

        $output->error(\sprintf(
            'Download failed: the temporary file holds %s of %d bytes. %s',
            $size === false ? 'none' : (string) $size,
            $written,
            $this->manualInstructions($url)
        ));

        return false;
    }

    /**
     * @param resource $handle
     * @param \HashContext $context
     */
    private function downloadWithCurl(
        string $url,
        $handle,
        \HashContext $context,
        int &$written,
        Output $output
    ): bool {
        $curl = \curl_init($url);

        if ($curl === false) {
            $output->error('cURL could not be initialised. ' . $this->manualInstructions($url));

            return false;
        }

        // The write callback is what makes the hash a streaming one: every
        // chunk is fed to the digest and to the file in the same pass, and
        // returning short aborts the transfer, which is how the size ceiling
        // is enforced without buffering.
        \curl_setopt($curl, \CURLOPT_WRITEFUNCTION, static function (
            $ignored,
            string $chunk
        ) use (
            $handle,
            $context,
            &$written
        ): int {
            $length = \strlen($chunk);
            $written += $length;

            if ($written > self::MAX_BYTES) {
                return 0;
            }

            \hash_update($context, $chunk);

            return (int) \fwrite($handle, $chunk);
        });

        \curl_setopt($curl, \CURLOPT_FOLLOWLOCATION, true);
        \curl_setopt($curl, \CURLOPT_MAXREDIRS, self::MAX_REDIRECTS);
        \curl_setopt($curl, \CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        \curl_setopt($curl, \CURLOPT_TIMEOUT, self::TRANSFER_TIMEOUT);
        \curl_setopt($curl, \CURLOPT_FAILONERROR, true);
        \curl_setopt($curl, \CURLOPT_MAXFILESIZE, self::MAX_BYTES);

        // Protocols are pinned so a redirect cannot move the transfer onto
        // `ftp://`, `file://` or anything else cURL happens to support.
        // Production redirects (GitHub → its asset CDN) are HTTPS→HTTPS; plain
        // HTTP is permitted only so the loopback fixture server in the T-18
        // suite works, and never appears in a real release URL.
        $protocols = \CURLPROTO_HTTPS | \CURLPROTO_HTTP;
        \curl_setopt($curl, \CURLOPT_PROTOCOLS, $protocols);
        \curl_setopt($curl, \CURLOPT_REDIR_PROTOCOLS, $protocols);

        // Never negotiable. A download whose certificate is not checked is a
        // download from whoever is on the path (T-18).
        \curl_setopt($curl, \CURLOPT_SSL_VERIFYPEER, true);
        \curl_setopt($curl, \CURLOPT_SSL_VERIFYHOST, 2);

        $ok = \curl_exec($curl) !== false;

        if (!$ok) {
            $reason = $written > self::MAX_BYTES
                ? 'the download exceeded ' . self::MAX_BYTES . ' bytes'
                : \curl_error($curl);

            $output->error('Download failed: ' . $reason . '. ' . $this->manualInstructions($url));
        }

        // No `curl_close()`: a no-op since PHP 8.0, deprecated in 8.5. The
        // handle (and the write callback holding $handle) is released when
        // $curl goes out of scope on return, before the caller's `fclose()`.
        return $ok;
    }

    /**
     * The fallback for hosts built without cURL.
     *
     * @param resource $handle
     */
    private function downloadWithStream(
        string $url,
        $handle,
        \HashContext $hash,
        int &$written,
        Output $output
    ): bool {
        if (!\ini_get('allow_url_fopen')) {
            $output->error(
                'Neither cURL nor allow_url_fopen is available. ' . $this->manualInstructions($url)
            );

            return false;
        }

        $context = \stream_context_create([
            'http' => [
                'timeout' => self::TRANSFER_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => self::MAX_REDIRECTS,
                // The wrapper list is the stream equivalent of cURL's protocol
                // pinning: a redirect cannot leave http/https.
                'protocol_version' => 1.1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        // Not silenced: a failure here is reported with the manual fallback,
        // which is more useful than a warning nobody reads.
        $source = \fopen($url, 'rb', false, $context);

        if ($source === false) {
            $output->error('Download failed: the release could not be opened. ' . $this->manualInstructions($url));

            return false;
        }

        $ok = $this->copyStream($source, $handle, $hash, $written, $output, $url);
        \fclose($source);

        return $ok;
    }

    /**
     * Copy the release body into the temporary file, feeding the digest with
     * exactly the bytes that were written. Copied by hand rather than with
     * `stream_copy_to_stream` so the digest and the size ceiling both see every
     * chunk as it arrives. False, with one error line, on a failed read, a short
     * write or the size ceiling; the caller then deletes the file.
     *
     * @param resource $source
     * @param resource $target
     */
    private function copyStream(
        $source,
        $target,
        \HashContext $hash,
        int &$written,
        Output $output,
        string $url
    ): bool {
        while (!\feof($source)) {
            $chunk = \fread($source, self::CHUNK_BYTES);

            // A failed read answers false (PHP 8: `string|false`): that is a
            // failed download, reported like the others, never a short body.
            if ($chunk === false) {
                $output->error('Download failed: reading the release failed. ' . $this->manualInstructions($url));

                return false;
            }

            // An empty read means nothing more is coming: the loop is over, and
            // `feof()` is what decides whether the body really ended.
            if ($chunk === '') {
                break;
            }

            $written += \strlen($chunk);

            if ($written > self::MAX_BYTES) {
                $output->error(
                    'Download failed: the release exceeded ' . self::MAX_BYTES . ' bytes. '
                    . $this->manualInstructions($url)
                );

                return false;
            }

            // Written, checked, then hashed. On a short write (a full disk) the
            // file would be truncated while the digest of the whole body still
            // matched, and the truncated binary would pass verification.
            if (\fwrite($target, $chunk) !== \strlen($chunk)) {
                $output->error(
                    'Download failed: writing the temporary file failed (is the disk full?). '
                    . $this->manualInstructions($url)
                );

                return false;
            }

            \hash_update($hash, $chunk);
        }

        return true;
    }

    /**
     * What to do when the network will not co-operate.
     *
     * Printed on **every** exit-3 path: an operator whose CI has no outbound
     * access needs the URL and the setting, not just the news that it failed.
     */
    private function manualInstructions(string $url): string
    {
        return \sprintf('Download it by hand from %s and point cli.binary at the result.', $url);
    }
}
