<?php

declare(strict_types=1);

namespace TailwindPhp\Bridge;

use TailwindPhp\Config\ConfigFile;
use TailwindPhp\Exception\ConfigurationException;
use TailwindPhp\Pipeline\ErrorRenderer;
use TailwindPhp\Support\Filesystem;
use TailwindPhp\Tailwind;

/**
 * The adapter for a front controller that already has a stylesheet route
 * (design doc 22 §legacy adapter, doc 04 §2 Style B, decisions HBC-A7/A8/A9).
 *
 * The caller's shape is fixed and predates this library:
 *
 * The caller sends a `Content-type` built from {@see self::getContentType()},
 * then writes {@see self::getContent()} and stops. (Spelled out in prose rather
 * than as a code sample on purpose: the project's own audit grep for output
 * calls in `src/` treats a sample as a hit, and a security grep with known
 * false positives is one people stop reading.)
 *
 * So this is the **one class in the library that does its work in the
 * constructor** (doc 18 forbids it everywhere else): `getContent()` has to be a
 * pure getter because the caller may invoke it twice, and there is no other
 * hook to build in. The CSS is therefore resolved once here and buffered.
 *
 * It sends **no** headers of its own, and no fingerprint, ETag, 304 or gzip —
 * the caller owns the response. An integrator who wants those should use
 * {@see Tailwind::respond()} instead; this class exists to make an existing
 * page work without rewriting its front controller.
 *
 * **Configuration.** A project may keep a full configuration array in
 * `config/tailwind.php` or in the directory above `$path`; if it does, that
 * array wins. This class fills in only the five keys it can infer — `input`,
 * `watch`, `cache_dir`, `debug`, `project_root` — and only when the file did
 * not set them, so a project can override any of them, including the `$debug`
 * the caller passed. Every other key (`safelist`, `limits`, `bundles`, …) is
 * honoured exactly as written, because the file is a normal config array and
 * not a fragment.
 *
 * **Threat T-08.** The config file is loaded through
 * {@see ConfigFile::load()}, which is the single `require` site in `src/` and
 * refuses anything under the cache directory. A `tailwind.php` written into
 * `cache/tailwind/` by an attacker who can reach the cache is therefore never
 * executed.
 */
final class LegacyHandler
{
    /**
     * The only content type this library produces.
     */
    private const CONTENT_TYPE = 'text/css; charset=utf-8';

    /**
     * Where the artefacts go. Fixed rather than configurable: a legacy front
     * controller has nowhere to put the setting.
     */
    private const CACHE_DIR = 'cache/tailwind';

    /**
     * Doc 22's list, filtered to the directories that actually exist — an
     * absent `assets/pages` is the normal case, not a configuration error.
     *
     * @var list<string>
     */
    private const DEFAULT_SOURCES = ['templates', 'assets/templates', 'assets/pages', 'assets/scripts'];

    /**
     * The keys this class supplies when the config file did not.
     *
     * @var list<string>
     */
    private const INFERRED = ['project_root', 'watch', 'cache_dir', 'debug'];

    /**
     * @var string
     */
    private $content;

    /**
     * @param bool $debug The host application's debug flag. Never read from a
     *                    request (threat T-09) — this class does not touch
     *                    request data at all.
     * @param string $path The stylesheet directory, relative to the current
     *                     working directory, e.g. `assets/styles`.
     *
     * @throws ConfigurationException When the project is misconfigured. This is
     *                                deliberately **not** failed soft: a typo in
     *                                a config file must be visible, and serving
     *                                a silent empty stylesheet for it is how a
     *                                site ends up unstyled with no explanation.
     */
    public function __construct(bool $debug, string $path)
    {
        $root = self::cwd();
        $relative = \trim(\str_replace('\\', '/', $path), '/');

        $config = $this->configure($root, $relative, $debug);
        $tailwind = Tailwind::fromArray($config);

        $this->content = $this->build($tailwind);
    }

    /**
     * The compiled CSS.
     *
     * A pure getter: the work happened in the constructor, so calling this
     * twice costs nothing and cannot produce two different answers.
     */
    public function getContent(): string
    {
        return $this->content;
    }

    public function getContentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /**
     * Resolve the bundle and buffer the bytes.
     *
     * The pipeline already fails soft (doc 15): a failed build comes back as an
     * inline result carrying the debug overlay or the constant production
     * comment, and a failed *rebuild* over a warm cache comes back as the
     * previous artefact. So the ordinary path covers HBC-A8 without a special
     * case here; the catch is for the failures the pipeline cannot turn into a
     * result, which must still leave the page with parseable CSS.
     */
    private function build(Tailwind $tailwind): string
    {
        try {
            $result = $tailwind->resolve();

            if ($result->isInline()) {
                return $result->body();
            }

            return self::readWholeArtefact((string) $result->path());
        } catch (ConfigurationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // `Config::debug()`, not the constructor argument: a project's
            // config file may override the flag the caller passed (see the
            // class docblock), and the pipeline has already decided which body
            // shape to produce from the same source. Reading it from `$debug`
            // here would let the two disagree — a config that turns debug on
            // would get the overlay from the pipeline and the constant body
            // from this catch.
            return $tailwind->config()->debug()
                ? ErrorRenderer::debugOverlayCss($e, 'app', $tailwind->config()->driver())
                : ErrorRenderer::productionBody();
        }
    }

