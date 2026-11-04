<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Conformance\Support;

use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Node\StyleSheet;
use TailwindPhp\Engine\Css\Parser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;

/**
 * Compares a stylesheet the engine produced with one the pinned CLI produced
 * (design doc 16 §Comparison; decision ENG-A9, ADR-009).
 *
 * Both sides are parsed with **our** parser and put in the canonical form of
 * {@see Canonicalizer}, then compared by region:
 *
 * - **utilities layer** — rule by rule, attributed to candidates, with the order
 *   judged on the **full sequence** (authored rules included), so a candidate
 *   split around another rule or an authored rule moved among utilities is out
 *   of order even when every candidate's own rules are unchanged;
 * - **theme variables** (`@layer theme`) — as a set by variable name, where a
 *   name's value is every `selector{declaration}` that sets it, in order, so a
 *   variable set under a second selector is never overwritten;
 * - **`@property`** and **`@keyframes`** rules — as sets by name, where a
 *   duplicate name keeps every copy, in order;
 * - **the `@layer a, b, …;` statement, the other layers and unlayered CSS** —
 *   in order;
 * - **authored utilities** (rules in the utilities layer no candidate accounts
 *   for) — in order.
 *
 * Nothing in the utilities layer is forgiven: a different value, a different
 * rule order and an extra or missing rule are each a distinct failure. And
 * nothing is compared at all if either side failed to parse: the result is
 * `ERROR_PARSE` for every candidate, whatever the two texts look like.
 *
 * **Deliberately strict** (the engine must emit the CLI's own forms, so a
 * difference here is a real conformance failure, not noise): `::before` against
 * `:before`, `'a'` against `"a"`, `rgb(1,2,3)` against `rgb(1 2 3)`, `+1px`,
 * `-0`, `1.50E2`, and the order of nested `@media` blocks. The `.min.css`
 * goldens are optimised by Lightning CSS (merged selectors, rewritten queries),
 * so they are only checked for readability, never rule by rule.
 *
 * Pure: strings in, a {@see ComparisonResult} out. Callers read the files, which
 * keeps this class free of I/O and promotable into `src/` for the console's
 * `report --corpus` (design doc 21). Linear in the size of its input; the
 * parser and the flattener carry the node and selector caps (threat T-05).
 */
final class CssComparator
{
    /**
     * At-rules whose block holds more rules, which are walked into.
     *
     * @var array<string, true>
     */
    private const GROUPING = [
        'media' => true,
        'supports' => true,
        'container' => true,
        'layer' => true,
        'scope' => true,
        'starting-style' => true,
    ];

    private const AUTHORED = '(authored)';

    /**
     * Compare `$actualCss` (the engine) with `$expectedCss` (the golden).
     *
     * @param list<string> $candidates The candidates the stylesheets were made
     *                                 from; each gets a status, duplicates once.
     */
    public function compare(string $expectedCss, string $actualCss, array $candidates): ComparisonResult
    {
        $candidates = self::unique($candidates);
        $diagnostics = [];

        $expectedSheet = self::prepare($expectedCss, 'expected', $diagnostics);
        $actualSheet = self::prepare($actualCss, 'actual', $diagnostics);

        if ($diagnostics !== []) {
            return ComparisonResult::parseFailure($candidates, $diagnostics);
        }

        $attribution = new CandidateAttribution($candidates);
        $expected = self::regions($expectedSheet, $attribution);
        $actual = self::regions($actualSheet, $attribution);

        $failures = [];
        $statuses = $this->candidateStatuses($candidates, $expected['utilities'], $actual['utilities'], $failures);

        foreach ($this->regionFailures($expected, $actual) as $failure) {
            $failures[] = $failure;
        }

        return new ComparisonResult($statuses, $failures);
    }

    // ------------------------------------------------------------ preparing

