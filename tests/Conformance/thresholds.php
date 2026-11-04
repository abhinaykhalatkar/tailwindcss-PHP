<?php

/**
 * Minimum conformance pass rate per tier (design doc 08 §Acceptance thresholds,
 * design doc 16 §Thresholds and reporting).
 *
 * T1 is the 1.0 blocker (M2); T2 is the 1.0 target (M3). `ConformanceTest`
 * asserts the measured rate against these.
 *
 * **Values only ever go up.** A ratchet that can be lowered is a number that
 * drifts toward whatever the current engine happens to score; a regression is
 * fixed or recorded in KNOWN-DIFFERENCES, never absorbed here.
 */

declare(strict_types=1);

return [
    'T1' => 0.98,
    'T2' => 0.90,
];
