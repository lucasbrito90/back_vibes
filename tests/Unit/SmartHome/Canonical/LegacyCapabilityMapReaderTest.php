<?php

declare(strict_types=1);

use App\SmartHome\Canonical\Access;
use App\SmartHome\Canonical\BooleanConstraint;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\LegacyCapabilityMapReader;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-01 — reading ADR-033 rows during the transition (ADR-037 §8)
|--------------------------------------------------------------------------
|
| No backfill migration exists or is wanted: capabilities are recomputed on
| every provider sync, so a device's row becomes canonical the next time it
| syncs under a CSDM-03/CSDM-04 mapper. Until then, rows written in the old
| shape must stay readable — and ADR-033 §5's fail-open policy must survive
| intact, so a malformed legacy row can never break a read path.
*/

/** The exact shape HomeAssistantAdapter::deriveCapabilities() writes today. */
function legacyDimmableLampMap(): array
{
    return [
        'can_turn_on' => [],
        'can_turn_off' => [],
        'can_toggle' => [],
        'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
    ];
}

test('a legacy dimmable lamp map reads as canonical power and brightness', function () {
    $capabilities = LegacyCapabilityMapReader::read(legacyDimmableLampMap());

    expect($capabilities)->toHaveCount(2);

    [$power, $brightness] = $capabilities;

    expect($power->id)->toBe(CapabilityId::Power)
        ->and($power->access)->toBe(Access::ReadWrite)
        ->and($power->operations)->toBe([Operation::On, Operation::Off, Operation::Toggle])
        ->and($power->constraints)->toBeInstanceOf(BooleanConstraint::class);

    expect($brightness->id)->toBe(CapabilityId::Brightness)
        ->and($brightness->operations)->toBe([Operation::Set]);
});

test('legacy brightness bounds are converted to the canonical range, never carried forward', function () {
    $capabilities = LegacyCapabilityMapReader::read(legacyDimmableLampMap());
    $brightness = $capabilities[1];

    expect($brightness->constraints)->toBeInstanceOf(NumberConstraint::class)
        ->and($brightness->constraints->min)->toBe(0.0)
        ->and($brightness->constraints->max)->toBe(100.0)
        ->and($brightness->constraints->step)->toBe(1.0)
        ->and($brightness->constraints->unit)->toBe(Unit::Percent);

    // The 255 in the legacy row is a Home Assistant scale that leaked into what
    // was supposed to be domain data. It must not survive the read.
    expect($brightness->constraints->max)->not->toBe(255.0);
});

test('a switch-only legacy map yields power without brightness', function () {
    $capabilities = LegacyCapabilityMapReader::read([
        'can_turn_on' => [],
        'can_turn_off' => [],
    ]);

    expect($capabilities)->toHaveCount(1)
        ->and($capabilities[0]->id)->toBe(CapabilityId::Power)
        ->and($capabilities[0]->operations)->toBe([Operation::On, Operation::Off]);
});

// ── Fail-open (ADR-033 §5, preserved verbatim) ──────────────────────────────

test('a null capability map reads as no capabilities, never an exception', function () {
    expect(LegacyCapabilityMapReader::read(null))->toBe([])
        ->and(LegacyCapabilityMapReader::read([]))->toBe([]);
});

test('an unknown legacy key is ignored rather than throwing', function () {
    $capabilities = LegacyCapabilityMapReader::read([
        'can_turn_on' => [],
        'can_set_colour_temperature' => ['min' => 2000, 'max' => 6500],
        'whatever_a_future_provider_wrote' => ['nonsense' => true],
    ]);

    expect($capabilities)->toHaveCount(1)
        ->and($capabilities[0]->id)->toBe(CapabilityId::Power);
});

test('a malformed legacy brightness constraint still reads, because the canonical range is fixed anyway', function () {
    $capabilities = LegacyCapabilityMapReader::read([
        'can_set_brightness' => ['min' => 'not a number', 'max' => null],
    ]);

    expect($capabilities)->toHaveCount(1)
        ->and($capabilities[0]->constraints->max)->toBe(100.0);
});

// ── Discriminating legacy from canonical ────────────────────────────────────

test('legacy and canonical payloads are told apart by the version marker', function () {
    expect(LegacyCapabilityMapReader::isLegacyShape(legacyDimmableLampMap()))->toBeTrue();

    $canonical = CapabilityContract::envelope(
        LegacyCapabilityMapReader::read(legacyDimmableLampMap()),
    );

    expect(LegacyCapabilityMapReader::isLegacyShape($canonical))->toBeFalse()
        ->and(LegacyCapabilityMapReader::isLegacyShape(null))->toBeFalse()
        ->and(LegacyCapabilityMapReader::isLegacyShape([]))->toBeFalse();
});

test('a legacy row read and re-emitted becomes a versioned canonical envelope', function () {
    $envelope = CapabilityContract::envelope(
        LegacyCapabilityMapReader::read(legacyDimmableLampMap()),
    );

    expect($envelope[CapabilityContract::VERSION_KEY])->toBe(CapabilityContract::VERSION)
        ->and($envelope['capabilities'])->toHaveKeys(['power', 'brightness'])
        ->and($envelope['capabilities']['brightness']['constraints'])->toBe([
            'type' => 'number',
            'min' => 0.0,
            'max' => 100.0,
            'step' => 1.0,
            'unit' => 'percent',
        ]);
});
