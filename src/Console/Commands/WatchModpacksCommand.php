<?php

namespace Devin\CurseForgeSync\Console\Commands;

use Devin\CurseForgeSync\Models\CurseForgeSync;
use Devin\CurseForgeSync\Services\ServerFiles;
use Devin\CurseForgeSync\Services\SyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Picks up modpack zips dropped into a server's watch folder (via SFTP or the file manager).
 */
class WatchModpacksCommand extends Command
{
    protected $signature = 'curseforge-sync:watch';

    protected $description = 'Check the watch folder of every server with auto sync enabled for a new modpack zip';

    public function handle(SyncService $service): int
    {
        $syncs = CurseForgeSync::query()->where('auto_sync', true)->with('server')->get();

        foreach ($syncs as $sync) {
            if ($sync->isBusy() || $sync->server->isInConflictState()) {
                continue;
            }

            try {
                $this->check($sync, $service);
            } catch (Throwable $exception) {
                report($exception);

                $this->warn("Server {$sync->server->uuid}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    protected function check(CurseForgeSync $sync, SyncService $service): void
    {
        $folder = $sync->watchPath();
        $files = ServerFiles::for($sync->server);

        $newest = collect($files->list($folder))
            ->filter(fn ($file) => $file['is_file'] && str_ends_with(strtolower($file['name']), '.zip'))
            ->sortByDesc('modified')
            ->first();

        if (!$newest) {
            return;
        }

        $signature = implode('|', [$folder, $newest['name'], $newest['size'], $newest['modified']]);

        if (($sync->pack['signature'] ?? null) === $signature) {
            return;
        }

        $this->info("Server {$sync->server->uuid}: syncing {$folder}/{$newest['name']}");

        $temp = $service->archivePath($sync) . '.download';
        $files->download("$folder/{$newest['name']}", $temp);

        try {
            $service->start($sync, $temp, 'watch', $signature, $sync->auto_apply);
        } catch (Throwable $exception) {
            @unlink($temp);

            // remember the broken file so it isn't retried every few minutes
            $sync->update([
                'pack' => [...($sync->pack ?? []), 'signature' => $signature],
                'message' => $newest['name'] . ': ' . $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
