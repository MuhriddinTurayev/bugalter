<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/** Pairing a computer lets it drive the company's tax cabinet: the owner's decision (autopilot.manage). */
class PairDesktopAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::AutopilotManage->value);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => __('tax.agent.computer_name')];
    }
}
