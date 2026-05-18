<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DigitalOceanSpacesService
{
    private const DISK = 'spaces';

    /**
     * Store an uploaded file or raw/path contents at the given object key.
     *
     * @param  UploadedFile|string  $contents  Uploaded file, filesystem path, or raw body
     */
    public function putFile(string $key, UploadedFile|string $contents, ?string $visibility = 'public'): string
    {
        $key = $this->normalizeKey($key);
        $disk = Storage::disk(self::DISK);
        $options = $visibility !== null ? ['visibility' => $visibility] : [];

        if ($contents instanceof UploadedFile) {
            $realPath = $contents->getRealPath();
            if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
                $stream = fopen($realPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Unable to open uploaded file stream.');
                }
                try {
                    $disk->put($key, $stream, $options);
                } finally {
                    fclose($stream);
                }
            } else {
                $disk->put($key, $contents->get(), $options);
            }
        } elseif (is_string($contents) && $contents !== '' && is_file($contents)) {
            $stream = fopen($contents, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open file: {$contents}");
            }
            try {
                $disk->put($key, $stream, $options);
            } finally {
                fclose($stream);
            }
        } else {
            $disk->put($key, $contents, $options);
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function put(string $key, string $contents, array $options = []): string
    {
        $key = $this->normalizeKey($key);
        Storage::disk(self::DISK)->put($key, $contents, $options);

        return $key;
    }

    public function delete(string $key): bool
    {
        $key = $this->normalizeKey($key);

        try {
            return Storage::disk(self::DISK)->delete($key);
        } catch (Throwable) {
            return false;
        }
    }

    public function exists(string $key): bool
    {
        $key = $this->normalizeKey($key);

        return Storage::disk(self::DISK)->exists($key);
    }

    public function publicUrl(string $key): string
    {
        $key = $this->normalizeKey($key);
        $base = rtrim((string) config('filesystems.disks.'.self::DISK.'.url'), '/');

        return $base === '' ? $key : $base.'/'.$key;
    }

    public function keyFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $host = Str::lower($parts['host']);
        $path = isset($parts['path']) ? rawurldecode(ltrim((string) $parts['path'], '/')) : '';

        if ($path === '') {
            return null;
        }

        $cdnUrl = (string) config('filesystems.disks.'.self::DISK.'.url');
        $cdnHost = $cdnUrl !== '' ? parse_url($cdnUrl, PHP_URL_HOST) : null;
        if (is_string($cdnHost) && $cdnHost !== '' && Str::lower($cdnHost) === $host) {
            return $this->normalizeKey($path);
        }

        $bucket = (string) config('filesystems.disks.'.self::DISK.'.bucket');
        $region = (string) config('filesystems.disks.'.self::DISK.'.region');
        $virtualHost = Str::lower(sprintf('%s.%s.digitaloceanspaces.com', $bucket, $region));
        if ($host === $virtualHost) {
            return $this->normalizeKey($path);
        }

        $endpoint = (string) config('filesystems.disks.'.self::DISK.'.endpoint');
        $endpointHost = $endpoint !== '' ? parse_url($endpoint, PHP_URL_HOST) : null;
        if (is_string($endpointHost) && $endpointHost !== '' && Str::lower($endpointHost) === $host) {
            if (! Str::startsWith($path, $bucket.'/')) {
                return null;
            }

            return $this->normalizeKey(Str::after($path, $bucket.'/'));
        }

        return null;
    }

    private function normalizeKey(string $key): string
    {
        return ltrim($key, '/');
    }
}
