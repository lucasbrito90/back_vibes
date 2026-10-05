<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SmartHome\ConnectionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a client-reported health update for a device-side provider connection.
 *
 * Input is treated as untrusted: the client cannot escalate its own connection
 * to a managed status (pending, connecting) and cannot report for another user's
 * connection (ownership checked in the controller).
 */
class ReportHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(ConnectionStatus::clientReportable())],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
