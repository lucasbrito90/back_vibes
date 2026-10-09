<?php

namespace App\Http\Requests;

use App\Services\Storage\UploadAssetValidator;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceSoundAudioRequest extends FormRequest
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
            'audio_file' => ['required', 'file', 'max:'.intdiv(UploadAssetValidator::AUDIO_MAX_BYTES, 1024)],
        ];
    }
}
