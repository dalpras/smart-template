<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Plugins;

interface TranslatorInterface
{
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
}
