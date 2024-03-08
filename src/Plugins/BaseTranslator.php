<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Plugins;

/**
 * Does not translate anything, simply returns the sting inserted
 */
class BaseTranslator implements TranslatorInterface
{
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return (string) $id;
    }
}
