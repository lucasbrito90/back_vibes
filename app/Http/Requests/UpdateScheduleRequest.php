<?php

namespace App\Http\Requests;

use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vibe_id' => ['sometimes', 'integer', Rule::exists('vibes', 'id')],
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'start_time' => ['sometimes', 'date'],
            'recurrence_type' => [
                'sometimes',
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
        if (! $this->has('vibe_id')) {
            return;
        }

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
        $schedule = $this->route('schedule');

        // Resolve effective recurrence_type: incoming value or existing record value.
        $type = $this->has('recurrence_type')
            ? $this->input('recurrence_type')
            : $schedule?->recurrence_type;

        if ($type !== RecurrenceType::Weekly->value) {
            return;
        }

        // When recurrence_type is weekly (new or existing), days_of_week is required
        // unless recurrence_config is explicitly not being changed and the existing
        // config already has valid days (which the controller will preserve).
        if (! $this->has('recurrence_config') && ! $this->has('recurrence_type')) {
            return;
        }

        // If config is being sent, ensure days_of_week is present.
        if ($this->has('recurrence_config')) {
            $days = $this->input('recurrence_config.days_of_week');

            if (! is_array($days) || count($days) === 0) {
                $validator->errors()->add(
                    'recurrence_config.days_of_week',
                    'The recurrence_config.days_of_week field is required for weekly recurrence.'
                );
            }
        }

        // If switching to weekly without providing config, require days_of_week.
        if ($this->has('recurrence_type') && ! $this->has('recurrence_config')) {
            $validator->errors()->add(
                'recurrence_config.days_of_week',
                'The recurrence_config.days_of_week field is required for weekly recurrence.'
            );
        }
    }
}
