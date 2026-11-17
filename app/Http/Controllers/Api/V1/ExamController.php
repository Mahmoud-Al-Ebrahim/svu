<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\Exam;
use App\Support\Audit;
use App\Support\Lifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Feature 5: schedule an exam. Dates and times are Damascus time; each class gets its own exam row. */
class ExamController extends Controller
{
    public function index(Request $request)
    {
        Lifecycle::closeEndedSoon(); // statuses are current even if the scheduler wasn't running
        return ExamResource::collection($this->paginate($this->filtered($request), $request));
    }

    /** Validate and report clashes without saving, so the dialog can warn before sending. */
    public function check(Request $request): JsonResponse
    {
        [$classes, $data] = $this->validated($request);
        $this->assertNoOverlap($classes, $data);

        return response()->json(['warnings' => $this->warnings($classes, $data)]);
    }

    public function store(Request $request): JsonResponse
    {
        [$classes, $data] = $this->validated($request);
        $warnings = $this->warnings($classes, $data);

        $exams = DB::transaction(function () use ($classes, $data, $request) {
            $this->assertNoOverlap($classes, $data);

            return $classes->map(fn (CourseClass $class) => Exam::create([
                'class_id' => $class->id,
                'type' => $data['type'],
                'date' => $data['date'],
                'start_time' => $data['start'],
                'duration_min' => $data['durationMin'],
                'center_id' => $data['centerId'],
                'notes' => $data['notes'] ?? null,
                'status' => ExamStatus::Pending,
                'requested_by' => $request->user()->id,
            ]));
        });
        $exams->each(fn ($e) => Audit::log('exam.requested', $e));

        return response()->json([
            'exams' => ExamResource::collection($exams->each->load('courseClass')),
            'warnings' => $warnings,
        ], 201);
    }

    public function destroy(Request $request, Exam $exam): JsonResponse
    {
        abort_unless($request->user()->teaches($exam->courseClass), 403, __('portal.not_your_class'));
        abort_unless($exam->status === ExamStatus::Pending, 409, __('portal.exams.not_cancellable'));
        $exam->delete();
        Audit::log('exam.cancelled', $exam);

        return response()->json(null, 204);
    }

    /** CSV (UTF-8 with BOM so Excel shows Arabic correctly), same filters as the list. */
    public function export(Request $request): StreamedResponse
    {
        $exams = $this->filtered($request)->with(['courseClass.offering.course', 'center'])->get();
        $ar = app()->getLocale() === 'ar';

        return response()->streamDownload(function () use ($exams, $ar) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Program', 'Course', 'Class', 'Term', 'Type', 'Date', 'Start', 'End', 'Duration', 'Center', 'Status']);
            foreach ($exams as $e) {
                $course = $e->courseClass->offering->course;
                fputcsv($out, [
                    $course->program_code, $course->code.' — '.($ar ? $course->name_ar : $course->name_en),
                    $e->courseClass->code, $e->courseClass->offering->term_code, $e->type->value,
                    $e->date->format('Y-m-d'), $e->start_time, $e->endTime(), $e->duration_min,
                    $ar ? $e->center->name_ar : $e->center->name_en, $e->status->value,
                ]);
            }
            fclose($out);
        }, 'exam-timetable.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request)
    {
        $request->validate([
            'term' => ['nullable', 'exists:terms,code'],
            'program' => ['nullable', 'exists:programs,code'],
            'offering' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return Exam::query()
            ->whereHas('courseClass', fn ($q) => $q
                ->where('tutor_id', $request->user()->id)
                ->when($request->query('offering'), fn ($q, $o) => $q->where('offering_id', $o))
                ->when($request->query('term'), fn ($q, $t) => $q->whereHas('offering', fn ($o) => $o->where('term_code', $t)))
                ->when($request->query('program'), fn ($q, $p) => $q->whereHas('offering.course', fn ($c) => $c->where('program_code', $p))))
            ->when($request->query('class'), fn ($q, $c) => $q->where('class_id', $c))
            ->when($request->query('from'), fn ($q, $d) => $q->where('date', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('date', '<=', $d))
            ->with('courseClass')
            ->orderBy('date')
            ->orderBy('start_time');
    }

    /** @return array{0: Collection<int, CourseClass>, 1: array} */
    private function validated(Request $request): array
    {
        $tz = config('portal.timezone');
        $cfg = config('portal.exams');
        $data = $request->validate([
            'offeringId' => ['required', 'integer', 'exists:course_offerings,id'],
            'classIds' => ['required', 'array', 'min:1'],
            'classIds.*' => ['integer', 'distinct'],
            'type' => ['required', Rule::enum(ExamType::class)],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now($tz)->addDay()->toDateString()],
            'start' => ['required', 'date_format:H:i', 'after_or_equal:'.$cfg['earliest_start'], 'before_or_equal:'.$cfg['latest_start']],
            'durationMin' => ['required', 'integer', "between:{$cfg['min_duration']},{$cfg['max_duration']}"],
            'centerId' => ['required', 'integer', 'exists:exam_centers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $classes = CourseClass::where('offering_id', $data['offeringId'])
            ->where('tutor_id', $request->user()->id)
            ->whereIn('id', $data['classIds'])
            ->get();
        if ($classes->count() !== count($data['classIds'])) {
            throw ValidationException::withMessages(['classIds' => __('portal.not_your_class')]);
        }

        return [$classes, $data];
    }

    /**
     * Overlapping exams for the same class are refused outright. When called inside a transaction, locking the
     * class rows first makes two simultaneous requests for the same class check one after the other.
     */
    private function assertNoOverlap(Collection $classes, array $data): void
    {
        CourseClass::whereKey($classes->modelKeys())->lockForUpdate()->get();
        $tz = config('portal.timezone');
        $start = Carbon::parse("{$data['date']} {$data['start']}", $tz);
        $end = $start->copy()->addMinutes($data['durationMin']);
        $overlap = Exam::whereIn('class_id', $classes->modelKeys())
            ->where('date', $data['date'])
            ->where('status', '!=', ExamStatus::Rejected)
            ->get()
            ->first(fn (Exam $e) => $e->startsAt()->lt($end) && $e->endsAt()->gt($start));
        if ($overlap) {
            throw ValidationException::withMessages(['start' => __('portal.exams.overlap', ['class' => $overlap->courseClass->code, 'time' => $overlap->start_time])]);
        }
    }

    /** Same-day exams or sessions for these classes: allowed, but worth a second look. */
    private function warnings(Collection $classes, array $data): array
    {
        $tz = config('portal.timezone');
        $dayStart = Carbon::parse($data['date'], $tz)->startOfDay()->utc();
        $ids = $classes->modelKeys();

        $exams = Exam::whereIn('class_id', $ids)->where('date', $data['date'])->where('status', '!=', ExamStatus::Rejected)
            ->with('courseClass')->get()
            ->map(fn ($e) => __('portal.exams.same_day_exam', ['class' => $e->courseClass->code, 'time' => $e->start_time]));
        $sessions = ClassSession::whereIn('class_id', $ids)
            ->whereBetween('starts_at', [$dayStart, $dayStart->copy()->addDay()])
            ->with('courseClass')->get()
            ->map(fn ($s) => __('portal.exams.same_day_session', ['class' => $s->courseClass->code, 'title' => $s->title]));

        return $exams->concat($sessions)->values()->all();
    }
}
