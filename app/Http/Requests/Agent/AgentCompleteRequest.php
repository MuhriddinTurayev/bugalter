<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A finished task: the cabinet snapshot (normalised again by PortalSnapshot), or what the cabinet answered to a
 * submission. Only hashes of the E-IMZO signature and of the signed content are accepted, never the signature.
 */
class AgentCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticateDesktopAgent
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'result' => ['required', 'array'],
            'result.snapshot' => ['nullable', 'array'],
            'result.draft' => ['nullable', 'array'],
            'result.status' => ['nullable', 'string', 'in:accepted,rejected,submitted,unknown,paid,failed'],
            'result.order_number' => ['nullable', 'string', 'max:12'],
            'result.receipt' => ['nullable', 'string', 'max:80'],
            'result.errors' => ['nullable', 'array', 'max:20'],
            'result.errors.*' => ['string', 'max:500'],
            'result.pkcs7_sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'result.content_sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
    }
}
