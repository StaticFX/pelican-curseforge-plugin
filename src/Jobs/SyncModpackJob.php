<?php

namespace Devin\CurseForgeSync\Jobs;

use Devin\CurseForgeSync\Models\CurseForgeSync;
use Devin\CurseForgeSync\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Does one time boxed step of an analysis or sync and queues the next step until it's done.
 *
 * Keeping every job short means a long sync never runs into the queue's retry_after,
 * which would start a second worker on the same sync.
 */
class SyncModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 85;

    public function __construct(
        public int $syncId,
        public string $runId,
        public string $mode,
    ) {}

    public function handle(SyncService $service): void
    {
        $lock = Cache::lock("curseforge-sync:{$this->syncId}", $this->timeout + 5);

        if (!$lock->get()) {
            // another step of this sync is still running, it will queue the next one
            return;
        }

        $next = null;

        try {
            $next = $this->step($service);
        } finally {
            // release before queueing the next step, or it could start while we still hold the lock and give up
            $lock->release();
        }

        if ($next === 'apply-new') {
            $sync = CurseForgeSync::find($this->syncId);
            if (!$sync) {
                return;
            }

            try {
                $service->startApply($sync);
            } catch (Throwable $exception) {
                report($exception);

                $sync->fail(Str::limit($exception->getMessage(), 500));
            }
        } elseif ($next !== null) {
            self::dispatch($this->syncId, $this->runId, $next);
        }
    }

    /**
     * @return string|null what to queue next
     */
    protected function step(SyncService $service): ?string
    {
        $sync = CurseForgeSync::find($this->syncId);

        // cancelled or replaced by a newer run
        if (!$sync || $sync->run_id !== $this->runId) {
            return null;
        }

        try {
            if ($this->mode === 'analyze') {
                if (!$service->analyzeStep($sync)) {
                    return 'analyze';
                }

                $sync->update(['run_id' => null]);

                return ($sync->pack['auto_apply'] ?? false) ? 'apply-new' : null;
            }

            return $service->applyStep($sync) ? null : 'apply';
        } catch (Throwable $exception) {
            report($exception);

            $sync->fail(Str::limit($exception->getMessage(), 500));

            return null;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $sync = CurseForgeSync::find($this->syncId);

        if ($sync && $sync->run_id === $this->runId) {
            $sync->fail(Str::limit($exception?->getMessage() ?? 'The sync job failed', 500));
        }
    }
}
