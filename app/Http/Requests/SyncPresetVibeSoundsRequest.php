<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SyncPresetVibeSoundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sounds = $this->input('sounds', []);
            if (! is_array($sounds)) {
                return;
            }
            foreach ($sounds as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $mode = isset($row['play_mode']) && is_string($row['play_mode']) ? $row['play_mode'] : 'loop';
                if ($mode !== 'interval') {
                    continue;
                }
                $interval = $row['repeat_interval_seconds'] ?? null;
                if ($interval === null || $interval === '') {
                    $validator->errors()->add(
                        "sounds.$i.repeat_interval_seconds",
                        'repeat_interval_seconds is required when play_mode is interval.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sounds' => ['required', 'array'],
            'sounds.*.sound_id' => ['required', 'integer', 'distinct', Rule::exists('sounds', 'id')],
            'sounds.*.play_mode' => ['sometimes', 'string', Rule::in(['loop', 'once', 'interval'])],
            'sounds.*.volume' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'sounds.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'sounds.*.repeat_interval_seconds' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'sounds.*.start_delay_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sounds.*.start_offset_seconds' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sounds.*.duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sounds.*.play_duration_seconds' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Rows sorted by sort_order then sound_id, normalized for persistence (matches vibe_sounds column names).
     *
     * @return list<array{
     *     sound_id: int,
     *     volume: int,
     *     sort_order: int,
     *     play_mode: string,
     *     loop: bool,
     *     repeat_interval_seconds: int|null,
     *     start_offset_seconds: int|null,
     *     play_duration_seconds: int|null
     * }>
     */
    public function normalizedLayers(): array
    {
        /** @var array{sounds: list<array<string, mixed>>} $data */
        $data = $this->validated();
        $sounds = $data['sounds'];

        usort(
            $sounds,
            static fn (array $a, array $b): int => (($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0))
                ?: (($a['sound_id'] ?? 0) <=> ($b['sound_id'] ?? 0)),
        );

        $out = [];
        foreach ($sounds as $row) {
            $playMode = isset($row['play_mode']) && is_string($row['play_mode']) ? $row['play_mode'] : 'loop';

            $startDelay = $row['start_delay_seconds'] ?? null;
            $startOffset = $row['start_offset_seconds'] ?? null;
            $resolvedStart = $startDelay !== null ? (int) $startDelay : ($startOffset !== null ? (int) $startOffset : null);

            $duration = $row['duration_seconds'] ?? null;
            $playDur = $row['play_duration_seconds'] ?? null;
            $resolvedDur = $duration !== null ? (int) $duration : ($playDur !== null ? (int) $playDur : null);

            $out[] = [
                'sound_id' => (int) $row['sound_id'],
                'volume' => isset($row['volume']) ? (int) $row['volume'] : 100,
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
                'play_mode' => $playMode,
                'loop' => $playMode === 'loop',
                'repeat_interval_seconds' => $playMode === 'interval'
                    ? (isset($row['repeat_interval_seconds']) ? (int) $row['repeat_interval_seconds'] : null)
                    : null,
                'start_offset_seconds' => $resolvedStart,
                'play_duration_seconds' => $resolvedDur,
            ];
        }

        return $out;
    }
}
