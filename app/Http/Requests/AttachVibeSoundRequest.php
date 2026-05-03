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
            'sound_id'              => ['required', 'integer', 'exists:sounds,id'],
            'volume'                => ['sometimes', 'integer', 'min:0', 'max:100'],
            'loop'                  => ['sometimes', 'boolean'],
            'sort_order'            => ['sometimes', 'integer', 'min:0'],
            'start_offset_seconds'  => ['nullable', 'integer', 'min:0'],
            'play_duration_seconds' => ['nullable', 'integer', 'min:1'],
            'fade_in_seconds'       => ['nullable', 'integer', 'min:0'],
            'fade_out_seconds'      => ['nullable', 'integer', 'min:0'],
        ];
    }
}