    /**
     * Parse and canonicalise one side; any diagnostic from either step is
     * recorded against it.
     *
     * @param list<string> $diagnostics
     */
    private static function prepare(string $css, string $side, array &$diagnostics): StyleSheet
    {
        $bag = new DiagnosticBag();
        $sheet = Parser::parse($css, $bag);
        $canonical = Canonicalizer::canonical($sheet, $bag);

        foreach ($bag->all() as $diagnostic) {
            $diagnostics[] = \sprintf(
                '%s: %s%s: %s',
                $side,
                $diagnostic->code(),
                $diagnostic->line() === null ? '' : ' line ' . $diagnostic->line(),
                $diagnostic->message()
            );
        }

        return $canonical;
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    private static function unique(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;

            if (!isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    // --------------------------------------------------------------- regions

    /**
     * Split a canonical stylesheet into the regions that are compared.
     *
     * @return array{
     *     theme: array<string, string>,
     *     utilities: list<array{candidates: list<string>, text: string}>,
     *     properties: array<string, string>,
     *     keyframes: array<string, string>,
     *     layers: list<string>,
     *     other: array<string, list<string>>
     * }
     */
    private static function regions(StyleSheet $sheet, CandidateAttribution $attribution): array
    {
        $theme = [];
        $utilities = [];
        $properties = [];
        $keyframes = [];
        $layers = [];
        $other = [];

        foreach ($sheet->nodes as $node) {
            if ($node instanceof AtRule && $node->name === 'property') {
                // Every `@property` of a name is kept, in order: a duplicate must stay visible.
                $properties[$node->params] = ($properties[$node->params] ?? '') . self::text([], $node);

                continue;
            }

            if ($node instanceof AtRule && $node->name === 'keyframes') {
                $keyframes[$node->params] = ($keyframes[$node->params] ?? '') . self::text([], $node);

                continue;
            }

            if ($node instanceof AtRule && $node->name === 'layer') {
                if ($node->nodes === null) {
                    $layers[] = self::text([], $node);

                    continue;
                }

                if ($node->params === 'theme') {
                    foreach ($node->nodes as $child) {
                        if ($child instanceof Rule) {
                            foreach ($child->nodes as $declaration) {
                                if ($declaration instanceof Declaration) {
                                    // Every selector that sets the variable is kept, in order.
                                    $theme[$declaration->property] = ($theme[$declaration->property] ?? '')
                                        . $child->selector . '{' . self::declarationText($declaration) . '}';
                                }
                            }

                            continue;
                        }

                        $other['(layer theme)'][] = self::text([], $child);
                    }

                    continue;
                }

                if ($node->params === 'utilities') {
                    foreach (self::entries($node->nodes, []) as $entry) {
                        $utilities[] = [
                            'candidates' => $attribution->attributeAll($entry['selector']),
                            'text' => $entry['text'],
                        ];
                    }

                    continue;
                }

                $label = $node->params === '' ? '(layer)' : '(layer ' . $node->params . ')';

                foreach (self::entries($node->nodes, []) as $entry) {
                    $other[$label][] = $entry['text'];
                }

                continue;
            }

            foreach (self::entries([$node], []) as $entry) {
                $other['(unlayered)'][] = $entry['text'];
            }
        }

        return [
            'theme' => $theme,
            'utilities' => $utilities,
            'properties' => $properties,
            'keyframes' => $keyframes,
            'layers' => $layers,
            'other' => $other,
        ];
    }

    /**
     * The style rules in a list of nodes, each with the at-rules it sits in
     * written into its text.
     *
     * @param array<array-key, Node> $nodes
     * @param list<AtRule> $wrappers Outermost first.
     *
     * @return list<array{selector: string, text: string}>
     */
    private static function entries(array $nodes, array $wrappers): array
    {
        $entries = [];

        foreach ($nodes as $node) {
            if ($node instanceof Rule) {
                $entries[] = ['selector' => $node->selector, 'text' => self::text($wrappers, $node)];

                continue;
            }

            if ($node instanceof AtRule && $node->nodes !== null && isset(self::GROUPING[$node->name])) {
                $inner = $wrappers;
                $inner[] = new AtRule($node->name, $node->params);

                foreach (self::entries($node->nodes, $inner) as $entry) {
                    $entries[] = $entry;
                }

                continue;
            }

            $entries[] = ['selector' => '', 'text' => self::text($wrappers, $node)];
        }

        return $entries;
    }

    /**
     * The compact CSS of a node inside its wrapping at-rules.
     *
     * @param list<AtRule> $wrappers Outermost first.
     */
    private static function text(array $wrappers, Node $node): string
    {
        $current = $node instanceof Declaration ? new Rule('', [$node]) : $node;

        for ($i = \count($wrappers) - 1; $i >= 0; --$i) {
            $current = new AtRule($wrappers[$i]->name, $wrappers[$i]->params, [$current]);
        }

        return Serializer::compact(new StyleSheet([$current]));
    }

    private static function declarationText(Declaration $declaration): string
    {
        return $declaration->property . ': ' . $declaration->value . ($declaration->important ? ' !important' : '');
    }

    // ------------------------------------------------------------ candidates

    /**
     * One status per candidate; failures are appended to `$failures`.
     *
     * Content is judged per candidate first (missing, extra, different rules, the
     * same rules in a different order among themselves). Order is then judged on
     * the **full sequence** of the utilities layer, authored rules included: a
     * candidate whose rules are split around another rule, or an authored rule
     * moved among utilities, is out of order even when each candidate's own list
     * is unchanged (doc 16 step 4).
     *
     * @param list<string> $candidates
     * @param list<array{candidates: list<string>, text: string}> $expected
     * @param list<array{candidates: list<string>, text: string}> $actual
     * @param list<array{candidate: string, status: string, expected: string, actual: string}> $failures
     *
     * @return array<string, string>
     */
    private function candidateStatuses(array $candidates, array $expected, array $actual, array &$failures): array
    {
        $expectedBy = self::group($expected);
        $actualBy = self::group($actual);
        $statuses = [];
        $sameContent = [];

        foreach ($candidates as $candidate) {
            $e = $expectedBy[$candidate] ?? [];
            $a = $actualBy[$candidate] ?? [];

            if ($e === [] && $a === []) {
                $statuses[$candidate] = ComparisonResult::PASS;
            } elseif ($e === []) {
                $statuses[$candidate] = ComparisonResult::FAIL_EXTRA;
            } elseif ($a === []) {
                $statuses[$candidate] = ComparisonResult::FAIL_MISSING;
            } elseif ($e === $a) {
                $statuses[$candidate] = ComparisonResult::PASS;
                $sameContent[$candidate] = true;
            } elseif (self::sorted($e) === self::sorted($a)) {
                $statuses[$candidate] = ComparisonResult::FAIL_ORDER;
            } else {
                $statuses[$candidate] = ComparisonResult::FAIL_VALUE;
            }
        }

        $authoredExpected = self::authored($expected);
        $authoredActual = self::authored($actual);

        if ($authoredExpected !== [] && $authoredExpected === $authoredActual) {
            $sameContent[self::AUTHORED] = true;
        }

        // Rules whose owner agrees on content must also come in the same order.
        $shouldBe = self::sequence($expected, $sameContent);
        $is = self::sequence($actual, $sameContent);
        $orderFailures = [];

        if (self::keys($shouldBe) !== self::keys($is)) {
            foreach (self::displaced(self::keys($shouldBe), self::keys($is)) as $index) {
                foreach ($is[$index]['labels'] as $label) {
                    if ($label !== self::AUTHORED) {
                        $statuses[$label] = ComparisonResult::FAIL_ORDER;

                        continue;
                    }

                    $at = $is[$index]['authored'];
                    $orderFailures[] = self::failure(
                        self::AUTHORED . ' #' . $at,
                        ComparisonResult::FAIL_ORDER,
                        $authoredExpected[$at] ?? '',
                        $authoredActual[$at] ?? ''
                    );
                }
            }
        }

        foreach ($statuses as $candidate => $status) {
            if ($status === ComparisonResult::PASS) {
                continue;
            }

            $failures[] = self::failure(
                (string) $candidate,
                $status,
                \implode('', $expectedBy[$candidate] ?? []),
                \implode('', $actualBy[$candidate] ?? [])
            );
        }

        foreach ($orderFailures as $failure) {
            $failures[] = $failure;
        }

        return $statuses;
    }

    /**
     * The rules of each candidate, in the order they appear. A rule whose
     * selector names several candidates belongs to each of them; a rule that
     * names none is left out here.
     *
     * @param list<array{candidates: list<string>, text: string}> $entries
     *
     * @return array<string, list<string>>
     */
    private static function group(array $entries): array
    {
        $by = [];

        foreach ($entries as $entry) {
            foreach ($entry['candidates'] as $candidate) {
                $by[$candidate][] = $entry['text'];
            }
        }

        return $by;
    }

    /**
     * The utilities layer as a sequence of rules, each identified by its owners
     * and its text (and how many identical ones came before it), keeping only
     * the rules whose owners are all in `$keep`.
     *
     * @param list<array{candidates: list<string>, text: string}> $entries
     * @param array<string, true> $keep
     *
     * @return list<array{key: string, labels: list<string>, authored: int}>
     */
    private static function sequence(array $entries, array $keep): array
    {
        $sequence = [];
        $seen = [];
        $authored = 0;

        foreach ($entries as $entry) {
            $labels = $entry['candidates'] === [] ? [self::AUTHORED] : $entry['candidates'];
            $index = $entry['candidates'] === [] ? $authored++ : -1;

            foreach ($labels as $label) {
                if (!isset($keep[$label])) {
                    continue 2;
                }
            }

            $key = \implode("\0", $labels) . "\0" . $entry['text'];
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            $sequence[] = ['key' => $key . "\0" . $seen[$key], 'labels' => $labels, 'authored' => $index];
        }

        return $sequence;
    }

    /**
     * @param list<array{key: string, labels: list<string>, authored: int}> $sequence
     *
     * @return list<string>
     */
    private static function keys(array $sequence): array
    {
        $keys = [];

        foreach ($sequence as $item) {
            $keys[] = $item['key'];
        }

        return $keys;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<string>
     */
    private static function sorted(array $texts): array
    {
        \usort($texts, 'strcmp');

        return $texts;
    }

    /**
     * The positions in the engine's sequence that are out of place: those not on
     * a longest increasing run of the engine's order measured against the
     * golden's, plus any rule the golden has no counterpart for. Moving one rule
     * far away reports that rule, not everything it passed; for an adjacent
     * swap, the one that should come second but comes first is reported; for a
     * rule split around another, the later part is reported.
     *
     * @param list<string> $shouldBe The golden's keys, in order.
     * @param list<string> $is The engine's keys, in order.
     *
     * @return list<int> Indexes into `$is`, ascending.
     */
    private static function displaced(array $shouldBe, array $is): array
    {
        $rank = [];

        foreach ($shouldBe as $index => $key) {
            $rank[$key] = $index;
        }

        $positions = [];
        $values = [];

        foreach ($is as $i => $key) {
            if (isset($rank[$key])) {
                $positions[] = $i;
                $values[] = $rank[$key];
            }
        }

        $tails = [];
        $previous = [];

        foreach ($values as $i => $value) {
            $low = 0;
            $high = \count($tails);

            while ($low < $high) {
                $middle = \intdiv($low + $high, 2);

                if ($values[$tails[$middle]] < $value) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            $previous[$i] = $low > 0 ? $tails[$low - 1] : -1;
            $tails[$low] = $i;
        }

        $keep = [];
        $at = $tails === [] ? -1 : $tails[\count($tails) - 1];

        while ($at !== -1) {
            $keep[$positions[$at]] = true;
            $at = $previous[$at];
        }

        $out = [];

        foreach (\array_keys($is) as $i) {
            if (!isset($keep[$i])) {
                $out[] = $i;
            }
        }

        return $out;
    }

    // --------------------------------------------------------------- regions

    /**
     * Failures in the regions no candidate owns.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     *
     * @return list<array{candidate: string, status: string, expected: string, actual: string}>
     */
    private function regionFailures(array $expected, array $actual): array
    {
        $failures = [];

        foreach (self::compareSets('(theme)', $expected['theme'], $actual['theme']) as $failure) {
            $failures[] = $failure;
        }

        foreach (self::compareSets('(property)', $expected['properties'], $actual['properties']) as $failure) {
            $failures[] = $failure;
        }

        foreach (self::compareSets('(keyframes)', $expected['keyframes'], $actual['keyframes']) as $failure) {
            $failures[] = $failure;
        }

        foreach (self::compareLists('(layers)', $expected['layers'], $actual['layers']) as $failure) {
            $failures[] = $failure;
        }

        $labels = \array_keys($expected['other'] + $actual['other']);
        \usort($labels, 'strcmp');

        foreach ($labels as $label) {
            $label = (string) $label;

            $expectedRules = $expected['other'][$label] ?? [];
            $actualRules = $actual['other'][$label] ?? [];

            foreach (self::compareLists($label, $expectedRules, $actualRules) as $failure) {
                $failures[] = $failure;
            }
        }

        foreach (
            self::compareLists(
                self::AUTHORED,
                self::authored($expected['utilities']),
                self::authored($actual['utilities'])
            ) as $failure
        ) {
            $failures[] = $failure;
        }

        return $failures;
    }

    /**
     * The utilities-layer rules no candidate accounts for, in order.
     *
     * @param list<array{candidates: list<string>, text: string}> $entries
     *
     * @return list<string>
     */
    private static function authored(array $entries): array
    {
        $texts = [];

        foreach ($entries as $entry) {
            if ($entry['candidates'] === []) {
                $texts[] = $entry['text'];
            }
        }

        return $texts;
    }

    /**
     * A set compared by key: a key on one side only is missing or extra; on
     * both with different text, a value failure.
     *
     * @param array<string, string> $expected
     * @param array<string, string> $actual
     *
     * @return list<array{candidate: string, status: string, expected: string, actual: string}>
     */
    private static function compareSets(string $label, array $expected, array $actual): array
    {
        $failures = [];

        foreach ($expected as $key => $text) {
            $key = (string) $key;

            if (!isset($actual[$key])) {
                $failures[] = self::failure($label . ' ' . $key, ComparisonResult::FAIL_MISSING, $text, '');
            } elseif ($actual[$key] !== $text) {
                $failures[] = self::failure($label . ' ' . $key, ComparisonResult::FAIL_VALUE, $text, $actual[$key]);
            }
        }

        foreach ($actual as $key => $text) {
            $key = (string) $key;

            if (!isset($expected[$key])) {
                $failures[] = self::failure($label . ' ' . $key, ComparisonResult::FAIL_EXTRA, '', $text);
            }
        }

        return $failures;
    }

    /**
     * A list compared position by position.
     *
     * @param list<string> $expected
     * @param list<string> $actual
     *
     * @return list<array{candidate: string, status: string, expected: string, actual: string}>
     */
    private static function compareLists(string $label, array $expected, array $actual): array
    {
        $failures = [];

        for ($i = 0, $n = \max(\count($expected), \count($actual)); $i < $n; ++$i) {
            $e = $expected[$i] ?? null;
            $a = $actual[$i] ?? null;

            if ($e === $a) {
                continue;
            }

            if ($a === null) {
                $failures[] = self::failure($label . ' #' . $i, ComparisonResult::FAIL_MISSING, (string) $e, '');
            } elseif ($e === null) {
                $failures[] = self::failure($label . ' #' . $i, ComparisonResult::FAIL_EXTRA, '', $a);
            } else {
                $failures[] = self::failure($label . ' #' . $i, ComparisonResult::FAIL_VALUE, $e, $a);
            }
        }

        return $failures;
    }

    /**
     * @return array{candidate: string, status: string, expected: string, actual: string}
     */
    private static function failure(string $candidate, string $status, string $expected, string $actual): array
    {
        return ['candidate' => $candidate, 'status' => $status, 'expected' => $expected, 'actual' => $actual];
    }
}
