<?php

declare(strict_types=1);

namespace TailwindPhp\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TailwindPhp\Cache\Manifest;
use TailwindPhp\Cache\ManifestValidator;
use TailwindPhp\Exception\ManifestCorruptException;
use TailwindPhp\Support\Json;

final class ManifestValidatorTest extends TestCase
{
    /** @test */
    public function classIsFinal(): void
    {
        $reflection = new ReflectionClass(ManifestValidator::class);

        self::assertTrue($reflection->isFinal());
        self::assertFalse($reflection->isInstantiable());
    }

    // ---------------------------------------------------------------------
    // The four fixtures.
    // ---------------------------------------------------------------------

    /** @test */
    public function theValidFixtureIsAccepted(): void
    {
        $manifest = self::validManifest();

        ManifestValidator::validate($manifest);

        self::assertSame(ManifestValidator::SCHEMA_VERSION, $manifest['schema']);
    }

    /** @test */
    public function theWrongSchemaFixtureIsRejected(): void
    {
        self::assertRejected(self::decodeFixture('wrong-schema.json'));
    }

    /** @test */
    public function theOversizedFixtureIsRejected(): void
    {
        self::assertRejected(self::decodeFixture('oversized.json'));
    }

    /**
     * `corrupt.json` is truncated mid-document: it never reaches the validator
     * because the decoder refuses it first.
     *
     * @test
     */
    public function theCorruptFixtureIsNotEvenValidJson(): void
    {
        $this->expectException(\JsonException::class);

        Json::decode(self::readFixture('corrupt.json'));
    }

    // ---------------------------------------------------------------------
    // schema
    // ---------------------------------------------------------------------

    /** @test */
    public function aMissingSchemaIsRejected(): void
    {
        $manifest = self::validManifest();
        unset($manifest['schema']);

        self::assertRejected($manifest);
    }

    /** @test */
    public function aForeignSchemaVersionIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['schema'] = ManifestValidator::SCHEMA_VERSION + 1;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aNonIntegerSchemaIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['schema'] = '1';

