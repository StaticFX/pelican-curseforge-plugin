<?php

namespace Devin\CurseForgeSync\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Traits\Filament\BlockAccessInConflict;
use Devin\CurseForgeSync\Enums\EntryAction;
use Devin\CurseForgeSync\Enums\ModSide;
use Devin\CurseForgeSync\Enums\SyncStatus;
use Devin\CurseForgeSync\Models\CurseForgeSync;
use Devin\CurseForgeSync\Services\CurseForgeClient;
use Devin\CurseForgeSync\Services\ServerFiles;
use Devin\CurseForgeSync\Services\SyncService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CurseForgeSyncPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'curseforge-sync';

    protected static ?int $navigationSort = 31;

    protected const MINECRAFT_VARIABLES = ['MC_VERSION', 'MINECRAFT_VERSION'];

    protected ?CurseForgeSync $sync = null;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess()
            && user()?->can(SubuserPermission::FileRead, $server)
            && static::isMinecraftServer($server);
    }

    protected static function isMinecraftServer(Server $server): bool
    {
        $tags = (array) config('curseforge-sync.egg_tags', []);

        if (empty($tags)) {
            return true;
        }

        $server->loadMissing('egg');

        if (array_intersect($tags, $server->egg->tags ?? [])) {
            return true;
        }

        // plenty of minecraft eggs have no tags, recognize them by their variables
        return $server->egg->variables()->whereIn('env_variable', [...self::MINECRAFT_VARIABLES, 'NEOFORGE_VERSION', 'FORGE_VERSION'])->exists();
    }

    public static function getNavigationLabel(): string
    {
        return trans('curseforge-sync::strings.navigation');
    }

    public function getTitle(): string
    {
        return trans('curseforge-sync::strings.title');
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    protected function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    protected function getSync(): CurseForgeSync
    {
        return $this->sync ??= CurseForgeSync::forServer($this->server());
    }

    protected function refreshSync(): void
    {
        $this->sync = null;
        $this->resetTable();
    }

    protected function can(SubuserPermission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!user()?->can($permission, $this->server())) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('curseforge-sync::strings.tabs.all')),
            'server' => Tab::make(trans('curseforge-sync::strings.tabs.server')),
            'client' => Tab::make(trans('curseforge-sync::strings.tabs.client')),
            'changes' => Tab::make(trans('curseforge-sync::strings.tabs.changes')),
            'problems' => Tab::make(trans('curseforge-sync::strings.tabs.problems')),
        ];
    }

    /**
     * @return Collection<array-key, array<string, mixed>>
     */
    protected function filteredEntries(?string $search): Collection
    {
        /** @var Collection<array-key, array<string, mixed>> $entries */
        $entries = collect($this->getSync()->entries ?? [])->keyBy('key');

        $entries = match ($this->activeTab) {
            'server' => $entries->filter(fn ($entry) => in_array($entry['action'], [EntryAction::Keep->value, EntryAction::Install->value, EntryAction::Update->value, EntryAction::Manual->value, EntryAction::Ignore->value])),
            'client' => $entries->filter(fn ($entry) => $entry['side'] === ModSide::Client->value),
            'changes' => $entries->filter(fn ($entry) => $entry['action'] && EntryAction::from($entry['action'])->changesServer()),
            'problems' => $entries->filter(fn ($entry) => $entry['error'] || $entry['state'] === 'failed' || $entry['action'] === EntryAction::Manual->value),
            default => $entries,
        };

        if ($search) {
            $search = strtolower($search);
            $entries = $entries->filter(fn ($entry) => str_contains(strtolower($entry['name'] . ' ' . $entry['filename'] . ' ' . ($entry['author'] ?? '') . ' ' . implode(' ', $entry['mod_ids'])), $search));
        }

        $order = [EntryAction::Manual, EntryAction::Remove, EntryAction::Update, EntryAction::Install, EntryAction::Keep, EntryAction::Ignore, EntryAction::Skip];
        $order = array_flip(array_map(fn (EntryAction $action) => $action->value, $order));

        return $entries->sortBy([
            fn ($a, $b) => ($a['state'] === 'failed' ? 0 : 1) <=> ($b['state'] === 'failed' ? 0 : 1),
            fn ($a, $b) => ($order[$a['action']] ?? 99) <=> ($order[$b['action']] ?? 99),
            fn ($a, $b) => strcasecmp($a['name'], $b['name']),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, int|string $recordsPerPage) {
                $entries = $this->filteredEntries($search);
                $perPage = $recordsPerPage === 'all' ? max(1, $entries->count()) : (int) $recordsPerPage;

                return new LengthAwarePaginator($entries->forPage($page, $perPage), $entries->count(), $perPage, $page);
            })
            ->paginated([25, 50, 100, 'all'])
            ->defaultPaginationPageOption(50)
            ->poll(fn () => $this->getSync()->isBusy() ? '3s' : null)
            ->emptyStateHeading(fn () => $this->getSync()->pack ? null : trans('curseforge-sync::strings.errors.no_pack'))
            ->emptyStateIcon('tabler-packages')
            ->columns([
                TextColumn::make('name')
                    ->label(trans('curseforge-sync::strings.fields.name'))
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (array $record) => implode(' · ', array_filter([
                        ($record['author'] ?? null) ? 'by ' . $record['author'] : null,
                        $record['filename'] ?: null,
                    ])) ?: null)
                    ->url(fn (array $record) => ($record['url'] ?? null) ?: ($record['project_id'] ? app(CurseForgeClient::class)->projectUrl($record['project_id']) : null), true),
                TextColumn::make('side')
                    ->label(trans('curseforge-sync::strings.fields.side'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? ModSide::from($state)->getLabel() : '…')
                    ->color(fn (?string $state) => $state ? ModSide::from($state)->getColor() : 'gray')
                    ->icon(fn (array $record) => isset($this->getSync()->overrides[$record['key']]) ? 'tabler-user' : null),
                TextColumn::make('reason')
                    ->label(trans('curseforge-sync::strings.fields.reason'))
                    ->color('gray')
                    ->wrap()
                    ->state(fn (array $record) => $record['error'] ?? $record['reason'])
                    ->toggleable(),
                TextColumn::make('action')
                    ->label(trans('curseforge-sync::strings.fields.action'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? EntryAction::from($state)->getLabel() : '…')
                    ->color(fn (array $record) => match ($record['state']) {
                        'failed' => 'danger',
                        'done' => 'success',
                        default => $record['action'] ? EntryAction::from($record['action'])->getColor() : 'gray',
                    })
                    ->icon(fn (array $record) => match ($record['state']) {
                        'failed' => 'tabler-x',
                        'done' => 'tabler-check',
                        default => $record['action'] ? EntryAction::from($record['action'])->getIcon() : null,
                    })
                    ->tooltip(fn (array $record) => $record['state_error'] ?? ($record['old_filename'] ? '← ' . $record['old_filename'] : null)),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->overrideAction('include', 'tabler-server', 'success'),
                    $this->overrideAction('exclude', 'tabler-device-desktop', 'gray'),
                    $this->overrideAction(null, 'tabler-wand', 'info'),
                ]),
            ]);
    }

    protected function overrideAction(?string $value, string $icon, string $color): Action
    {
        return Action::make('override_' . ($value ?? 'reset'))
            ->label(trans('curseforge-sync::strings.actions.' . ($value ?? 'reset')))
            ->icon($icon)
            ->color($color)
            ->visible(function (array $record) use ($value) {
                if ($record['source'] === 'server' || $record['kind'] !== 'mod' || $this->getSync()->isBusy() || !$this->can(SubuserPermission::FileUpdate)) {
                    return false;
                }

                $current = $this->getSync()->overrides[$record['key']] ?? null;

                return $value === null ? $current !== null : $current !== $value;
            })
            ->action(function (array $record, SyncService $service) use ($value) {
                $sync = $this->getSync();

                $overrides = $sync->overrides ?? [];
                if ($value === null) {
                    unset($overrides[$record['key']]);
                } else {
                    $overrides[$record['key']] = $value;
                }

                $sync->update(['overrides' => $overrides]);

                try {
                    $service->classify($sync);
                    $service->plan($sync);

                    if ($sync->status === SyncStatus::Synced) {
                        $sync->update(['status' => SyncStatus::Ready]);
                    }

                    Notification::make()->title(trans('curseforge-sync::strings.messages.override_saved'))->success()->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()->title(trans('curseforge-sync::strings.errors.failed'))->body($exception->getMessage())->danger()->send();
                }

                $this->refreshSync();
            });
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'sm' => 2, 'lg' => 5])
                ->schema([
                    TextEntry::make('modpack')
                        ->label(trans('curseforge-sync::strings.fields.modpack'))
                        ->state(fn () => $this->getSync()->pack ? trim($this->getSync()->pack['name'] . ' ' . $this->getSync()->pack['version']) : '—')
                        ->helperText(fn () => ($this->getSync()->pack['author'] ?? '') ?: null),
                    TextEntry::make('minecraft')
                        ->label(trans('curseforge-sync::strings.fields.minecraft'))
                        ->state(fn () => $this->getSync()->pack['minecraft'] ?? '—')
                        ->badge()
                        ->color(fn () => $this->variableMismatches() ? 'warning' : 'gray')
                        ->helperText(fn () => ($value = $this->serverVariableValue(self::MINECRAFT_VARIABLES)) !== null ? trans('curseforge-sync::strings.fields.server_value', ['value' => $value ?: '—']) : null),
                    TextEntry::make('loader')
                        ->label(trans('curseforge-sync::strings.fields.loader'))
                        ->state(fn () => ($loader = $this->getSync()->pack['loader'] ?? null) ? $this->loaderName($loader['type']) . ' ' . $loader['version'] : '—')
                        ->badge()
                        ->color(fn () => $this->variableMismatches() ? 'warning' : 'gray')
                        ->helperText(function () {
                            $variables = array_keys($this->loaderVariables());

                            return $variables && ($value = $this->serverVariableValue($variables)) !== null ? trans('curseforge-sync::strings.fields.server_value', ['value' => $value ?: '—']) : null;
                        }),
                    TextEntry::make('status')
                        ->label(trans('curseforge-sync::strings.fields.status'))
                        ->state(fn () => $this->getSync()->status)
                        ->badge()
                        ->helperText(function () {
                            $sync = $this->getSync();

                            if ($sync->isBusy()) {
                                return trans('curseforge-sync::strings.messages.progress', ['done' => $sync->progress_done, 'total' => $sync->progress_total])
                                    . ($sync->updated_at->diffInSeconds(now()) > 60 ? ' · ' . trans('curseforge-sync::strings.messages.queue_hint') : '');
                            }

                            return $sync->message;
                        }),
                    TextEntry::make('last_synced')
                        ->label(trans('curseforge-sync::strings.fields.last_synced'))
                        ->state(fn () => $this->getSync()->last_synced_at?->diffForHumans() ?? trans('curseforge-sync::strings.fields.never'))
                        ->helperText(fn () => $this->getSync()->auto_sync ? '⟳ /' . $this->getSync()->watchPath() : null),
                ]),
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $busy = fn () => $this->getSync()->isBusy();

        return [
            Action::make('cancel')
                ->label(trans('curseforge-sync::strings.actions.cancel'))
                ->icon('tabler-player-stop')
                ->color('danger')
                ->visible($busy)
                ->requiresConfirmation()
                ->action(function (SyncService $service) {
                    $service->cancel($this->getSync());
                    $this->refreshSync();
                }),

            Action::make('apply')
                ->label(trans('curseforge-sync::strings.actions.apply'))
                ->icon('tabler-refresh')
                ->color('success')
                ->visible(fn () => !$busy() && $this->getSync()->pack && $this->can(SubuserPermission::FileCreate, SubuserPermission::FileUpdate, SubuserPermission::FileDelete))
                ->modalHeading(fn () => trans('curseforge-sync::strings.actions.apply_heading', ['name' => $this->getSync()->pack['name'] ?? '']))
                ->modalDescription(function () {
                    $counts = collect($this->getSync()->entries ?? [])->countBy('action');

                    return trans('curseforge-sync::strings.actions.apply_description', [
                        'install' => $counts[EntryAction::Install->value] ?? 0,
                        'update' => $counts[EntryAction::Update->value] ?? 0,
                        'remove' => $counts[EntryAction::Remove->value] ?? 0,
                        'folder' => $this->getSync()->stateFolder() . '/removed',
                    ]);
                })
                ->fillForm(fn () => [
                    'remove_unmanaged' => $this->getSync()->remove_unmanaged,
                    'apply_overrides' => $this->getSync()->apply_overrides,
                    'restart_after' => $this->getSync()->restart_after,
                ])
                ->schema([
                    Toggle::make('remove_unmanaged')->label(trans('curseforge-sync::strings.actions.remove_unmanaged')),
                    Toggle::make('apply_overrides')
                        ->label(trans('curseforge-sync::strings.actions.apply_overrides'))
                        ->helperText(trans('curseforge-sync::strings.actions.apply_overrides_help')),
                    Toggle::make('restart_after')
                        ->label(trans('curseforge-sync::strings.actions.restart_after'))
                        ->visible(fn () => $this->can(SubuserPermission::ControlRestart)),
                ])
                ->action(function (array $data, SyncService $service) {
                    $sync = $this->getSync();
                    $sync->update([
                        'remove_unmanaged' => $data['remove_unmanaged'] ?? false,
                        'apply_overrides' => $data['apply_overrides'] ?? true,
                        'restart_after' => ($data['restart_after'] ?? false) && $this->can(SubuserPermission::ControlRestart),
                    ]);

                    $this->runSafely(fn () => $service->startApply($sync), 'apply_started');
                }),

            Action::make('upload')
                ->label(trans('curseforge-sync::strings.actions.upload'))
                ->icon('tabler-upload')
                ->visible(fn () => !$busy() && $this->can(SubuserPermission::FileCreate, SubuserPermission::FileUpdate))
                ->schema([
                    FileUpload::make('file')
                        ->label(trans('curseforge-sync::strings.actions.upload_file'))
                        ->helperText(trans('curseforge-sync::strings.actions.upload_help'))
                        ->disk('local')
                        ->directory('curseforge-sync/uploads')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/octet-stream'])
                        ->maxSize((int) config('curseforge-sync.max_upload_size'))
                        ->required(),
                    Toggle::make('auto_apply')
                        ->label(trans('curseforge-sync::strings.actions.auto_apply_now'))
                        ->default(false),
                ])
                ->action(function (array $data, SyncService $service) {
                    $path = Storage::disk('local')->path($data['file']);

                    $this->runSafely(
                        fn () => $service->start($this->getSync(), $path, 'upload', null, (bool) ($data['auto_apply'] ?? false)),
                        'started',
                        function () use ($path) {
                            @unlink($path);
                        },
                    );
                }),

            ActionGroup::make([
                Action::make('from_server')
                    ->label(trans('curseforge-sync::strings.actions.from_server'))
                    ->icon('tabler-file-zip')
                    ->visible(fn () => !$busy() && $this->can(SubuserPermission::FileReadContent, SubuserPermission::FileCreate, SubuserPermission::FileUpdate))
                    ->schema([
                        TextInput::make('path')
                            ->label(trans('curseforge-sync::strings.actions.from_server_path'))
                            ->helperText(trans('curseforge-sync::strings.actions.from_server_help'))
                            ->default(fn () => $this->getSync()->watchPath() . '/modpack.zip')
                            ->required()
                            ->rule('regex:/^[^\0]+\.zip$/i')
                            ->notRegex('/(^|\/)\.\.(\/|$)/'),
                        Toggle::make('auto_apply')
                            ->label(trans('curseforge-sync::strings.actions.auto_apply_now'))
                            ->default(false),
                    ])
                    ->action(function (array $data, SyncService $service) {
                        $sync = $this->getSync();
                        $temp = $service->archivePath($sync) . '.download';

                        $this->runSafely(function () use ($data, $service, $sync, $temp) {
                            ServerFiles::for($this->server())->download(ltrim($data['path'], '/'), $temp);

                            $service->start($sync, $temp, 'server', null, (bool) ($data['auto_apply'] ?? false));
                        }, 'started', function () use ($temp) {
                            @unlink($temp);
                        });
                    }),

                Action::make('reanalyze')
                    ->label(trans('curseforge-sync::strings.actions.reanalyze'))
                    ->icon('tabler-zoom-scan')
                    ->visible(fn () => !$busy() && $this->getSync()->pack && is_file($this->getSync()->pack['archive']) && $this->can(SubuserPermission::FileUpdate))
                    ->action(function (SyncService $service) {
                        $sync = $this->getSync();
                        $pack = $sync->pack;

                        $this->runSafely(fn () => $service->start($sync, $pack['archive'], $pack['source'], $pack['signature'] ?? null), 'started');
                    }),

                Action::make('variables')
                    ->label(trans('curseforge-sync::strings.actions.variables'))
                    ->icon('tabler-variable')
                    ->visible(fn () => !$busy() && $this->variableMismatches() && $this->can(SubuserPermission::StartupUpdate))
                    ->requiresConfirmation()
                    ->modalDescription(fn () => trans('curseforge-sync::strings.actions.variables_description', [
                        'list' => collect($this->variableMismatches())->map(fn ($value, $variable) => "$variable = $value")->implode(', '),
                    ]))
                    ->action(fn () => $this->updateVariables()),

                Action::make('settings')
                    ->label(trans('curseforge-sync::strings.actions.settings'))
                    ->icon('tabler-settings')
                    ->visible(fn () => $this->can(SubuserPermission::FileReadContent, SubuserPermission::FileCreate, SubuserPermission::FileUpdate, SubuserPermission::FileDelete))
                    ->fillForm(fn () => [
                        'auto_sync' => $this->getSync()->auto_sync,
                        'watch_path' => $this->getSync()->watchPath(),
                        'auto_apply' => $this->getSync()->auto_apply,
                    ])
                    ->schema([
                        Toggle::make('auto_sync')
                            ->label(trans('curseforge-sync::strings.actions.auto_sync'))
                            ->helperText(trans('curseforge-sync::strings.actions.auto_sync_help'))
                            ->live(),
                        TextInput::make('watch_path')
                            ->label(trans('curseforge-sync::strings.actions.watch_path'))
                            ->prefix('/')
                            ->required(fn (Get $get) => $get('auto_sync'))
                            ->notRegex('/(^|\/)\.\.(\/|$)/')
                            ->visible(fn (Get $get) => $get('auto_sync')),
                        Toggle::make('auto_apply')
                            ->label(trans('curseforge-sync::strings.actions.auto_apply'))
                            ->helperText(trans('curseforge-sync::strings.actions.auto_apply_help'))
                            ->visible(fn (Get $get) => $get('auto_sync')),
                    ])
                    ->action(function (array $data) {
                        $this->getSync()->update([
                            'auto_sync' => (bool) $data['auto_sync'],
                            'watch_path' => trim((string) ($data['watch_path'] ?? ''), '/') ?: null,
                            'auto_apply' => (bool) ($data['auto_apply'] ?? true),
                        ]);

                        Notification::make()->title(trans('curseforge-sync::strings.messages.saved'))->success()->send();
                        $this->refreshSync();
                    }),

                Action::make('open_folder')
                    ->label(trans('curseforge-sync::strings.actions.open_folder'))
                    ->icon('tabler-folder-open')
                    ->url(fn () => ListFiles::getUrl(['path' => SyncService::MODS_FOLDER]), true),
            ]),
        ];
    }

    /**
     * @param  callable(): void  $callback
     * @param  callable(): void|null  $onFailure
     */
    protected function runSafely(callable $callback, string $successMessage, ?callable $onFailure = null): void
    {
        try {
            $callback();

            Notification::make()
                ->title(trans("curseforge-sync::strings.messages.$successMessage"))
                ->body(trans('curseforge-sync::strings.messages.started_body'))
                ->success()
                ->send();
        } catch (Throwable $exception) {
            report($exception);

            if ($onFailure) {
                $onFailure();
            }

            Notification::make()
                ->title(trans('curseforge-sync::strings.errors.failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }

        $this->refreshSync();
    }

    protected function loaderName(string $type): string
    {
        return match ($type) {
            'neoforge' => 'NeoForge',
            default => ucfirst($type),
        };
    }

    /**
     * Startup variables that hold the loader version, with the value the modpack needs.
     *
     * @return array<string, string>
     */
    protected function loaderVariables(): array
    {
        $loader = $this->getSync()->pack['loader'] ?? null;
        $minecraft = $this->getSync()->pack['minecraft'] ?? null;

        if (!$loader) {
            return [];
        }

        $version = $loader['version'];

        return match ($loader['type']) {
            'neoforge' => ['NEOFORGE_VERSION' => $version],
            // the forge eggs want "<minecraft>-<forge>", e.g. 1.20.1-47.2.0
            'forge' => ['FORGE_VERSION' => $minecraft ? "$minecraft-$version" : $version],
            'fabric' => ['FABRIC_LOADER_VERSION' => $version, 'LOADER_VERSION' => $version],
            'quilt' => ['QUILT_LOADER_VERSION' => $version, 'LOADER_VERSION' => $version],
            default => [],
        };
    }

    /**
     * @param  string[]  $names
     */
    protected function serverVariableValue(array $names): ?string
    {
        $variable = $this->serverVariables()->first(fn (EggVariable $variable) => in_array($variable->env_variable, $names, true));

        return $variable ? (string) ($variable->server_value ?? $variable->default_value) : null;
    }

    /** @var Collection<int, EggVariable>|null */
    protected ?Collection $serverVariablesCache = null;

    /**
     * @return Collection<int, EggVariable>
     */
    protected function serverVariables(): Collection
    {
        return $this->serverVariablesCache ??= $this->server()->variables()->get();
    }

    /**
     * Variables of this server whose value differs from what the modpack needs.
     *
     * @return array<string, string> env variable => wanted value
     */
    protected function variableMismatches(): array
    {
        $wanted = $this->loaderVariables();

        if ($minecraft = $this->getSync()->pack['minecraft'] ?? null) {
            foreach (self::MINECRAFT_VARIABLES as $name) {
                $wanted[$name] = $minecraft;
            }
        }

        $mismatches = [];

        foreach ($this->serverVariables() as $variable) {
            if (!isset($wanted[$variable->env_variable]) || !$variable->user_viewable) {
                continue;
            }

            $current = (string) ($variable->server_value ?? $variable->default_value);

            if ($current !== $wanted[$variable->env_variable]) {
                $mismatches[$variable->env_variable] = $wanted[$variable->env_variable];
            }
        }

        return $mismatches;
    }

    protected function updateVariables(): void
    {
        abort_unless($this->can(SubuserPermission::StartupUpdate), 403);

        $server = $this->server();
        $updated = [];

        foreach ($this->variableMismatches() as $name => $value) {
            /** @var EggVariable|null $variable */
            $variable = $this->serverVariables()->firstWhere('env_variable', $name);

            if (!$variable || !$variable->user_editable) {
                continue;
            }

            if (Validator::make(['variable_value' => $value], ['variable_value' => $variable->rules])->fails()) {
                continue;
            }

            $old = $variable->server_value;

            ServerVariable::query()->updateOrCreate(
                ['server_id' => $server->id, 'variable_id' => $variable->id],
                ['variable_value' => $value],
            );

            Activity::event('server:startup.edit')
                ->property(['variable' => $name, 'old' => $old, 'new' => $value])
                ->log();

            $updated[] = "$name = $value";
        }

        $this->serverVariablesCache = null;

        if (empty($updated)) {
            Notification::make()->title(trans('curseforge-sync::strings.messages.variables_none'))->warning()->send();

            return;
        }

        Notification::make()
            ->title(trans('curseforge-sync::strings.messages.variables_updated', ['list' => implode(', ', $updated)]))
            ->success()
            ->send();
    }
}
