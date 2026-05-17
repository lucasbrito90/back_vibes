<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSoundRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'file_url' => ['required_without:audio_url', 'nullable', 'string', 'max:2048'],
            'audio_url' => ['required_without:file_url', 'nullable', 'string', 'max:2048'],
            'thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function resolvedFileUrl(): string
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $url = $data['audio_url'] ?? $data['file_url'] ?? '';

        return is_string($url) ? $url : '';
    }

    public function resolvedDuration(): ?int
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        if (isset($data['duration_seconds'])) {
            return (int) $data['duration_seconds'];
        }

        if (isset($data['duration'])) {
            return (int) $data['duration'];
        }

        return null;
    }
}
