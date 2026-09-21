<?php

declare(strict_types=1);

use App\SmartHome\Canonical\Access;
use App\SmartHome\Canonical\BooleanConstraint;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityCatalog;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\Constraint;
use App\SmartHome\Canonical\EnumConstraint;
use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
use App\SmartHome\Canonical\NumberConstraint;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-01 — canonical capability contract (ADR-037 §2-§5, §10)
|--------------------------------------------------------------------------
|
| Construction is validating by design, so most of these tests assert that an
| invalid shape THROWS rather than producing a half-valid object. The worked
| examples of ADR-037 §12 are the reference for every valid shape below.
*/

// ── The v1 catalog, built from ADR-037 §12's worked examples ────────────────

test('every v1 catalog capability builds in its canonical shape', function () {
    $power = Capability::fromCatalog(CapabilityId::Power);

    expect($power->access)->toBe(Access::ReadWrite)
        ->and($power->operations)->toBe([Operation::On, Operation::Off, Operation::Toggle])
        ->and($power->constraints)->toBeInstanceOf(BooleanConstraint::class);

    $brightness = Capability::fromCatalog(CapabilityId::Brightness);

    expect($brightness->operations)->toBe([Operation::Set])
        ->and($brightness->constraints)->toBeInstanceOf(NumberConstraint::class)
        ->and($brightness->constraints->min)->toBe(0.0)
        ->and($brightness->constraints->max)->toBe(100.0)
        ->and($brightness->constraints->step)->toBe(1.0)
        ->and($brightness->constraints->unit)->toBe(Unit::Percent);

    // Plug with read-only energy (§12): no operations, unbounded max, kWh.
    $energy = Capability::fromCatalog(
        CapabilityId::Energy,
        new NumberConstraint(0.0, null, 0.01, Unit::KilowattHour),
    );

    expect($energy->access)->toBe(Access::Read)
        ->and($energy->operations)->toBe([])
        ->and($energy->constraints->max)->toBeNull();

    // Thermostat (§12): current_temperature has honestly-null bounds.
    $current = Capability::fromCatalog(CapabilityId::CurrentTemperature);

    expect($current->access)->toBe(Access::Read)
        ->and($current->constraints->min)->toBeNull()
        ->and($current->constraints->max)->toBeNull()
        ->and($current->constraints->step)->toBeNull()
        ->and($current->constraints->unit)->toBe(Unit::Celsius);

    // …while target_temperature has a real, device-enforced range.
    $target = Capability::fromCatalog(
        CapabilityId::TargetTemperature,
        new NumberConstraint(5.0, 35.0, 0.5, Unit::Celsius),
    );

    expect($target->access)->toBe(Access::ReadWrite)
        ->and($target->operations)->toBe([Operation::Set])
        ->and($target->constraints->min)->toBe(5.0);

    $hvac = Capability::fromCatalog(
        CapabilityId::HvacMode,
        new EnumConstraint(['off', 'heat', 'cool', 'auto']),
    );

    expect($hvac->constraints)->toBeInstanceOf(EnumConstraint::class)
        ->and($hvac->constraints->allowedValues)->toBe(['off', 'heat', 'cool', 'auto']);
});

// ── Closed vocabulary ───────────────────────────────────────────────────────

test('a capability id outside the closed vocabulary is rejected', function () {
    expect(fn () => Capability::fromArray([
        'id' => 'color',
        'access' => 'read_write',
        'operations' => ['set'],
        'constraints' => ['type' => 'boolean'],
    ]))->toThrow(InvalidCapabilityDefinitionException::class, 'Unknown canonical capability id');
});

test('an operation outside the closed vocabulary is rejected', function () {
    expect(fn () => Capability::fromArray([
        'id' => 'power',
        'access' => 'read_write',
        'operations' => ['dim_slowly'],
        'constraints' => ['type' => 'boolean'],
    ]))->toThrow(InvalidCapabilityDefinitionException::class, 'Unknown canonical operation');
});

// ── Operations must be admissible for the capability ────────────────────────

test('an operation not admissible on the capability is rejected', function () {
    expect(fn () => new Capability(
        CapabilityId::Brightness,
        Access::ReadWrite,
        [Operation::On],
        CapabilityCatalog::canonicalBrightnessConstraint(),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'is not admissible on capability [brightness]');
});

test('a read-only measurement cannot declare any operation', function () {
    expect(fn () => new Capability(
        CapabilityId::Energy,
        Access::ReadWrite,
        [Operation::Set],
        new NumberConstraint(0.0, null, 0.01, Unit::KilowattHour),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'read-only');
});

test('a read-only measurement cannot be widened to a writable access level', function () {
    expect(fn () => new Capability(
        CapabilityId::CurrentTemperature,
        Access::ReadWrite,
        [],
        new NumberConstraint(null, null, null, Unit::Celsius),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'can never be widened');
});

test('declaring operations while access forbids writing is rejected', function () {
    expect(fn () => new Capability(
        CapabilityId::Brightness,
        Access::Read,
        [Operation::Set],
        CapabilityCatalog::canonicalBrightnessConstraint(),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'does not permit commanding');
});

// ── Constraints — the PO's three pre-acceptance corrections ─────────────────

test('power requires a boolean constraint, never null (PO correction 2)', function () {
    expect(fn () => new Capability(
        CapabilityId::Power,
        Access::ReadWrite,
        [Operation::On, Operation::Off, Operation::Toggle],
        null,
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'requires a [boolean] constraint');
});

test('a number constraint without a unit is rejected', function () {
    expect(fn () => Constraint::fromArray([
        'type' => 'number',
        'min' => 0,
        'max' => 100,
        'step' => 1,
    ]))->toThrow(InvalidCapabilityDefinitionException::class, 'requires a unit');
});

test('a number constraint with null min, max and step is accepted — null means not known (PO correction 3)', function () {
    $constraint = Constraint::fromArray([
        'type' => 'number',
        'min' => null,
        'max' => null,
        'step' => null,
        'unit' => 'celsius',
    ]);

    expect($constraint)->toBeInstanceOf(NumberConstraint::class)
        ->and($constraint->min)->toBeNull()
        ->and($constraint->unit)->toBe(Unit::Celsius);
});

test('a number constraint with min greater than max is rejected', function () {
    expect(fn () => new NumberConstraint(80.0, 20.0, 1.0, Unit::Percent))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'greater than max');
});

test('a number constraint with a non-positive step is rejected', function () {
    expect(fn () => new NumberConstraint(0.0, 100.0, 0.0, Unit::Percent))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'greater than zero');
});

test('an enum constraint with no allowed values is rejected', function () {
    expect(fn () => new EnumConstraint([]))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'at least one allowed value');

    expect(fn () => Constraint::fromArray(['type' => 'enum']))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'requires an allowed_values array');
});

test('an enum constraint with duplicate allowed values is rejected', function () {
    expect(fn () => new EnumConstraint(['heat', 'heat']))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'must be unique');
});

