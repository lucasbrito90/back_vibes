<?php

declare(strict_types=1);

use App\SmartHome\ActionType;
use App\SmartHome\Canonical\ActionTypeTranslation;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\CommandRejectionReason;
use App\SmartHome\Canonical\CommandValidator;
use App\SmartHome\Canonical\EnumConstraint;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-02 — canonical command validation (ADR-037 §7)
|--------------------------------------------------------------------------
|
| The defect this exists to close is named in ADR-037's own Context §5:
| SceneAction.parameters is free-form JSON, and the adapter merges it straight
| into the provider payload, so brightness: 9999 reaches Home Assistant
| unexamined. Every test below is either that case or a boundary of it.
*/

function canonicalValidator(): CommandValidator
{
    return new CommandValidator;
}

/** A canonical envelope for a dimmable lamp — the post-CSDM-03 shape. */
function canonicalLamp(): array
{
    return CapabilityContract::envelope([
        Capability::fromCatalog(CapabilityId::Power),
        Capability::fromCatalog(CapabilityId::Brightness),
    ]);
}

/** The ADR-033 shape a device row still carries before it re-syncs. */
function legacyLamp(): array
{
    return [
        'can_turn_on' => [],
        'can_turn_off' => [],
        'can_toggle' => [],
        'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
    ];
}

// ── The headline case ───────────────────────────────────────────────────────

test('brightness 9999 is rejected before any provider is reached — canonical shape', function () {
    $result = canonicalValidator()->validate(
        canonicalLamp(),
        CapabilityId::Brightness,
        Operation::Set,
        ['value' => 9999],
    );

    expect($result->wasRejected())->toBeTrue()
        ->and($result->reason)->toBe(CommandRejectionReason::ParameterOutOfRange)
        ->and($result->message)->toContain('at most 100 percent');
});

test('brightness 9999 is rejected on a device whose row is still legacy-shaped', function () {
    // The value is checked against the bounds the device itself declared, so
    // the defect is closed during the transition too — not only after CSDM-03.
    $result = canonicalValidator()->validateLegacyAction(
        legacyLamp(),
        ActionType::SetBrightness->value,
        ['brightness' => 9999],
    );

    expect($result->wasRejected())->toBeTrue()
        ->and($result->reason)->toBe(CommandRejectionReason::ParameterOutOfRange);
});

test('a value a legacy device genuinely accepts still passes — no working path is broken', function () {
    $result = canonicalValidator()->validateLegacyAction(
        legacyLamp(),
        ActionType::SetBrightness->value,
        ['brightness' => 200],
    );

    expect($result->wasRejected())->toBeFalse();
});

// ── Canonical range, step and type ──────────────────────────────────────────

test('a brightness inside the canonical range is accepted', function () {
    expect(canonicalValidator()->validate(canonicalLamp(), CapabilityId::Brightness, Operation::Set, ['value' => 65])->wasRejected())
        ->toBeFalse();
});

test('a brightness below the canonical minimum is rejected', function () {
    $result = canonicalValidator()->validate(canonicalLamp(), CapabilityId::Brightness, Operation::Set, ['value' => -1]);

    expect($result->reason)->toBe(CommandRejectionReason::ParameterOutOfRange)
        ->and($result->message)->toContain('at least 0 percent');
});

test('a non-numeric value for a numeric capability is rejected', function () {
    $result = canonicalValidator()->validate(canonicalLamp(), CapabilityId::Brightness, Operation::Set, ['value' => 'bright']);

    expect($result->reason)->toBe(CommandRejectionReason::ParameterMalformed)
        ->and($result->message)->toContain('must be a number');
});

test('a value off the constraint step is rejected', function () {
    $thermostat = CapabilityContract::envelope([
        Capability::fromCatalog(
            CapabilityId::TargetTemperature,
            new NumberConstraint(5.0, 35.0, 0.5, Unit::Celsius),
        ),
    ]);

    expect(canonicalValidator()->validate($thermostat, CapabilityId::TargetTemperature, Operation::Set, ['value' => 21.5])->wasRejected())
        ->toBeFalse();

    $result = canonicalValidator()->validate($thermostat, CapabilityId::TargetTemperature, Operation::Set, ['value' => 21.3]);

    expect($result->reason)->toBe(CommandRejectionReason::ParameterOutOfRange)
        ->and($result->message)->toContain('increments of 0.5');
});

test('a null bound degrades to type checking rather than defaulting (ADR-037 §4)', function () {
    $sensorless = CapabilityContract::envelope([
        Capability::fromCatalog(
            CapabilityId::TargetTemperature,
            new NumberConstraint(null, null, null, Unit::Celsius),
        ),
    ]);

    // No bound is known, so no bound is enforced — but a non-number still fails.
    expect(canonicalValidator()->validate($sensorless, CapabilityId::TargetTemperature, Operation::Set, ['value' => 900])->wasRejected())
        ->toBeFalse()
        ->and(canonicalValidator()->validate($sensorless, CapabilityId::TargetTemperature, Operation::Set, ['value' => 'warm'])->wasRejected())
        ->toBeTrue();
});

// ── Enum membership ─────────────────────────────────────────────────────────

