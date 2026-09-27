<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backfill vibe_categories and preset_vibe_vibe_categories from preset_vibes.category
 * (legacy free-text column) before that column is dropped in the next migration.
 *
 * Distinct trimmed non-empty values become catalog rows (slug via Str::slug with numeric
 * suffix on collision; names.en = canonical label; sort_order by alphabetical order of
 * distinct groups). Presets are linked with case-insensitive trimmed matching.
 *
 * Rollback tracking: rows inserted into vibe_categories by this migration are recorded in
 * cat02_vibe_category_backfill_ids so down() can delete only those catalog rows (and their
 * preset pivots) without touching admin-created categories added later.
 */
return new class extends Migration
{
    private const AUDIT_TABLE = 'cat02_vibe_category_backfill_ids';

    public function up(): void
    {
        if (! Schema::hasColumn('preset_vibes', 'category')) {
            return;
        }

        if (! Schema::hasTable(self::AUDIT_TABLE)) {
            Schema::create(self::AUDIT_TABLE, function (Blueprint $table): void {
                $table->unsignedBigInteger('vibe_category_id')->primary();
            });
        }

        $presets = DB::table('preset_vibes')
            ->select(['id', 'category'])
            ->whereNotNull('category')
            ->get();

        /** @var array<string, list<array{id: int, raw: string}>> $groups */
        $groups = [];

        foreach ($presets as $row) {
            $raw = is_string($row->category) ? trim($row->category) : '';
            if ($raw === '') {
                continue;
            }

            $key = mb_strtolower($raw);
            $groups[$key] ??= [];
            $groups[$key][] = ['id' => (int) $row->id, 'raw' => $raw];
        }

        if ($groups === []) {
            return;
        }

        uksort($groups, static fn (string $a, string $b): int => strcmp($a, $b));

        $sortOrder = 0;
        $now = now();

        foreach ($groups as $members) {
            $labels = array_map(static fn (array $m): string => $m['raw'], $members);
            sort($labels, SORT_STRING);
            $canonicalLabel = $labels[0];

            $slug = $this->resolveUniqueSlug($canonicalLabel);

            $existingId = DB::table('vibe_categories')->where('slug', $slug)->value('id');

            if ($existingId !== null) {
                $categoryId = (int) $existingId;
            } else {
                $categoryId = (int) DB::table('vibe_categories')->insertGetId([
                    'slug' => $slug,
                    'names' => json_encode(['en' => $canonicalLabel], JSON_THROW_ON_ERROR),
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table(self::AUDIT_TABLE)->insert(['vibe_category_id' => $categoryId]);
            }

            foreach ($members as $member) {
                DB::table('preset_vibe_vibe_categories')->insertOrIgnore([
                    'preset_vibe_id' => $member['id'],
                    'vibe_category_id' => $categoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $sortOrder++;
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::AUDIT_TABLE)) {
            return;
        }

        $ids = DB::table(self::AUDIT_TABLE)->pluck('vibe_category_id')->all();

        if ($ids !== []) {
            DB::table('preset_vibe_vibe_categories')
                ->whereIn('vibe_category_id', $ids)
                ->delete();

            DB::table('vibe_categories')
                ->whereIn('id', $ids)
                ->delete();
        }

        Schema::dropIfExists(self::AUDIT_TABLE);
    }

    private function resolveUniqueSlug(string $label): string
    {
        $base = Str::slug($label);
        if ($base === '') {
            $base = 'category';
        }

        $base = mb_substr($base, 0, 40);
        $candidate = $base;
        $suffix = 2;

        while (DB::table('vibe_categories')->where('slug', $candidate)->exists()) {
            $suffixPart = '-'.$suffix;
            $maxBaseLen = 40 - mb_strlen($suffixPart);
            $candidate = mb_substr($base, 0, max(1, $maxBaseLen)).$suffixPart;
            $suffix++;
        }

        return $candidate;
    }
};
