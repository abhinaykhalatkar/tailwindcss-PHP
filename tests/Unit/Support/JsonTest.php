<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Support\Json;

final class JsonTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(Json::class);

        self::assertTrue($reflection->isFinal());
    }

    /** @test */
    public function aDocumentRoundTrips(): void
    {
        $data = [
            'version' => '0.1.0-dev',
            'files' => ['a.css' => ['m' => 1, 's' => 2]],
            'warnings' => [],
            'frozen' => false,
            'nothing' => null,
        ];

        self::assertSame($data, Json::decode(Json::encode($data)));
    }

    /**
     * Manifests store project-relative paths; escaping the separators would
     * make them unreadable and change the bytes we hash (ADR-014).
     *
     * @test
     */
    public function slashesAreNotEscaped(): void
    {
        self::assertSame('{"path":"assets/styles/main.css"}', Json::encode(['path' => 'assets/styles/main.css']));
    }

    /** @test */
    public function unicodeIsNotEscaped(): void
    {
        self::assertSame('{"text":"héllo"}', Json::encode(['text' => 'héllo']));
    }

    /**
     * A float that happens to be integral must stay a float, otherwise a
     * round trip would silently change the document's type.
     *
     * @test
     */
    public function zeroFractionIsPreserved(): void
    {
        self::assertSame('{"n":1.0}', Json::encode(['n' => 1.0]));
    }

    /** @test */
    public function prettyPrintingIsOptIn(): void
    {
        $compact = Json::encode(['a' => 1]);
        $pretty = Json::encode(['a' => 1], true);

        self::assertSame('{"a":1}', $compact);
        self::assertNotSame($compact, $pretty);
        self::assertStringContainsString("\n", $pretty);
        self::assertSame(['a' => 1], Json::decode($pretty));
    }

    /** @test */
    public function anEmptyArrayEncodesAsAJsonArray(): void
    {
        self::assertSame('[]', Json::encode([]));
        self::assertSame([], Json::decode('[]'));
    }

    /** @test */
    public function decodeReturnsAssociativeArrays(): void
    {
        $decoded = Json::decode('{"a":{"b":1}}');

        self::assertSame(['a' => ['b' => 1]], $decoded);
    }

    /** @test */
    public function invalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);

        Json::decode('{not json');
    }

    /** @test */
    public function truncatedJsonThrows(): void
    {
        $this->expectException(\JsonException::class);

        Json::decode('{"a":');
    }

    /**
     * Threat T-08: a hostile cache artefact must not be able to drive the
     * decoder into unbounded recursion. The default depth is 16.
     *
     * @test
     */
    public function exceedingTheDepthLimitThrows(): void
    {
        $deep = \str_repeat('[', 20) . \str_repeat(']', 20);

        $this->expectException(\JsonException::class);

        Json::decode($deep);
    }

    /** @test */
    public function aDocumentWithinTheDepthLimitIsAccepted(): void
    {
        $shallow = \str_repeat('[', 8) . \str_repeat(']', 8);

        self::assertCount(1, Json::decode($shallow));
    }

    /** @test */
    public function theDepthLimitIsConfigurable(): void
    {
        $deep = \str_repeat('[', 20) . \str_repeat(']', 20);

        self::assertCount(1, Json::decode($deep, 32));
    }

    /** @test */
    public function aDepthBelowOneIsRejected(): void
    {
        $this->expectException(\JsonException::class);
        $this->expectExceptionMessage('Decoding depth must be at least 1');

        Json::decode('{"a":1}', 0);
    }

    /**
     * @test
     *
     * @dataProvider scalarTopLevels
     */
    public function aScalarTopLevelIsRejected(string $json): void
    {
        $this->expectException(\JsonException::class);

        Json::decode($json);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scalarTopLevels(): array
    {
        return [
            'string' => ['"just a string"'],
            'integer' => ['42'],
            'float' => ['1.5'],
            'boolean' => ['true'],
            'null' => ['null'],
        ];
    }
}
