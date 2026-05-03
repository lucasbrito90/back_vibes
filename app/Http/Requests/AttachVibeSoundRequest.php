<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachVibeSoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sound_id'                => ['required', 'integer', 'exists:sounds,id'],
            'volume'                  => ['sometimes', 'integer', 'min:0', 'max:100'],
            'sort_order'              => ['sometimes', 'integer', 'min:0'],
            // play_mode is the source of truth; loop is derived in the controller
            'play_mode'               => ['sometimes', 'string', 'in:loop,once,interval'],
            'repeat_interval_seconds' => [
                'nullable', 'integer', 'min:1',
                'required_if:play_mode,interval',
            ],
            'start_offset_seconds'    => ['nullable', 'integer', 'min:0'],
            'play_duration_seconds'   => ['nullable', 'integer', 'min:1'],
            'fade_in_seconds'         => ['nullable', 'integer', 'min:0'],
            'fade_out_seconds'        => ['nullable', 'integer', 'min:0'],
        ];
    }
}