test('an enum value outside the allowed set is rejected, and one inside is accepted', function () {
    $thermostat = CapabilityContract::envelope([
        Capability::fromCatalog(CapabilityId::HvacMode, new EnumConstraint(['off', 'heat', 'cool'])),
    ]);

    expect(canonicalValidator()->validate($thermostat, CapabilityId::HvacMode, Operation::Set, ['value' => 'heat'])->wasRejected())
        ->toBeFalse();

    $result = canonicalValidator()->validate($thermostat, CapabilityId::HvacMode, Operation::Set, ['value' => 'turbo']);

    expect($result->reason)->toBe(CommandRejectionReason::ParameterOutOfRange)
        ->and($result->message)->toContain('off, heat, cool');
});

// ── Capability, access and operation gates ──────────────────────────────────

test('a capability the device does not declare is rejected as unavailable', function () {
    $switchOnly = CapabilityContract::envelope([Capability::fromCatalog(CapabilityId::Power)]);

    $result = canonicalValidator()->validate($switchOnly, CapabilityId::Brightness, Operation::Set, ['value' => 50]);

    expect($result->reason)->toBe(CommandRejectionReason::CapabilityUnavailable);
});

test('a read-only measurement cannot be commanded', function () {
    $plug = CapabilityContract::envelope([
        Capability::fromCatalog(CapabilityId::Energy, new NumberConstraint(0.0, null, 0.01, Unit::KilowattHour)),
    ]);

    $result = canonicalValidator()->validate($plug, CapabilityId::Energy, Operation::Set, ['value' => 5]);

    expect($result->reason)->toBe(CommandRejectionReason::AccessForbidsOperation)
        ->and($result->message)->toContain('can only be read');
});

test('an operation the capability does not declare is rejected', function () {
    $result = canonicalValidator()->validate(canonicalLamp(), CapabilityId::Brightness, Operation::Toggle);

    expect($result->reason)->toBe(CommandRejectionReason::OperationUnsupported);
});

test('setting a value without the canonical key is rejected', function () {
    $result = canonicalValidator()->validate(canonicalLamp(), CapabilityId::Brightness, Operation::Set, []);

    expect($result->reason)->toBe(CommandRejectionReason::ParameterMalformed)
        ->and($result->message)->toContain('requires a "value" parameter');
});

// ── What the validator deliberately does NOT police ─────────────────────────

test('a parameterless operation accepts provider extras rather than rejecting them', function () {
    // Passing a fade time through to the provider works today. Ending that
    // passthrough is CSDM-03/CSDM-07 work; breaking it here, as a side effect
    // of adding validation, would be a regression unrelated to the defect.
    expect(canonicalValidator()->validate(canonicalLamp(), CapabilityId::Power, Operation::Off, ['transition' => 2])->wasRejected())
        ->toBeFalse();
});

test('capabilities that were never derived fail open, exactly as before (ADR-033 §5)', function () {
    expect(canonicalValidator()->validate(null, CapabilityId::Brightness, Operation::Set, ['value' => 9999])->wasRejected())
        ->toBeFalse();
});

// ── Legacy wire vocabulary ──────────────────────────────────────────────────

test('every legacy action type translates to a canonical pair', function () {
    expect(ActionTypeTranslation::toCanonical(ActionType::TurnOn))->toBe([CapabilityId::Power, Operation::On])
        ->and(ActionTypeTranslation::toCanonical(ActionType::TurnOff))->toBe([CapabilityId::Power, Operation::Off])
        ->and(ActionTypeTranslation::toCanonical(ActionType::Toggle))->toBe([CapabilityId::Power, Operation::Toggle])
        ->and(ActionTypeTranslation::toCanonical(ActionType::SetBrightness))->toBe([CapabilityId::Brightness, Operation::Set]);
});

test('an unrecognised wire action type is rejected, not translated', function () {
    expect(ActionTypeTranslation::tryFromWire('explode'))->toBeNull();

    $result = canonicalValidator()->validateLegacyAction(canonicalLamp(), 'explode');

    expect($result->reason)->toBe(CommandRejectionReason::OperationUnsupported);
});

test('the three power verbs validate against the single canonical power capability', function () {
    foreach (['turn_on', 'turn_off', 'toggle'] as $actionType) {
        expect(canonicalValidator()->validateLegacyAction(canonicalLamp(), $actionType)->wasRejected())
            ->toBeFalse("Legacy action {$actionType} should validate against canonical power.");
    }
});

// ── Provider neutrality ─────────────────────────────────────────────────────

test('no provider slug, service name or native scale appears in the validation layer', function () {
    $sources = [
        app_path('SmartHome/Canonical/CommandValidator.php'),
        app_path('SmartHome/Canonical/ActionTypeTranslation.php'),
        app_path('SmartHome/Canonical/CommandRejectionReason.php'),
        app_path('SmartHome/Canonical/CommandValidationResult.php'),
    ];

    foreach ($sources as $source) {
        $contents = (string) file_get_contents($source);
        $code = preg_replace('!/\*.*?\*/|//.*!s', '', $contents) ?? '';

        expect(preg_match('/\b(home_assistant|google_home)\b/', $code))
            ->toBe(0, sprintf('Provider slug found in %s.', basename($source)));

        expect(preg_match('/\b25[45](?:\.0)?\b/', $code))
            ->toBe(0, sprintf('Provider scale literal found in %s.', basename($source)));
    }
});
