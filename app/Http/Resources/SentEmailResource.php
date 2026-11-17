<?php

namespace App\Http\Resources;

use App\Models\SentEmail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SentEmail */
class SentEmailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'to' => $this->recipient_type === 'class'
                ? [
                    'kind' => 'class',
                    'courseId' => (string) $this->courseClass?->offering_id,
                    'classId' => (string) $this->class_id,
                    'classCode' => $this->courseClass?->code,
                ]
                : ['kind' => 'student', 'address' => $this->student_address],
            'recipients' => $this->recipients_count,
            'failed' => $this->failed_count,
            'subject' => $this->subject,
            'body' => $this->body,
            'attachments' => $this->attachments->pluck('original_name'),
            'status' => $this->status,
            'at' => $this->created_at->toIso8601String(),
        ];
    }
}
