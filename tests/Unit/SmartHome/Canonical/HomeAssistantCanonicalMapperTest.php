<?php

declare(strict_types=1);

use App\SmartHome\Adapters\HomeAssistantCanonicalMapper;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\CommandValidator;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-03 — the Home Assistant mapper (ADR-037 §12)
|--------------------------------------------------------------------------
|
| The contract this file enforces is a boundary, not a function: Home
| Assistant's conventions — domains, supported_features, service names, the
| 0-255 scale — may exist inside the mapper and nowhere else. Everything that
| leaves it is canonical.
*/

function haMapper(): HomeAssistantCanonicalMapper
{
    return new HomeAssistantCanonicalMapper;
}

// ── Capability derivation ───────────────────────────────────────────────────

test('a dimmable light maps to canonical power and brightness', function () {
    $capabilities = haMapper()->capabilitiesFor('light', [], true);

    expect($capabilities)->toHaveCount(2);

    [$power, $brightness] = $capabilities;

    expect($power->id)->toBe(CapabilityId::Power)
        ->and($power->operations)->toBe([Operation::On, Operation::Off, Operation::Toggle])
        ->and($power->constraints->type())->toBe('boolean');

    expect($brightness->id)->toBe(CapabilityId::Brightness)
        ->and($brightness->constraints)->toBeInstanceOf(NumberConstraint::class)
        ->and($brightness->constraints->max)->toBe(100.0)
        ->and($brightness->constraints->unit)->toBe(Unit::Percent);
});

test('a light without the brightness feature maps to power alone', function () {
    $capabilities = haMapper()->capabilitiesFor('light', [], false);

    expect($capabilities)->toHaveCount(1)
        ->and($capabilities[0]->id)->toBe(CapabilityId::Power);
});

test('a media player carries only the operations the provider genuinely offers', function () {
    $capabilities = haMapper()->capabilitiesFor('media_player', [], false);

    // No toggle: the canonical capability reports what this provider can do
    // with this entity, not a uniform set per capability id.
    expect($capabilities[0]->operations)->toBe([Operation::On, Operation::Off]);
});

test('an unmapped domain yields no capabilities, leaving the fail-open path intact', function () {
    expect(haMapper()->capabilitiesFor('vacuum', [], false))->toBe([]);
});

// ── Brightness conversion, both directions (the card's round-trip) ──────────

test('canonical percent converts to the provider scale, and back', function () {
    $mapper = haMapper();

    // ADR-037 §12's worked example: 65 percent is 166 on Home Assistant's scale.
    expect($mapper->percentToProviderScale(65.0))->toBe(166)
        ->and($mapper->providerScaleToPercent(166))->toBe(65);

    // The boundaries are exact in both directions.
    expect($mapper->percentToProviderScale(0.0))->toBe(0)
        ->and($mapper->percentToProviderScale(100.0))->toBe(255)
        ->and($mapper->providerScaleToPercent(0))->toBe(0)
        ->and($mapper->providerScaleToPercent(255))->toBe(100);
});

test('a percent survives a full round trip through the provider scale', function () {
    $mapper = haMapper();

    // ADR-037 §5 accepts that 255/100 is not an integer, so a round trip is
    // not bit-exact by construction. What must hold is that it never drifts:
    // brightness is a coarse, human-perceived quantity, and a value the user
    // chose must come back as the value the user chose.
    foreach (range(0, 100) as $percent) {
        $roundTripped = $mapper->providerScaleToPercent(
            $mapper->percentToProviderScale((float) $percent),
        );

        expect($roundTripped)->toBe($percent, "Brightness {$percent}% did not survive the round trip.");
    }
});

// ── Command translation ─────────────────────────────────────────────────────

test('a canonical brightness command becomes a provider-scaled service call', function () {
    $call = haMapper()->toServiceCall(
        CapabilityId::Brightness,
        Operation::Set,
        [CommandValidator::VALUE_KEY => 65],
    );

    // Home Assistant has no brightness service — it dims through turn_on.
    expect($call['service'])->toBe('turn_on')
        ->and($call['payload'])->toBe(['brightness' => 166]);
});

