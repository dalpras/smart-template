<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Preset;

use DalPraS\SmartTemplate\Collection\RenderCollection;
use DalPraS\SmartTemplate\HtmlAttributes;
use DalPraS\SmartTemplate\TemplateEngine;

final class HtmlPreset implements PresetInterface
{
    public const NAMESPACE = 'html';

    /**
     * Register the HTML preset.
     */
    public static function register(
        TemplateEngine $engine,
        string $namespace = '',
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine {
        $namespace = $namespace !== '' ? $namespace : self::NAMESPACE;

        $templates = $engine->require(self::path());

        if (!is_array($templates)) {
            throw new \RuntimeException(
                'HtmlPreset root template must return an array.'
            );
        }

        $engine->register($namespace, $templates, default: $default);

        // Provide the default |join serializer without replacing an application
        // serializer that may already be registered.
        if (!isset($engine->getCustomParamModifiers()['join'])) {
            (new HtmlAttributes())->register($engine);
        }

        if ($overrides !== []) {
            $engine->register($namespace, $overrides);
        }

        return $engine;
    }

    /**
     * Get the HTML collection.
     */
    public static function collection(
        TemplateEngine $engine,
        ?string $namespace = self::NAMESPACE,
    ): RenderCollection {
        return $engine->collection($namespace);
    }

    /**
     * Absolute path to the preset root template file.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/resources/templates/html.php';
    }
}