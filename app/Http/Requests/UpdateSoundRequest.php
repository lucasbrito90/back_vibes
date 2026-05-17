<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSoundRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'file_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'audio_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * When either URL field is present, return the resolved storage URL (audio_url wins).
     */
    public function resolvedFileUrl(): ?string
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (! array_key_exists('audio_url', $data) && ! array_key_exists('file_url', $data)) {
            return null;
        }

        $audio = $data['audio_url'] ?? null;
        $file = $data['file_url'] ?? null;

        if (is_string($audio) && $audio !== '') {
            return $audio;
        }

        if (is_string($file) && $file !== '') {
            return $file;
        }

        return '';
    }
}
