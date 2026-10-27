<?php

namespace Devin\CurseForgeSync\Services;

use Devin\CurseForgeSync\Enums\ModSide;

/**
 * What a mod jar says about itself.
 */
class ModMetadata
{
    public const FILES = [
        'META-INF/neoforge.mods.toml',
        'META-INF/mods.toml',
        'fabric.mod.json',
        'quilt.mod.json',
    ];

    protected const IGNORED_DEPENDENCIES = ['minecraft', 'neoforge', 'forge', 'java', 'fabricloader', 'quilt_loader', 'fml'];

    /**
     * @param  string[]  $modIds
     * @param  string[]  $requires  mod ids this mod needs on a dedicated server
     * @param  array{side: ModSide, strong: bool, reason: string}|null  $signal
     */
    public function __construct(
        public array $modIds = [],
        public ?string $name = null,
        public array $requires = [],
        public ?array $signal = null,
    ) {}

    /**
     * @param  array<string, string>  $files  metadata file name => contents
     */
    public static function parse(array $files, ?string $loader): self
    {
        $fabricFirst = in_array($loader, ['fabric', 'quilt'], true);

        $order = $fabricFirst
            ? ['quilt.mod.json', 'fabric.mod.json', 'META-INF/neoforge.mods.toml', 'META-INF/mods.toml']
            : ['META-INF/neoforge.mods.toml', 'META-INF/mods.toml', 'quilt.mod.json', 'fabric.mod.json'];

        foreach ($order as $file) {
            if (!isset($files[$file])) {
                continue;
            }

            $metadata = match ($file) {
                'quilt.mod.json' => self::parseQuilt($files[$file]),
                'fabric.mod.json' => self::parseFabric($files[$file]),
                default => self::parseToml($files[$file]),
            };

            if ($metadata) {
                return $metadata;
            }
        }

        return new self();
    }

    protected static function parseFabric(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $environment = $data['environment'] ?? '*';

        return new self(
            modIds: isset($data['id']) ? [strtolower((string) $data['id'])] : [],
            name: isset($data['name']) ? (string) $data['name'] : null,
            requires: self::filterDependencies(array_keys(is_array($data['depends'] ?? null) ? $data['depends'] : [])),
            signal: self::environmentSignal($environment, 'fabric.mod.json'),
        );
    }