    /**
     * Read the built stylesheet in full.
     *
     * Bounded by the **artefact's own size**, not by `limits.max_total_css`:
     * that limit caps the *entry* stylesheet the compiler reads, never the CSS
     * it produces, and `Filesystem::read()` truncates silently at whatever cap
     * it is given. Passing the wrong one would serve a stylesheet cut off
     * mid-rule under a 200 — a project that lowered the limit, or simply built
     * something large, would get a page that is subtly wrong with nothing in
     * the log.
     *
     * @throws \RuntimeException When the artefact cannot be read.
     */
    private static function readWholeArtefact(string $path): string
    {
        $fs = new Filesystem();
        $stat = $fs->stat($path);

        if ($stat === null) {
            throw new \RuntimeException('The built stylesheet is no longer readable.');
        }

        return $fs->read($path, $stat['size']);
    }

    /**
     * Build the configuration array doc 22 describes.
     *
     * @return array<string, mixed>
     */
    private function configure(string $root, string $path, bool $debug): array
    {
        $config = $this->loadConfigFile($root, $path);

        // The entry is inferred only when the config file does not name one.
        // Inferring it unconditionally would let a `main.scss` beside the
        // stylesheets switch the scss stage on for a *configured* plain-CSS
        // entry, which is how the test project's `tailwind.css` first met
        // scssphp ("`@import … layer(theme)` isn't allowed in plain CSS").
        if (!\array_key_exists('input', $config)) {
            $config['input'] = $this->entry($root, $path, $config);
        }

        $inferred = [
            'project_root' => $root,
            'watch' => [$path],
            'cache_dir' => self::CACHE_DIR,
            'debug' => $debug,
        ];

        foreach (self::INFERRED as $key) {
            if (!\array_key_exists($key, $config)) {
                $config[$key] = $inferred[$key];
            }
        }

        if (!\array_key_exists('sources', $config)) {
            $config['sources'] = $this->existingSources($root);
        }

        if (!\array_key_exists('bundles', $config) || $config['bundles'] === []) {
            $config['bundles'] = ['app' => []];
        }

        return $config;
    }

    /**
     * `main.css`, or `main.scss` with the SCSS stage switched on.
     *
     * The fallback is doc 22's, and it sets `preprocessor` only when the file it
     * chose needs it — a project that already declared a pre-processor keeps
     * whatever it declared.
     *
     * @param array<string, mixed> $config Modified in place when SCSS is chosen.
     *
     * @return string The project-relative entry stylesheet.
     */
    private function entry(string $root, string $path, array &$config): string
    {
        $css = $path . '/main.css';

        if (\is_file($root . '/' . $css)) {
            return $css;
        }

        $scss = $path . '/main.scss';

        if (\is_file($root . '/' . $scss)) {
            if (!\array_key_exists('preprocessor', $config)) {
                $config['preprocessor'] = 'scss';
            }

            return $scss;
        }

        // Neither exists. Returning the `.css` name rather than throwing here
        // lets `ConfigBuilder` produce the "must point to an existing file"
        // message, which names the key and the path — a better error than
        // anything this class could invent.
        return $css;
    }

    /**
     * Doc 11's lookup order, `config/tailwind.php` first.
     *
     * The project-wide location wins over the one beside the stylesheets: it is
     * the conventional place, it is the one a project is likely to keep under
     * version control, and a file that happens to sit next to the CSS should
     * not silently override it.
     *
     * @return array<string, mixed>
     */
    private function loadConfigFile(string $root, string $path): array
    {
        $candidates = [$root . '/config/tailwind.php'];

        $parent = \strrpos($path, '/');
        $beside = $parent === false ? 'tailwind.php' : \substr($path, 0, $parent) . '/tailwind.php';
        $candidates[] = $root . '/' . $beside;

        foreach ($candidates as $candidate) {
            if (\is_file($candidate)) {
                // The cache directory is passed so the loader can refuse a file
                // inside it (threat T-08); this is the only `require` in `src/`.
                return ConfigFile::load($candidate, $root, $root . '/' . self::CACHE_DIR);
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function existingSources(string $root): array
    {
        $sources = [];

        foreach (self::DEFAULT_SOURCES as $candidate) {
            if (\is_dir($root . '/' . $candidate)) {
                $sources[] = $candidate;
            }
        }

        return $sources;
    }

    /**
     * The project root, per HBC-A7.
     */
    private static function cwd(): string
    {
        $cwd = \getcwd();

        if ($cwd === false) {
            throw new ConfigurationException([
                'The current working directory could not be determined, so the project root is unknown.',
            ]);
        }

        return \rtrim(\str_replace('\\', '/', $cwd), '/');
    }
}
