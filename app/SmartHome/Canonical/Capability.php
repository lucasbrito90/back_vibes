<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;

/**
 * One canonical capability of a device (ADR-037 §2).
 *
 * Construction is validating: there is no half-valid Capability. Every rule the
 * catalog declares — which operations the id admits, which constraint type it
 * must carry, which unit, whether it may be commanded at all — is enforced here,
 * so a malformed capability fails at the mapper boundary that produced it rather
 * than three layers downstream when a command is dispatched.
 *
 * Supersedes ADR-033's flat `capability_key => constraint` map, where the key
 * doubled as the sole operation and nothing carried a unit.
 */
final readonly class Capability
{
    /** @param list<Operation> $operations */
    public function __construct(
        public CapabilityId $id,
        public Access $access,
        public array $operations,
        public ?Constraint $constraints,
    ) {
        $this->assertOperationsAreAdmissible();
        $this->assertAccessAgreesWithOperations();
        $this->assertConstraintMatchesCatalog();
    }

    /**
     * Builds a capability using every catalog default, overriding only the
     * bounds a provider mapper actually knows. This is the constructor mappers
     * (CSDM-03/CSDM-04) are expected to reach for.
     */
    public static function fromCatalog(
        CapabilityId $id,
        ?Constraint $constraints = null,
        ?Access $access = null,
    ): self {
        return new self(
            $id,
            $access ?? CapabilityCatalog::defaultAccessFor($id),
            CapabilityCatalog::operationsFor($id),
            $constraints ?? self::defaultConstraintFor($id),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'access' => $this->access->value,
            'operations' => array_map(static fn (Operation $o): string => $o->value, $this->operations),
            'constraints' => $this->constraints?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidCapabilityDefinitionException
     */
    public static function fromArray(array $data): self
    {
        $id = CapabilityId::tryFrom(is_string($data['id'] ?? null) ? $data['id'] : '');

        if ($id === null) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Unknown canonical capability id [%s]. The vocabulary is closed — adding one is a deliberate amendment, not a provider mapper decision.',
                    is_scalar($data['id'] ?? null) ? (string) $data['id'] : gettype($data['id'] ?? null),
                ),
            );
        }

        $access = Access::tryFrom(is_string($data['access'] ?? null) ? $data['access'] : '');

        if ($access === null) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('Unknown access level for capability [%s].', $id->value),
            );
        }

        $operations = [];
        foreach ((array) ($data['operations'] ?? []) as $raw) {
            $operation = Operation::tryFrom(is_string($raw) ? $raw : '');

            if ($operation === null) {
                throw new InvalidCapabilityDefinitionException(
                    sprintf(
                        'Unknown canonical operation [%s] on capability [%s].',
                        is_scalar($raw) ? (string) $raw : gettype($raw),
                        $id->value,
                    ),
                );
            }

            $operations[] = $operation;
        }

        $constraints = $data['constraints'] ?? null;

        return new self(
            $id,
            $access,
            $operations,
            is_array($constraints) ? Constraint::fromArray($constraints) : null,
        );
    }

    /** Whether this capability declares the given operation. */
    public function supports(Operation $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    private static function defaultConstraintFor(CapabilityId $id): Constraint
    {
        return match (CapabilityCatalog::constraintTypeFor($id)) {
            'boolean' => new BooleanConstraint,
            'number' => $id === CapabilityId::Brightness
                ? CapabilityCatalog::canonicalBrightnessConstraint()
                // Bounds unknown until a mapper supplies them; the unit is not.
                : new NumberConstraint(null, null, null, CapabilityCatalog::unitFor($id) ?? Unit::Percent),
            // An enum's allowed values are per-device; there is no honest default.
            default => throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] requires an explicit enum constraint — its allowed values are device-specific and cannot be defaulted.',
                    $id->value,
                ),
            ),
        };
    }

    private function assertOperationsAreAdmissible(): void
    {
        $admissible = CapabilityCatalog::operationsFor($this->id);

        foreach ($this->operations as $operation) {
            if (! in_array($operation, $admissible, true)) {
                throw new InvalidCapabilityDefinitionException(
                    sprintf(
                        'Operation [%s] is not admissible on capability [%s]. Admissible: [%s].',
                        $operation->value,
                        $this->id->value,
                        $admissible === []
                            ? 'none — this capability is read-only'
                            : implode(', ', array_map(static fn (Operation $o): string => $o->value, $admissible)),
                    ),
                );
            }
        }

        if (count(array_unique($this->operations, SORT_REGULAR)) !== count($this->operations)) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('Capability [%s] declares a duplicate operation.', $this->id->value),
            );
        }
    }

    private function assertAccessAgreesWithOperations(): void
    {
        if ($this->operations !== [] && ! $this->access->permitsWrite()) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] declares operations but access [%s] does not permit commanding it.',
                    $this->id->value,
                    $this->access->value,
                ),
            );
        }

        if (CapabilityCatalog::isReadOnly($this->id) && $this->access->permitsWrite()) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] is a measurement and can never be widened to [%s].',
                    $this->id->value,
                    $this->access->value,
                ),
            );
        }
    }

    private function assertConstraintMatchesCatalog(): void
    {
        $expected = CapabilityCatalog::constraintTypeFor($this->id);

        if ($this->constraints === null) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] requires a [%s] constraint. No v1 capability carries a null constraint — power included (ADR-037 §4).',
                    $this->id->value,
                    $expected,
                ),
            );
        }

        if ($this->constraints->type() !== $expected) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] requires a [%s] constraint, got [%s].',
                    $this->id->value,
                    $expected,
                    $this->constraints->type(),
                ),
            );
        }

        $expectedUnit = CapabilityCatalog::unitFor($this->id);

        if ($this->constraints instanceof NumberConstraint && $expectedUnit !== null
            && $this->constraints->unit !== $expectedUnit) {
            throw new InvalidCapabilityDefinitionException(
                sprintf(
                    'Capability [%s] is canonically measured in [%s], got [%s].',
                    $this->id->value,
                    $expectedUnit->value,
                    $this->constraints->unit->value,
                ),
            );
        }

        if ($this->id === CapabilityId::Brightness && $this->constraints instanceof NumberConstraint) {
            $canonical = CapabilityCatalog::canonicalBrightnessConstraint();

            if ($this->constraints->min !== $canonical->min || $this->constraints->max !== $canonical->max) {
                throw new InvalidCapabilityDefinitionException(
                    sprintf(
                        'Brightness is canonically %s-%s percent (ADR-037 §5); a provider scale such as 0-255 or 0-254 must be converted at the mapper boundary, never declared here.',
                        $canonical->min,
                        $canonical->max,
                    ),
                );
            }
        }
    }
}
