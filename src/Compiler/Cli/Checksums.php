<?php

declare(strict_types=1);

namespace TailwindPhp\Compiler\Cli;

use TailwindPhp\Support\Hash;
use TailwindPhp\Support\Json;
use TailwindPhp\Support\Regex;

/**
 * The pinned SHA-256 table for the official Tailwind CSS standalone binaries
 * (design doc 06 §BinaryVerifier, design doc 12 threat T-04).
 *
 * T-04 is "the binary on disk is not the binary upstream published". Nothing
 * this library does can stop an operator from putting an arbitrary executable at
 * `vendor/bin/tailwindcss`, but it can refuse to *run* one it does not
 * recognise, and recognition means comparing its digest against a table that
 * ships inside the package rather than one fetched at run time. A table fetched
 * over the network would be attackable by exactly the party this control exists
 * to stop.
 *
 * The table is `{ "<version>": { "<platform>": "<64-hex>" } }` — the shape of
 * the `sha256sums.txt` each tailwindcss release publishes, transposed so that a
 * version is the natural unit to add or retire. Digests are compared with
 * {@see Hash::equals()} and never with `===`: a short-circuiting comparison
 * against a value an attacker controls leaks how many leading bytes matched.
 *
 * Two things are deliberately *not* here. There is no environment switch that
 * relaxes verification — decision CCP-A9 — because a switch readable from the
 * environment is a switch an attacker who can set the environment can flip.
 * Tests that need a fake binary recognised call {@see self::withAdditional()},
 * which produces a **new** instance and leaves the shipped table untouched. And
 * there is no "verify off" flag on this class either: opting out is the
 * caller's decision, taken in {@see BinaryVerifier}, so that this class only
 * ever answers the one question it is authoritative about.
 */
final class Checksums
{
    /**
     * The release whose digests {@see self::load()} ships.
     *
     * Kept in step with `resources/checksums.json` by
     * `ChecksumsTest::theDefaultVersionIsPresentInTheShippedTable()` — a
     * constant that had drifted from the file would name a version whose
     * digests are not actually pinned.
     */
    public const DEFAULT_VERSION = '4.1.18';

    /**
     * The shipped table, relative to this file. Derived from `__DIR__` rather
     * than written out, so the package works wherever Composer installs it.
     */
    private const RESOURCE = '/../../../resources/checksums.json';

    /**
     * Read cap for the table. Six digests per release and a few dozen releases
     * is a handful of kilobytes; 256 KiB is far above any legitimate table and
     * still bounds a corrupt or hostile file (threat T-05).
     */
    private const MAX_BYTES = 262144;

    /**
     * A bare lowercase SHA-256, the form the table and the manifest both store.
     *
     * The `D` modifier is required, not stylistic: without it PCRE's `$` also
     * matches immediately before a trailing newline, so `"<64-hex>\n"` would be
     * accepted as a digest.
     */
    private const DIGEST_PATTERN = '#^[0-9a-f]{64}$#D';

    /**
     * A release key: three dot-separated numbers, nothing else. Anchored with
     * `D`, so a trailing newline cannot sneak past the `$` (design doc 18).
     */
    private const VERSION_PATTERN = '#^\d+\.\d+\.\d+$#D';

    /**
     * The platform keys a table may use — the release asset names, and the same
     * set {@see Environment::platform()} can return. A key outside this list is
     * either a typo or a table from somewhere else.
     *
     * @var list<string>
     */
    private const PLATFORMS = [
        Environment::PLATFORM_LINUX_X64,
        Environment::PLATFORM_LINUX_X64_MUSL,
        Environment::PLATFORM_LINUX_ARM64,
        Environment::PLATFORM_MACOS_X64,
        Environment::PLATFORM_MACOS_ARM64,
        Environment::PLATFORM_WINDOWS_X64,
    ];

    /**
     * @var array<string, array<string, string>> version => platform => digest
     */
    private $table;

    /**
     * @var array<string, mixed> digest => caller-supplied metadata (CCP-A9)
     */
    private $additional;

    /**
     * Every digest from both maps, flattened once so that {@see self::isKnown()}
     * is a plain loop over the hot path rather than two nested ones.
     *
     * @var list<string>
     */
    private $digests;

    /**
     * @param array<string, array<string, string>> $table
     * @param array<string, mixed> $additional
     */
    private function __construct(array $table, array $additional)
    {
        $this->table = $table;
        $this->additional = $additional;

        $digests = [];

        foreach ($table as $platforms) {
            foreach ($platforms as $digest) {
                $digests[] = $digest;
            }
        }

        foreach (\array_keys($additional) as $digest) {
            $digests[] = (string) $digest;
        }

        $this->digests = $digests;
    }

    /**
     * Read the table shipped with the package, or one at $path.
     *
     * `$path` exists for tests and for the `doctor` command, which may want to
     * point at a table an operator maintains locally; production code passes
     * nothing and gets the pinned file.
     *
     * @param string|null $path Absolute path to a table; null for the shipped one.
     *
     * @throws \RuntimeException When the file is missing, unreadable or malformed.
     * @throws \JsonException When the file is not valid JSON.
     */
    public static function load(?string $path = null): self
    {
        $file = $path === null ? __DIR__ . self::RESOURCE : $path;

        // Probed rather than suppressed: `@` is forbidden project-wide outside
        // the few places design doc 18 names, and a missing table on a broken
        // install deserves a sentence rather than a warning.
        if (!\is_file($file) || !\is_readable($file)) {
            throw new \RuntimeException(\sprintf('The checksum table is missing or unreadable: %s', $file));
        }

        $json = \file_get_contents($file, false, null, 0, self::MAX_BYTES);

        if ($json === false) {
            throw new \RuntimeException(\sprintf('The checksum table could not be read: %s', $file));
        }

        return self::fromTable(Json::decode($json));
    }

