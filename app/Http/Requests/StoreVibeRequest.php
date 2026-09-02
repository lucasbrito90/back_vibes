<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Scene;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVibeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'scene_id' => ['nullable', 'integer', Rule::exists('scenes', 'id')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateSceneOwnership($validator);
            },
        ];
    }

    private function validateSceneOwnership(Validator $validator): void
    {
        $sceneId = $this->input('scene_id');

        if ($sceneId === null) {
            return;
        }

        $owned = Scene::where('id', $sceneId)
            ->where('user_id', $this->user()->id)
            ->exists();

        if (! $owned) {
            $validator->errors()->add(
                'scene_id',
                'The selected scene does not belong to you.'
            );
        }
    }
}
