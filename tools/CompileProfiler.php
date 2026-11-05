<?php

declare(strict_types=1);

namespace TailwindPhp\Tools;

use TailwindPhp\Engine\Candidate\Parser as CandidateParser;
use TailwindPhp\Engine\Compiler;
use TailwindPhp\Engine\CompileRequest;
use TailwindPhp\Engine\Css\Escape;
use TailwindPhp\Engine\Css\Nesting;
use TailwindPhp\Engine\Css\Node\AtRule;
use TailwindPhp\Engine\Css\Node\Declaration;
use TailwindPhp\Engine\Css\Node\Node;
use TailwindPhp\Engine\Css\Node\Rule;
use TailwindPhp\Engine\Css\Parser as CssParser;
use TailwindPhp\Engine\Css\Serializer;
use TailwindPhp\Engine\Data\Variants;
use TailwindPhp\Engine\Diagnostic\DiagnosticBag;
use TailwindPhp\Engine\Directive\ProcessedSheet;
use TailwindPhp\Engine\Directive\Processor;
use TailwindPhp\Engine\Directive\ProcessorOptions;
use TailwindPhp\Engine\Output\ApplyResolver;
use TailwindPhp\Engine\Output\Assembler;
use TailwindPhp\Engine\Output\SortKey;
use TailwindPhp\Engine\Theme\ThemeRegistry;
use TailwindPhp\Engine\Utility\Compiler as UtilityCompiler;
use TailwindPhp\Engine\Utility\Registry as UtilityRegistry;
use TailwindPhp\Engine\Variant\Applier;
use TailwindPhp\Engine\Variant\Registry as VariantRegistry;
use TailwindPhp\Tests\Benchmark\Median;
use TailwindPhp\Tests\Conformance\Support\CorpusPaths;
use TailwindPhp\Tests\Support\InMemoryImportResolver;

/**
 * Where one engine compile spends its time (design doc 13 §Profiling, TASK-0041):
 * `php tools/profile-compile.php <corpus-key> [--runs=N] [--minify]`.
 *
 * `Engine\Compiler` has no phase hooks and this tool adds none to `src/Engine`,
 * so the phases are timed on the profiler's **own run of the compiler's public
 * steps**, in the compiler's order: the registries, the parse, the directives
 * (with the final variant registry), `@apply`, the candidate loop (parse,
 * compile, prefix, variants, sort key), the sort, the assembly, the flattening
 * and the serialisation. Each run also compiles the same request through
 * `Engine\Compiler` itself, times that as a whole, and compares the two
 * stylesheets byte for byte: the table says whether the phases reproduced the
 * engine's output, so a timing is never quoted for a run that did something
 * else. Like the compiler, the candidate loop flattens each utility as it goes
 * (`Nesting::flattenCharged()`) and the final flatten writes those nodes as they
 * are, so the phase split matches the engine's. The run omits the compiler's
 * node and byte rooms (T-05), which only matter past a hundred thousand nodes
 * or four mebibytes of selectors; no corpus entry comes near them, and the
 * byte comparison would show the day one did.
 *
 * Inputs are corpus entries only (T-01): a key is looked up in `CorpusPaths`'
 * listing, never joined to a path, and an error names no path. The runs are
 * bounded (at most {@see self::MAX_RUNS}). Nothing is written. Timings use
 * `hrtime()` with the cycle collector paused, after one untimed warm-up.
 *
 * No regular expressions (R1). Lives under `tools/`: maintainer tooling, not
 * autoloaded, not shipped.
 *
 * @phpstan-type Profile array{
 *     key: string,
 *     runs: int,
 *     minify: bool,
 *     candidates: int,
 *     utilities: int,
 *     diagnostics: int,
 *     phases: array<string, list<float>>,
 *     compile: list<float>,
 *     peakBytes: int,
 *     faithful: bool
 * }
 */
final class CompileProfiler
{
    /**
     * The phases, in the order `Engine\Compiler` runs them.
     */
    public const PHASES = [
        'setup',
        'parse',
        'directives',
        'apply',
        'candidates',
        'sort',
        'assemble',
        'flatten',
        'serialize',
    ];

    public const DEFAULT_RUNS = 5;

    public const MAX_RUNS = 50;

    /**
     * The preamble the goldens' candidate lists are compiled under (ENG-A7).
     */
    private const PREAMBLE = "@import \"tailwindcss\" source(none);\n";

    /**
     * @var CorpusPaths
     */
    private $paths;

    public function __construct(CorpusPaths $paths)
    {
        $this->paths = $paths;
    }