test('an unknown constraint type is rejected — the union is closed', function () {
    expect(fn () => Constraint::fromArray(['type' => 'colour_temperature']))
        ->toThrow(InvalidCapabilityDefinitionException::class, 'union is closed');
});

test('a capability carrying the wrong constraint type for its id is rejected', function () {
    expect(fn () => new Capability(
        CapabilityId::HvacMode,
        Access::ReadWrite,
        [Operation::Set],
        new BooleanConstraint,
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'requires a [enum] constraint, got [boolean]');
});

test('a numeric capability declared in the wrong canonical unit is rejected', function () {
    expect(fn () => new Capability(
        CapabilityId::TargetTemperature,
        Access::ReadWrite,
        [Operation::Set],
        new NumberConstraint(5.0, 35.0, 0.5, Unit::Percent),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'canonically measured in [celsius]');
});

// ── Brightness is canonically 0-100 percent (ADR-037 §5) ────────────────────

test('brightness declared on a provider scale is rejected', function () {
    // Home Assistant's 0-255 …
    expect(fn () => new Capability(
        CapabilityId::Brightness,
        Access::ReadWrite,
        [Operation::Set],
        new NumberConstraint(0.0, 255.0, 1.0, Unit::Percent),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'canonically 0-100 percent');

    // … and Google Home's 0-254.
    expect(fn () => new Capability(
        CapabilityId::Brightness,
        Access::ReadWrite,
        [Operation::Set],
        new NumberConstraint(0.0, 254.0, 1.0, Unit::Percent),
    ))->toThrow(InvalidCapabilityDefinitionException::class, 'canonically 0-100 percent');
});

test('no provider scale appears anywhere in the contract artifacts', function () {
    $sources = [
        base_path(CapabilityContract::SCHEMA_PATH),
        app_path('SmartHome/Canonical/CapabilityCatalog.php'),
        app_path('SmartHome/Canonical/Capability.php'),
        app_path('SmartHome/Canonical/NumberConstraint.php'),
        app_path('SmartHome/Canonical/Unit.php'),
    ];

    foreach ($sources as $source) {
        $contents = file_get_contents($source);

        expect($contents)->not->toBeFalse("Could not read {$source}.");

        // 255 / 254 may be NAMED in prose explaining why they are excluded, but
        // must never appear as a numeric literal the contract depends on.
        $withoutComments = preg_replace('!/\*.*?\*/|//.*!s', '', (string) $contents) ?? '';

        expect(preg_match('/\b25[45](?:\.0)?\b/', $withoutComments))
            ->toBe(0, sprintf('Provider scale literal found in %s — brightness is canonically 0-100 percent.', basename($source)));
    }
});

// ── Versioning (ADR-037 §10) ────────────────────────────────────────────────

test('the envelope carries the contract version alongside the data', function () {
    $envelope = CapabilityContract::envelope([
        Capability::fromCatalog(CapabilityId::Power),
        Capability::fromCatalog(CapabilityId::Brightness),
    ]);

    expect($envelope[CapabilityContract::VERSION_KEY])->toBe(CapabilityContract::VERSION)
        ->and($envelope['capabilities'])->toHaveKeys(['power', 'brightness'])
        ->and($envelope['capabilities']['brightness']['constraints']['unit'])->toBe('percent');
});

test('the canonical envelope is distinguishable from a legacy payload', function () {
    $canonical = CapabilityContract::envelope([Capability::fromCatalog(CapabilityId::Power)]);

    expect(CapabilityContract::isCanonicalEnvelope($canonical))->toBeTrue()
        ->and(CapabilityContract::isCanonicalEnvelope(['can_turn_on' => []]))->toBeFalse();
});

test('the contract major version is readable for compatibility branching', function () {
    expect(CapabilityContract::majorVersionOf(CapabilityContract::VERSION))->toBe(1)
        ->and(CapabilityContract::majorVersionOf('not-a-version'))->toBeNull();
});

// ── Round trip ──────────────────────────────────────────────────────────────

test('a capability survives a round trip through its array form', function () {
    $original = Capability::fromCatalog(
        CapabilityId::TargetTemperature,
        new NumberConstraint(5.0, 35.0, 0.5, Unit::Celsius),
    );

    $restored = Capability::fromArray($original->toArray());

    expect($restored->toArray())->toBe($original->toArray())
        ->and($restored->supports(Operation::Set))->toBeTrue()
        ->and($restored->supports(Operation::Toggle))->toBeFalse();
});
