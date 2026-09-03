<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Integration\Support\Logging;

use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use TailwindPhp\Support\Logging\ErrorLogLogger;

/**
 * Integration rather than unit: the logger's whole job is the side effect on
 * `error_log`, so the test redirects that to a temp file and reads it back
 * (design doc 15 §Testing).
 */
final class ErrorLogLoggerTest extends TestCase
{
    /**
     * @var string
     */
    private $logFile = '';

    /**
     * @var string|false
     */
    private $previousErrorLog = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = \sys_get_temp_dir() . '/tailwindphp-log-' . \bin2hex(\random_bytes(6)) . '.log';
        $this->previousErrorLog = \ini_get('error_log');

        \ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        if ($this->previousErrorLog !== false) {
            \ini_set('error_log', $this->previousErrorLog);
        }

        if ($this->logFile !== '' && \is_file($this->logFile)) {
            \unlink($this->logFile);
        }

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ErrorLogLogger::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function itIsAPsrLogger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new ErrorLogLogger());
    }

    /** @test */
    public function aWarningIsWrittenInTheExpectedFormat(): void
    {
        (new ErrorLogLogger())->warning('cache directory is not writable');

        self::assertSame(
            '[tailwindcss-php] WARNING cache directory is not writable',
            $this->lastLine()
        );
    }

    /**
     * @test
     *
     * @dataProvider forwardedLevels
     */
    public function warningAndAboveAreForwarded(string $level, string $expectedLabel): void
    {
        (new ErrorLogLogger())->log($level, 'boom');

        self::assertSame('[tailwindcss-php] ' . $expectedLabel . ' boom', $this->lastLine());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forwardedLevels(): array
    {
        return [
            'emergency' => [LogLevel::EMERGENCY, 'EMERGENCY'],
            'alert' => [LogLevel::ALERT, 'ALERT'],
            'critical' => [LogLevel::CRITICAL, 'CRITICAL'],
            'error' => [LogLevel::ERROR, 'ERROR'],
            'warning' => [LogLevel::WARNING, 'WARNING'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider droppedLevels
     */
    public function noticeAndBelowAreDropped(string $level): void
    {
        (new ErrorLogLogger())->log($level, 'quiet');

        self::assertSame('', $this->contents());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function droppedLevels(): array
    {
        return [
            'notice' => [LogLevel::NOTICE],
            'info' => [LogLevel::INFO],
            'debug' => [LogLevel::DEBUG],
        ];
    }

    /** @test */
    public function theThresholdCanBeLowered(): void
    {
        (new ErrorLogLogger(LogLevel::DEBUG))->debug('now visible');

        self::assertSame('[tailwindcss-php] DEBUG now visible', $this->lastLine());
    }

    /** @test */
    public function theThresholdCanBeRaised(): void
    {
        $logger = new ErrorLogLogger(LogLevel::CRITICAL);

        $logger->error('dropped');

        self::assertSame('', $this->contents());

        $logger->critical('kept');

        self::assertSame('[tailwindcss-php] CRITICAL kept', $this->lastLine());
    }

    /** @test */
    public function placeholdersAreInterpolatedFromTheContext(): void
    {
        (new ErrorLogLogger())->error('compile failed for {bundle} in {ms} ms', [
            'bundle' => 'styles',
            'ms' => 12,
        ]);

        self::assertSame('[tailwindcss-php] ERROR compile failed for styles in 12 ms', $this->lastLine());
    }

    /** @test */
    public function anUnmatchedPlaceholderIsLeftAlone(): void
    {
        (new ErrorLogLogger())->error('missing {nope}', ['other' => 'x']);

        self::assertSame('[tailwindcss-php] ERROR missing {nope}', $this->lastLine());
    }

    /** @test */
    public function anObjectWithToStringIsInterpolated(): void
    {
        $stringable = new class () {
            public function __toString(): string
            {
                return 'rendered';
            }
        };

        (new ErrorLogLogger())->error('value {v}', ['v' => $stringable]);

        self::assertSame('[tailwindcss-php] ERROR value rendered', $this->lastLine());
    }

    /** @test */
    public function aNonStringableContextValueIsNotInterpolated(): void
    {
        (new ErrorLogLogger())->error('value {v}', ['v' => ['an', 'array']]);

        self::assertSame('[tailwindcss-php] ERROR value {v}', $this->lastLine());
    }

    /**
     * One log line per event: a multi-line message would break line-oriented
     * log tooling and could be used to forge a second entry.
     *
     * @test
     */
    public function newlinesAreReplacedWithSpaces(): void
    {
        (new ErrorLogLogger())->error("first\nsecond\r\nthird\rfourth");

        self::assertSame('[tailwindcss-php] ERROR first second third fourth', $this->lastLine());
        self::assertCount(1, $this->lines());
    }

    /** @test */
    public function newlinesInAnInterpolatedValueAreAlsoStripped(): void
    {
        (new ErrorLogLogger())->error('value {v}', ['v' => "a\nb"]);

        self::assertSame('[tailwindcss-php] ERROR value a b', $this->lastLine());
        self::assertCount(1, $this->lines());
    }

    /**
     * An exception is rendered as class + message only. A stack trace would
     * leak absolute paths into the log (threat T-06).
     *
     * @test
     */
    public function anExceptionContextIsRenderedWithoutATrace(): void
    {
        (new ErrorLogLogger())->error('build failed', [
            'exception' => new \RuntimeException('inner cause'),
        ]);

        $line = $this->lastLine();

        self::assertStringContainsString('build failed', $line);
        self::assertStringContainsString('RuntimeException', $line);
        self::assertStringContainsString('inner cause', $line);
        self::assertStringNotContainsString('#0', $line);
        self::assertStringNotContainsString(__FILE__, $line);
    }

    /** @test */
    public function anUnknownLevelIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ErrorLogLogger())->log('shouty', 'message');
    }

    /** @test */
    public function anUnknownThresholdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ErrorLogLogger('shouty');
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        $contents = $this->contents();

        if ($contents === '') {
            return [];
        }

        $lines = [];

        foreach (\explode("\n", \trim($contents)) as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function lastLine(): string
    {
        $lines = $this->lines();

        if ($lines === []) {
            return '';
        }

        $last = $lines[\count($lines) - 1];

        // error_log() prefixes each entry with a timestamp in brackets when it
        // writes to a file; strip it so the assertions describe our format only.
        $marker = '[tailwindcss-php]';
        $position = \strpos($last, $marker);

        return $position === false ? $last : \substr($last, $position);
    }

    private function contents(): string
    {
        if (!\is_file($this->logFile)) {
            return '';
        }

        $contents = \file_get_contents($this->logFile);

        return $contents === false ? '' : $contents;
    }
}
