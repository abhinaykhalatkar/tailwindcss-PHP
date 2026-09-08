<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps every record so a test can assert what the
 * library said — and, through the `code` context key every library log line
 * carries, which diagnostic it said it for.
 *
 * `log()` leaves `$message` untyped on purpose: psr/log 1.x declares it
 * untyped and 3.x as `string|\Stringable`; an untyped override satisfies both,
 * which keeps this double valid on the PHP 7.3 floor and on 8.x alike.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Whether a line at $level carried the diagnostic $code in its context.
     */
    public function has(string $level, string $code): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && ($record['context']['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every message logged at $level, in order.
     *
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];

        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $messages[] = $record['message'];
            }
        }

        return $messages;
    }
}
