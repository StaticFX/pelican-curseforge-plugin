<?php

namespace Devin\CurseForgeSync\Providers;

use Devin\CurseForgeSync\Console\Commands\WatchModpacksCommand;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Throwable;

class CurseForgeSyncPluginProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $interval = max(1, (int) config('curseforge-sync.watch_interval', 5));

        Schedule::command(WatchModpacksCommand::class)
            ->cron("*/$interval * * * *")
            ->withoutOverlapping(30)
            ->runInBackground();

        $this->restartQueueWorkersOnce();
    }

    /**
     * A queue worker that was started before this plugin was installed (or updated) doesn't know
     * its classes and fails every sync job with an "incomplete object" error. Signal the workers
     * to restart once per plugin version, the same thing `php artisan queue:restart` does.
     */
    protected function restartQueueWorkersOnce(): void
    {
        try {
            $manifest = json_decode((string) file_get_contents(plugin_path('curseforge-sync', 'plugin.json')), true);
            $key = 'curseforge-sync:queue-restarted:' . ($manifest['version'] ?? 'unknown');

            if (cache()->has($key)) {
                return;
            }

            cache()->forever('illuminate:queue:restart', now()->getTimestamp());
            cache()->forever($key, true);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
