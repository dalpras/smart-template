<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Preset;

use DalPraS\SmartTemplate\TemplateEngine;

interface PresetInterface
{
    /**
     * Register the preset templates into the engine.
     */
    public static function register(
        TemplateEngine $engine,
        string $namespace = '',
        array $overrides = [],
        bool $default = true,
    ): TemplateEngine;
}