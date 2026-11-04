<?php

/**
 * The known-differences allow-list (design doc 08 §Known differences policy;
 * decisions ENG-A9, ENG-A12): every corpus case the engine is allowed to get
 * wrong, with the reason.
 *
 * Plain data, no code: `tools/KnownDifferences` refuses a file that holds anything
 * but literals. Keep it honest: `ConformanceTest` fails both when a listed case
 * starts to pass (remove the entry) and when a case fails that is not listed. The
 * Markdown report `docs/compat/KNOWN-DIFFERENCES.md` is generated from this file
 * with `php tools/known-differences.php`.
 *
 * Fields: candidate (raw), corpus (`<group>/<name>`), class (`deliberate` | `bug` |
 * `upstream`), reason (one sentence), since (the pinned CLI release).
 */

declare(strict_types=1);

return [
    // T-15: what the CLI emits and the engine refuses by design (ArbitraryValue::validate).
    [
        'candidate' => 'bg-[url(javascript:alert(1))]',
        'corpus' => 'utilities/edge-rejections',
        'class' => 'deliberate',
        'reason' => 'The CLI keeps a javascript: URL in background-image; the engine refuses it (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'p-[1px/*x*/]',
        'corpus' => 'utilities/edge-rejections',
        'class' => 'deliberate',
        'reason' => 'The CLI keeps the comment in padding: 1px/*x*/; the engine refuses it (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'text-[javascript:alert(1)]',
        'corpus' => 'utilities/edge-rejections',
        'class' => 'deliberate',
        'reason' => 'The CLI reads javascript as a type hint and emits color: alert(1); refused (T-15).',
        'since' => '4.1.18',
    ],

    // Variants the CLI accepts that the engine does not build (TASK-0031 review). All ten
    // candidates are in corpus/variants/compat-gaps.txt; VariantGoldenTest skips them and
    // asserts that the applier really refuses each one.
    [
        'candidate' => '[@layer_x]:flex',
        'corpus' => 'variants/compat-gaps',
        'class' => 'deliberate',
        'reason' => 'The CLI accepts any at-rule here; the engine allows only grouping ones (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => '[@layer_x]:underline',
        'corpus' => 'variants/compat-gaps',
        'class' => 'deliberate',
        'reason' => 'The CLI accepts any at-rule here; the engine allows only grouping ones (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => '[@import_url(x)]:flex',
        'corpus' => 'variants/compat-gaps',
        'class' => 'deliberate',
        'reason' => 'The CLI emits @import around the rule; the engine refuses a non-grouping at-rule (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => '[@import_url(x)]:underline',
        'corpus' => 'variants/compat-gaps',
        'class' => 'deliberate',
        'reason' => 'The CLI emits @import around the rule; the engine refuses a non-grouping at-rule (T-15).',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'group-group-hover:flex',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI nests a compound in a compound; the engine compounds pseudo-classes only until M3.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'group-group-hover:underline',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI nests a compound in a compound; the engine compounds pseudo-classes only until M3.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'group-[.x]:flex',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI accepts group-[selector]; arbitrary compound variants come with M3.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'group-[.x]:underline',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI accepts group-[selector]; arbitrary compound variants come with M3.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'peer-[.x]:flex',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI accepts peer-[selector]; arbitrary compound variants come with M3.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'peer-[.x]:underline',
        'corpus' => 'variants/compat-gaps',
        'class' => 'bug',
        'reason' => 'The CLI accepts peer-[selector]; arbitrary compound variants come with M3.',
        'since' => '4.1.18',
    ],

    // CLI behaviour a data row cannot say (TASK-0042). All four candidates are in corpus/utilities/edge-quirks.txt.
    [
        'candidate' => 'stroke-1/2',
        'corpus' => 'utilities/edge-quirks',
        'class' => 'bug',
        'reason' => 'The CLI reads a whole-number stroke width with a modifier and drops it; no row can say that.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'stroke-2/50',
        'corpus' => 'utilities/edge-quirks',
        'class' => 'bug',
        'reason' => 'The CLI reads a whole-number stroke width with a modifier and drops it; no row can say that.',
        'since' => '4.1.18',
    ],
    [
        'candidate' => 'bg-[size:10px]',
        'corpus' => 'utilities/edge-quirks',
        'class' => 'bug',
        'reason' => 'The CLI reads the size hint as background-size; the engine has no size type and writes a colour.',
        'since' => '4.1.18',
    ],
];
