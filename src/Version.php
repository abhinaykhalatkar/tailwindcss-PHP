<?php

declare(strict_types=1);

namespace TailwindPhp;

/**
 * Single source of truth for the installed package version (doc 19 §Versioning).
 *
 * The release workflow verifies that the git tag matches this constant and
 * that CHANGELOG.md carries a section for it. Never instantiated.
 */
final class Version
{
    public const VERSION = '0.1.0-dev';

    private function __construct()
    {
    }
}
