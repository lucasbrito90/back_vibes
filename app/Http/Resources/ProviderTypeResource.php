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
        ];
    }

    /**
     * @param  array<string, ProviderFieldSchema>  $fields
     * @return array<string, array{type: string, required: bool, format?: string}>
     */
    private function schemaToArray(array $fields): array
    {
        $mapped = [];

        foreach ($fields as $key => $field) {
            $mapped[$key] = $field->toArray();
        }

        return $mapped;
    }
}