    protected static function parseQuilt(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['quilt_loader'] ?? null)) {
            return null;
        }

        $requires = [];
        foreach ($data['quilt_loader']['depends'] ?? [] as $dependency) {
            if (is_string($dependency)) {
                $requires[] = $dependency;
            } elseif (is_array($dependency) && isset($dependency['id']) && empty($dependency['optional'])) {
                $requires[] = (string) $dependency['id'];
            }
        }

        return new self(
            modIds: isset($data['quilt_loader']['id']) ? [strtolower((string) $data['quilt_loader']['id'])] : [],
            name: $data['quilt_loader']['metadata']['name'] ?? null,
            requires: self::filterDependencies($requires),
            signal: self::environmentSignal($data['minecraft']['environment'] ?? '*', 'quilt.mod.json'),
        );
    }

    /**
     * @return array{side: ModSide, strong: bool, reason: string}|null
     */
    protected static function environmentSignal(mixed $environment, string $file): ?array
    {
        return match ($environment) {
            'client' => ['side' => ModSide::Client, 'strong' => true, 'reason' => trans('curseforge-sync::strings.reason.environment', ['file' => $file, 'value' => 'client'])],
            'server', 'dedicated_server' => ['side' => ModSide::Server, 'strong' => true, 'reason' => trans('curseforge-sync::strings.reason.environment', ['file' => $file, 'value' => 'server'])],
            '*' => ['side' => ModSide::Both, 'strong' => true, 'reason' => trans('curseforge-sync::strings.reason.environment', ['file' => $file, 'value' => '*'])],
            default => null,
        };
    }

    /**
     * A small line based reader for (neo)forge mods.toml files, it only looks at the keys we need.
     */
    protected static function parseToml(string $toml): ?self
    {
        $modIds = [];
        $name = null;
        $displayTest = null;
        $clientSideOnly = false;

        /** @var array<int, array{modId?: string, type?: string, mandatory?: string, side?: string}> $dependencies */
        $dependencies = [];

        $section = '';
        $inMultiline = false;

        foreach (preg_split('/\R/', $toml) ?: [] as $line) {
            // skip multi line strings (descriptions)
            $quotes = substr_count($line, "'''") + substr_count($line, '"""');
            if ($inMultiline) {
                if ($quotes % 2 === 1) {
                    $inMultiline = false;
                }

                continue;
            }

            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\[\[\s*([^\]]+?)\s*\]\]/', $line, $matches)) {
                $section = $matches[1];
                if (str_starts_with($section, 'dependencies')) {
                    $dependencies[] = [];
                }

                continue;
            }

            if (preg_match('/^\[\s*([^\]]+?)\s*\]/', $line, $matches)) {
                $section = '[' . $matches[1] . ']';

                continue;
            }

            if (!preg_match('/^([A-Za-z0-9_\-]+)\s*=\s*(.+)$/', $line, $matches)) {
                continue;
            }

            [, $key, $raw] = $matches;

            if ($quotes % 2 === 1) {
                $inMultiline = true;

                continue;
            }

            $value = self::tomlValue($raw);

            if ($key === 'clientSideOnly' && $value === 'true') {
                $clientSideOnly = true;
            }

            if ($section === 'mods') {
                match ($key) {
                    'modId' => $modIds[] = strtolower($value),
                    'displayName' => $name ??= $value,
                    'displayTest' => $displayTest ??= $value,
                    default => null,
                };
            } elseif (str_starts_with($section, 'dependencies') && in_array($key, ['modId', 'type', 'mandatory', 'side'], true)) {
                $dependencies[array_key_last($dependencies)][$key] = $value;
            }
        }

        if (empty($modIds)) {
            return null;
        }

        $requires = [];
        $sides = [];
        foreach ($dependencies as $dependency) {
            $side = strtoupper($dependency['side'] ?? 'BOTH');
            $sides[] = $side;

            $required = isset($dependency['type'])
                ? strtolower($dependency['type']) === 'required'
                : ($dependency['mandatory'] ?? 'true') === 'true';

            if ($required && $side !== 'CLIENT' && isset($dependency['modId'])) {
                $requires[] = $dependency['modId'];
            }
        }

        $signal = null;
        if ($clientSideOnly) {
            $signal = ['side' => ModSide::Client, 'strong' => true, 'reason' => trans('curseforge-sync::strings.reason.client_side_only')];
        } elseif (!empty($sides) && count(array_unique($sides)) === 1 && $sides[0] === 'CLIENT') {
            $signal = ['side' => ModSide::Client, 'strong' => true, 'reason' => trans('curseforge-sync::strings.reason.client_dependencies')];
        } elseif ($displayTest === 'IGNORE_ALL_VERSION') {
            $signal = ['side' => ModSide::Client, 'strong' => false, 'reason' => trans('curseforge-sync::strings.reason.display_test')];
        } elseif ($displayTest === 'IGNORE_SERVER_VERSION') {
            $signal = ['side' => ModSide::Server, 'strong' => false, 'reason' => trans('curseforge-sync::strings.reason.display_test_server')];
        }

        return new self(
            modIds: array_values(array_unique($modIds)),
            name: $name !== null && !str_contains($name, '${') ? $name : null,
            requires: self::filterDependencies($requires),
            signal: $signal,
        );
    }

    protected static function tomlValue(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches) || preg_match("/^'([^']*)'/", $raw, $matches)) {
            return $matches[1];
        }

        // strip trailing comments of bare values
        return trim(explode('#', $raw, 2)[0]);
    }

    /**
     * @param  string[]  $dependencies
     * @return string[]
     */
    protected static function filterDependencies(array $dependencies): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($dependency) => strtolower(trim((string) $dependency)), $dependencies),
            // fabric api modules (fabric-*-v1) are bundled inside the fabric-api jar, so only fabric-api itself can be matched
            fn ($dependency) => $dependency !== '' && !in_array($dependency, self::IGNORED_DEPENDENCIES, true) && (!str_starts_with($dependency, 'fabric-') || $dependency === 'fabric-api'),
        )));
    }

    /**
     * @return array{mod_ids: string[], name: ?string, requires: string[], signal: array{side: string, strong: bool, reason: string}|null}
     */
    public function toArray(): array
    {
        return [
            'mod_ids' => $this->modIds,
            'name' => $this->name,
            'requires' => $this->requires,
            'signal' => $this->signal ? [...$this->signal, 'side' => $this->signal['side']->value] : null,
        ];
    }

    /**
     * @param  array{mod_ids?: string[], name?: ?string, requires?: string[], signal?: array{side: string, strong: bool, reason: string}|null}  $data
     */
    public static function fromArray(array $data): self
    {
        $signal = $data['signal'] ?? null;

        return new self(
            modIds: $data['mod_ids'] ?? [],
            name: $data['name'] ?? null,
            requires: $data['requires'] ?? [],
            signal: $signal ? [...$signal, 'side' => ModSide::from($signal['side'])] : null,
        );
    }
}
