<?php

namespace Devin\CurseForgeSync\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ModSide: string implements HasColor, HasLabel
{
    case Client = 'client';
    case Server = 'server';
    case Both = 'both';

    public function getLabel(): string
    {
        return trans('curseforge-sync::strings.side.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Client => 'gray',
            self::Server => 'warning',
            self::Both => 'success',
        };
    }
}
