<?php

namespace Devin\CurseForgeSync\Services;

use Devin\CurseForgeSync\Enums\ModSide;

/**
 * Decides whether a mod belongs on a dedicated server.
 *
 * Signals are checked from most to least trustworthy, the first one that has an opinion wins:
 *  1. the user's own decision for this mod
 *  2. the client_only / server_required lists from the config
 *  3. explicit metadata inside the jar (fabric environment, clientSideOnly, client-only dependencies)
 *  4. the Client / Server environment tags of the file on CurseForge
 *  5. weak metadata hints (displayTest = IGNORE_ALL_VERSION)
 * Anything without a signal is installed, a missing client mod costs nothing but a missing
 * content mod can take blocks out of the world.
 */
class ModClassifier
{
    /**
     * @param  string[]  $gameVersions  CurseForge tags of the file
     * @return array{side: ModSide, reason: string}
     */
    public function classify(?int $projectId, ModMetadata $metadata, array $gameVersions, ?string $override): array
    {
        if ($override === 'include') {
            return ['side' => ModSide::Both, 'reason' => trans('curseforge-sync::strings.reason.user_include')];
        }

        if ($override === 'exclude') {
            return ['side' => ModSide::Client, 'reason' => trans('curseforge-sync::strings.reason.user_exclude')];
        }

        if ($this->matchesList('server_required', $projectId, $metadata)) {
            return ['side' => ModSide::Both, 'reason' => trans('curseforge-sync::strings.reason.config_server')];
        }

        if ($this->matchesList('client_only', $projectId, $metadata)) {
            return ['side' => ModSide::Client, 'reason' => trans('curseforge-sync::strings.reason.config_client')];
        }

        if ($metadata->signal && $metadata->signal['strong']) {
            return ['side' => $metadata->signal['side'], 'reason' => $metadata->signal['reason']];
        }

        $client = in_array('Client', $gameVersions, true);
        $server = in_array('Server', $gameVersions, true);

        if ($client && !$server) {
            return ['side' => ModSide::Client, 'reason' => trans('curseforge-sync::strings.reason.tag_client')];
        }

        if ($server && !$client) {
            return ['side' => ModSide::Server, 'reason' => trans('curseforge-sync::strings.reason.tag_server')];
        }

        if ($client) {
            return ['side' => ModSide::Both, 'reason' => trans('curseforge-sync::strings.reason.tag_both')];
        }

        if ($metadata->signal) {
            return ['side' => $metadata->signal['side'], 'reason' => $metadata->signal['reason']];
        }

        return ['side' => ModSide::Both, 'reason' => trans('curseforge-sync::strings.reason.no_signal')];
    }

    /**
     * Put excluded mods back when a mod that stays on the server requires them, a server
     * refuses to start with a missing required dependency.
     *
     * @param  array<int, array{key: string, mod_ids: string[], deps: string[], side: ?string, reason: ?string, name: string}>  $entries
     * @param  array<string, string>  $overrides
     * @return array<int, array{key: string, mod_ids: string[], deps: string[], side: ?string, reason: ?string, name: string}>
     */
    public function protectDependencies(array $entries, array $overrides): array
    {
        $byModId = [];
        foreach ($entries as $index => $entry) {
            foreach ($entry['mod_ids'] as $modId) {
                $byModId[$modId] ??= $index;
            }
        }

        do {
            $changed = false;

            foreach ($entries as $entry) {
                if ($entry['side'] === ModSide::Client->value || $entry['side'] === null) {
                    continue;
                }

                foreach ($entry['deps'] as $dependency) {
                    $index = $byModId[$dependency] ?? null;

                    if ($index === null || $entries[$index]['side'] !== ModSide::Client->value) {
                        continue;
                    }

                    // the user explicitly wants it gone, respect that
                    if (($overrides[$entries[$index]['key']] ?? null) === 'exclude') {
                        continue;
                    }

                    $entries[$index]['side'] = ModSide::Both->value;
                    $entries[$index]['reason'] = trans('curseforge-sync::strings.reason.required_by', ['name' => $entry['name']]);
                    $changed = true;
                }
            }
        } while ($changed);

        return $entries;
    }

    protected function matchesList(string $list, ?int $projectId, ModMetadata $metadata): bool
    {
        $values = array_map(fn ($value) => strtolower((string) $value), (array) config("curseforge-sync.$list", []));

        if ($projectId !== null && in_array((string) $projectId, $values, true)) {
            return true;
        }

        return !empty(array_intersect($metadata->modIds, $values));
    }
}