    /**
     * @param list<string> $arguments The command line after the script name.
     *
     * @throws \InvalidArgumentException On anything but one key and the two options.
     *
     * @return array{key: string, runs: int, minify: bool}
     */
    public static function parseArguments(array $arguments): array
    {
        $key = null;
        $runs = self::DEFAULT_RUNS;
        $minify = false;

        foreach ($arguments as $argument) {
            if ($argument === '--minify') {
                $minify = true;

                continue;
            }

            if (\strncmp($argument, '--runs=', 7) === 0) {
                $runs = self::runs((string) \substr($argument, 7));

                continue;
            }

            if (\strncmp($argument, '--', 2) === 0 || $key !== null) {
                throw new \InvalidArgumentException(\sprintf('Unexpected argument "%s".', \substr($argument, 0, 40)));
            }

            $key = $argument;
        }

        if ($key === null) {
            throw new \InvalidArgumentException('Name one corpus entry, such as utilities/layout.');
        }

        return ['key' => $key, 'runs' => $runs, 'minify' => $minify];
    }

    /**
     * The corpus entries that are compiles: every candidate list and every
     * directive entry (an extractor fixture is a scan, not a compile).
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->paths->keys() as $key) {
            if ($this->paths->kind($key) !== CorpusPaths::KIND_EXTRACTOR) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The request the goldens were generated from: a candidate list under the
     * goldens' preamble, or a directive entry with its candidates and every
     * partial beside it.
     *
     * @throws \InvalidArgumentException When `$key` is not a compile in the listing.
     */
    public function request(string $key, bool $minify): CompileRequest
    {
        if (!$this->paths->has($key)) {
            throw new \InvalidArgumentException(\sprintf(
                '"%s" is not an entry of the conformance corpus.',
                Escape::string(\substr($key, 0, 60))
            ));
        }

        $kind = $this->paths->kind($key);

        if ($kind === CorpusPaths::KIND_EXTRACTOR) {
            throw new \InvalidArgumentException(\sprintf('"%s" is an extractor fixture, not a compile.', $key));
        }

        if ($kind === CorpusPaths::KIND_CANDIDATES) {
            return new CompileRequest(
                self::PREAMBLE,
                new InMemoryImportResolver([]),
                CorpusPaths::parseCandidates(self::read($this->paths->sourcePath($key))),
                $minify
            );
        }

        $partials = [];

        foreach ($this->paths->partials() as $file => $path) {
            $partials[$file] = self::read($path);
        }

        $candidatesPath = (string) $this->paths->candidatesPath($key);

        return new CompileRequest(
            self::read($this->paths->sourcePath($key)),
            new InMemoryImportResolver($partials),
            CorpusPaths::parseCandidates(self::read($candidatesPath)),
            $minify,
            true,
            CompileRequest::DEFAULT_MAX_CANDIDATES,
            \basename($this->paths->sourcePath($key))
        );
    }

    /**
     * Profile `$runs` compiles of one corpus entry.
     *
     * @throws \InvalidArgumentException On an unknown key or runs outside 1 … MAX_RUNS.
     *
     * @return Profile
     */
    public function profile(string $key, int $runs, bool $minify): array
    {
        if ($runs < 1 || $runs > self::MAX_RUNS) {
            throw new \InvalidArgumentException(\sprintf('Runs must be between 1 and %d.', self::MAX_RUNS));
        }

        $request = $this->request($key, $minify);
        $phases = \array_fill_keys(self::PHASES, []);
        $compile = [];
        $faithful = true;
        $utilities = 0;
        $diagnostics = 0;
        $collecting = \gc_enabled();

        \gc_disable();

        try {
            // One untimed warm-up: autoloading and the first-use costs.
            self::phased($request);
            (new Compiler())->compile($request);

            if (\function_exists('memory_reset_peak_usage')) {
                \memory_reset_peak_usage();
            }

            for ($run = 0; $run < $runs; ++$run) {
                [$timings, $css] = self::phased($request);

                foreach ($timings as $phase => $seconds) {
                    $phases[$phase][] = $seconds;
                }

                $started = self::now();
                $output = (new Compiler())->compile($request);
                $compile[] = self::now() - $started;
                $faithful = $faithful && $css === $output->css;
                $utilities = $output->stats['utilities'];
                $diagnostics = \count($output->diagnostics->all());
            }
        } finally {
            if ($collecting) {
                \gc_enable();
            }
        }

        return [
            'key' => $key,
            'runs' => $runs,
            'minify' => $minify,
            'candidates' => \count($request->candidates),
            'utilities' => $utilities,
            'diagnostics' => $diagnostics,
            'phases' => $phases,
            'compile' => $compile,
            'peakBytes' => \memory_get_peak_usage(),
            'faithful' => $faithful,
        ];
    }

