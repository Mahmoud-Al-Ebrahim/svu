<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferingResource;
use App\Models\CourseOffering;
use App\Models\ExamCenter;
use App\Models\Program;
use App\Models\Term;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Picker data (feature 1: open a specific course page). */
class CatalogController extends Controller
{
    public function terms(): JsonResponse
    {
        return response()->json(Term::orderByDesc('starts_on')->get()->map(fn (Term $t) => [
            'code' => $t->code,
            'name' => ['ar' => $t->name_ar, 'en' => $t->name_en],
            'current' => $t->is_current,
        ]));
    }

    public function programs(): JsonResponse
    {
        return response()->json(Program::orderBy('code')->get()->map(fn (Program $p) => [
            'code' => $p->code,
            'name' => ['ar' => $p->name_ar, 'en' => $p->name_en],
        ]));
    }

    public function examCenters(): JsonResponse
    {
        return response()->json(ExamCenter::orderByDesc('is_online')->orderBy('id')->get()->map(fn (ExamCenter $c) => [
            'id' => (string) $c->id,
            'code' => $c->code,
            'name' => ['ar' => $c->name_ar, 'en' => $c->name_en],
            'online' => $c->is_online,
        ]));
    }

    /** Offerings that have at least one class taught by the current user, with only those classes. */
    public function myOfferings(Request $request)
    {
        $request->validate([
            'term' => ['nullable', 'exists:terms,code'],
            'program' => ['nullable', 'exists:programs,code'],
        ]);
        $userId = $request->user()->id;
        $mine = fn ($q) => $q->where('tutor_id', $userId);

        $offerings = CourseOffering::query()
            ->whereHas('classes', $mine)
            ->when($request->query('term'), fn ($q, $t) => $q->where('term_code', $t))
            ->when($request->query('program'), fn ($q, $p) => $q->whereHas('course', fn ($c) => $c->where('program_code', $p)))
            ->with(['course', 'classes' => fn ($q) => $mine($q)->withCount('students')])
            ->get()
            ->sortBy(fn ($o) => [$o->term_code, $o->course->program_code, $o->course->code])
            ->values();

        return OfferingResource::collection($offerings);
    }

    public function offering(Request $request, CourseOffering $offering): OfferingResource
    {
        $userId = $request->user()->id;
        $offering->load(['course', 'classes' => fn ($q) => $q->where('tutor_id', $userId)->withCount('students')]);
        abort_if($offering->classes->isEmpty(), 403, __('portal.not_your_class'));

        return new OfferingResource($offering);
    }
}
