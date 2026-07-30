<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\Core\BaseFormRequest;

class PendingDeviceLoginResponseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'pending_id' => ['required', 'integer', 'exists:pending_device_logins,id'],
            'action' => ['required', 'string', 'in:approve,revoke'],
        ];
    }
}
