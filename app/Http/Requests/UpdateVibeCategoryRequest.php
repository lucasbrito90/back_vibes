<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\VibeCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateVibeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->has('slug')) {
                return;
            }

            $category = $this->route('vibe_category');
            if (! $category instanceof VibeCategory) {
                return;
            }

            $submitted = $this->input('slug');
            if (! is_string($submitted) || $submitted !== $category->slug) {
                $validator->errors()->add('slug', 'The slug cannot be changed.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'names' => ['sometimes', 'array'],
            'names.en' => ['required_with:names', 'string'],
            'names.pt' => ['sometimes', 'string'],
            'names.fr' => ['sometimes', 'string'],
            'names.es' => ['sometimes', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