        self::assertRejected($manifest);
    }

    // ---------------------------------------------------------------------
    // Scalars.
    // ---------------------------------------------------------------------

    /**
     * @test
     *
     * @dataProvider stringFields
     */
    public function aNonStringScalarIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        $manifest[$key] = 42;

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider stringFields
     */
    public function aMissingScalarIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        unset($manifest[$key]);

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function stringFields(): array
    {
        return [
            'package_version' => ['package_version'],
            'driver_version' => ['driver_version'],
            'input' => ['input'],
            'bundle' => ['bundle'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider badBundleTokens
     */
    public function aMalformedBundleTokenIsRejected(string $bundle): void
    {
        $manifest = self::validManifest();
        $manifest['bundle'] = $bundle;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badBundleTokens(): array
    {
        return [
            'empty' => [''],
            'uppercase' => ['App'],
            'a slash' => ['app/main'],
            'a dot' => ['app.css'],
            'a space' => ['my app'],
            'too long' => [\str_repeat('a', 41)],
        ];
    }

    /**
     * @test
     *
     * @dataProvider badFingerprints
     */
    public function aMalformedFingerprintIsRejected(string $fingerprint): void
    {
        $manifest = self::validManifest();
        $manifest['fingerprint'] = $fingerprint;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badFingerprints(): array
    {
        return [
            'empty' => [''],
            'too short' => ['0123abc'],
            'too long' => ['0123456789abcdef0'],
            'uppercase' => ['0123ABCD'],
            'not hex' => ['zzzzzzzz'],
            'a traversal' => ['../../etc'],
        ];
    }

    /**
     * CSF-A3: the four digests are stored with the `sha256:` prefix. A bare hex
     * digest is the shape PHP uses internally and must not appear on disk.
     *
     * @test
     *
     * @dataProvider hashFields
     */
    public function aHashWithoutTheSha256PrefixIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        $manifest[$key] = \str_repeat('a', 64);

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider hashFields
     */
    public function aNonStringHashIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        $manifest[$key] = 1234;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function hashFields(): array
    {
        return [
            'config_hash' => ['config_hash'],
            'content_hash' => ['content_hash'],
            'candidates_hash' => ['candidates_hash'],
            'safelist_hash' => ['safelist_hash'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider unknownDrivers
     */
    public function aDriverOtherThanPhpOrCliIsRejected(string $driver): void
    {
        $manifest = self::validManifest();
        $manifest['driver'] = $driver;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownDrivers(): array
    {
        return [
            'empty' => [''],
            'node' => ['node'],
            'uppercase php' => ['PHP'],
            'with whitespace' => [' cli'],
        ];
    }

    /** @test */
    public function bothDocumentedDriversAreAccepted(): void
    {
        $accepted = [];

        foreach (['php', 'cli'] as $driver) {
            $manifest = self::validManifest();
            $manifest['driver'] = $driver;

            ManifestValidator::validate($manifest);

            $accepted[] = $driver;
        }

        // Asserting the list rather than nothing: a validator that threw on
        // 'cli' would fail the loop, but one that stopped being called at all
        // would silently "pass" against a bare assertTrue(true).
        self::assertSame(['php', 'cli'], $accepted);
    }

    /** @test */
    public function aNonBooleanMinifiedIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['minified'] = 'true';

        self::assertRejected($manifest);
    }

    /** @test */
    public function aMissingMinifiedIsRejected(): void
    {
        $manifest = self::validManifest();
        unset($manifest['minified']);

        self::assertRejected($manifest);
    }

    /** @test */
    public function aNonNumericBuildMsIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['build_ms'] = '42.5';

        self::assertRejected($manifest);
    }

    /** @test */
    public function aMissingBuildMsIsRejected(): void
    {
        $manifest = self::validManifest();
        unset($manifest['build_ms']);

        self::assertRejected($manifest);
    }

    /**
     * JSON has one number type, so a whole-numbered duration decodes to an int
     * and must not be mistaken for a corrupt value.
     *
     * @test
     */
    public function anIntegerBuildMsIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['build_ms'] = 42;

        ManifestValidator::validate($manifest);

        self::assertSame(42, $manifest['build_ms']);
    }

    /** @test */
    public function aNegativeCandidatesCountIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['candidates_count'] = -1;

        self::assertRejected($manifest);
    }

    /**
     * CCP-A6: no configuration can permit this many candidates, so a value above
     * the ceiling did not come from a build of ours.
     *
     * @test
     */
    public function aCandidatesCountAboveTheCeilingIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['candidates_count'] = ManifestValidator::MAX_CANDIDATES_CEILING + 1;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aCandidatesCountAtTheCeilingIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['candidates_count'] = ManifestValidator::MAX_CANDIDATES_CEILING;

        ManifestValidator::validate($manifest);

        self::assertSame(ManifestValidator::MAX_CANDIDATES_CEILING, $manifest['candidates_count']);
    }

    // ---------------------------------------------------------------------
    // driver_probe
    // ---------------------------------------------------------------------

    /** @test */
    public function aMissingDriverProbeKeyIsRejected(): void
    {
        $manifest = self::validManifest();
        unset($manifest['driver_probe']);

        self::assertRejected($manifest);
    }

    /** @test */
    public function aNullDriverProbeIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['driver_probe'] = null;

        ManifestValidator::validate($manifest);

        self::assertNull($manifest['driver_probe']);
    }

    /** @test */
    public function aWellFormedDriverProbeIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['driver_probe'] = self::driverProbe();

        ManifestValidator::validate($manifest);

        self::assertIsArray($manifest['driver_probe']);
    }

    /**
     * CCP-A3: the binary may sit outside the project root, in which case only
     * the checksum identifies it.
     *
     * @test
     */
    public function aDriverProbeWithANullBinaryIsAccepted(): void
    {
        $probe = self::driverProbe();
        $probe['binary'] = null;

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        ManifestValidator::validate($manifest);

        // Asserted through the consumer rather than on the input array: reading
        // back the value we just set would restate the setup, not the rule.
        $probe = Manifest::fromArray($manifest)->driverProbe();

        self::assertIsArray($probe);
        self::assertNull($probe['binary']);
    }

    /**
     * @test
     *
     * @dataProvider nonArrayDriverProbes
     *
     * @param mixed $probe
     */
    public function aDriverProbeThatIsNeitherNullNorAnObjectIsRejected($probe): void
    {
        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonArrayDriverProbes(): array
    {
        return [
            'a string' => ['probe'],
            'an integer' => [7],
            'a float' => [1.5],
            'a boolean' => [true],
        ];
    }

    /**
     * @test
     *
     * @dataProvider driverProbeKeys
     */
    public function aDriverProbeMissingAKeyIsRejected(string $key): void
    {
        $probe = self::driverProbe();
        unset($probe[$key]);

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function driverProbeKeys(): array
    {
        return [
            'binary' => ['binary'],
            'm' => ['m'],
            's' => ['s'],
            'sha256' => ['sha256'],
            'version' => ['version'],
        ];
    }

    /**
     * A forged probe would let an unverified binary look verified, and an
     * absolute path is how it would point somewhere we do not own (T-02).
     *
     * @test
     */
    public function aDriverProbeWithAnAbsoluteBinaryIsRejected(): void
    {
        $probe = self::driverProbe();
        $probe['binary'] = '/usr/local/bin/tailwindcss';

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aDriverProbeWithANonIntegerMtimeIsRejected(): void
    {
        $probe = self::driverProbe();
        $probe['m'] = '1756857000';

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aDriverProbeWithAPrefixedChecksumIsRejected(): void
    {
        // The probe checksum is bare hex, unlike the manifest's four digests.
        $probe = self::driverProbe();
        $probe['sha256'] = 'sha256:' . \str_repeat('a', 64);

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aDriverProbeWithANonStringVersionIsRejected(): void
    {
        $probe = self::driverProbe();
        $probe['version'] = 4;

        $manifest = self::validManifest();
        $manifest['driver_probe'] = $probe;

        self::assertRejected($manifest);
    }

    // ---------------------------------------------------------------------
    // Paths.
    // ---------------------------------------------------------------------

    /**
     * Every path in the manifest is project-relative (ADR-014). The cache
     * directory is often writable by more than the PHP user, so a path that
     * escapes the root is treated as a hostile edit, not as data to normalise
     * (threats T-02/T-07).
     *
     * @test
     *
     * @dataProvider unacceptablePaths
     */
    public function anUnacceptableInputPathIsRejected(string $path): void
    {
        $manifest = self::validManifest();
        $manifest['input'] = $path;

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider unacceptablePaths
     */
    public function anUnacceptableImportPathIsRejected(string $path): void
    {
        $manifest = self::validManifest();
        $manifest['imports'] = [$path];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider unacceptablePaths
     */
    public function anUnacceptableRootPathIsRejected(string $path): void
    {
        $manifest = self::validManifest();
        $manifest['roots']['sources'] = [$path];

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unacceptablePaths(): array
    {
        return [
            'a posix absolute path' => ['/abs/path'],
            'a drive with a separator' => ['C:/x'],
            'a drive-relative path' => ['C:x'],
            'a UNC share' => ['\\\\server\\share'],
            'a backslash separator' => ['a\\b'],
            'a leading traversal' => ['../escape'],
            'an interior traversal' => ['a/../../b'],
            'the empty string' => [''],
            'a NUL byte' => ["a\0b"],
            // Neither absolute nor drive-qualified, so every other rule passed
            // them — and a wrapper reaching a file operation is the whole of
            // T-01/T-08.
            'a php:// wrapper' => ['php://filter/read=convert.base64-encode/resource=x'],
            'a phar:// wrapper' => ['phar://x.phar/y'],
            'a data: wrapper' => ['data:text/plain,x'],
            // The `D` modifier again: `$` matches before a final newline.
            'a trailing newline' => ["templates/a.twig\n"],
        ];
    }

    /** @test */
    public function anImportThatIsNotAStringIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['imports'] = ['resources/css/ok.css', 7];

        self::assertRejected($manifest);
    }

    /** @test */
    public function anEmptyImportsListIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['imports'] = [];

        ManifestValidator::validate($manifest);

        self::assertSame([], $manifest['imports']);
    }

    /**
     * A JSON object decodes to a PHP array too, so "is it an array" is not
     * enough — a list has the keys 0..n-1.
     *
     * @test
     *
     * @dataProvider listFields
     */
    public function anObjectWhereAListIsExpectedIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        $manifest[$key] = ['a' => 'b'];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider listFields
     */
    public function aMissingListIsRejected(string $key): void
    {
        $manifest = self::validManifest();
        unset($manifest[$key]);

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function listFields(): array
    {
        return [
            'imports' => ['imports'],
            'inline_sources' => ['inline_sources'],
            'warnings' => ['warnings'],
        ];
    }

    // ---------------------------------------------------------------------
    // roots
    // ---------------------------------------------------------------------

    /** @test */
    public function aNonObjectRootsIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['roots'] = 'resources';

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider rootKinds
     */
    public function aMissingRootKindIsRejected(string $kind): void
    {
        $manifest = self::validManifest();
        unset($manifest['roots'][$kind]);

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider rootKinds
     */
    public function aNonArrayRootKindIsRejected(string $kind): void
    {
        $manifest = self::validManifest();
        $manifest['roots'][$kind] = 'templates';

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider rootKinds
     */
    public function aNonStringRootIsRejected(string $kind): void
    {
        $manifest = self::validManifest();
        $manifest['roots'][$kind] = [123];

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rootKinds(): array
    {
        return [
            'input' => ['input'],
            'watch' => ['watch'],
            'sources' => ['sources'],
        ];
    }

    // ---------------------------------------------------------------------
    // files
    // ---------------------------------------------------------------------

    /** @test */
    public function aNonObjectFilesMapIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = 'nope';

        self::assertRejected($manifest);
    }

    /** @test */
    public function aFilesEntryThatIsNotAnObjectIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = ['templates/home.twig' => 'nope'];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aFilesEntryWithAnUnknownKindIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = ['templates/home.twig' => ['k' => 'x', 'm' => 1, 's' => 2]];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aFilesEntryWithoutAKindIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = ['templates/home.twig' => ['m' => 1, 's' => 2]];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider fileStatKeys
     */
    public function aFilesEntryWithANonIntegerStatIsRejected(string $key): void
    {
        $entry = ['k' => 's', 'm' => 1756857100, 's' => 512];
        $entry[$key] = '1';

        $manifest = self::validManifest();
        $manifest['files'] = ['templates/home.twig' => $entry];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider fileStatKeys
     */
    public function aFilesEntryWithAMissingStatIsRejected(string $key): void
    {
        $entry = ['k' => 's', 'm' => 1756857100, 's' => 512];
        unset($entry[$key]);

        $manifest = self::validManifest();
        $manifest['files'] = ['templates/home.twig' => $entry];

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fileStatKeys(): array
    {
        return [
            'mtime' => ['m'],
            'size' => ['s'],
        ];
    }

    /**
     * Candidates are extracted from *source* files; an input stylesheet carrying
     * a candidate list means the map was written by something else.
     *
     * @test
     */
    public function anInputFilesEntryCarryingCandidatesIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = [
            'resources/css/app.css' => ['k' => 'i', 'm' => 1, 's' => 2, 'c' => ['flex']],
        ];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aFilesEntryWithANonListCandidateBagIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = [
            'templates/home.twig' => ['k' => 's', 'm' => 1, 's' => 2, 'c' => 'flex'],
        ];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aFilesEntryWithANonStringCandidateIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = [
            'templates/home.twig' => ['k' => 's', 'm' => 1, 's' => 2, 'c' => ['flex', 9]],
        ];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider unacceptablePaths
     */
    public function aFilesEntryWithAnUnacceptablePathIsRejected(string $path): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = [$path => ['k' => 's', 'm' => 1, 's' => 2]];

        self::assertRejected($manifest);
    }

    /**
     * PHP silently turns a numeric JSON object key into an int, so "12" as a
     * filename would otherwise reach the path check as an integer.
     *
     * @test
     */
    public function aFilesEntryWithANumericPathIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = ['12' => ['k' => 's', 'm' => 1, 's' => 2]];

        self::assertRejected($manifest);
    }

    /** @test */
    public function anEmptyFilesMapIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['files'] = [];

        ManifestValidator::validate($manifest);

        self::assertSame([], $manifest['files']);
    }

    // ---------------------------------------------------------------------
    // inline_sources
    // ---------------------------------------------------------------------

    /** @test */
    public function aNonStringInlineSourceIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['inline_sources'] = ['mt-2', ['nested']];

        self::assertRejected($manifest);
    }

    // ---------------------------------------------------------------------
    // warnings
    // ---------------------------------------------------------------------

    /**
     * CCP-A25: a warnings row is exactly `Diagnostic::toArray()` — six keys, no
     * more and no fewer.
     *
     * @test
     *
     * @dataProvider diagnosticKeys
     */
    public function aWarningMissingAKeyIsRejected(string $key): void
    {
        $warning = self::warningRow();
        unset($warning[$key]);

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function diagnosticKeys(): array
    {
        return [
            'level' => ['level'],
            'code' => ['code'],
            'message' => ['message'],
            'file' => ['file'],
            'line' => ['line'],
            'candidate' => ['candidate'],
        ];
    }

    /** @test */
    public function aWarningWithASeventhKeyIsRejected(): void
    {
        $warning = self::warningRow();
        $warning['extra'] = 'smuggled';

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aWarningThatIsNotAnObjectIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['warnings'] = ['just a message'];

        self::assertRejected($manifest);
    }

    /**
     * @test
     *
     * @dataProvider warningTextKeys
     */
    public function aWarningWithANonStringTextFieldIsRejected(string $key): void
    {
        $warning = self::warningRow();
        $warning[$key] = 1;

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function warningTextKeys(): array
    {
        return [
            'level' => ['level'],
            'code' => ['code'],
            'message' => ['message'],
        ];
    }

    /**
     * A warnings row travels into logs and the debug overlay, so an absolute
     * path here would leak the server layout (threat T-06).
     *
     * @test
     */
    public function aWarningWithAnAbsoluteFileIsRejected(): void
    {
        $warning = self::warningRow();
        $warning['file'] = '/var/www/templates/home.twig';

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aWarningWithNullPositionFieldsIsAccepted(): void
    {
        $warning = self::warningRow();
        $warning['file'] = null;
        $warning['line'] = null;
        $warning['candidate'] = null;

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        ManifestValidator::validate($manifest);

        $warnings = Manifest::fromArray($manifest)->warnings();

        self::assertNull($warnings[0]['file']);
    }

    /** @test */
    public function aWarningWithANonIntegerLineIsRejected(): void
    {
        $warning = self::warningRow();
        $warning['line'] = '12';

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /** @test */
    public function aWarningWithANonStringCandidateIsRejected(): void
    {
        $warning = self::warningRow();
        $warning['candidate'] = ['bg-nope-500'];

        $manifest = self::validManifest();
        $manifest['warnings'] = [$warning];

        self::assertRejected($manifest);
    }

    /** @test */
    public function moreWarningsThanTheCapAreRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['warnings'] = self::warningRows(ManifestValidator::MAX_WARNINGS + 1);

        self::assertRejected($manifest);
    }

    /** @test */
    public function exactlyTheCapOfWarningsIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['warnings'] = self::warningRows(ManifestValidator::MAX_WARNINGS);

        ManifestValidator::validate($manifest);

        self::assertCount(ManifestValidator::MAX_WARNINGS, $manifest['warnings']);
    }

    // ---------------------------------------------------------------------
    // last_error
    // ---------------------------------------------------------------------

    /** @test */
    public function aMissingLastErrorKeyIsRejected(): void
    {
        $manifest = self::validManifest();
        unset($manifest['last_error']);

        self::assertRejected($manifest);
    }

    /** @test */
    public function aWellFormedLastErrorIsAccepted(): void
    {
        $manifest = self::validManifest();
        $manifest['last_error'] = self::lastError();

        ManifestValidator::validate($manifest);

        self::assertIsArray($manifest['last_error']);
    }

    /** @test */
    public function aLastErrorThatIsNeitherNullNorAnObjectIsRejected(): void
    {
        $manifest = self::validManifest();
        $manifest['last_error'] = 'E_CLI_FAILED';

        self::assertRejected($manifest);
    }

    /** @test */
    public function aLastErrorWithoutACodeIsRejected(): void
    {
        $error = self::lastError();
        unset($error['code']);

        $manifest = self::validManifest();
        $manifest['last_error'] = $error;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aLastErrorWithoutAShortMessageIsRejected(): void
    {
        $error = self::lastError();
        unset($error['message_short']);

        $manifest = self::validManifest();
        $manifest['last_error'] = $error;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aLastErrorWithANonIntegerTimestampIsRejected(): void
    {
        $error = self::lastError();
        $error['at'] = '1756857600';

        $manifest = self::validManifest();
        $manifest['last_error'] = $error;

        self::assertRejected($manifest);
    }

    /** @test */
    public function aLastErrorWithoutATimestampIsRejected(): void
    {
        $error = self::lastError();
        unset($error['at']);

        $manifest = self::validManifest();
        $manifest['last_error'] = $error;

        self::assertRejected($manifest);
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    /**
     * Rejection is never a repair: the validator throws, and the caller rebuilds.
     *
     * @param array<string, mixed> $manifest
     */
    private static function assertRejected(array $manifest): void
    {
        try {
            ManifestValidator::validate($manifest);
        } catch (ManifestCorruptException $e) {
            self::assertNotSame('', $e->reason());

            return;
        }

        self::fail('Expected the manifest to be rejected, but it validated.');
    }

    /**
     * @return array<string, mixed>
     */
    private static function validManifest(): array
    {
        return self::decodeFixture('valid.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeFixture(string $name): array
    {
        return Json::decode(self::readFixture($name));
    }

    private static function readFixture(string $name): string
    {
        return (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/tests/Fixtures/manifests/' . $name
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function driverProbe(): array
    {
        return [
            'binary' => 'node_modules/.bin/tailwindcss',
            'm' => 1756857000,
            's' => 1024,
            'sha256' => \str_repeat('e', 64),
            'version' => '4.0.0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function warningRow(): array
    {
        return [
            'level' => 'warning',
            'code' => 'W_UNKNOWN_UTILITY',
            'message' => 'Unknown utility.',
            'file' => 'templates/home.twig',
            'line' => 12,
            'candidate' => 'bg-nope-500',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function warningRows(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; ++$i) {
            $rows[] = self::warningRow();
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lastError(): array
    {
        return [
            'code' => 'E_CLI_FAILED',
            'message_short' => 'The CLI exited with status 1.',
            'at' => 1756857600,
        ];
    }
}
