<?php

namespace Devin\CurseForgeSync\Services;

use App\Exceptions\Repository\FileExistsException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Http\Client\RequestException;

/**
 * The few file operations the sync needs, on top of the daemon file api.
 */
class ServerFiles
{
    // Seconds the daemon may take for a single download. Kept below the queue's retry_after (90s).
    protected const PULL_TIMEOUT = 50;

    public function __construct(protected Server $server) {}

    public static function for(Server $server): self
    {
        return app(self::class, ['server' => $server]);
    }

    protected function repository(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }

    /**
     * @return array<string, array{name: string, size: int, modified: string, is_file: bool}> keyed by file name
     *
     * @throws Exception
     */
    public function list(string $folder): array
    {
        try {
            $files = $this->repository()->getDirectory($folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: " . (is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        $result = [];
        foreach ($files as $file) {
            if (!is_array($file) || !isset($file['name'])) {
                continue;
            }

            $result[(string) $file['name']] = [
                'name' => (string) $file['name'],
                'size' => (int) ($file['size'] ?? 0),
                'modified' => (string) ($file['modified'] ?? ''),
                'is_file' => (bool) ($file['file'] ?? true),
            ];
        }

        return $result;
    }

    public function exists(string $folder, string $filename): bool
    {
        try {
            return isset($this->list($folder)[$filename]);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Let the daemon download a file and wait until it is on disk.
     *
     * @throws Exception
     */
    public function pull(string $url, string $folder, string $filename): void
    {
        try {
            $this->repository()
                ->getHttpClient()
                ->timeout(self::PULL_TIMEOUT)
                ->post("/api/servers/{$this->server->uuid}/files/pull", [
                    'url' => $url,
                    'root' => $folder,
                    'file_name' => $filename,
                    'foreground' => true,
                ]);
        } catch (Exception $exception) {
            // a slow download can outlive our request while still finishing on the node
            if ($this->exists($folder, $filename)) {
                return;
            }

            throw $exception;
        }

        if (!$this->exists($folder, $filename)) {
            throw new Exception("The daemon reported success but $folder/$filename is missing");
        }
    }

    /** @throws Exception */
    public function put(string $path, string $content): void
    {
        $this->repository()->putContent($path, $content)->throw();
    }

    /** @throws Exception */
    public function move(string $from, string $to): void
    {
        $this->repository()->renameFiles('/', [['from' => $from, 'to' => $to]])->throw();
    }

    /** @throws Exception */
    public function delete(string $path): void
    {
        $this->repository()->deleteFiles('/', [$path])->throw();
    }

    /** @throws Exception */
    public function decompress(string $root, string $file): void
    {
        $this->repository()->decompressFile($root, $file)->throw();
    }

    /**
     * Create a folder and all its parents.
     *
     * @throws Exception
     */
    public function ensureFolder(string $path): void
    {
        $parent = '/';

        foreach (array_filter(explode('/', trim($path, '/'))) as $segment) {
            try {
                $this->repository()->createDirectory($segment, $parent);
            } catch (FileExistsException) {
                // already there
            } catch (RequestException $exception) {
                if (!in_array($exception->response->status(), [400, 409], true)) {
                    throw $exception;
                }
            }

            $parent = rtrim($parent, '/') . '/' . $segment;
        }
    }

    /**
     * Copy a file from the server to the panel.
     *
     * @throws Exception
     */
    public function download(string $path, string $localTarget): void
    {
        $directory = dirname($localTarget);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->repository()
            ->getHttpClient()
            ->timeout(300)
            ->withOptions(['sink' => $localTarget])
            ->get("/api/servers/{$this->server->uuid}/files/contents", ['file' => $path, 'download' => '1'])
            ->throw();
    }
}