    /**
     * The table `profile-compile.php` prints.
     *
     * @param Profile $profile
     */
    public static function render(array $profile): string
    {
        $runs = $profile['runs'];
        $sums = [];

        for ($run = 0; $run < $runs; ++$run) {
            $sum = 0.0;

            foreach ($profile['phases'] as $samples) {
                $sum += $samples[$run] ?? 0.0;
            }

            $sums[] = $sum;
        }

        $total = Median::of($sums);
        $out = \sprintf(
            "Profile of %s: %s, %s output, %s, %s, %s.\n\n",
            $profile['key'],
            self::counted($runs, 'run', 'runs'),
            $profile['minify'] ? 'minified' : 'pretty',
            self::counted($profile['candidates'], 'candidate', 'candidates'),
            self::counted($profile['utilities'], 'utility', 'utilities'),
            self::counted($profile['diagnostics'], 'diagnostic', 'diagnostics')
        );
        $out .= \sprintf("%-20s %10s %11s %8s\n", 'phase', 'best ms', 'median ms', 'share');

        foreach ($profile['phases'] as $phase => $samples) {
            $median = Median::of($samples);
            $out .= \sprintf(
                "%-20s %10.2f %11.2f %7.1f%%\n",
                $phase,
                self::best($samples) * 1000,
                $median * 1000,
                $total > 0.0 ? $median / $total * 100 : 0.0
            );
        }

        $out .= \sprintf(
            "%-20s %10.2f %11.2f %7.1f%%\n",
            'sum of phases',
            self::best($sums) * 1000,
            $total * 1000,
            100.0
        );
        $out .= \sprintf(
            "%-20s %10.2f %11.2f\n\n",
            'Engine\\Compiler',
            self::best($profile['compile']) * 1000,
            Median::of($profile['compile']) * 1000
        );
        $out .= \sprintf("Peak memory: %.1f MB.\n", $profile['peakBytes'] / 1048576);
        $out .= "Engine\\Compiler has no phase hooks, so the phases are this profiler's own run of its public steps;\n";
        $out .= $profile['faithful']
            ? "that run produced Engine\\Compiler's output byte for byte."
            : "that run's output DIFFERED from Engine\\Compiler's, so the phase timings are approximate.";

        return $out . " Best and median are over the runs after one warm-up, the GC paused.\n";
    }

    /**
     * One compile, step by step as `Engine\Compiler::compile()` takes them,
     * and the seconds each phase took.
     *
     * @return array{0: array<string, float>, 1: string}
     */
    private static function phased(CompileRequest $request): array
    {
        $timings = [];
        $mark = self::now();

        $bag = new DiagnosticBag();
        $theme = new ThemeRegistry();
        $utilities = UtilityRegistry::default();
        $baseVariants = new VariantRegistry(Variants::table());
        $timings['setup'] = self::lap($mark);

        $entry = CssParser::parse($request->entryCss, $bag);
        $timings['parse'] = self::lap($mark);

        $sheet = Processor::process(
            $entry,
            $request->imports,
            $theme,
            $utilities,
            $baseVariants,
            new ProcessorOptions($request->entryPath, \strlen($request->entryCss), $request->preflight),
            $bag
        );
        $variants = new VariantRegistry(Variants::table(), $sheet->breakpoints);

        foreach ($sheet->customVariants as $name => $definition) {
            $variants->addCustom((string) $name, $definition);
        }

        $timings['directives'] = self::lap($mark);

        $parser = new CandidateParser($utilities, $sheet->prefix, $variants);
        $compiler = new UtilityCompiler($utilities);
        $applier = new Applier($variants, new DiagnosticBag());
        $applies = new ApplyResolver($parser, $compiler, $applier, $theme, $sheet->prefix, $sheet->breakpoints);
        $applies->resolve($sheet->applies, $bag);
        $timings['apply'] = self::lap($mark);

        $entries = [];
        $used = [];
        /** @var \SplObjectStorage<Node, mixed> $flatSet */
        $flatSet = new \SplObjectStorage();
        $flatBytes = 0;

        foreach (self::candidates($request, $sheet) as $raw) {
            $readings = $parser->parse($raw);
            $compiled = $readings === [] ? null : $compiler->compileReadings($raw, $readings, $theme);

            if ($compiled === null) {
                continue;
            }

            $nodes = Assembler::prefixed($compiled->nodes, $sheet->prefix, $theme);

            if ($sheet->important) {
                $nodes = self::important($nodes);
            }

            $wrapped = $applier->wrap($nodes, $readings[0]->variants, '.' . Escape::identifier($raw));

            if ($wrapped === null) {
                continue;
            }

            // Flattened here, as the compiler does to learn the byte charge;
            // the final flatten then writes these nodes as they are.
            [$flatNodes, $bytes] = Nesting::flattenCharged($wrapped->nodes());
            $flatBytes += $bytes;

            foreach ($flatNodes as $node) {
                $flatSet->attach($node);
            }

            foreach ($compiled->usedVars as $name) {
                $used[$name] = true;
            }

            $entries[] = [
                SortKey::of(
                    $readings[0]->variants,
                    $wrapped->variantOrders(),
                    $compiled->sortKey,
                    ApplyResolver::declarations($compiled->nodes),
                    $raw,
                    $sheet->breakpoints
                ),
                $flatNodes,
                $compiled->properties,
                $wrapped->properties(),
            ];
        }

        $timings['candidates'] = self::lap($mark);

        \usort($entries, static function (array $a, array $b): int {
            return SortKey::compare($a[0], $b[0]);
        });
        $timings['sort'] = self::lap($mark);

        $rules = [];
        $properties = [];
        // First registration wins: a variant's first registration takes the
        // CLI's other `@property` descriptor order, as in the compiler.
        $registeredBy = [];

        foreach ($entries as [, $nodes, $byUtility, $byVariant]) {
            foreach ($nodes as $node) {
                $rules[] = $node;
            }

            foreach ([[$byUtility, false], [$byVariant, true]] as [$registered, $variant]) {
                foreach ($registered as $property) {
                    $properties[] = $property;
                    $registeredBy[$property] = $registeredBy[$property] ?? $variant;
                }
            }
        }

        foreach (\array_merge(\array_keys($used), $applies->usedVars()) as $name) {
            $theme->markUsed((string) $name);
        }

        foreach ($applies->registrations() as [$property, $variant]) {
            $properties[] = $property;
            $registeredBy[$property] = $registeredBy[$property] ?? $variant;
        }

        $variantFirst = [];

        foreach ($registeredBy as $property => $variant) {
            if ($variant) {
                $variantFirst[] = (string) $property;
            }
        }

        $assembled = Assembler::assemble($sheet, $theme, $rules, $properties, $variantFirst);
        $timings['assemble'] = self::lap($mark);

        $flat = Nesting::flatten($assembled, $bag, $flatSet, $flatBytes);
        $timings['flatten'] = self::lap($mark);

        $css = $request->minify ? Serializer::compact($flat) : Serializer::pretty($flat);
        $timings['serialize'] = self::lap($mark);

        return [$timings, $css];
    }

