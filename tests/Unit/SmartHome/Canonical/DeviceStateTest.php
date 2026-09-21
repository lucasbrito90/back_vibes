<?php

declare(strict_types=1);

use App\SmartHome\Adapters\HomeAssistantCanonicalMapper;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityCatalog;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\DeviceState;
use App\SmartHome\Canonical\EnumConstraint;
use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Unit;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\DeviceStatusResult;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-05 — canonical DeviceState (ADR-037 §6)
|--------------------------------------------------------------------------
|
| Two conflations this closes, both from the ADR's own audit: connectivity was
| the same word as functional state, and functional state was provider-raw
| (`raw_state` plus an attribute dictionary, both un-normalised by their own
| naming). A client reading state and a client sending a command now share one
| vocabulary.
*/

/** @return array<string, Capability> */
function dsLampCapabilities(): array
{
    return [
        'power' => Capability::fromCatalog(CapabilityId::Power),
        'brightness' => Capability::fromCatalog(CapabilityId::Brightness),
    ];
}

// ── The shape ───────────────────────────────────────────────────────────────

test('state is keyed by the same vocabulary commands use', function () {
    $state = DeviceState::of(['power' => true, 'brightness' => 65], dsLampCapabilities());

    expect($state->value(CapabilityId::Power))->toBeTrue()
        ->and($state->value(CapabilityId::Brightness))->toBe(65)
        ->and($state->has(CapabilityId::Power))->toBeTrue()
        ->and($state->isEmpty())->toBeFalse();
});

test('a capability absent from the read is absent, not null and not zero', function () {
    // Reporting brightness: 0 for a lamp with no dimmer would read as "off at
    // full darkness" rather than "this device has no such capability".
    $state = DeviceState::of(['power' => true], dsLampCapabilities());

    expect($state->has(CapabilityId::Brightness))->toBeFalse()
        ->and($state->value(CapabilityId::Brightness))->toBeNull();
});

test('state does not carry connectivity', function () {
    $state = DeviceState::of(['power' => false], dsLampCapabilities());

    // Device.connectivity is the sole authority (ADR-037 §6, PO correction 1).
    // An earlier draft duplicated it here; a device that is online with its
    // light off must not be confusable with a device that is unreachable.
    expect($state->toArray())->toHaveKeys(['values', 'read_at'])
        ->and($state->toArray())->not->toHaveKey('connectivity')
        ->and($state->toArray())->not->toHaveKey('status');
});

// ── Read-only capabilities: the case that had no representation before ─────

test('a read-only measurement is reportable without inventing an action for it', function () {
    // ADR-037 §12's plug: energy has no operations at all. Before this model,
    // representing it meant inventing an ActionType for something that can
    // never be acted on.
    $plug = [
        'power' => Capability::fromCatalog(CapabilityId::Power),
        'energy' => Capability::fromCatalog(
            CapabilityId::Energy,
            new NumberConstraint(0.0, null, 0.01, Unit::KilowattHour),
        ),
    ];

    $state = DeviceState::of(['power' => true, 'energy' => 12.34], $plug);

    expect($state->value(CapabilityId::Energy))->toBe(12.34)
        ->and($plug['energy']->operations)->toBe([]);
});

test('a thermostat reports all three of its capability shapes at once', function () {
    $thermostat = [
        'current_temperature' => Capability::fromCatalog(CapabilityId::CurrentTemperature),
        'target_temperature' => Capability::fromCatalog(
            CapabilityId::TargetTemperature,
            new NumberConstraint(5.0, 35.0, 0.5, Unit::Celsius),
        ),
        'hvac_mode' => Capability::fromCatalog(
            CapabilityId::HvacMode,
            new EnumConstraint(['off', 'heat', 'cool', 'auto']),
        ),
    ];

    $state = DeviceState::of([
        'current_temperature' => 19.4,
        'target_temperature' => 21.0,
        'hvac_mode' => 'heat',
    ], $thermostat);

    expect($state->value(CapabilityId::CurrentTemperature))->toBe(19.4)
        ->and($state->value(CapabilityId::HvacMode))->toBe('heat');
});

test('an unbounded sensor reading is accepted — a null bound is not enforced', function () {
    // current_temperature has honestly-null bounds (ADR-037 §4): a thermostat's
    // sensor range is typically undocumented, so the check is skipped rather
    // than defaulted.
    $thermostat = ['current_temperature' => Capability::fromCatalog(CapabilityId::CurrentTemperature)];

    expect(DeviceState::of(['current_temperature' => -40.0], $thermostat)->value(CapabilityId::CurrentTemperature))
        ->toBe(-40.0);
});

// ── Validation: a mapper defect surfaces here, not at the reader ───────────

test('a value outside its declared constraint is rejected', function () {
    expect(fn () => DeviceState::of(['brightness' => 9999], dsLampCapabilities()))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'above its declared maximum');
});

