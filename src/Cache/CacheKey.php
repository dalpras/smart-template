<?php declare(strict_types=1);

namespace DalPraS\SmartTemplate\Cache;

final class CacheKey
{
    public static function templateFile(string $realpath): string
    {
        return hash('sha256', $realpath);
    }
}