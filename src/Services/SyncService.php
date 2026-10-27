<?php

namespace Devin\CurseForgeSync\Services;

use App\Repositories\Daemon\DaemonServerRepository;
use Devin\CurseForgeSync\Enums\EntryAction;
use Devin\CurseForgeSync\Enums\ModSide;
use Devin\CurseForgeSync\Enums\SyncStatus;
use Devin\CurseForgeSync\Jobs\SyncModpackJob;
use Devin\CurseForgeSync\Models\CurseForgeSync;
use Exception;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Analyzing a modpack and syncing a server to it.
 *
 * Both phases run in small steps (see SyncModpackJob) so no single queue job runs for long, and
 * every step saves its progress on the sync model.
 */
class SyncService
{
    public const MODS_FOLDER = 'mods';

    // Seconds of work per job before it hands over to the next one.
    protected const STEP_BUDGET = 30;

    public function __construct(
        protected CurseForgeClient $curseForge,
        protected RemoteZipReader $zipReader,
        protected ModClassifier $classifier,
    ) {}

    /**
     * Take a modpack zip that is on the panel, read its manifest and queue the analysis.
     *
     * @throws Exception when the zip is not a CurseForge modpack
     */
    public function start(CurseForgeSync $sync, string $localZip, string $source, ?string $signature = null, bool $autoApply = false): void
    {
        throw_if($sync->isBusy(), new Exception(trans('curseforge-sync::strings.errors.busy')));

        $target = $this->archivePath($sync);
        File::ensureDirectoryExists(dirname($target));

        if (realpath($localZip) !== realpath($target)) {
            File::move($localZip, $target);
        }

        $archive = new ModpackArchive($target);
        $loader = $archive->loader();

        $entries = [];
        $modList = $archive->modList();

        foreach ($archive->files() as $index => $file) {
            $entry = $this->newEntry(
                key: 'cf:' . $file['project_id'],
                source: 'curseforge',
                name: $modList[$index]['name'] ?? '#' . $file['project_id'],
                projectId: $file['project_id'],
                fileId: $file['file_id'],
                required: $file['required'],
            );

            $entry['author'] = $modList[$index]['author'] ?? null;
            $entry['url'] = $modList[$index]['url'] ?? null;

            $entries[] = $entry;
        }

        foreach ($archive->bundledJars() as $jar) {
            $filename = basename($jar);

            $entry = $this->newEntry(key: 'jar:' . $filename, source: 'override', name: Str::beforeLast($filename, '.jar'));
            $entry['filename'] = $filename;
            $entry['kind'] = 'mod';
            $entry['analyzed'] = true;

            try {
                $metadata = ModMetadata::parse($archive->readFromBundledJar($jar, ModMetadata::FILES), $loader['type'] ?? null);
                $entry['meta'] = $metadata->toArray();
                $entry['name'] = $metadata->name ?? $entry['name'];
                $entry['mod_ids'] = $metadata->modIds;
                $entry['deps'] = $metadata->requires;
            } catch (Exception $exception) {
                $entry['error'] = $exception->getMessage();
            }

            $entries[] = $entry;
        }

        $sync->update([
            'status' => SyncStatus::Analyzing,
            'message' => null,
            'run_id' => $runId = (string) Str::uuid(),
            'progress_done' => 0,
            'progress_total' => count($archive->files()),
            'entries' => $entries,
            'pack' => [
                'name' => $archive->name(),
                'version' => $archive->version(),
                'author' => $archive->author(),
                'minecraft' => $archive->minecraftVersion(),
                'loader' => $loader,
                'archive' => $target,
                'source' => $source,
                'signature' => $signature,
                'auto_apply' => $autoApply,
                'overrides_prefix' => $archive->overridesPrefix(),
            ],
        ]);

        SyncModpackJob::dispatch($sync->id, $runId, 'analyze');
    }

