<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Plugins;

interface HelpersInterface
{
    public function translator(): TranslatorInterface;

    public function escaper(): EscaperInterface;
    
}
