<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

use TailwindPhp\Exception\DriverUnavailableException;
use TailwindPhp\Support\FilesystemInterface;
use TailwindPhp\Support\Hash;
use TailwindPhp\Support\Path;
use TailwindPhp\Support\Regex;

/**
 * Decides whether the Tailwind CLI binary on disk is one we are willing to run
 * (design doc 06 §BinaryVerifier, design doc 12 threat T-04).
 *
 * The security question is easy: hash the file, compare the digest against the
 * pinned table with a constant-time comparison. The engineering question is the
 * hard one, because this runs on **every request**, and the standalone binary is
 * ~100 MB. Hashing it per request would put tens of milliseconds and a hundred
 * megabytes of disk reads on the warm path, which is precisely the path this
 * library exists to keep at a couple of milliseconds.
 *
 * So the digest is cached in the manifest's `driver_probe` alongside the file's
 * mtime and size, and a request that finds all three unchanged reuses the
 * recorded digest instead of recomputing it. That is a real trade: it means a
 * binary swapped for one of identical size **within the same second** would be
 * accepted on a stale digest. Both fields are compared rather than just mtime
 * because that shrinks the window to same-second-and-same-length, and the
 * alternative — hashing 100 MB per request — is a denial of service we would be
 * inflicting on ourselves (T-05). The verdict itself is never cached, only the
 * digest: see {@see ProbeResult::toArray()} for why that distinction matters.
 *
 * **This class never spawns a process.** The binary's version string is
 * something only the binary can answer, and answering it costs a `proc_open`;
 * doing that here would put a fork on the warm path and would make a class whose
 * entire job is "decide whether this thing is safe to execute" execute it. So
 * `version` is either carried over from the cached probe or left empty, and the
 * caller — `DriverResolver`, which already knows whether it is about to run the
 * binary anyway — fills it in once per binary change (CCP-A4, CSF-A19).
 */
final class BinaryVerifier
{
    /**
     * A bare lowercase SHA-256, matching what `ManifestValidator` enforces for
     * `driver_probe.sha256`.
     *
     * The `D` modifier is required, not stylistic: without it PCRE's `$` also
     * matches immediately before a trailing newline, so a cached probe carrying
     * `"<64-hex>\n"` would be reused as though it were a digest.
     */
    private const DIGEST_PATTERN = '#^[0-9a-f]{64}$#D';

    /**
     * @var Checksums
     */
    private $checksums;

    /**
     * @var FilesystemInterface
     */
    private $fs;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @param string $projectRoot Absolute; the root `binary` is expressed
     *                            relative to (CCP-A3, ADR-014).
     */
    public function __construct(Checksums $checksums, FilesystemInterface $fs, string $projectRoot)
    {
        $this->checksums = $checksums;
        $this->fs = $fs;
        $this->projectRoot = $projectRoot;
    }

    /**
     * Probe the binary at $absPath, reusing $cachedProbe when it still applies.
     *
     * The path is expected to have been through `Path::resolve()` already — this
     * class confines nothing and normalises nothing, in line with the rule that
     * every path reaching the filesystem layer has been validated by its caller.
     *
     * @param string $absPath Absolute path to the binary.
     * @param array<array-key, mixed>|null $cachedProbe The manifest's
     *                                                  `driver_probe`, or null.
     * @param bool $verifyEnabled `cli.verify`. False means the operator has
     *                            explicitly accepted an unrecognised binary.
     *
     * @throws DriverUnavailableException When nothing can be read at $absPath.
     * @throws \RuntimeException When the file exists for `stat()` but cannot be
     *                           hashed — it was removed or its permissions
     *                           changed between the two calls.
     */
    public function verify(string $absPath, ?array $cachedProbe, bool $verifyEnabled): ProbeResult
    {
        $stat = $this->fs->stat($absPath);

        if ($stat === null) {
            // Deliberately vague, and deliberately free of the path: this
            // message reaches the user, and a driver diagnostic is not a place
            // to disclose the server's filesystem layout (threat T-06).
            throw new DriverUnavailableException('cli', 'the binary could not be read');
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];
        $binary = $this->relativeName($absPath);

        $reusable = $this->reusableProbe($cachedProbe, $binary, $mtime, $size);

        if ($reusable !== null) {
            // The warm path, and the reason the whole class is shaped this way:
            // no read of the binary at all, just the `stat()` above.
            $sha256 = $reusable['sha256'];
            $version = $reusable['version'];
        } else {
            $sha256 = Hash::sha256File($absPath);

            // Left to the caller (CCP-A4): obtaining it means running the
            // binary, which is not a decision this class gets to make.
            $version = '';
        }

        // Order matters. `$verifyEnabled === false` is the operator having said
        // "I know what this binary is, stop checking" — checked first so that
        // the table is not even consulted, which is what makes opting out also
        // an opt-out of needing an up-to-date table for a private build.
        $verified = $verifyEnabled === false || $this->checksums->isKnown($sha256);

        return new ProbeResult($binary, $mtime, $size, $sha256, $version, $verified);
    }

