<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SceneAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderSceneActionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateIdsBelongToScene($validator);
            },
        ];
    }

    private function validateIdsBelongToScene(Validator $validator): void
    {
        $sceneId = (int) $this->route('scene');

        $orderedIds = $this->input('ordered_ids', []);

        $ownedIds = SceneAction::query()
            ->where('scene_id', $sceneId)
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->all();

        $foreignIds = array_diff($orderedIds, $ownedIds);

        if ($foreignIds !== []) {
            $validator->errors()->add(
                'ordered_ids',
                'All action ids must belong to this scene.'
            );
        }
    }
}
