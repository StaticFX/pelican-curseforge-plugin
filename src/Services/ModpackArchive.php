<?php

namespace Devin\CurseForgeSync\Services;

use Exception;
use ZipArchive;

/**
 * A CurseForge modpack export (the zip with manifest.json, modlist.html and overrides/).
 */
class ModpackArchive
{
    protected ZipArchive $zip;

    /** @var array<string, mixed> */
    protected array $manifest;

    /** @throws Exception */
    public function __construct(protected string $path)
    {
        $this->zip = new ZipArchive();

        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new Exception(trans('curseforge-sync::strings.errors.not_a_zip'));
        }

        $manifest = $this->zip->getFromName('manifest.json');
        $manifest = $manifest !== false ? json_decode($manifest, true) : null;

        if (!is_array($manifest) || !is_array($manifest['files'] ?? null) || !is_array($manifest['minecraft'] ?? null)) {
            $this->zip->close();

            throw new Exception(trans('curseforge-sync::strings.errors.no_manifest'));
        }

        $this->manifest = $manifest;
    }

    public function __destruct()
    {
        try {
            $this->zip->close();
        } catch (\Throwable) {
            // already closed
        }
    }

    public function name(): string
    {
        return (string) ($this->manifest['name'] ?? 'Unnamed modpack');
    }

    public function version(): string
    {
        return (string) ($this->manifest['version'] ?? '');
    }

    public function author(): string
    {
        return (string) ($this->manifest['author'] ?? '');
    }

    public function minecraftVersion(): ?string
    {
        $version = $this->manifest['minecraft']['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The primary mod loader, e.g. "neoforge-21.1.262" => [type: neoforge, version: 21.1.262].
     *
     * @return array{type: string, version: string, id: string}|null
     */
    public function loader(): ?array
    {
        $loaders = $this->manifest['minecraft']['modLoaders'] ?? [];
        if (!is_array($loaders) || empty($loaders)) {
            return null;
        }

        $primary = collect($loaders)->first(fn ($loader) => !empty($loader['primary'])) ?? $loaders[0];
        $id = (string) ($primary['id'] ?? '');

        if (!preg_match('/^([a-z]+)-(.+)$/i', $id, $matches)) {
            return null;
        }

        return ['type' => strtolower($matches[1]), 'version' => $matches[2], 'id' => $id];
    }

    public function overridesPrefix(): string
    {
        return trim((string) ($this->manifest['overrides'] ?? 'overrides'), '/') . '/';
    }

    /**
     * @return array<int, array{project_id: int, file_id: int, required: bool}>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->manifest['files'] as $file) {
            if (!is_array($file) || !isset($file['projectID'], $file['fileID'])) {
                continue;
            }

            $files[] = [
                'project_id' => (int) $file['projectID'],
                'file_id' => (int) $file['fileID'],
                'required' => (bool) ($file['required'] ?? true),
            ];
        }

        return $files;
    }

    /**
     * Names, authors and links from modlist.html.
     *
     * The list has no project ids, but CurseForge writes it in the same order as the manifest's
     * files, so it is only returned when the counts match.
     *
     * @return array<int, array{name: string, author: ?string, url: ?string}>
     */
    public function modList(): array
    {
        $html = $this->zip->getFromName('modlist.html');
        if ($html === false) {
            return [];
        }

        preg_match_all('#<li>\s*<a href="([^"]*)"[^>]*>(.*?)</a>#si', $html, $matches, PREG_SET_ORDER);

        if (count($matches) !== count($this->files())) {
            return [];
        }

        return array_map(function (array $match) {
            $label = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
            $author = null;

            if (preg_match('/^(.*)\s+\(by\s+(.+)\)$/s', $label, $parts)) {
                [, $label, $author] = $parts;
            }

            $url = html_entity_decode($match[1]);

            return [
                'name' => trim($label),
                'author' => $author !== null ? trim($author) : null,
                'url' => str_starts_with($url, 'https://www.curseforge.com/') ? $url : null,
            ];
        }, $matches);
    }

    /**
     * Jars shipped inside the overrides (overrides/mods/*.jar), relative to the server root.
     *
     * @return string[]
     */
    public function bundledJars(): array
    {
        $prefix = $this->overridesPrefix() . 'mods/';
        $jars = [];

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if (str_starts_with($name, $prefix) && str_ends_with(strtolower($name), '.jar') && !str_contains(substr($name, strlen($prefix)), '/')) {
                $jars[] = substr($name, strlen($this->overridesPrefix()));
            }
        }

        return $jars;
    }

    public function readOverride(string $relativePath): ?string
    {
        $content = $this->zip->getFromName($this->overridesPrefix() . $relativePath);

        return $content === false ? null : $content;
    }

    /**
     * Read metadata files out of a bundled jar without extracting it.
     *
     * @param  string[]  $wanted
     * @return array<string, string>
     *
     * @throws Exception
     */
    public function readFromBundledJar(string $relativePath, array $wanted): array
    {
        $jar = $this->readOverride($relativePath);
        if ($jar === null) {
            return [];
        }

        return app(RemoteZipReader::class)->readLocal($jar, $wanted);
    }

    /**
     * Write every override file that belongs on a server into a new zip with paths relative to the server root.
     *
     * @param  string[]  $excludedPaths  paths relative to the overrides folder, "dir/" for folders
     * @return int number of files written
     *
     * @throws Exception
     */
    public function buildOverridesZip(string $target, array $excludedPaths): int
    {
        $prefix = $this->overridesPrefix();

        $out = new ZipArchive();
        if ($out->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Could not create the overrides archive');
        }

        $count = 0;

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen($prefix));

            if ($relative === '' || !$this->isSafePath($relative) || $this->isExcluded($relative, $excludedPaths)) {
                continue;
            }

            $content = $this->zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $out->addFromString($relative, $content);
            $count++;
        }

        $out->close();

        if ($count === 0) {
            @unlink($target);
        }

        return $count;
    }

    /**
     * @param  string[]  $excludedPaths
     */
    public function isExcluded(string $relative, array $excludedPaths): bool
    {
        $lower = strtolower($relative);

        foreach ($excludedPaths as $excluded) {
            $excluded = strtolower(ltrim($excluded, '/'));

            if (str_ends_with($excluded, '/') ? str_starts_with($lower, $excluded) : $lower === $excluded) {
                return true;
            }
        }

        return false;
    }

    public function isSafePath(string $relative): bool
    {
        return !str_contains($relative, "\0")
            && !str_contains($relative, '\\')
            && !str_starts_with($relative, '/')
            && !in_array('..', explode('/', $relative), true);
    }
}
