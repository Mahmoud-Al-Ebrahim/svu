<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'courseId' => (string) $this->courseClass->offering_id,
            'classId' => (string) $this->class_id,
            'classCode' => $this->courseClass->code,
            'type' => $this->type->value,
            'date' => $this->date->format('Y-m-d'),
            'start' => $this->start_time,
            'end' => $this->endTime(),
            'durationMin' => $this->duration_min,
            'center' => (string) $this->center_id,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'reviewNote' => $this->review_note,
            // Course and tutor details, included when the caller loaded them (review screens).
            $this->mergeWhen($this->courseClass->relationLoaded('offering') && $this->courseClass->relationLoaded('tutor'), fn () => [
                'context' => [
                    'courseCode' => $this->courseClass->offering->course->code,
                    'courseName' => ['ar' => $this->courseClass->offering->course->name_ar, 'en' => $this->courseClass->offering->course->name_en],
                    'program' => $this->courseClass->offering->course->program_code,
                    'term' => $this->courseClass->offering->term_code,
                    'tutor' => $this->courseClass->tutor
                        ? ['ar' => $this->courseClass->tutor->name_ar, 'en' => $this->courseClass->tutor->name_en]
                        : null,
                ],
            ]),
        ];
    }
}
