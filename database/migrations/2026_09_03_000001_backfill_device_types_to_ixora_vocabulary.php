<?php

declare(strict_types=1);

use App\SmartHome\DeviceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill devices.type from legacy Home Assistant domain strings to the
 * IXORA-normalised DeviceType vocabulary (T15 / v1.4.0).
 *
 * Null type is left unchanged — manual creates may omit type per
 * 2026_06_17_000001_make_devices_type_nullable; only legacy HA slugs are
 * converted here.
 */
return new class extends Migration
{
    /** @var array<string, string> Legacy HA domain → IXORA DeviceType value */
    private const LEGACY_HA_DOMAIN_MAP = [
        'light' => DeviceType::Lighting->value,
        'switch' => DeviceType::Switchable->value,
        'media_player' => DeviceType::Media->value,
        'fan' => DeviceType::Ventilation->value,
    ];

    public function up(): void
    {
        $ixoraValues = DeviceType::values();

        foreach (self::LEGACY_HA_DOMAIN_MAP as $legacyDomain => $ixoraType) {
            DB::table('devices')
                ->where('type', $legacyDomain)
                ->update(['type' => $ixoraType]);
        }

        DB::table('devices')
            ->whereNotNull('type')
            ->whereNotIn('type', $ixoraValues)
            ->update(['type' => DeviceType::Other->value]);
    }

    public function down(): void
    {
        $reverseMap = array_flip(self::LEGACY_HA_DOMAIN_MAP);

        foreach ($reverseMap as $ixoraType => $legacyDomain) {
            DB::table('devices')
                ->where('type', $ixoraType)
                ->update(['type' => $legacyDomain]);
        }

        // Rows backfilled to Other from unknown legacy strings cannot be
        // restored to their original value — down() leaves them as Other.
    }
};
