<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\PushNotifications\PushPlatform;
use App\PushNotifications\PushProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the push token registration payload.
 *
 * user_id, is_active, revoked_at, last_seen_at are prohibited in the request body —
 * they are always derived from auth context or set by the service layer.
 *
 * References: ADR-018, ADR-021, spec.md §5.
 */
final class StorePushTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'platform' => [
                'required',
                'string',
                Rule::in(array_map(fn (PushPlatform $p) => $p->value, PushPlatform::mvpAllowed())),
            ],
            'provider' => [
                'nullable',
                'string',
                Rule::in(array_map(fn (PushProvider $p) => $p->value, PushProvider::mvpAllowed())),
            ],
            'device_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:64'],
            'device_model' => ['nullable', 'string', 'max:255'],
            'user_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'revoked_at' => ['prohibited'],
            'last_seen_at' => ['prohibited'],
        ];
    }
}