test('a value of the wrong type for its constraint is rejected', function () {
    expect(fn () => DeviceState::of(['power' => 'on'], dsLampCapabilities()))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'must be true or false');

    expect(fn () => DeviceState::of(['brightness' => 'bright'], dsLampCapabilities()))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'must be a number');
});

test('an enum value outside the allowed set is rejected', function () {
    $thermostat = [
        'hvac_mode' => Capability::fromCatalog(CapabilityId::HvacMode, new EnumConstraint(['off', 'heat'])),
    ];

    expect(fn () => DeviceState::of(['hvac_mode' => 'turbo'], $thermostat))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'must be one of: off, heat');
});

test('a key outside the canonical vocabulary is rejected', function () {
    expect(fn () => DeviceState::of(['raw_state' => 'on'], dsLampCapabilities()))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'not a canonical capability id');
});

test('reporting a capability the device does not declare is rejected', function () {
    $switchOnly = ['power' => Capability::fromCatalog(CapabilityId::Power)];

    expect(fn () => DeviceState::of(['brightness' => 50], $switchOnly))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'does not declare as a capability');
});

// ── Freshness ───────────────────────────────────────────────────────────────

test('state knows when it was read, and whether that was long ago', function () {
    $state = DeviceState::of(
        ['power' => true],
        dsLampCapabilities(),
        new DateTimeImmutable('2026-09-20 12:00:00'),
    );

    $twoMinutesLater = new DateTimeImmutable('2026-09-20 12:02:00');

    // The TTL is the caller's to choose (ADR-037 §6 leaves caching to each
    // capability family). What this guarantees is that the question is
    // answerable at all — raw_state carried no read time.
    expect($state->isOlderThan(60, $twoMinutesLater))->toBeTrue()
        ->and($state->isOlderThan(300, $twoMinutesLater))->toBeFalse();
});

test('an unreadable device yields empty state rather than a fabricated one', function () {
    $state = DeviceState::unknown();

    expect($state->isEmpty())->toBeTrue()
        ->and($state->value(CapabilityId::Power))->toBeNull();
});

// ── The provider read path ──────────────────────────────────────────────────

test('Home Assistant raw status becomes canonical state', function () {
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->capabilitiesFor('light', [], true);

    // 166 on the provider's scale is 65 percent in the domain — the same
    // conversion CSDM-03 performs on the way out, now on the way in.
    $state = $mapper->toDeviceState('on', ['brightness' => 166], $capabilities);

    expect($state->value(CapabilityId::Power))->toBeTrue()
        ->and($state->value(CapabilityId::Brightness))->toBe(65);
});

test('the provider scale never reaches canonical state', function () {
    $mapper = new HomeAssistantCanonicalMapper;
    $state = $mapper->toDeviceState('on', ['brightness' => 255], $mapper->capabilitiesFor('light', [], true));

    expect($state->value(CapabilityId::Brightness))->toBe(100)
        ->and($state->value(CapabilityId::Brightness))->not->toBe(255);
});

test('an undimmable light reports power only, even when the provider sends a brightness attribute', function () {
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->capabilitiesFor('light', [], false);

    $state = $mapper->toDeviceState('off', ['brightness' => 120], $capabilities);

    expect($state->value(CapabilityId::Power))->toBeFalse()
        ->and($state->has(CapabilityId::Brightness))->toBeFalse();
});

test('an unavailable provider state produces no power value rather than a false one', function () {
    $mapper = new HomeAssistantCanonicalMapper;

    // "unavailable" is not "off". Mapping it to false would tell the user the
    // lamp is switched off when the truth is that nobody knows.
    $state = $mapper->toDeviceState('unavailable', [], $mapper->capabilitiesFor('light', [], false));

    expect($state->has(CapabilityId::Power))->toBeFalse()
        ->and($state->isEmpty())->toBeTrue();
});

test('connectivity and functional state stay separable on the result DTO', function () {
    $mapper = new HomeAssistantCanonicalMapper;
    $capabilities = $mapper->capabilitiesFor('light', [], true);

    $result = new DeviceStatusResult(
        provider_device_id: 'light.living_room',
        status: DeviceStatus::Online,
        raw_state: 'off',
        attributes: ['brightness' => 0],
        last_changed: null,
        state: $mapper->toDeviceState('off', ['brightness' => 0], $capabilities),
    );

    // Online, and switched off — two facts that used to be one word.
    expect($result->status)->toBe(DeviceStatus::Online)
        ->and($result->state->value(CapabilityId::Power))->toBeFalse();
});

test('the catalog still refuses to widen a read-only capability', function () {
    // Guards the CSDM-01 invariant from the state side: energy is a
    // measurement, and no provider makes it commandable by reporting it.
    expect(CapabilityCatalog::isReadOnly(CapabilityId::Energy))->toBeTrue()
        ->and(CapabilityCatalog::isReadOnly(CapabilityId::CurrentTemperature))->toBeTrue()
        ->and(CapabilityCatalog::isReadOnly(CapabilityId::Power))->toBeFalse();
});