    /**
     * Queue applying the current plan.
     *
     * @throws Exception
     */
    public function startApply(CurseForgeSync $sync): void
    {
        throw_if($sync->isBusy(), new Exception(trans('curseforge-sync::strings.errors.busy')));
        throw_if(!$sync->pack || empty($sync->entries), new Exception(trans('curseforge-sync::strings.errors.no_pack')));

        // the plan may be stale (files changed on the server since the analysis)
        $this->plan($sync);

        $pack = $sync->pack;
        $pack['overrides_applied'] = !$sync->apply_overrides;
        $pack['quarantine'] = $sync->stateFolder() . '/removed/' . now()->format('Y-m-d_His');
        unset($pack['quarantine_ready']);

        $todo = collect($sync->entries)->filter(fn ($entry) => $this->needsWork($entry))->count();

        $sync->update([
            'status' => SyncStatus::Applying,
            'message' => null,
            'run_id' => $runId = (string) Str::uuid(),
            'progress_done' => 0,
            'progress_total' => $todo + ($pack['overrides_applied'] ? 0 : 1),
            'pack' => $pack,
        ]);

        SyncModpackJob::dispatch($sync->id, $runId, 'apply');
    }

    public function cancel(CurseForgeSync $sync): void
    {
        $sync->update([
            'status' => $sync->entries && collect($sync->entries)->every(fn ($entry) => $entry['analyzed']) ? SyncStatus::Ready : SyncStatus::Idle,
            'run_id' => null,
            'message' => trans('curseforge-sync::strings.messages.cancelled'),
        ]);
    }

    public function archivePath(CurseForgeSync $sync): string
    {
        return Storage::disk('local')->path("curseforge-sync/{$sync->server->uuid}/modpack.zip");
    }

