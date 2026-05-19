<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class StorageAssetReferenceService
{
    /**
     * Count rows that store this exact public URL (CDN or legacy origin).
     *
     * @var list<array{0: string, 1: string}>
     */
    private const URL_COLUMNS = [
        ['sounds', 'file_url'],
        ['sounds', 'thumbnail_url'],
        ['cover_bundles', 'thumbnail_url'],
        ['cover_bundles', 'artwork_url'],
        ['cover_bundles', 'player_background_url'],
        ['vibes', 'thumbnail_url'],
        ['vibes', 'artwork_url'],
        ['vibes', 'player_background_url'],
        ['users', 'avatar_url'],
    ];

    public function countReferencesToUrl(string $url): int
    {
        $total = 0;

        foreach (self::URL_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $total += (int) DB::table($table)->where($column, $url)->count();
        }

        return $total;
    }
}
