<?php

declare(strict_types=1);

namespace TailwindPhp\Exception;

/**
 * Bad configuration (design doc 15 §Exception hierarchy).
 *
 * Thrown by the config builder once validation has finished, carrying *every*
 * problem found rather than the first, so a user fixes their config in one
 * round rather than one error per run (design doc 11 §Resolution and validation).
 *
 * Not final: {@see PathException} specialises it for confinement failures.
 */
class ConfigurationException extends \InvalidArgumentException implements TailwindPhpException
{
    use ExceptionContext;

    /**
     * @var list<string>
     */
    private $problems;

    /**
     * @param list<string> $problems One human-readable sentence per problem.
     */
    public function __construct(array $problems, ?\Throwable $previous = null)
    {
        // Scrubbed at the boundary: a config problem sentence routinely quotes
        // the path it is complaining about (threat T-06).
        $scrubbed = [];

        foreach (\array_values($problems) as $problem) {
            $scrubbed[] = self::scrubText($problem);
        }

        $this->problems = $scrubbed;

        parent::__construct(self::describe($this->problems), 0, $previous);

        $this->initContext(['problems' => $this->problems]);
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * @param list<string> $problems
     */
    private static function describe(array $problems): string
    {
        if ($problems === []) {
            return 'Invalid configuration.';
        }

        if (\count($problems) === 1) {
            return 'Invalid configuration: ' . $problems[0];
        }

        return 'Invalid configuration:' . "\n  - " . \implode("\n  - ", $problems);
    }
}