    /**
     * Look up and classify the next batch of CurseForge files.
     *
     * @return bool true once everything is analyzed
     */
    public function analyzeStep(CurseForgeSync $sync): bool
    {
        $started = microtime(true);
        $entries = $sync->entries ?? [];
        $loader = $sync->pack['loader']['type'] ?? null;

        while (microtime(true) - $started < self::STEP_BUDGET) {
            $batch = array_slice(array_keys(array_filter($entries, fn ($entry) => !$entry['analyzed'])), 0, 10);

            if (empty($batch)) {
                break;
            }

            $files = $this->curseForge->getFiles(array_map(fn ($index) => [
                'project_id' => (int) $entries[$index]['project_id'],
                'file_id' => (int) $entries[$index]['file_id'],
            ], $batch));

            foreach ($batch as $index) {
                $entries[$index] = $this->analyzeEntry($entries[$index], $files[$entries[$index]['file_id']] ?? null, $loader);
            }

            $sync->update([
                'entries' => $entries,
                'progress_done' => collect($entries)->where('source', 'curseforge')->where('analyzed', true)->count(),
            ]);
        }

        if (collect($entries)->contains('analyzed', false)) {
            return false;
        }

        $this->classify($sync);
        $this->plan($sync);

        $pack = $sync->pack;
        $pack['analyzed_at'] = now()->toIso8601String();

        $sync->update([
            'status' => SyncStatus::Ready,
            'pack' => $pack,
            'message' => null,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array{fileName: string, displayName: string, fileLength: int, gameVersions: string[]}|null  $file
     * @return array<string, mixed>
     */
    protected function analyzeEntry(array $entry, ?array $file, ?string $loader): array
    {
        $entry['analyzed'] = true;

        if ($file === null) {
            $entry['error'] = trans('curseforge-sync::strings.errors.lookup_failed');

            return $entry;
        }

        $entry['filename'] = basename($file['fileName']);
        $hasName = !str_starts_with($entry['name'], '#');
        if (!$hasName) {
            $entry['name'] = Str::beforeLast($file['displayName'], '.jar');
        }
        $entry['tags'] = $file['gameVersions'];
        $entry['kind'] = str_ends_with(strtolower($entry['filename']), '.jar') ? 'mod' : 'pack';

        if ($entry['kind'] !== 'mod') {
            return $entry;
        }

        try {
            $entry['download_url'] = $this->curseForge->resolveDownloadUrl($entry['project_id'], $entry['file_id'], $entry['filename']);
        } catch (Throwable $exception) {
            report($exception);

            $entry['error'] = trans('curseforge-sync::strings.errors.url_failed');

            return $entry;
        }

        $cacheKey = "curseforge-sync:meta:{$entry['file_id']}:" . ($loader ?? 'any');
        $meta = cache()->get($cacheKey);

        if (!is_array($meta)) {
            try {
                $meta = ModMetadata::parse($this->zipReader->read($entry['download_url'], ModMetadata::FILES), $loader)->toArray();

                cache()->forever($cacheKey, $meta);
            } catch (Throwable $exception) {
                // not fatal, the CurseForge tags still give a decent answer
                report($exception);

                $meta = (new ModMetadata())->toArray();
            }
        }

        $entry['meta'] = $meta;
        $entry['mod_ids'] = $meta['mod_ids'];
        $entry['deps'] = $meta['requires'];
        if (!$hasName) {
            $entry['name'] = $meta['name'] ?? $entry['name'];
        }

        return $entry;
    }

    /**
     * (Re)decide the side of every mod, e.g. after the user changed an override.
     */
    public function classify(CurseForgeSync $sync): void
    {
        $overrides = $sync->overrides ?? [];
        $entries = $sync->entries ?? [];

        foreach ($entries as $index => $entry) {
            if ($entry['source'] === 'server') {
                continue;
            }

            $override = $overrides[$entry['key']] ?? null;

            if ($entry['kind'] === 'pack') {
                // resource packs / shaders, nothing a server can use and they don't belong in the mods folder
                $side = ModSide::Client;
                $reason = trans('curseforge-sync::strings.reason.not_a_mod');
            } elseif (!$entry['required'] && $override === null) {
                $side = ModSide::Client;
                $reason = trans('curseforge-sync::strings.reason.disabled_in_pack');
            } else {
                ['side' => $side, 'reason' => $reason] = $this->classifier->classify(
                    $entry['project_id'],
                    ModMetadata::fromArray($entry['meta'] ?? []),
                    $entry['tags'],
                    $override,
                );
            }

            $entries[$index]['side'] = $side->value;
            $entries[$index]['reason'] = $reason;
        }

        $sync->update(['entries' => $this->classifier->protectDependencies($entries, $overrides)]);
    }

    /**
     * Compare the classified modpack with what is in the mods folder and decide what to do with every file.
     *
     * @throws Exception when the server's files can't be listed
     */
    public function plan(CurseForgeSync $sync): void
    {
        $listing = collect(ServerFiles::for($sync->server)->list(self::MODS_FOLDER))->filter(fn ($file) => $file['is_file']);
        $managed = $sync->managed ?? [];

        // drop managed files that disappeared from the server
        $managed = array_filter($managed, fn ($file) => $listing->has($file['filename']));

        $entries = array_values(array_filter($sync->entries ?? [], fn ($entry) => $entry['source'] !== 'server'));
        $claimed = [];

        foreach ($entries as $index => $entry) {
            $entry['old_filename'] = null;
            $entry['state'] = null;

            $wanted = $entry['side'] !== ModSide::Client->value;
            $present = $entry['filename'] !== '' && $listing->has($entry['filename']);
            $previous = $managed[$entry['key']]['filename'] ?? null;
            $previousPresent = $previous !== null && $previous !== $entry['filename'] && $listing->has($previous);

            if ($wanted) {
                $action = match (true) {
                    $present => EntryAction::Keep,
                    $entry['source'] === 'curseforge' && empty($entry['download_url']) => EntryAction::Manual,
                    $previousPresent => EntryAction::Update,
                    default => EntryAction::Install,
                };

                if ($action === EntryAction::Update || ($action === EntryAction::Keep && $previousPresent)) {
                    $entry['old_filename'] = $previous;
                }

                if ($action === EntryAction::Keep && $previousPresent) {
                    // already downloaded, only the old version is left over
                    $action = EntryAction::Update;
                }
            } else {
                $action = match (true) {
                    $present => EntryAction::Remove,
                    $previousPresent => EntryAction::Remove,
                    default => EntryAction::Skip,
                };

                if (!$present && $previousPresent) {
                    $entry['old_filename'] = $previous;
                }
            }

            if ($action->changesServer()) {
                $entry['state'] = 'pending';
            }

            // adopt files that are already there, so the next modpack version knows which jar to replace
            if ($action === EntryAction::Keep) {
                $managed[$entry['key']] = [
                    'filename' => $entry['filename'],
                    'project_id' => $entry['project_id'],
                    'file_id' => $entry['file_id'],
                ];
            }

            $entry['action'] = $action->value;
            $entries[$index] = $entry;

            $claimed[] = $entry['filename'];
            if ($previous !== null) {
                $claimed[] = $previous;
            }
        }

        $packKeys = array_column($entries, 'key');

        foreach ($listing as $filename => $file) {
            if (in_array($filename, $claimed, true) || !str_ends_with(strtolower($filename), '.jar')) {
                continue;
            }

            $managedKey = collect($managed)->search(fn ($managedFile) => $managedFile['filename'] === $filename);
            $droppedFromPack = $managedKey !== false && !in_array($managedKey, $packKeys, true);

            $action = $droppedFromPack || $sync->remove_unmanaged ? EntryAction::Remove : EntryAction::Ignore;

            $entry = $this->newEntry(key: 'srv:' . $filename, source: 'server', name: Str::beforeLast($filename, '.jar'));
            $entry['filename'] = $filename;
            $entry['kind'] = 'mod';
            $entry['analyzed'] = true;
            $entry['reason'] = trans('curseforge-sync::strings.reason.' . ($droppedFromPack ? 'dropped_from_pack' : 'unmanaged'));
            $entry['action'] = $action->value;
            $entry['state'] = $action->changesServer() ? 'pending' : null;

            $entries[] = $entry;
        }

        $sync->update([
            'entries' => $entries,
            'managed' => $managed,
        ]);
    }

    /**
     * Work through pending downloads / removals, then the overrides.
     *
     * @return bool true once everything is done
     *
     * @throws Exception
     */
    public function applyStep(CurseForgeSync $sync): bool
    {
        $started = microtime(true);
        $files = ServerFiles::for($sync->server);
        $entries = $sync->entries ?? [];
        $managed = $sync->managed ?? [];
        $pack = $sync->pack ?? [];

        foreach ($entries as $index => $entry) {
            if (microtime(true) - $started > self::STEP_BUDGET) {
                break;
            }

            if (!$this->needsWork($entry)) {
                continue;
            }

            try {
                $action = EntryAction::from($entry['action']);

                if ($action === EntryAction::Remove || $entry['old_filename']) {
                    if (empty($pack['quarantine_ready'])) {
                        $files->ensureFolder($pack['quarantine']);
                        $pack['quarantine_ready'] = true;
                    }
                }

                if ($action === EntryAction::Install || $action === EntryAction::Update) {
                    $this->installEntry($sync, $files, $entry);

                    $managed[$entry['key']] = [
                        'filename' => $entry['filename'],
                        'project_id' => $entry['project_id'],
                        'file_id' => $entry['file_id'],
                    ];
                }

                if ($action === EntryAction::Remove) {
                    $filename = $entry['old_filename'] ?? $entry['filename'];
                    $this->quarantine($files, $pack['quarantine'], $filename);

                    $managed = array_filter($managed, fn ($file) => $file['filename'] !== $filename);
                } elseif ($entry['old_filename']) {
                    // update: the new file is in place, move the old version away
                    $this->quarantine($files, $pack['quarantine'], $entry['old_filename']);
                }

                $entries[$index]['state'] = 'done';
                $entries[$index]['state_error'] = null;
            } catch (Throwable $exception) {
                report($exception);

                $entries[$index]['state'] = 'failed';
                $entries[$index]['state_error'] = Str::limit($exception->getMessage(), 250);
            }

            $sync->update([
                'entries' => $entries,
                'managed' => $managed,
                'pack' => $pack,
                'progress_done' => $sync->progress_done + 1,
            ]);
        }

        if (collect($entries)->contains(fn ($entry) => $this->needsWork($entry))) {
            return false;
        }

        if (empty($pack['overrides_applied'])) {
            if (microtime(true) - $started > self::STEP_BUDGET / 2) {
                // start the overrides with a fresh time budget
                return false;
            }

            $this->applyOverrides($sync, $files);

            $pack['overrides_applied'] = true;
            $sync->update(['pack' => $pack, 'progress_done' => $sync->progress_done + 1]);
        }

        $this->finish($sync);

        return true;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function needsWork(array $entry): bool
    {
        return ($entry['state'] ?? null) === 'pending';
    }

    /**
     * @param  array<string, mixed>  $entry
     *
     * @throws Exception
     */
    protected function installEntry(CurseForgeSync $sync, ServerFiles $files, array $entry): void
    {
        $filename = $this->safeFilename($entry['filename']);

        if ($entry['source'] === 'override') {
            $content = (new ModpackArchive($sync->pack['archive']))->readOverride(self::MODS_FOLDER . '/' . $filename);
            throw_if($content === null, new Exception("$filename is missing from the modpack"));

            $files->put(self::MODS_FOLDER . '/' . $filename, $content);

            return;
        }

        throw_if(empty($entry['download_url']), new Exception("No download url for $filename"));

        $files->pull($entry['download_url'], self::MODS_FOLDER, $filename);
    }

    /** @throws Exception */
    protected function quarantine(ServerFiles $files, string $quarantine, string $filename): void
    {
        $filename = $this->safeFilename($filename);

        $files->move(self::MODS_FOLDER . '/' . $filename, $quarantine . '/' . $filename);
    }

    /**
     * Copy the overrides (configs, kubejs scripts, ...) to the server, without client-only files.
     *
     * @throws Exception
     */
    protected function applyOverrides(CurseForgeSync $sync, ServerFiles $files): void
    {
        $archive = new ModpackArchive($sync->pack['archive']);

        $temp = tempnam(sys_get_temp_dir(), 'cfsync-overrides');
        throw_if($temp === false, new Exception('Could not create a temporary file'));

        try {
            $count = $archive->buildOverridesZip($temp, (array) config('curseforge-sync.client_only_paths', []));

            if ($count === 0) {
                return;
            }

            $remote = $sync->stateFolder() . '/overrides.zip';

            $files->ensureFolder($sync->stateFolder());
            $files->put($remote, (string) file_get_contents($temp));
            $files->decompress('/', $remote);

            try {
                $files->delete($remote);
            } catch (Exception $exception) {
                report($exception);
            }
        } finally {
            @unlink($temp);
        }
    }

    protected function finish(CurseForgeSync $sync): void
    {
        $failed = collect($sync->entries)->where('state', 'failed');

        $restarted = false;
        if ($sync->restart_after) {
            try {
                if ($sync->server->retrieveStatus()->isStartingOrRunning()) {
                    app(DaemonServerRepository::class)->setServer($sync->server)->power('restart');
                    $restarted = true;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        // a fresh plan shows the result (everything "keep") and lets failed files be retried
        try {
            $this->plan($sync);
        } catch (Throwable $exception) {
            report($exception);
        }

        $sync->update([
            'status' => $failed->isEmpty() ? SyncStatus::Synced : SyncStatus::Failed,
            'run_id' => null,
            'last_synced_at' => now(),
            'message' => $failed->isEmpty()
                ? trans('curseforge-sync::strings.messages.synced' . ($restarted ? '_restarted' : ''))
                : trans('curseforge-sync::strings.messages.synced_with_errors', [
                    'count' => $failed->count(),
                    'files' => $failed->map(fn ($entry) => $entry['filename'] . ': ' . $entry['state_error'])->implode('; '),
                ]),
        ]);
    }

    /** @throws Exception */
    protected function safeFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception("Invalid file name: $filename");
        }

        return $filename;
    }

    /**
     * @return array<string, mixed>
     */
    protected function newEntry(string $key, string $source, string $name, ?int $projectId = null, ?int $fileId = null, bool $required = true): array
    {
        return [
            'key' => $key,
            'source' => $source,
            'project_id' => $projectId,
            'file_id' => $fileId,
            'required' => $required,
            'filename' => '',
            'name' => $name,
            'author' => null,
            'url' => null,
            'mod_ids' => [],
            'deps' => [],
            'tags' => [],
            'meta' => null,
            'kind' => 'mod',
            'download_url' => null,
            'analyzed' => false,
            'error' => null,
            'side' => null,
            'reason' => null,
            'action' => null,
            'old_filename' => null,
            'state' => null,
            'state_error' => null,
        ];
    }
}
