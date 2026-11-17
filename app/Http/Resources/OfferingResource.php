<?php

namespace App\Http\Resources;

use App\Models\CourseOffering;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The frontend's `Course`: a course in one term, with the classes the current tutor teaches.
 * Expects `classes` to be loaded (already filtered) with `students_count`.
 *
 * @mixin CourseOffering
 */
class OfferingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->course->code,
            'name' => ['ar' => $this->course->name_ar, 'en' => $this->course->name_en],
            'program' => $this->course->program_code,
            'term' => $this->term_code,
            'hue' => $this->hue,
            'classes' => $this->classes->map(fn ($c) => [
                'id' => (string) $c->id,
                'code' => $c->code,
                'students' => (int) ($c->students_count ?? 0),
            ])->values(),
        ];
    }
}
