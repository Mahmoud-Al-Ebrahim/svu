<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'username' => $this->username,
            // SVUIS user group shown in the welcome box: TU = tutor.
            'group' => match ($this->role) {
                Role::Tutor => 'TU',
                Role::ProgramManager => 'PM',
                Role::Admin => 'AD',
            },
            'role' => $this->role->value,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'rank' => ['ar' => $this->rank_ar, 'en' => $this->rank_en],
            'email' => $this->email,
            'phone' => $this->phone,
            'employeeId' => $this->employee_id,
            // The login before this one, like the SVUIS welcome box.
            'lastLogin' => $this->previous_login_at?->toIso8601String(),
            'lastIp' => $this->previous_login_ip,
            'preferences' => array_merge(User::DEFAULT_PREFERENCES, $this->preferences ?? []),
        ];
    }
}
