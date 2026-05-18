<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Preset;

use DalPraS\SmartTemplate\TemplateEngine;

final class HtmlPreset
{
    public const NAMESPACE = 'html';

    /**
     * Register the official HTML template collection.
     */
    public static function register(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
        array $overrides = [],
    ): TemplateEngine {
        $engine->addCustom(
            $namespace,
            $engine->require(self::path())
        );

        if ($overrides !== []) {
            $engine->addCustom(
                $namespace,
                $overrides
            );
        }

        return $engine;
    }

    /**
     * Extend an existing namespace with another template file.
     */
    public static function extend(
        TemplateEngine $engine,
        string $file,
        string $namespace = self::NAMESPACE,
    ): TemplateEngine {
        return $engine->addCustom(
            $namespace,
            $engine->require($file)
        );
    }

    /**
     * Get the collection instance.
     */
    public static function collection(
        TemplateEngine $engine,
        string $namespace = self::NAMESPACE,
    ) {
        return $engine->collection($namespace);
    }

    /**
     * Absolute path to the preset root template file.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2)
            . '/resources/templates/html.php';
    }
}