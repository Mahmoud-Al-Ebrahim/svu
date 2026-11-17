<?php

namespace App\Http\Resources;

use App\Models\AccountRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AccountRequest */
class AccountRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'ref' => $this->ref,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'employeeId' => $this->employee_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'program' => $this->program_code,
            'rank' => $this->rank,
            'specialty' => $this->specialty,
            'courses' => $this->courses_text,
            'status' => $this->status,
            'reviewNote' => $this->review_note,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
