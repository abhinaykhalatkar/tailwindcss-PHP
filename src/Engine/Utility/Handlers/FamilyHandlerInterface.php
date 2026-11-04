<?php

declare(strict_types=1);

namespace TailwindPhp\Engine\Utility\Handlers;

use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Definition;

/**
 * The handlers of one utility family (design doc 07 §Definition schema,
 * `handler`; TASK-0049).
 *
 * A family owns one class under `Utility\Handlers\`, named after its part in
 * `Data\Utilities\`, so two executors can work on two families without ever
 * editing the same file. {@see \TailwindPhp\Engine\Utility\Handlers} builds a
 * map from handler name to class once, from {@see names()}, and refuses a name two
 * families claim.
 *
 * A handler class is constructed with the shared `ValueResolver`; it never reads
 * raw candidate text (T-15), only the parsed value objects, the definition and
 * the theme, and it holds no static state.
 */
interface FamilyHandlerInterface
{
    /**
     * The handler names this family implements; each must be in
     * `Handlers::TAKES_VALUE`.
     *
     * @return list<string>
     */
    public static function names(): array;

    /**
     * Run handler `$name`, one of {@see names()}.
     *
     * The third element is optional: the `--tw-*` custom properties the CLI
     * registers with `@property` for this utility — exactly those, listed
     * explicitly (a declared `--tw-*` property is not registered by itself:
     * `outline-solid` declares `--tw-outline-style`, `outline-2` registers it).
     *
     * Returns `[nodes, usedVars]` or `[nodes, usedVars, properties]`, or null
     * when the handler does not take this candidate.
     *
     * @return array{0: list<\TailwindPhp\Engine\Css\Node\Node>, 1: list<string>, 2?: list<string>}|null
     */
    public function run(string $name, Definition $definition, Candidate $candidate, ThemeRegistry $theme): ?array;
}