    /**
     * The cached digest and version, when $probe still describes this file.
     *
     * Every field is validated, not merely read. The manifest lives in a
     * directory the web server can write, so a probe is untrusted input: a
     * forged `sha256` that is not a digest at all, or an `m` that arrives as the
     * string `"1700000000"` rather than the integer, must fail the comparison
     * rather than slip through a loose one. Strict `===` throughout is what
     * makes the type check and the value check the same check.
     *
     * A probe whose `binary` is null matches a binary that is also outside the
     * project (CCP-A3), since neither has a relative name to compare. The mtime
     * and size still have to agree, which is the same window every other path
     * through here accepts.
     *
     * @param array<array-key, mixed>|null $probe
     * @param string|null $binary The name just computed for this file.
     *
     * @return array{sha256: string, version: string}|null Null ⇒ must re-hash.
     */
    private function reusableProbe(?array $probe, ?string $binary, int $mtime, int $size): ?array
    {
        if ($probe === null) {
            return null;
        }

        // A null name means the binary lives outside the project, so there is no
        // project-relative path to key the cache on and `(null, m, s)` would
        // match *any* out-of-project binary with the same mtime and size — a
        // narrow but real way for a cached digest to stand in for different
        // bytes. This configuration therefore always re-hashes (CCP-A3, ruling
        // of 2026-09-03).
        //
        // The cost is one sha256File per request, paid only by the out-of-project
        // case: already the unusual setup, and one an operator opts into
        // explicitly by pointing `cli.binary` outside the project. Keeping T-04
        // strict is worth more than saving that hash.
        if ($binary === null) {
            return null;
        }

        if (!\array_key_exists('binary', $probe) || $probe['binary'] !== $binary) {
            return null;
        }

        if (!\array_key_exists('m', $probe) || $probe['m'] !== $mtime) {
            return null;
        }

        if (!\array_key_exists('s', $probe) || $probe['s'] !== $size) {
            return null;
        }

        if (
            !\array_key_exists('sha256', $probe)
            || !\is_string($probe['sha256'])
            || !Regex::match(self::DIGEST_PATTERN, $probe['sha256'])
        ) {
            return null;
        }

        if (!\array_key_exists('version', $probe) || !\is_string($probe['version'])) {
            return null;
        }

        return ['sha256' => $probe['sha256'], 'version' => $probe['version']];
    }

    /**
     * $absPath expressed relative to the project root, or null.
     *
     * Null is a legitimate answer, not a failure (CCP-A3). A binary at
     * `/usr/local/bin/tailwindcss` is a perfectly ordinary setup on a machine
     * where one copy serves several projects; it simply has no name in this
     * project's terms, so the manifest records none and the digest is what
     * identifies it on the next request.
     */
    private function relativeName(string $absPath): ?string
    {
        try {
            return Path::relative($this->projectRoot, $absPath);
        } catch (\TailwindPhp\Exception\PathException $e) {
            return null;
        }
    }
}
