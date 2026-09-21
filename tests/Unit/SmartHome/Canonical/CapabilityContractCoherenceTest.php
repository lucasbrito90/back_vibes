<?php

declare(strict_types=1);

use App\SmartHome\Canonical\Access;
use App\SmartHome\Canonical\CapabilityCatalog;
use App\SmartHome\Canonical\CapabilityContract;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\Canonical\Unit;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| CSDM-01 — the seam that stops the schema rotting (ADR-037 §10-§11)
|--------------------------------------------------------------------------
|
| The contract is deliberately expressed twice: once declaratively, in a JSON
| Schema that Kotlin and TypeScript will validate against in their own idiom,
| and once in PHP value objects that enforce it at runtime here. Two
| expressions of one truth drift silently unless something forces them
| together — that is this file's only job.
|
| Add a capability to the PHP enum and forget the schema (or the reverse) and
| these tests fail, naming exactly what is missing.
*/

/** @return array<string, mixed> */
function csdm01Schema(): array
{
    return CapabilityContract::schema();
}

test('the vendored schema is present, parseable and declares the contract version', function () {
    $schema = csdm01Schema();

    expect($schema['$id'])->toContain('capability.v1.schema.json')
        ->and($schema['x-contract-version'])->toBe(CapabilityContract::VERSION);
});

test('the schema capability id vocabulary is exactly the PHP enum', function () {
    $schemaIds = csdm01Schema()['$defs']['capabilityId']['enum'];
    $phpIds = array_map(static fn (CapabilityId $c): string => $c->value, CapabilityId::cases());

    sort($schemaIds);
    sort($phpIds);

    expect($schemaIds)->toBe(
        $phpIds,
        'The closed capability vocabulary drifted between the JSON Schema and CapabilityId.',
    );
});

test('the schema operation vocabulary is exactly the PHP enum', function () {
    $schemaOperations = csdm01Schema()['$defs']['operation']['enum'];
    $phpOperations = array_map(static fn (Operation $o): string => $o->value, Operation::cases());

    sort($schemaOperations);
    sort($phpOperations);

    expect($schemaOperations)->toBe($phpOperations);
});

test('the schema access vocabulary is exactly the PHP enum', function () {
    $schemaAccess = csdm01Schema()['$defs']['access']['enum'];
    $phpAccess = array_map(static fn (Access $a): string => $a->value, Access::cases());

    sort($schemaAccess);
    sort($phpAccess);

    expect($schemaAccess)->toBe($phpAccess);
});

test('the schema unit vocabulary is exactly the PHP enum', function () {
    $schemaUnits = csdm01Schema()['$defs']['numberConstraint']['properties']['unit']['enum'];
    $phpUnits = array_map(static fn (Unit $u): string => $u->value, Unit::cases());

    sort($schemaUnits);
    sort($phpUnits);

    expect($schemaUnits)->toBe($phpUnits);
});

test('the schema constraint union is exactly the three PHP constraint types', function () {
    $union = csdm01Schema()['$defs']['constraint']['oneOf'];
    $refs = array_map(static fn (array $entry): string => $entry['$ref'], $union);

    expect($refs)->toEqualCanonicalizing([
        '#/$defs/numberConstraint',
        '#/$defs/enumConstraint',
        '#/$defs/booleanConstraint',
    ]);
});

test('every capability in the schema catalog agrees with CapabilityCatalog', function () {
    $catalog = csdm01Schema()['x-catalog'];

    foreach (CapabilityId::cases() as $id) {
        expect(array_key_exists($id->value, $catalog))->toBeTrue(
            sprintf('Capability [%s] exists in PHP but is missing from the schema catalog.', $id->value),
        );

        $entry = $catalog[$id->value];

        $phpOperations = array_map(
            static fn (Operation $o): string => $o->value,
            CapabilityCatalog::operationsFor($id),
        );

        expect($entry['operations'])->toBe(
            $phpOperations,
            sprintf('Operations for [%s] drifted between schema and catalog.', $id->value),
        );

        expect($entry['constraint_type'])->toBe(
            CapabilityCatalog::constraintTypeFor($id),
            sprintf('Constraint type for [%s] drifted.', $id->value),
        );

        expect($entry['default_access'])->toBe(
            CapabilityCatalog::defaultAccessFor($id)->value,
            sprintf('Default access for [%s] drifted.', $id->value),
        );

        $unit = CapabilityCatalog::unitFor($id);

        if ($unit !== null) {
            expect($entry['unit'] ?? null)->toBe(
                $unit->value,
                sprintf('Canonical unit for [%s] drifted.', $id->value),
            );
        }
    }

    // …and nothing lives in the schema catalog that PHP does not know about.
    $phpIds = array_map(static fn (CapabilityId $c): string => $c->value, CapabilityId::cases());

    foreach (array_keys($catalog) as $key) {
        if ($key === 'description') {
            continue;
        }

        expect(in_array($key, $phpIds, true))->toBeTrue(
            sprintf('Capability [%s] exists in the schema catalog but not in the PHP enum.', $key),
        );
    }
});

test('the schema fixes brightness at the canonical range the catalog uses', function () {
    $fixed = csdm01Schema()['x-catalog']['brightness']['fixed_range'];
    $canonical = CapabilityCatalog::canonicalBrightnessConstraint();

    expect((float) $fixed['min'])->toBe($canonical->min)
        ->and((float) $fixed['max'])->toBe($canonical->max)
        ->and((float) $fixed['step'])->toBe($canonical->step);
});

test('the vendored schema is byte-identical to the canonical copy in ixora-infra, when that repo is checked out alongside', function () {
    $canonicalPath = base_path('../ixora-infra/contracts/smart-home/capability.v1.schema.json');

    if (! is_readable($canonicalPath)) {
        // ixora-infra is a separate repository and is not guaranteed to be
        // present — in CI, or in a checkout of back_vibes alone, it will not be.
        // Cross-repo drift detection is a documented manual step precisely
        // because no cross-repo CI exists to automate it.
        expect(true)->toBeTrue();

        return;
    }

    expect(file_get_contents($canonicalPath))->toBe(
        file_get_contents(base_path(CapabilityContract::SCHEMA_PATH)),
        'The vendored schema drifted from the canonical copy in ixora-infra/contracts/smart-home/.',
    );
});
