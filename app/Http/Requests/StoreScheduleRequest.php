<?php

namespace App\Http\Requests;

use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vibe_id' => ['required', 'integer', Rule::exists('vibes', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'start_time' => ['required', 'date'],
            'recurrence_type' => [
                'required',
                'string',
                Rule::in(array_map(fn (RecurrenceType $t) => $t->value, RecurrenceType::mvpAllowed())),
            ],
            'recurrence_config' => ['sometimes', 'nullable', 'array'],
            'recurrence_config.days_of_week' => ['sometimes', 'array', 'min:1'],
            'recurrence_config.days_of_week.*' => ['integer', 'between:1,7'],
            'is_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateVibeOwnership($validator);
                $this->validateRecurrenceConfig($validator);
            },
        ];
    }

    private function validateVibeOwnership(Validator $validator): void
    {
        $vibeId = $this->input('vibe_id');

        if ($vibeId === null) {
            return;
        }

        $owned = Vibe::where('id', $vibeId)
            ->where('user_id', $this->user()->id)
            ->exists();

        if (! $owned) {
            $validator->errors()->add('vibe_id', 'The selected vibe does not belong to you.');
        }
    }

    private function validateRecurrenceConfig(Validator $validator): void
    {
        $type = $this->input('recurrence_type');

        if ($type !== RecurrenceType::Weekly->value) {
            return;
        }

        $days = $this->input('recurrence_config.days_of_week');

        if (! is_array($days) || count($days) === 0) {
            $validator->errors()->add(
                'recurrence_config.days_of_week',
                'The recurrence_config.days_of_week field is required for weekly recurrence.'
            );
        }
    }
}
