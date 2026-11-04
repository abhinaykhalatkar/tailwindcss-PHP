<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Engine\Variant;

use PHPUnit\Framework\TestCase;
use TailwindPhp\Engine\Candidate\Candidate;
use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Candidate\Value\NamedValue;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Engine\Variant\Registry;
use TailwindPhp\Tests\Conformance\Support\CandidateAttribution;
use TailwindPhp\Tests\Conformance\Support\Canonicalizer;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\StaticRootSet;

/**
 * The acceptance signal for the variant table, the templates and the order before
 * the full comparator has an engine to compare (TASK-0031 criterion 6).
 *
 * For every line of `corpus/variants/*.txt`: parse the candidate, wrap a fixed
 * declaration set in its variants, flatten, and compare each resulting rule
 * (selector, the at-rules around it, and the declarations, `content` and
 * `!important` included) with the rules the pinned 4.1.18 CLI emitted for that
 * candidate in the golden, rule by rule in order. The declarations are the ones
 * the golden shows for each base utility, written out here by hand.
 *
 * A second check reads the order of the rules in each golden: for candidates with
 * one variant and the same base utility, the registry's order must never go down.
 */
final class VariantGoldenTest extends TestCase
{
    private const BREAKPOINTS = [
        'sm' => '40rem',
        'md' => '48rem',
        'lg' => '64rem',
        'xl' => '80rem',
        '2xl' => '96rem',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const BASE_DECLARATIONS = [
        'p-4' => ['padding', 'calc(var(--spacing) * 4)'],
        'flex' => ['display', 'flex'],
        'bg-red-500' => ['background-color', 'var(--color-red-500)'],
        'text-center' => ['text-align', 'center'],
        'underline' => ['text-decoration-line', 'underline'],
        '-m-4' => ['margin', 'calc(var(--spacing) * -4)'],
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function goldens(): array
    {
        $paths = new CorpusPaths();
        $rows = [];

        foreach ($paths->select('variants') as $key) {
            $rows[$key] = [$key];
        }

        return $rows;
    }

    /**
     * @test
     *
     * @dataProvider goldens
     */
    public function everyCorpusCandidateWrapsToTheRulesTheCliEmitted(string $key): void
    {
        $paths = new CorpusPaths();
        $candidates = CorpusPaths::parseCandidates(self::read($paths->sourcePath($key)));
        $golden = self::goldenRules($paths, $key, $candidates);
        $parser = self::parser();
        $mismatches = [];

        foreach ($candidates as $raw) {
            $ours = self::ourRules($parser, $raw);
            $theirs = $golden[$raw] ?? [];

            if ($ours !== $theirs) {
                $mismatches[$raw] = "expected:\n  " . \implode("\n  ", $theirs)
                    . "\nactual:\n  " . \implode("\n  ", (array) $ours);
            }
        }

        self::assertSame(
            [],
            \array_slice($mismatches, 0, 5, true),
            $key . ' has ' . \count($mismatches) . ' mismatching candidates.'
        );
    }

    /**
     * @test
     *
     * @dataProvider goldens
     */
    public function theOrderOfSingleVariantRulesNeverGoesDownInAGolden(string $key): void
    {
        $paths = new CorpusPaths();
        $candidates = CorpusPaths::parseCandidates(self::read($paths->sourcePath($key)));
        $sequence = self::goldenSequence($paths, $key, $candidates);
        $parser = self::parser();
        $registry = self::registry();
        $applier = new Applier($registry, new DiagnosticBag());
        $lastByBase = [];

        foreach ($sequence as $raw) {
            $candidate = self::first($parser, $raw);

            if ($candidate === null || \count($candidate->variants) !== 1 || self::isArbitraryBreakpoint($candidate)) {
                continue;
            }

            $wrapped = $applier->wrap([], $candidate->variants, '.x');

            self::assertNotNull($wrapped, $raw);

            $base = self::baseKey($candidate);
            $order = $wrapped->variantOrders()[0];

            if (isset($lastByBase[$base])) {
                self::assertGreaterThanOrEqual(
                    $lastByBase[$base][1],
                    $order,
                    $raw . ' comes after ' . $lastByBase[$base][0] . ' in the golden but has a lower order.'
                );
            }

            $lastByBase[$base] = [$raw, $order];
        }

        self::assertNotEmpty($sequence);
    }

    /** @test */
    public function beforeAndAfterRegisterTheContentPropertyTheGoldenDeclares(): void
    {
        $paths = new CorpusPaths();
        $golden = self::read($paths->goldenPath('variants/pseudo-elements'));
        $parser = self::parser();
        $applier = new Applier(self::registry(), new DiagnosticBag());

        self::assertStringContainsString('@property --tw-content', $golden);

        foreach (['before:flex', 'after:flex', 'before:hover:flex'] as $raw) {
            $candidate = self::first($parser, $raw);

            self::assertNotNull($candidate, $raw);

            $wrapped = $applier->wrap([], $candidate->variants, '.x');

            self::assertNotNull($wrapped, $raw);
            self::assertSame(['--tw-content'], $wrapped->properties(), $raw);
        }
    }

    /** @test */
    public function theCorpusIsBigEnoughToMeanSomething(): void
    {
        $paths = new CorpusPaths();
        $total = 0;

        foreach ($paths->select('variants') as $key) {
            $total += \count(CorpusPaths::parseCandidates(self::read($paths->sourcePath($key))));
        }

        self::assertGreaterThan(700, $total);
    }

    // ---------------------------------------------------------------- helpers

    private static function registry(): Registry
    {
        return new Registry(Variants::table(), self::BREAKPOINTS);
    }

    private static function parser(): CandidateParser
    {
        return new CandidateParser(
            new StaticRootSet(['flex', 'underline'], ['p', 'm', 'bg', 'text']),
            null,
            self::registry()
        );
    }

    private static function first(CandidateParser $parser, string $raw): ?Candidate
    {
        $all = $parser->parse($raw);

        return $all === [] ? null : $all[0];
    }

    private static function baseKey(Candidate $candidate): string
    {
        $key = ($candidate->negative ? '-' : '') . (string) $candidate->root;

        if ($candidate->value instanceof NamedValue) {
            $key .= '-' . $candidate->value->name;
        }

        return $key;
    }

    private static function isArbitraryBreakpoint(Candidate $candidate): bool
    {
        $variant = $candidate->variants[0];

        return $variant->arbitrary !== null && ($variant->name === 'min' || $variant->name === 'max');
    }

    /**
     * Our canonical rule texts for one candidate, or null when it cannot be built.
     *
     * @return list<string>|null
     */
    private static function ourRules(CandidateParser $parser, string $raw): ?array
    {
        $candidate = self::first($parser, $raw);

        if ($candidate === null) {
            return null;
        }

        $base = self::baseKey($candidate);

        if (!isset(self::BASE_DECLARATIONS[$base])) {
            return null;
        }

        $declaration = new Declaration(
            self::BASE_DECLARATIONS[$base][0],
            self::BASE_DECLARATIONS[$base][1],
            $candidate->important
        );
        $applier = new Applier(self::registry(), new DiagnosticBag());
        $wrapped = $applier->wrap([$declaration], $candidate->variants, '.' . Escape::identifier($raw));

        if ($wrapped === null) {
            return null;
        }

        $rules = [];

        foreach (self::walk(Nesting::flatten(new StyleSheet($wrapped->nodes()))->nodes, []) as $entry) {
            $rules[] = self::canonicalText($entry['wrappers'], $entry['rule']);
        }

        return $rules;
    }

    /**
     * The golden's canonical rule texts by candidate.
     *
     * @param list<string> $candidates
     *
     * @return array<string, list<string>>
     */
    private static function goldenRules(CorpusPaths $paths, string $key, array $candidates): array
    {
        $attribution = new CandidateAttribution($candidates);
        $by = [];

        foreach (self::goldenEntries($paths, $key) as $entry) {
            foreach ($attribution->attributeAll($entry['rule']->selector) as $candidate) {
                $by[$candidate][] = self::canonicalText($entry['wrappers'], $entry['rule']);
            }
        }

        return $by;
    }

    /**
     * The candidates in the order the golden's utilities layer first mentions them.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private static function goldenSequence(CorpusPaths $paths, string $key, array $candidates): array
    {
        $attribution = new CandidateAttribution($candidates);
        $seen = [];

        foreach (self::goldenEntries($paths, $key) as $entry) {
            foreach ($attribution->attributeAll($entry['rule']->selector) as $candidate) {
                $seen[$candidate] = true;
            }
        }

        return \array_map('strval', \array_keys($seen));
    }

    /**
     * @return list<array{wrappers: list<AtRule>, rule: Rule}>
     */
    private static function goldenEntries(CorpusPaths $paths, string $key): array
    {
        $bag = new DiagnosticBag();
        $sheet = Nesting::flatten(Parser::parse(self::read($paths->goldenPath($key)), $bag), $bag);

        self::assertTrue($bag->isEmpty(), $key . ' did not parse cleanly.');

        foreach ($sheet->nodes as $node) {
            if ($node instanceof AtRule && $node->name === 'layer' && $node->params === 'utilities') {
                return self::walk($node->nodes ?? [], []);
            }
        }

        return [];
    }

    /**
     * Every style rule under `$nodes` with the at-rules that wrap it.
     *
     * @param array<array-key, Node> $nodes
     * @param list<AtRule> $wrappers
     *
     * @return list<array{wrappers: list<AtRule>, rule: Rule}>
     */
    private static function walk(array $nodes, array $wrappers): array
    {
        $entries = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $entries[] = ['wrappers' => $wrappers, 'rule' => $node];
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $inner = $wrappers;
                $inner[] = new AtRule($node->name, $node->params);

                foreach (self::walk($node->nodes, $inner) as $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<AtRule> $wrappers Outermost first.
     */
    private static function canonicalText(array $wrappers, Rule $rule): string
    {
        $current = new Rule($rule->selector, $rule->nodes);

        for ($i = \count($wrappers) - 1; $i >= 0; --$i) {
            $current = new AtRule($wrappers[$i]->name, $wrappers[$i]->params, [$current]);
        }

        $bag = new DiagnosticBag();
        $sheet = Canonicalizer::canonical(Parser::parse(Serializer::compact(new StyleSheet([$current])), $bag), $bag);

        return Serializer::compact($sheet);
    }

    private static function read(string $path): string
    {
        $contents = \file_get_contents($path);

        self::assertNotFalse($contents, 'Unreadable corpus file.');

        return \str_replace("\r\n", "\n", (string) $contents);
    }
}