    /**
     * Build a table from an already-decoded structure.
     *
     * The shape is enforced rather than assumed. A table that silently dropped
     * an unexpected entry would turn a genuine binary into an unrecognised one
     * and take the CLI driver offline for no visible reason; a table that
     * silently kept a non-digest would carry a value into a constant-time
     * comparison that can never match. Both are worth a loud failure at load
     * time, which is once per process, over a quiet one per request.
     *
     * @param array<array-key, mixed> $table version => platform => 64-hex digest
     *
     * @throws \RuntimeException When any entry does not have that shape.
     */
    public static function fromTable(array $table): self
    {
        $validated = [];

        foreach ($table as $version => $platforms) {
            $versionKey = (string) $version;

            // The keys are as much a contract as the digests are. An integer
            // key, a stray `"not-a-version"`, or a key carrying a newline were
            // all accepted and stored, so a malformed or hand-edited table
            // loaded silently and `versions()` reported releases that never
            // existed. Validated here, at the one place a table is built.
            if (!Regex::match(self::VERSION_PATTERN, $versionKey)) {
                throw new \RuntimeException(
                    \sprintf('Checksum table version key "%s" is not a semantic version.', $versionKey)
                );
            }

            if (!\is_array($platforms)) {
                throw new \RuntimeException(
                    \sprintf('Checksum table entry "%s" is not an object of platform => digest.', $versionKey)
                );
            }

            $entries = [];

            foreach ($platforms as $platform => $digest) {
                $platformKey = (string) $platform;

                if (!\in_array($platformKey, self::PLATFORMS, true)) {
                    throw new \RuntimeException(
                        \sprintf(
                            'Checksum table platform key "%s.%s" is not a known platform.',
                            $versionKey,
                            $platformKey
                        )
                    );
                }

                if (!\is_string($digest) || !Regex::match(self::DIGEST_PATTERN, $digest)) {
                    throw new \RuntimeException(
                        \sprintf('Checksum "%s.%s" is not a bare lowercase SHA-256 digest.', $versionKey, $platformKey)
                    );
                }

                $entries[$platformKey] = $digest;
            }

            $validated[$versionKey] = $entries;
        }

        return new self($validated, []);
    }

    /**
     * Whether $sha256 is one of the pinned digests (threat T-04).
     *
     * Every candidate is compared with {@see Hash::equals()}, so the time this
     * takes depends on how many entries the table has and never on how many
     * leading bytes of any one entry the caller guessed right. Returning as soon
     * as a match is found is safe: at that point the answer is already public.
     *
     * A digest of the wrong length — `''`, a truncated hash — simply matches
     * nothing, because `hash_equals()` rejects a length mismatch outright.
     */
    public function isKnown(string $sha256): bool
    {
        foreach ($this->digests as $digest) {
            if (Hash::equals($digest, $sha256)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The platform => digest map for one release, or null when it is not pinned.
     *
     * @return array<string, string>|null
     */
    public function forVersion(string $version): ?array
    {
        return $this->table[$version] ?? null;
    }

    /**
     * Every pinned release, in the order the table lists them.
     *
     * Digests added through {@see self::withAdditional()} are deliberately
     * absent: they belong to no release, which is exactly why they are keyed by
     * digest instead.
     *
     * @return list<string>
     */
    public function versions(): array
    {
        // `array_values` so the result is a genuine `list<string>`: the keys are
        // validated version strings, and `array_keys` on a table whose keys look
        // numeric ("1.2.3" does not, but the guarantee should not rest on that)
        // can hand back integers.
        return \array_values(\array_map('\strval', \array_keys($this->table)));
    }

    /**
     * A copy of this table that also recognises $sha256ToMeta (decision CCP-A9).
     *
     * This is how the test suite gets its fake binary accepted, and it is the
     * *only* such route: there is no environment variable and no "trust
     * everything" mode, because either would be a control an attacker who can
     * influence the environment could switch off. Injecting a digest requires
     * being able to construct the object graph, which means being inside the
     * process already.
     *
     * The receiver is not modified — an instance handed to a collaborator can
     * never gain entries behind that collaborator's back.
     *
     * @param array<array-key, mixed> $sha256ToMeta digest => anything the caller
     *                                              wants to remember about it
     *                                              (a label, a provenance note);
     *                                              only the key is consulted here.
     *
     * @throws \RuntimeException When a key is not a bare lowercase SHA-256.
     */
    public function withAdditional(array $sha256ToMeta): self
    {
        $additional = $this->additional;

        foreach ($sha256ToMeta as $sha256 => $meta) {
            $digest = (string) $sha256;

            if (!Regex::match(self::DIGEST_PATTERN, $digest)) {
                throw new \RuntimeException(
                    \sprintf('Additional checksum "%s" is not a bare lowercase SHA-256 digest.', $digest)
                );
            }

            $additional[$digest] = $meta;
        }

        return new self($this->table, $additional);
    }
}
