<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVibeCategoryRequest extends FormRequest
{
    /**
     * Matches {@see StoreSoundRequest} / {@see StorePresetVibeRequest}: always true here;
     * {@see VibeCategoryController} enforces {@see VibeCategoryPolicy} via authorize().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('vibe_categories', 'slug')],
            'names' => ['required', 'array'],
            'names.en' => ['required', 'string'],
            'names.pt' => ['sometimes', 'string'],
            'names.fr' => ['sometimes', 'string'],
            'names.es' => ['sometimes', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
