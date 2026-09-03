<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

/**
 * What one look at the Tailwind CLI binary established
 * (design doc 06 §BinaryVerifier; decisions CCP-A3, CCP-A4).
 *
 * Immutable, and the unit the manifest stores: {@see self::toArray()} is
 * literally the `driver_probe` object, so that the next request can compare
 * three cheap fields against a fresh `stat()` and, when they agree, skip
 * re-hashing a binary that can be a hundred megabytes. That comparison is
 * {@see self::matches()}, and it is the whole reason this type exists as
 * something more than an array.
 *
 * `binary` is **project-relative, or null** (CCP-A3). Relative because a cache
 * artefact that recorded an absolute path would both invalidate itself whenever
 * the project moved — a capistrano-style release directory changes on every
 * deploy — and leak the server's layout into a file the web root may expose
 * (ADR-014, threat T-06). Null because the binary is genuinely allowed to live
 * outside the project, and in that case there is no relative name to record; the
 * checksum identifies it instead.
 */
final class ProbeResult
{
    /**
     * @var string|null
     */
    private $binary;

    /**
     * @var int
     */
    private $m;

    /**
     * @var int
     */
    private $s;

    /**
     * @var string
     */
    private $sha256;

    /**
     * @var string
     */
    private $version;

    /**
     * @var bool
     */
    private $verified;

    /**
     * @param string|null $binary Project-relative path, or null when the binary
     *                            lies outside the project root (CCP-A3).
     * @param int $m Modification time, in Unix seconds.
     * @param int $s Size in bytes. Recorded alongside the mtime because mtime
     *               alone misses a same-second replacement and misses an
     *               archive restore that carries an older stamp.
     * @param string $sha256 Bare lowercase digest — no `sha256:` prefix, which
     *                       is the form the manifest validator enforces here.
     * @param string $version The CLI's self-reported version, opaque to us
     *                        (CCP-A4/CSF-A19). Empty when nobody has asked it
     *                        yet; {@see BinaryVerifier} never spawns a process.
     * @param bool $verified Whether this run is willing to execute the binary.
     */
    public function __construct(
        ?string $binary,
        int $m,
        int $s,
        string $sha256,
        string $version,
        bool $verified
    ) {
        $this->binary = $binary;
        $this->m = $m;
        $this->s = $s;
        $this->sha256 = $sha256;
        $this->version = $version;
        $this->verified = $verified;
    }

    /**
     * @return string|null Null when the binary lies outside the project (CCP-A3).
     */
    public function binary(): ?string
    {
        return $this->binary;
    }

    public function m(): int
    {
        return $this->m;
    }

    public function s(): int
    {
        return $this->s;
    }

    /**
     * @return string Bare lowercase SHA-256, no prefix.
     */
    public function sha256(): string
    {
        return $this->sha256;
    }

    /**
     * @return string Opaque version string; `''` when it has not been probed.
     */
    public function version(): string
    {
        return $this->version;
    }

    public function verified(): bool
    {
        return $this->verified;
    }

    /**
     * The `driver_probe` object, exactly as `ManifestValidator` expects it.
     *
     * **`verified` is deliberately absent, and that is a security property, not
     * an oversight.** Verification is a property of *this* run — of the checksum
     * table this process loaded and of whether this process's configuration
     * asked for verification at all — not of the binary. Writing it to disk
     * would make the manifest a place where "already verified" can be asserted,
     * and anyone who can write the cache directory could then assert it for a
     * binary that has never matched anything. So the cached probe carries only
     * the facts needed to *avoid re-hashing* (path, mtime, size, digest), and
     * the verdict is recomputed from the digest on every single request, which
     * costs one array walk (threat T-04).
     *
     * `version` is cached because it is the binary's own answer about itself,
     * not ours about it, and re-obtaining it would cost a process spawn
     * (CCP-A4).
     *
     * @return array{binary: string|null, m: int, s: int, sha256: string, version: string}
     */
    public function toArray(): array
    {
        return [
            'binary' => $this->binary,
            'm' => $this->m,
            's' => $this->s,
            'sha256' => $this->sha256,
            'version' => $this->version,
        ];
    }

    /**
     * Whether this probe still describes the file just found at $binary.
     *
     * All three must agree. Size is checked as well as mtime because a
     * same-second swap leaves the mtime unchanged, and mtime is checked as well
     * as size because a rebuilt binary of identical length is the ordinary case
     * for a patch release — either one alone is a hole through which an
     * unverified binary reaches `proc_open` on the strength of a stale digest.
     *
     * A probe whose `binary` is null never matches, because $binary is a name
     * and this probe has none: an out-of-project binary is re-identified by its
     * digest, not by a path it does not have.
     */
    public function matches(string $binary, int $m, int $s): bool
    {
        return $this->binary === $binary && $this->m === $m && $this->s === $s;
    }
}
