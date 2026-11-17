<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CourseClass;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Admin: which tutor teaches each class (SVUIS "Course & Tutors"). */
class ClassAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'term' => ['nullable', 'exists:terms,code'],
            'program' => ['nullable', 'exists:programs,code'],
        ]);
        $classes = CourseClass::query()
            ->whereHas('offering', fn ($q) => $q
                ->when($request->query('term'), fn ($q, $t) => $q->where('term_code', $t))
                ->when($request->query('program'), fn ($q, $p) => $q->whereHas('course', fn ($c) => $c->where('program_code', $p))))
            ->with(['offering.course', 'tutor'])
            ->withCount('students')
            ->get()
            ->sortBy(fn (CourseClass $c) => [$c->offering->term_code, $c->offering->course->program_code, $c->offering->course->code, $c->code])
            ->values();

        return response()->json($classes->map(fn (CourseClass $c) => $this->present($c)));
    }

    public function tutors(): JsonResponse
    {
        return response()->json(User::where('role', Role::Tutor)->where('status', 'active')->orderBy('name_en')->get()
            ->map(fn (User $u) => ['id' => (string) $u->id, 'username' => $u->username, 'name' => ['ar' => $u->name_ar, 'en' => $u->name_en]]));
    }

    public function assign(Request $request, CourseClass $class): JsonResponse
    {
        $data = $request->validate([
            'tutorId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', Role::Tutor->value)],
        ]);
        $class->update(['tutor_id' => $data['tutorId'] ?? null]);
        Audit::log('class.assigned', $class, ['tutor' => $class->tutor_id]);

        return response()->json($this->present($class->load(['offering.course', 'tutor'])->loadCount('students')));
    }

    private function present(CourseClass $c): array
    {
        $course = $c->offering->course;

        return [
            'id' => (string) $c->id,
            'code' => $c->code,
            'courseCode' => $course->code,
            'courseName' => ['ar' => $course->name_ar, 'en' => $course->name_en],
            'program' => $course->program_code,
            'term' => $c->offering->term_code,
            'students' => (int) $c->students_count,
            'tutor' => $c->tutor ? ['id' => (string) $c->tutor->id, 'name' => ['ar' => $c->tutor->name_ar, 'en' => $c->tutor->name_en]] : null,
        ];
    }
}
