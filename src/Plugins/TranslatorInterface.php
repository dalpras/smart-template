<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Plugins;

use Laminas\Translator\TranslatorInterface as LaminasTranslatorInterface;
use Laminas\Validator\Translator\TranslatorInterface as LaminasOldTranslatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorInterface;

interface TranslatorInterface extends LaminasTranslatorInterface, SymfonyTranslatorInterface, LaminasOldTranslatorInterface
{
    
}