test('power operations map to their services and carry no payload', function () {
    $mapper = haMapper();

    expect($mapper->toServiceCall(CapabilityId::Power, Operation::On))
        ->toBe(['service' => 'turn_on', 'payload' => []])
        ->and($mapper->toServiceCall(CapabilityId::Power, Operation::Off))
        ->toBe(['service' => 'turn_off', 'payload' => []]);
});

test('toggle uses the provider native service while staying a canonical operation', function () {
    // Home Assistant happens to offer a native toggle, so this mapper uses it.
    // That is this mapper's implementation detail — Google Home's composes the
    // same canonical operation from read-invert-write (GH04, CSDM-04).
    expect(haMapper()->toServiceCall(CapabilityId::Power, Operation::Toggle)['service'])
        ->toBe('toggle');
});

// ── The stored payload: expand, not flip ────────────────────────────────────

test('the stored payload carries the canonical envelope and the legacy keys together', function () {
    $payload = haMapper()->toStoredPayload(haMapper()->capabilitiesFor('light', [], true));

    // Canonical half — what CSDM-06 and CommandValidator read.
    expect(CapabilityContract::isCanonicalEnvelope($payload))->toBeTrue()
        ->and($payload['capabilities'])->toHaveKeys(['power', 'brightness'])
        ->and($payload['capabilities']['brightness']['constraints']['max'])->toBe(100.0);

    // Legacy half — what front_vibes' action editor and
    // ActionType::isBlockedByDeviceCapabilities still read today. Dropping it
    // would leave the editor offering no actions at all.
    expect($payload)->toHaveKeys(['can_turn_on', 'can_turn_off', 'can_toggle', 'can_set_brightness']);
});

test('the legacy half of the payload keeps declaring the scale the provider really uses', function () {
    $payload = haMapper()->toStoredPayload(haMapper()->capabilitiesFor('light', [], true));

    // CSDM-02 range-checks legacy-shaped parameters against these bounds, so
    // they must stay truthful about the provider while the window is open.
    expect($payload['can_set_brightness'])->toBe(['min' => 0, 'max' => 255, 'step' => 1]);
});

test('a media player payload advertises no toggle in either half', function () {
    $payload = haMapper()->toStoredPayload(haMapper()->capabilitiesFor('media_player', [], false));

    expect($payload)->toHaveKeys(['can_turn_on', 'can_turn_off'])
        ->and($payload)->not->toHaveKey('can_toggle')
        ->and($payload['capabilities']['power']['operations'])->toBe(['on', 'off']);
});

// ── Read path ───────────────────────────────────────────────────────────────

test('the provider state string maps to the canonical boolean', function () {
    $mapper = haMapper();

    expect($mapper->stateToPower('on'))->toBeTrue()
        ->and($mapper->stateToPower('off'))->toBeFalse()
        ->and($mapper->stateToPower('unavailable'))->toBeNull()
        ->and($mapper->stateToPower(null))->toBeNull();
});

// ── Containment ─────────────────────────────────────────────────────────────

test('the provider scale exists in the mapper and nowhere else in the canonical layer', function () {
    $canonicalDir = app_path('SmartHome/Canonical');
    $files = glob($canonicalDir.'/*.php') ?: [];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $code = preg_replace('!/\*.*?\*/|//.*!s', '', (string) file_get_contents($file)) ?? '';

        expect(preg_match('/\b25[45](?:\.0)?\b/', $code))
            ->toBe(0, sprintf('Provider scale leaked into the canonical layer via %s.', basename($file)));
    }

    // …while the mapper, which is the boundary, is allowed to know it.
    $mapperCode = (string) file_get_contents(app_path('SmartHome/Adapters/HomeAssistantCanonicalMapper.php'));

    expect(str_contains($mapperCode, '255'))->toBeTrue();
});
