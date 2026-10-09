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
            // Sound audio is versioned and processed asynchronously; this generic endpoint would overwrite
            // a published object in place, so it is closed for audio.
            if ($this->input('entity_type') === 'sound' && $this->input('asset_type') === 'audio') {
                $validator->errors()->add(
                    'asset_type',
                    'Sound audio cannot be uploaded here. Use POST /api/admin/sounds/{id}/audio (or POST /api/admin/sounds to create).',
                );

                return;
            }

            UploadAssetValidator::validateAfterBaseRules($validator);
        });
    }
}
