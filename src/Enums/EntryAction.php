<?php

namespace Devin\CurseForgeSync\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EntryAction: string implements HasColor, HasIcon, HasLabel
{
    /** Download the file to the server. */
    case Install = 'install';
    /** Download the new file and move the old one away. */
    case Update = 'update';
    /** Already on the server. */
    case Keep = 'keep';
    /** Move the file off the server (client-only, dropped from the pack or unmanaged). */
    case Remove = 'remove';
    /** Not wanted on the server and not there either. */
    case Skip = 'skip';
    /** Wanted, but there is no way to download it. */
    case Manual = 'manual';
    /** A jar the pack doesn't know about, left alone. */
    case Ignore = 'ignore';

    public function getLabel(): string
    {
        return trans('curseforge-sync::strings.action.' . $this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Install, self::Update => 'info',
            self::Keep => 'success',
            self::Remove => 'danger',
            self::Skip, self::Ignore => 'gray',
            self::Manual => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Install => 'tabler-download',
            self::Update => 'tabler-refresh',
            self::Keep => 'tabler-check',
            self::Remove => 'tabler-trash',
            self::Skip => 'tabler-device-desktop',
            self::Manual => 'tabler-alert-triangle',
            self::Ignore => 'tabler-question-mark',
        };
    }

    public function changesServer(): bool
    {
        return in_array($this, [self::Install, self::Update, self::Remove]);
    }
}
