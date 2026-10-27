<?php

namespace Devin\CurseForgeSync\Services;

use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the public curseforge.com endpoints the website itself uses, so no API key is needed.
 *
 * A file id never changes its contents on CurseForge, so everything resolved for one is cached forever.
 */
class CurseForgeClient
{
    protected const BASE_URL = 'https://www.curseforge.com/api/v1';

    protected const USER_AGENT = 'pelican-curseforge-sync/1.0 (+https://pelican.dev)';

    /**
     * @param  array<int, array{project_id: int, file_id: int}>  $files
     * @return array<int, array{fileName: string, displayName: string, fileLength: int, gameVersions: string[]}|null> keyed by file id, null when the lookup failed
     */
    public function getFiles(array $files): array
    {
        $results = [];
        $missing = [];

        foreach ($files as $file) {
            $cached = cache()->get($this->fileCacheKey($file['file_id']));

            if (is_array($cached)) {
                $results[$file['file_id']] = $cached;
            } else {
                $missing[$file['file_id']] = $file;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (array $file) => $pool->as((string) $file['file_id'])
                ->withUserAgent(self::USER_AGENT)
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->get(self::BASE_URL . "/mods/{$file['project_id']}/files/{$file['file_id']}"),
            array_values($missing)
        ));

        foreach ($missing as $fileId => $file) {
            $response = $responses[(string) $fileId] ?? null;

            if (!$response instanceof Response || !$response->successful() || !is_array($data = $response->json('data'))) {
                if ($response instanceof Exception) {
                    report($response);
                }

                $results[$fileId] = null;

                continue;
            }

            $info = [
                'fileName' => (string) ($data['fileName'] ?? ''),
                'displayName' => (string) ($data['displayName'] ?? $data['fileName'] ?? ''),
                'fileLength' => (int) ($data['fileLength'] ?? 0),
                'gameVersions' => array_values(array_filter($data['gameVersions'] ?? [], 'is_string')),
            ];

            if ($info['fileName'] === '') {
                $results[$fileId] = null;

                continue;
            }

            cache()->forever($this->fileCacheKey($fileId), $info);

            $results[$fileId] = $info;
        }

        return $results;
    }

    /**
     * Resolve the direct CDN url for a file.
     *
     * The website's download endpoint redirects to edge.forgecdn.net, which redirects again to
     * mediafilez.forgecdn.net. We hand the final url to the daemon and use it for range requests,
     * since the edge host answers range requests with a 404.
     *
     * @throws Exception
     */
    public function resolveDownloadUrl(int $projectId, int $fileId, string $fileName): string
    {
        $key = "curseforge-sync:url:$fileId";

        $cached = cache()->get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $url = self::BASE_URL . "/mods/$projectId/files/$fileId/download";

        // follow at most a few hops by hand, so we learn the final url without downloading anything
        for ($hop = 0; $hop < 4; $hop++) {
            $response = $this->client()->withoutRedirecting()->head($url);

            if (!$response->redirect()) {
                break;
            }

            $location = $response->header('Location');
            if (!$location) {
                break;
            }

            $url = $this->absoluteUrl($location, $url);
        }

        if (!str_contains((string) parse_url($url, PHP_URL_HOST), 'forgecdn.net')) {
            // the redirect chain changed, fall back to the well known cdn layout
            $url = sprintf('https://mediafilez.forgecdn.net/files/%d/%d/%s', intdiv($fileId, 1000), $fileId % 1000, rawurlencode($fileName));
        }

        cache()->forever($key, $url);

        return $url;
    }

    public function projectUrl(int $projectId): string
    {
        return "https://www.curseforge.com/projects/$projectId";
    }

    protected function client(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->timeout(15)
            ->connectTimeout(5);
    }

    protected function fileCacheKey(int $fileId): string
    {
        return "curseforge-sync:file:$fileId";
    }

    protected function absoluteUrl(string $location, string $base): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/' . ltrim($location, '/');
    }
}
