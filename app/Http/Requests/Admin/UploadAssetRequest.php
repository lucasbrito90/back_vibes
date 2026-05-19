<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Storage\UploadAssetValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UploadAssetRequest extends FormRequest
{
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
            'entity_type' => ['required', 'string', Rule::in(['sound', 'cover', 'vibe', 'user'])],
            'entity_id' => ['required', 'integer', 'min:1'],
            'asset_type' => ['required', 'string'],
            'file' => ['required', 'file'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            UploadAssetValidator::validateAfterBaseRules($validator);
        });
    }
}
