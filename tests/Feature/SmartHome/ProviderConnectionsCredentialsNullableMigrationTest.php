<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ProviderType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * ADR-036 Decision 4 (P02) — provider_connections.encrypted_credentials
 * becomes nullable. Mirrors the up/down/re-up round-trip pattern used by
 * tests/Feature/SmartHome/DeviceCapabilitiesMigrationTest.php.
 *
 * The test suite runs against SQLite only (phpunit.xml — matches this
 * repo's established test-DB convention; there is no dual-DB test harness
 * to also exercise PostgreSQL here). Production runs PostgreSQL, where
 * `text NOT NULL -> text NULL` is the same well-understood, reversible
 * ALTER COLUMN operation with no data-loss risk in the `up` direction.
 */
function credentialsNullableMigration(): object
{
    return require database_path('migrations/2026_09_08_000000_make_encrypted_credentials_nullable_on_provider_connections.php');
}

test('migration makes encrypted_credentials nullable and rolls back cleanly', function () {
    // RefreshDatabase already ran every migration, including this one — the
    // column is nullable at this point. Roll back first so `up` has
    // something real to prove.
    credentialsNullableMigration()->down();

    expect(fn () => DB::table('provider_connections')->insert([
        'user_id' => User::factory()->create()->id,
        'name' => 'Null credentials before up',
        'provider' => ProviderType::GoogleHome->value,
        'config' => json_encode([]),
        'encrypted_credentials' => null,
        'status' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    credentialsNullableMigration()->up();

    $user = User::factory()->create();

    DB::table('provider_connections')->insert([
        'user_id' => $user->id,
        'name' => 'Null credentials after up',
        'provider' => ProviderType::GoogleHome->value,
        'config' => json_encode([]),
        'encrypted_credentials' => null,
        'status' => 'unknown',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(
        DB::table('provider_connections')->where('name', 'Null credentials after up')->value('encrypted_credentials')
    )->toBeNull();
});

test('home_assistant connections with encrypted credentials are unaffected by the nullable column', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->provider)->toBe(ProviderType::HomeAssistant->value)
        ->and(Schema::hasColumn('provider_connections', 'encrypted_credentials'))->toBeTrue()
        ->and($connection->fresh()->encrypted_credentials)->not->toBeNull()
        ->and($connection->fresh()->decryptedCredentials())->toBeArray();
});
