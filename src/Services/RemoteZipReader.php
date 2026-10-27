<?php

namespace Devin\CurseForgeSync\Services;

use Exception;
use Illuminate\Support\Facades\Http;

/**
 * Reads single files out of a remote zip (a mod jar) with HTTP range requests.
 *
 * Only the central directory and the wanted entries are fetched, which is a few KB per jar
 * instead of the whole file. If the server ignores the range header the whole jar is read,
 * as long as it is below the size limit.
 */
class RemoteZipReader
{
    protected const TAIL_SIZE = 65536 + 22;

    protected const MAX_FULL_DOWNLOAD = 64 * 1024 * 1024;

    protected const USER_AGENT = 'pelican-curseforge-sync/1.0 (+https://pelican.dev)';

    /**
     * @param  string[]  $wanted  entry names to read
     * @return array<string, string> entry name => contents, for every wanted entry that exists
     *
     * @throws Exception
     */
    public function read(string $url, array $wanted): array
    {
        [$tail, $tailStart, $whole] = $this->fetchTail($url);

        if ($whole !== null) {
            return $this->readLocal($whole, $wanted);
        }

        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false || strlen($tail) < $eocd + 22) {
            throw new Exception('Not a zip file (no end of central directory)');
        }

        /** @var array{entries: int, cd_size: int, cd_offset: int} $end */
        $end = unpack('ventries/Vcd_size/Vcd_offset', substr($tail, $eocd + 10, 10));

        if ($end['cd_offset'] === 0xFFFFFFFF || $end['cd_size'] === 0xFFFFFFFF) {
            throw new Exception('zip64 archives are not supported');
        }

        if ($end['cd_offset'] >= $tailStart) {
            $directory = substr($tail, $end['cd_offset'] - $tailStart, $end['cd_size']);
        } else {
            $directory = $this->fetchRange($url, $end['cd_offset'], $end['cd_offset'] + $end['cd_size'] - 1);
        }

        $result = [];

        foreach ($this->parseCentralDirectory($directory) as $name => $entry) {
            if (!in_array($name, $wanted, true)) {
                continue;
            }

            // local header is 30 bytes + name + extra, the extra field may differ from the central one
            $chunk = $this->fetchRange($url, $entry['offset'], $entry['offset'] + 30 + strlen($name) + $entry['compressed'] + 1024);

            $result[$name] = $this->extractLocalEntry($chunk, $entry);
        }

        return $result;
    }

    /**
     * @param  string[]  $wanted
     * @return array<string, string>
     *
     * @throws Exception
     */
    public function readLocal(string $zipBytes, array $wanted): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'cfsync');
        if ($temp === false) {
            throw new Exception('Could not create a temporary file');
        }

        try {
            file_put_contents($temp, $zipBytes);

            return $this->readLocalFile($temp, $wanted);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @param  string[]  $wanted
     * @return array<string, string>
     *
     * @throws Exception
     */
    public function readLocalFile(string $path, array $wanted): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new Exception('Could not open jar');
        }

        try {
            $result = [];
            foreach ($wanted as $name) {
                $content = $zip->getFromName($name);
                if ($content !== false) {
                    $result[$name] = $content;
                }
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{0: string, 1: int, 2: ?string} tail bytes, offset of the tail, whole file when the range was ignored
     *
     * @throws Exception
     */
    protected function fetchTail(string $url): array
    {
        $response = Http::withUserAgent(self::USER_AGENT)
            ->timeout(20)
            ->connectTimeout(5)
            ->withHeaders(['Range' => 'bytes=-' . self::TAIL_SIZE])
            ->get($url);

        if ($response->status() === 200) {
            $body = $response->body();
            if (strlen($body) > self::MAX_FULL_DOWNLOAD) {
                throw new Exception('Server ignored the range request and the file is too large');
            }

            return [$body, 0, $body];
        }

        if ($response->status() !== 206) {
            throw new Exception("Unexpected status {$response->status()} while reading $url");
        }

        if (!preg_match('#bytes (\d+)-(\d+)/(\d+)#', (string) $response->header('Content-Range'), $matches)) {
            throw new Exception('Missing Content-Range header');
        }

        return [$response->body(), (int) $matches[1], null];
    }

    /** @throws Exception */
    protected function fetchRange(string $url, int $from, int $to): string
    {
        $response = Http::withUserAgent(self::USER_AGENT)
            ->timeout(20)
            ->connectTimeout(5)
            ->withHeaders(['Range' => "bytes=$from-$to"])
            ->get($url);

        if ($response->status() === 206) {
            return $response->body();
        }

        if ($response->status() === 200) {
            return substr($response->body(), $from, $to - $from + 1);
        }

        throw new Exception("Unexpected status {$response->status()} while reading $url");
    }

    /**
     * @return array<string, array{method: int, compressed: int, size: int, offset: int}>
     */
    protected function parseCentralDirectory(string $directory): array
    {
        $entries = [];
        $position = 0;
        $length = strlen($directory);

        while ($position + 46 <= $length && substr($directory, $position, 4) === "PK\x01\x02") {
            /** @var array{method: int, compressed: int, size: int, name_length: int, extra_length: int, comment_length: int, offset: int} $header */
            $header = unpack('vmethod/x8/Vcompressed/Vsize/vname_length/vextra_length/vcomment_length/x8/Voffset', substr($directory, $position + 10, 36));

            $name = substr($directory, $position + 46, $header['name_length']);

            $entries[$name] = [
                'method' => $header['method'],
                'compressed' => $header['compressed'],
                'size' => $header['size'],
                'offset' => $header['offset'],
            ];

            $position += 46 + $header['name_length'] + $header['extra_length'] + $header['comment_length'];
        }

        return $entries;
    }

    /**
     * @param  array{method: int, compressed: int, size: int, offset: int}  $entry
     *
     * @throws Exception
     */
    protected function extractLocalEntry(string $chunk, array $entry): string
    {
        if (substr($chunk, 0, 4) !== "PK\x03\x04") {
            throw new Exception('Invalid local file header');
        }

        /** @var array{name_length: int, extra_length: int} $header */
        $header = unpack('vname_length/vextra_length', substr($chunk, 26, 4));

        $data = substr($chunk, 30 + $header['name_length'] + $header['extra_length'], $entry['compressed']);

        return match ($entry['method']) {
            0 => $data,
            8 => @gzinflate($data) ?: throw new Exception('Could not inflate zip entry'),
            default => throw new Exception("Unsupported zip compression method {$entry['method']}"),
        };
    }
}
