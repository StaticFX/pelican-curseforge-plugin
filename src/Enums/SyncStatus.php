<?php

namespace Devin\CurseForgeSync\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncStatus: string implements HasColor, HasLabel
{
    case Idle = 'idle';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Applying = 'applying';
    case Synced = 'synced';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return trans('curseforge-sync::strings.status.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Idle => 'gray',
            self::Analyzing, self::Applying => 'info',
            self::Ready => 'warning',
            self::Synced => 'success',
            self::Failed => 'danger',
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Analyzing || $this === self::Applying;
    }
}
