<?php

namespace App\Http\Requests;

use App\Services\MembershipService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // yetki route middleware'inde (permission:membership.manage,company)
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'role' => ['required', Rule::in(MembershipService::ASSIGNABLE_ROLES)],
        ];
    }
}
