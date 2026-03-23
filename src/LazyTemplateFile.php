<?php 

declare(strict_types=1);

namespace DalPraS\SmartTemplate;

final class LazyTemplateFile
{
    public function __construct(
        public readonly string $path
    ) {}
}