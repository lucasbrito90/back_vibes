<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Log;

final class SafeAssetDeletionService
{
    public const STATUS_DELETED = 'deleted';

    public const STATUS_SKIPPED_EXTERNAL_URL = 'skipped_external_url';

    public const STATUS_SKIPPED_STILL_REFERENCED = 'skipped_still_referenced';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly DigitalOceanSpacesService $spaces,
        private readonly StorageAssetReferenceService $references,
    ) {}

    /**
     * Attempt to delete the Spaces object for this URL when nothing in the DB still stores it.
     *
     * Intended to run **after** the owning row (e.g. Sound or CoverBundle) was removed so reference counting
     * excludes that row automatically.
     */
    public function deleteUrlIfUnreferenced(?string $url): bool
    {
        return $this->deleteUrlWithStatus($url) === self::STATUS_DELETED;
    }

    /**
     * @param  list<string|null>  $urls
     * @return array<string, string> Trimmed URL => status (see STATUS_* constants)
     */
    public function deleteUrlsIfUnreferenced(array $urls): array
    {
        $unique = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $trimmed = trim($url);
            if ($trimmed === '') {
                continue;
            }
            $unique[$trimmed] = true;
        }

        $results = [];
        foreach (array_keys($unique) as $url) {
            $results[$url] = $this->deleteUrlWithStatus($url);
        }

        return $results;
    }

    /**
     * @return self::STATUS_*
     */
    private function deleteUrlWithStatus(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            Log::info('Safe asset deletion skipped: empty URL');

            return self::STATUS_EMPTY;
        }

        $url = trim($url);

        $key = $this->spaces->keyFromUrl($url);
        if ($key === null) {
            Log::info('Safe asset deletion skipped: external URL (not a Spaces CDN/origin URL)', ['url' => $url]);

            return self::STATUS_SKIPPED_EXTERNAL_URL;
        }

        if ($this->references->countReferencesToUrl($url) > 0) {
            Log::info('Safe asset deletion skipped: URL still referenced by another row', ['url' => $url]);

            return self::STATUS_SKIPPED_STILL_REFERENCED;
        }

        if (! $this->spaces->delete($key)) {
            Log::warning('Safe asset deletion failed: Spaces delete returned false', ['url' => $url, 'key' => $key]);

            return self::STATUS_FAILED;
        }

        Log::info('Safe asset deletion: object removed from Spaces', ['url' => $url, 'key' => $key]);

        return self::STATUS_DELETED;
    }
}
