<?php

declare(strict_types=1);

namespace TailwindPhp\Config;

/**
 * One bundle's resolved view of the configuration (design doc 11 §Multiple
 * bundles): the entry stylesheet, the directories watched for it, and the
 * per-bundle output settings.
 *
 * Immutable, and built only by {@see ConfigBuilder} — every path here has
 * already been resolved and confined, and is exposed as an absolute/relative
 * pair so callers never have to join or normalise one themselves (ADR-014).
 *
 * The scan roots are exposed as **plain arrays**, not `Scanner\Root` objects:
 * `Config` sits below `Scanner` in the layering (design doc 17) and the scanner
 * builds its own value objects from this data.
 */
final class BundleConfig
{
    /**
     * @var array<string, mixed>
     */
    private $values;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public function name(): string
    {
        return (string) $this->values['name'];
    }

    /**
     * Absolute path of the entry stylesheet.
     */
    public function input(): string
    {
        return (string) $this->values['input'];
    }

    public function inputRel(): string
    {
        return (string) $this->values['input_rel'];
    }

    public function publicRoute(): string
    {
        return (string) $this->values['public_route'];
    }

    /**
     * Roots holding the stylesheet and anything it imports — kind `i`, watched
     * with `input_extensions`. A change here means a full recompile.
     *
     * @return list<array{abs: string, rel: string, extensions: list<string>}>
     */
    public function inputRoots(): array
    {
        /** @var list<array{abs: string, rel: string, extensions: list<string>}> $roots */
        $roots = $this->values['input_roots'];

        return $roots;
    }

    /**
     * Roots scanned for class-name candidates — kind `s`. A change here only
     * needs a rescan, and only recompiles when the candidate set differs.
     *
     * @return list<array{abs: string, rel: string, extensions: list<string>}>
     */
    public function sourceRoots(): array
    {
        /** @var list<array{abs: string, rel: string, extensions: list<string>}> $roots */
        $roots = $this->values['source_roots'];

        return $roots;
    }

    /**
     * @return list<string>
     */
    public function inputExtensions(): array
    {
        /** @var list<string> $value */
        $value = $this->values['input_extensions'];

        return $value;
    }

    /**
     * @return list<string>
     */
    public function sourceExtensions(): array
    {
        /** @var list<string> $value */
        $value = $this->values['source_extensions'];

        return $value;
    }

    /**
     * @return list<string>
     */
    public function ignore(): array
    {
        /** @var list<string> $value */
        $value = $this->values['ignore'];

        return $value;
    }

    /**
     * @return list<string>
     */
    public function safelist(): array
    {
        /** @var list<string> $value */
        $value = $this->values['safelist'];

        return $value;
    }

    /**
     * @return list<string>
     */
    public function blocklist(): array
    {
        /** @var list<string> $value */
        $value = $this->values['blocklist'];

        return $value;
    }

    public function minify(): bool
    {
        return (bool) $this->values['minify'];
    }

    /**
     * @return array{prefix: string|null, important: bool, preflight: bool|null}
     */
    public function engine(): array
    {
        /** @var array{prefix: string|null, important: bool, preflight: bool|null} $value */
        $value = $this->values['engine'];

        return $value;
    }

    public function preprocessor(): ?string
    {
        $value = $this->values['preprocessor'];

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function preprocessorOptions(): array
    {
        /** @var array<string, mixed> $value */
        $value = $this->values['preprocessor_options'];

        return $value;
    }

    /**
     * The bundle's slice of the hash input (decision CSF-A4).
     *
     * @return array<string, mixed>
     */
    public function hashInput(): array
    {
        /** @var array<string, mixed> $value */
        $value = $this->values['hash_input'];

        return $value;
    }
}
