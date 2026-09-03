<?php

declare(strict_types=1);

namespace TailwindPhp\PreProcessor;

/**
 * The default pre-processor: hands the entry text straight back (design doc 06
 * §Optional pre-processor stage).
 *
 * A null object rather than a nullable pre-processor, so the pipeline has one
 * code path — `process()` is always called, and a plain `.css` entry costs
 * exactly one method call. It reports no imports because it opened no files.
 */
final class NullPreProcessor implements PreProcessorInterface
{
    public function process(string $entryText, string $entryRelPath): PreProcessResult
    {
        return new PreProcessResult($entryText, []);
    }
}
