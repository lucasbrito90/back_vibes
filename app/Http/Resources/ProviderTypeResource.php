<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\SmartHome\DTOs\ProviderDescriptor;
use App\SmartHome\DTOs\ProviderFieldSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exposes a registered provider's slug, label, and field schemas.
 *
 * Credential schemas describe keys inside `encrypted_credentials` on create/update
 * requests — never actual token values.
 *
 * @mixin ProviderDescriptor
 */
class ProviderTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'config' => $this->schemaToArray($this->config),
            'credentials' => $this->schemaToArray($this->credentials),
            // ADR-036 Decision 2 — provider-level execution capabilities.
            // A genuine list (order-independent set of strings), unlike
            // config/credentials above, so it always serializes as a JSON
            // array — no empty-map/empty-list ambiguity to correct here.
            'execution_capabilities' => array_map(
                fn ($capability) => $capability->value,
                $this->executionCapabilities,
            ),
        ];
    }

    /**
     * `config`/`credentials` are keyed maps, never JSON arrays — a provider
     * with no fields (e.g. google_home, which has nothing to collect
     * server-side) must still serialize as `{}`, not `[]`. PHP's json_encode
     * treats an empty array as a JSON array regardless of its declared
     * shape, so an empty map is cast to stdClass to force object encoding.
     * This applies to every provider descriptor, not just google_home.
     *
     * @param  array<string, ProviderFieldSchema>  $fields
     * @return array<string, array{type: string, required: bool, format?: string}>|object
     */
    private function schemaToArray(array $fields): array|object
    {
        $mapped = [];

        foreach ($fields as $key => $field) {
            $mapped[$key] = $field->toArray();
        }

        return $mapped === [] ? (object) [] : $mapped;
    }
}
