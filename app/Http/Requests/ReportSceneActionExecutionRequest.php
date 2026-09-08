<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Client-reported scene action execution result (ADR-036 Decision 7).
 *
 * The mobile runtime executes device-side actions and pushes outcomes here; the
 * backend validates shape only — persistence, ownership, and idempotency are P07.
 */
class ReportSceneActionExecutionRequest extends FormRequest
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
            'scene_execution_id' => ['required', 'uuid'],
            'scene_action_id' => ['required', 'integer'],
            'outcome' => ['required', 'string', Rule::in(self::allowedOutcomes())],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'outcome.in' => 'The outcome does not belong to the SmartHomeActionOutcome vocabulary.',
        ];
    }

    /**
     * Outcome values derived from SmartHomeActionOutcome — not hardcoded, so new
     * cases automatically extend the allowed vocabulary.
     *
     * @return list<string>
     */
    private static function allowedOutcomes(): array
    {
        return array_map(
            static fn (SmartHomeActionOutcome $outcome): string => $outcome->value,
            SmartHomeActionOutcome::cases(),
        );
    }
}
