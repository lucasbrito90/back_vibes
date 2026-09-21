<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

use App\SmartHome\Canonical\Exceptions\InvalidCapabilityDefinitionException;
use JsonException;

/**
 * The versioned contract artifact itself (ADR-037 §10).
 *
 * The schema file is the declarative source of truth, shared across languages —
 * PHP here, Kotlin in the Android plugin, TypeScript in front_vibes, each
 * validating in its own idiom. No shared runtime library exists or should
 * (ADR-037 §11); what is shared is this JSON document.
 *
 * The canonical copy lives in `ixora-infra/contracts/smart-home/`; the copy read
 * here is vendored into back_vibes because the deploy pipeline builds this repo
 * alone and cannot reach a sibling. Both carry the same $id and version, and
 * CapabilityContractCoherenceTest fails if this class and the schema drift.
 */
final class CapabilityContract
{
    /**
     * Semver of the canonical contract. Additive changes (a new capability id, a
     * new operation on an existing capability, a new optional field) are minor;
     * changing an existing constraint's required shape is major and triggers the
     * dual-read compatibility path of ADR-037 §8 / CSDM-07.
     */
    public const VERSION = '1.0.0';

    /** Accompanies persisted and transmitted capability data so a consumer can tell which shape it is reading. */
    public const VERSION_KEY = 'contract_version';

    public const SCHEMA_PATH = 'contracts/smart-home/capability.v1.schema.json';

    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /**
     * Loads the vendored schema document.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = base_path(self::SCHEMA_PATH);
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('The canonical capability schema is missing at [%s].', self::SCHEMA_PATH),
            );
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidCapabilityDefinitionException(
                sprintf('The canonical capability schema is not valid JSON: %s', $e->getMessage()),
            );
        }

        return self::$schema = $decoded;
    }

    /**
     * Wraps a set of capabilities in the envelope that carries the contract
     * version alongside the data (ADR-037 §10).
     *
     * @param  list<Capability>  $capabilities
     * @return array{contract_version: string, capabilities: array<string, array<string, mixed>>}
     */
    public static function envelope(array $capabilities): array
    {
        $map = [];

        foreach ($capabilities as $capability) {
            $map[$capability->id->value] = $capability->toArray();
        }

        return [
            self::VERSION_KEY => self::VERSION,
            'capabilities' => $map,
        ];
    }

    /**
     * Whether a stored/transmitted payload declares the canonical envelope.
     *
     * A payload without the version marker is legacy ADR-033 data and must be
     * read through LegacyCapabilityMapReader — this is the discriminator that
     * makes the transition window detectable rather than guessed.
     *
     * @param  array<mixed>  $payload
     */
    public static function isCanonicalEnvelope(array $payload): bool
    {
        return isset($payload[self::VERSION_KEY]) && is_string($payload[self::VERSION_KEY]);
    }

    /** Major version of a payload's declared contract, or null when it declares none. */
    public static function majorVersionOf(string $version): ?int
    {
        if (preg_match('/^(\d+)\.\d+\.\d+$/', $version, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
