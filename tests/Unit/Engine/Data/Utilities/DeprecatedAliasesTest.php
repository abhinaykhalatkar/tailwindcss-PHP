<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Data\Utilities;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Data\DefaultTheme;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler;
use TailwindPhp\Engine\Utility\Registry;

/**
 * The deprecated v3 names the pinned 4.1.18 CLI still emits (TASK-0055): `flex-grow[-*]`, `flex-shrink[-*]`,
 * `overflow-ellipsis`, `decoration-slice` and `decoration-clone`. A real project's templates carry them
 * (`flex-grow-1` is everywhere), so they are Tier 1 and must render as the CLI renders them. Each expected rule
 * was generated from the binary (rendered like this class's own `render()`), not written from the engine; the
 * refusals are what the binary answers with nothing, including the removed v3 opacity names and the hostile
 * arbitrary values.
 */
final class DeprecatedAliasesTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider emitted
     */
    public function theCliStillEmitsThem(string $candidate, string $expected): void
    {
        self::assertSame($expected, self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function emitted(): array
    {
        return [
            'flex-grow' => ['flex-grow', 'flex-grow:1'],
            'flex-grow-0' => ['flex-grow-0', 'flex-grow:0'],
            'flex-grow-1' => ['flex-grow-1', 'flex-grow:1'],
            'flex-grow-2' => ['flex-grow-2', 'flex-grow:2'],
            'flex-grow-3' => ['flex-grow-3', 'flex-grow:3'],
            'flex-grow-100' => ['flex-grow-100', 'flex-grow:100'],
            'flex-grow-[2]' => ['flex-grow-[2]', 'flex-grow:2'],
            'flex-grow-[0.5]' => ['flex-grow-[0.5]', 'flex-grow:0.5'],
            'flex-grow-(--x)' => ['flex-grow-(--x)', 'flex-grow:var(--x)'],
            'flex-grow-[var(--y)]' => ['flex-grow-[var(--y)]', 'flex-grow:var(--y)'],
            'flex-grow-1!' => ['flex-grow-1!', 'flex-grow:1!important'],
            '!flex-grow-1' => ['!flex-grow-1', 'flex-grow:1!important'],
            'flex-shrink' => ['flex-shrink', 'flex-shrink:1'],
            'flex-shrink-0' => ['flex-shrink-0', 'flex-shrink:0'],
            'flex-shrink-1' => ['flex-shrink-1', 'flex-shrink:1'],
            'flex-shrink-3' => ['flex-shrink-3', 'flex-shrink:3'],
            'flex-shrink-[0.5]' => ['flex-shrink-[0.5]', 'flex-shrink:0.5'],
            'flex-shrink-(--x)' => ['flex-shrink-(--x)', 'flex-shrink:var(--x)'],
            'flex-shrink-0!' => ['flex-shrink-0!', 'flex-shrink:0!important'],
            'overflow-ellipsis' => ['overflow-ellipsis', 'text-overflow:ellipsis'],
            'overflow-ellipsis!' => ['overflow-ellipsis!', 'text-overflow:ellipsis!important'],
            'decoration-slice' => ['decoration-slice', '-webkit-box-decoration-break:slice;box-decoration-break:slice'],
            'decoration-clone' => ['decoration-clone', '-webkit-box-decoration-break:clone;box-decoration-break:clone'],
            'decoration-slice!' => [
                'decoration-slice!',
                '-webkit-box-decoration-break:slice!important;box-decoration-break:slice!important',
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider refused
     */
    public function theCliAnswersNothingToTheseAndSoDoesTheEngine(string $candidate): void
    {
        self::assertNull(self::css($candidate), $candidate);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refused(): array
    {
        return [
            'flex-grow-1/2' => ['flex-grow-1/2'],
            '-flex-grow-1' => ['-flex-grow-1'],
            'flex-grow-x' => ['flex-grow-x'],
            'flex-grow-1.5' => ['flex-grow-1.5'],
            'flex-grow-[1;color:red]' => ['flex-grow-[1;color:red]'],
            'flex-grow-[1}]' => ['flex-grow-[1}]'],
            'flex-shrink-1/2' => ['flex-shrink-1/2'],
            '-flex-shrink-1' => ['-flex-shrink-1'],
            'flex-shrink-x' => ['flex-shrink-x'],
            'overflow-ellipsis/50' => ['overflow-ellipsis/50'],
            '-overflow-ellipsis' => ['-overflow-ellipsis'],
            'overflow-ellipsis-1' => ['overflow-ellipsis-1'],
            'decoration-slice/50' => ['decoration-slice/50'],
            'decoration-slice-1' => ['decoration-slice-1'],
            '-decoration-clone' => ['-decoration-clone'],
            'bg-opacity-50' => ['bg-opacity-50'],
            'text-opacity-50' => ['text-opacity-50'],
            'placeholder-opacity-50' => ['placeholder-opacity-50'],
        ];
    }

    private static function css(string $candidate): ?string
    {
        $registry = Registry::default();
        $compiled = (new Compiler($registry))->compileReadings(
            $candidate,
            (new CandidateParser($registry))->parse($candidate),
            ThemeRegistry::fromDefault(DefaultTheme::vars(), DefaultTheme::keyframes())
        );

        return $compiled === null ? null : self::render($compiled->nodes);
    }

    /**
     * @param list<Node> $nodes
     */
    private static function render(array $nodes): string
    {
        $parts = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $parts[] = $node->property . ':' . $node->value . ($node->important ? '!important' : '');
            } elseif ($node instanceof AtRule) {
                $parts[] = '@' . $node->name . ' ' . $node->params . '{' . self::render($node->nodes ?? []) . '}';
            } elseif ($node instanceof Rule) {
                $parts[] = $node->selector . '{' . self::render($node->nodes) . '}';
            }
        }

        return \implode(';', $parts);
    }
}
