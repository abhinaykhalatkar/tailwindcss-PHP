<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Hash;
use TailwindPhp\Tests\Support\FailingStream;

final class HashTest extends TestCase
{
    /**
     * Published SHA-256 vectors, so a refactor cannot silently change the
     * algorithm or the encoding.
     */
    private const EMPTY_DIGEST = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const ABC_DIGEST = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    /**
     * @var list<string>
     */
    private $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }

        $this->tempFiles = [];
        FailingStream::unregister();

        parent::tearDown();
    }

    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Hash::class);

        self::assertTrue($reflection->isFinal());
    }

    /**
     * @test
     *
     * @dataProvider knownVectors
     */
    public function sha256MatchesTheKnownVectors(string $input, string $expected): void
    {
        self::assertSame($expected, Hash::sha256($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function knownVectors(): array
    {
        return [
            'empty string' => ['', self::EMPTY_DIGEST],
            'abc' => ['abc', self::ABC_DIGEST],
        ];
    }

    /** @test */
    public function sha256IsLowercaseHexOfTheExpectedLength(): void
    {
        $digest = Hash::sha256('anything');

        self::assertSame(64, \strlen($digest));
        self::assertSame(\strtolower($digest), $digest);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $digest);
    }

    /** @test */
    public function sha256IsByteSensitive(): void
    {
        self::assertNotSame(Hash::sha256("a\nb"), Hash::sha256("a\r\nb"));
    }

    /** @test */
    public function sha256FileMatchesTheInMemoryDigest(): void
    {
        $path = $this->writeTempFile('abc');

        self::assertSame(self::ABC_DIGEST, Hash::sha256File($path));
    }

    /** @test */
    public function sha256FileHandlesAnEmptyFile(): void
    {
        $path = $this->writeTempFile('');

        self::assertSame(self::EMPTY_DIGEST, Hash::sha256File($path));
    }

    /** @test */
    public function sha256FileThrowsWhenTheFileCannotBeRead(): void
    {
        $missing = \sys_get_temp_dir() . '/tailwindphp-absent-' . \bin2hex(\random_bytes(6));

        $this->expectException(\RuntimeException::class);

        Hash::sha256File($missing);
    }

    /**
     * PHP 8's `fread()` answers false on a failed read (TASK-0059 typed that
     * branch): the stream is refused, never hashed as if it had ended there.
     *
     * @test
     */
    public function aReadThatFailsAfterOpeningIsRefusedRatherThanHashedShort(): void
    {
        FailingStream::register();
        $stream = \fopen(FailingStream::SCHEME . '://read-fails', 'rb');

        self::assertNotFalse($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reading the stream being hashed failed.');

        try {
            Hash::sha256Stream($stream);
        } finally {
            \fclose($stream);
        }
    }

    /** @test */
    public function sha256StreamMatchesTheInMemoryDigest(): void
    {
        $stream = \fopen('php://memory', 'r+b');

        self::assertNotFalse($stream);

        \fwrite($stream, 'abc');
        \rewind($stream);

        self::assertSame(self::ABC_DIGEST, Hash::sha256Stream($stream));

        \fclose($stream);
    }

    /**
     * The streaming variant exists so a large artefact is never held in memory;
     * a payload spanning several chunks must give the same answer.
     *
     * @test
     */
    public function sha256StreamMatchesAcrossChunkBoundaries(): void
    {
        $payload = \str_repeat('x', (64 * 1024) + 17);

        $stream = \fopen('php://memory', 'r+b');

        self::assertNotFalse($stream);

        \fwrite($stream, $payload);
        \rewind($stream);

        self::assertSame(Hash::sha256($payload), Hash::sha256Stream($stream));

        \fclose($stream);
    }

    /** @test */
    public function sha256StreamHandlesAnEmptyStream(): void
    {
        $stream = \fopen('php://memory', 'r+b');

        self::assertNotFalse($stream);

        self::assertSame(self::EMPTY_DIGEST, Hash::sha256Stream($stream));

        \fclose($stream);
    }

    /** @test */
    public function sha256StreamRejectsAStreamNotOpenForReading(): void
    {
        $path = $this->writeTempFile('');
        $stream = \fopen($path, 'wb');

        self::assertNotFalse($stream);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not open for reading');

            Hash::sha256Stream($stream);
        } finally {
            \fclose($stream);
        }
    }

    /** @test */
    public function sha256StreamRejectsAClosedStream(): void
    {
        $stream = \fopen('php://memory', 'r+b');

        self::assertNotFalse($stream);

        \fclose($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an open resource');

        Hash::sha256Stream($stream);
    }

    /**
     * @test
     *
     * @dataProvider linesCases
     *
     * @param list<string> $lines
     */
    public function sha256LinesMatchesTheImplodedEquivalent(array $lines): void
    {
        self::assertSame(Hash::sha256(\implode("\n", $lines)), Hash::sha256Lines($lines));
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function linesCases(): array
    {
        return [
            'empty' => [[]],
            'single line' => [['flex']],
            'multiple lines' => [['flex', 'p-4', 'md:grid']],
            'a line containing a newline' => [["a\nb", 'c']],
            'empty lines' => [['', '', '']],
            'many lines' => [\array_map(static function (int $i): string {
                return 'candidate-' . $i;
            }, \range(1, 500))],
        ];
    }

    /** @test */
    public function sha256LinesOfAnEmptyArrayIsTheEmptyDigest(): void
    {
        self::assertSame(self::EMPTY_DIGEST, Hash::sha256Lines([]));
    }

    /** @test */
    public function sha256LinesOfASingleLineIsItsOwnDigest(): void
    {
        self::assertSame(self::ABC_DIGEST, Hash::sha256Lines(['abc']));
    }

    /** @test */
    public function equalsComparesDigests(): void
    {
        self::assertTrue(Hash::equals(self::ABC_DIGEST, self::ABC_DIGEST));
        self::assertFalse(Hash::equals(self::ABC_DIGEST, self::EMPTY_DIGEST));
    }

    /** @test */
    public function equalsRejectsALengthMismatch(): void
    {
        self::assertFalse(Hash::equals(self::ABC_DIGEST, \substr(self::ABC_DIGEST, 0, 32)));
    }

    private function writeTempFile(string $contents): string
    {
        $path = \sys_get_temp_dir() . '/tailwindphp-hash-' . \bin2hex(\random_bytes(6));

        \file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        return $path;
    }
}
