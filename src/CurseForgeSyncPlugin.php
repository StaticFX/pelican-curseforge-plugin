<?php

namespace Devin\CurseForgeSync;

use Filament\Contracts\Plugin;
use Filament\Panel;

class CurseForgeSyncPlugin implements Plugin
{
    public function getId(): string
    {
        return 'curseforge-sync';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Devin\\CurseForgeSync\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}