    /**
     * The request's candidates and the `@source inline()` ones, each once,
     * without the excluded ones, at most `maxCandidates` — as the compiler
     * takes them.
     *
     * @return list<string>
     */
    private static function candidates(CompileRequest $request, ProcessedSheet $sheet): array
    {
        $excluded = \array_flip($sheet->excludedCandidates);
        $seen = [];
        $out = [];

        foreach ([$request->candidates, $sheet->inlineCandidates] as $list) {
            foreach ($list as $raw) {
                if (isset($seen[$raw]) || isset($excluded[$raw]) || \count($out) >= $request->maxCandidates) {
                    continue;
                }

                $seen[$raw] = true;
                $out[] = $raw;
            }
        }

        return $out;
    }

    /**
     * $nodes with every declaration `!important`, as the compiler marks them
     * under the import's `important` flag.
     *
     * @param list<Node> $nodes
     *
     * @return list<Node>
     */
    private static function important(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if ($node instanceof Declaration) {
                $out[] = new Declaration($node->property, $node->value, true, $node->line, $node->col);
            } elseif ($node instanceof Rule) {
                $out[] = new Rule($node->selector, self::important($node->nodes), $node->line, $node->col);
            } elseif ($node instanceof AtRule && $node->nodes !== null) {
                $out[] = new AtRule($node->name, $node->params, self::important($node->nodes), $node->line, $node->col);
            } else {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @throws \InvalidArgumentException When the value is not a whole number in 1 … MAX_RUNS.
     */
    private static function runs(string $value): int
    {
        if ($value === '' || \strspn($value, '0123456789') !== \strlen($value) || \strlen($value) > 3) {
            throw new \InvalidArgumentException('--runs takes a whole number.');
        }

        $runs = (int) $value;

        if ($runs < 1 || $runs > self::MAX_RUNS) {
            throw new \InvalidArgumentException(\sprintf('--runs must be between 1 and %d.', self::MAX_RUNS));
        }

        return $runs;
    }

    /**
     * Seconds since `$mark`, and `$mark` moved to now.
     */
    private static function lap(float &$mark): float
    {
        $now = self::now();
        $elapsed = $now - $mark;
        $mark = $now;

        return $elapsed;
    }

    private static function counted(int $count, string $one, string $many): string
    {
        return $count . ' ' . ($count === 1 ? $one : $many);
    }

    private static function now(): float
    {
        return \hrtime(true) / 1e9;
    }

    /**
     * @param list<float> $samples
     */
    private static function best(array $samples): float
    {
        return $samples === [] ? 0.0 : (float) \min($samples);
    }

    private static function read(string $path): string
    {
        $contents = \is_file($path) ? \file_get_contents($path) : false;

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('Cannot read the corpus file %s.', \basename($path)));
        }

        return \str_replace("\r\n", "\n", $contents);
    }
}
