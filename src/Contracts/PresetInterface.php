<?php

declare(strict_types=1);

namespace DalPraS\SmartTemplate\Contracts;
interface PresetInterface
{
    public function namespace(): string;

    public function templates(): array;

    public function overlays(): array;
}