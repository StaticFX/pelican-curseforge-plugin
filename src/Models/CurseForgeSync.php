<?php

namespace Devin\CurseForgeSync\Models;

use App\Models\Server;
use Carbon\Carbon;
use Devin\CurseForgeSync\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-type PackInfo array{name: string, version: string, author: string, minecraft: ?string, loader: array{type: string, version: string, id: string}|null, archive: string, source: string, signature: ?string, auto_apply: bool, overrides_prefix: string, overrides_applied?: bool, quarantine?: string, quarantine_ready?: bool, analyzed_at?: string}
 * @phpstan-type Entry array{key: string, source: string, project_id: ?int, file_id: ?int, required: bool, filename: string, name: string, author: ?string, url: ?string, mod_ids: string[], deps: string[], tags: string[], meta: array{mod_ids: string[], name: ?string, requires: string[], signal: array{side: string, strong: bool, reason: string}|null}|null, kind: string, download_url: ?string, analyzed: bool, error: ?string, side: ?string, reason: ?string, action: ?string, old_filename: ?string, state: ?string, state_error: ?string}
 *
 * @property int $id
 * @property int $server_id
 * @property Server $server
 * @property SyncStatus $status
 * @property ?string $message
 * @property ?string $run_id
 * @property int $progress_done
 * @property int $progress_total
 * @property PackInfo|null $pack
 * @property array<int, Entry>|null $entries
 * @property array<string, array{filename: string, project_id: ?int, file_id: ?int}>|null $managed
 * @property array<string, string>|null $overrides
 * @property bool $auto_sync
 * @property bool $auto_apply
 * @property ?string $watch_path
 * @property bool $remove_unmanaged
 * @property bool $apply_overrides
 * @property bool $restart_after
 * @property ?Carbon $last_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CurseForgeSync extends Model
{
    protected $table = 'curseforge_syncs';

    protected $fillable = [
        'server_id',
        'status',
        'message',
        'run_id',
        'progress_done',
        'progress_total',
        'pack',
        'entries',
        'managed',
        'overrides',
        'auto_sync',
        'auto_apply',
        'watch_path',
        'remove_unmanaged',
        'apply_overrides',
        'restart_after',
        'last_synced_at',
    ];

    protected $attributes = [
        'status' => 'idle',
        'auto_sync' => false,
        'auto_apply' => true,
        'remove_unmanaged' => false,
        'apply_overrides' => true,
        'restart_after' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'pack' => 'array',
            'entries' => 'array',
            'managed' => 'array',
            'overrides' => 'array',
            'auto_sync' => 'boolean',
            'auto_apply' => 'boolean',
            'remove_unmanaged' => 'boolean',
            'apply_overrides' => 'boolean',
            'restart_after' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public static function forServer(Server $server): self
    {
        return self::firstOrCreate(['server_id' => $server->id], [
            'watch_path' => config('curseforge-sync.default_watch_path'),
        ]);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isBusy(): bool
    {
        return $this->status->isBusy();
    }

    public function watchPath(): string
    {
        return trim($this->watch_path ?: (string) config('curseforge-sync.default_watch_path'), '/');
    }

    public function stateFolder(): string
    {
        return trim((string) config('curseforge-sync.state_folder', '.curseforge-sync'), '/');
    }

    public function fail(string $message): void
    {
        $this->update([
            'status' => SyncStatus::Failed,
            'message' => $message,
            'run_id' => null,
        ]);
    }
}
