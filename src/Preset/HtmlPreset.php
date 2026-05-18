<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Preset;

use DalPraS\SmartTemplate\TemplateEngine;

final class HtmlPreset
{
    /**
     * Default namespace used when registering the HTML preset.
     *
     * Users will render this preset with:
     *
     *   $engine->render('html', ...)
     */
    public const NAMESPACE = 'html';

    /**
     * Register the built-in HTML templates into the engine.
     *
     * The TemplateEngine no longer discovers templates from the filesystem.
     * Instead, the preset explicitly loads its own root template file and
     * registers the returned array under a namespace.
     */
    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
    ): TemplateEngine {
        /**
         * The preset knows where its template file is.
         * The engine only provides a low-level require() helper.
         */
        $templates = $engine->require(self::path());

        if (!is_array($templates)) {
            throw new \RuntimeException('HtmlPreset root template must return an array.');
        }

        /**
         * Register the default HTML templates.
         */
        $engine->register($namespace, $templates);

        /**
         * Optional user overrides are merged into the same namespace.
         *
         * This allows:
         *
         *   HtmlPreset::register($engine, overrides: [
         *      'button' => '<button class="my-btn">{body}</button>',
         *   ]);
         */
        if ($overrides !== []) {
            $engine->register($namespace, $overrides);
        }

        return $engine;
    }

    /**
     * Extend an already-registered namespace with another explicit template file.
     *
     * This still uses a file, but the file is selected explicitly by the caller.
     * There is no automatic filesystem search.
     */
    public static function extend(
        TemplateEngine $engine,
        string $file,
        string $namespace = self::NAMESPACE,
    ): TemplateEngine {
        $templates = $engine->require($file);

        if (!is_array($templates)) {
            throw new \RuntimeException("Preset extension file must return an array: {$file}");
        }

        return $engine->register($namespace, $templates);
    }

    /**
     * Convenience accessor for the registered HTML collection.
     */
    public static function collection(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
    ) {
        return $engine->collection($namespace);
    }

    /**
     * Absolute path to the root PHP file containing the built-in HTML templates.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/templates/html.php';
    }
}