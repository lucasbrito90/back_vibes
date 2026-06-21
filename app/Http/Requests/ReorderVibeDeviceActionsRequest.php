<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Vibe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReorderVibeDeviceActionsRequest extends FormRequest
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

                $this->validateIdsBelongToVibe($validator);
            },
        ];
    }

    private function validateIdsBelongToVibe(Validator $validator): void
    {
        /** @var Vibe $vibe */
        $vibe = $this->route('vibe');

        $orderedIds = $this->input('ordered_ids', []);

        $ownedIds = $vibe->deviceActions()
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->all();

        $foreignIds = array_diff($orderedIds, $ownedIds);

        if ($foreignIds !== []) {
            $validator->errors()->add(
                'ordered_ids',
                'All action ids must belong to this vibe.'
            );
        }
    }
}
