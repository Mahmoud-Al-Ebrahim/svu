<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FileKind;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Mail\PortalNoticeMail;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\CourseFile;
use App\Models\Upload;
use App\Support\Audit;
use App\Support\Uploads;
use App\Support\Lifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Features 2 and 3: schedule a session, upload a session. */
class SessionController extends Controller
{
    public function index(Request $request)
    {
        Lifecycle::closeEndedSoon(); // statuses are current even if the scheduler wasn't running
        $request->validate([
            'term' => ['nullable', 'exists:terms,code'],
            'offering' => ['nullable', 'integer'],
            'class' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(SessionStatus::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $sessions = ClassSession::query()
            ->whereHas('courseClass', fn ($q) => $q
                ->where('tutor_id', $request->user()->id)
                ->when($request->query('offering'), fn ($q, $o) => $q->where('offering_id', $o))
                ->when($request->query('term'), fn ($q, $t) => $q->whereHas('offering', fn ($o) => $o->where('term_code', $t))))
            ->when($request->query('class'), fn ($q, $c) => $q->where('class_id', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('from'), fn ($q, $d) => $q->where('starts_at', '>=', Carbon::parse($d)))
            ->when($request->query('to'), fn ($q, $d) => $q->where('starts_at', '<=', Carbon::parse($d)))
            ->with('courseClass')
            ->withCount('files')
            ->orderBy('starts_at');

        return SessionResource::collection($this->paginate($sessions, $request));
    }

    /** Schedule a live (synchronous) session. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classId' => ['required', 'integer', 'exists:course_classes,id'],
            'title' => ['required', 'string', 'max:255'],
            'startsAt' => ['required', 'date', 'after:now'],
            'durationMin' => ['required', 'integer', 'between:'.config('portal.sessions.min_duration').','.config('portal.sessions.max_duration')],
            'notifyStudents' => ['boolean'],
            'recordAuto' => ['boolean'],
        ]);
        $class = CourseClass::findOrFail($data['classId']);
        Gate::authorize('teach', $class);

        $start = Carbon::parse($data['startsAt'])->utc();

        $session = DB::transaction(function () use ($class, $start, $data, $request) {
            $this->assertNoClash($class, $start, $data['durationMin']);

            return ClassSession::create([
                'class_id' => $class->id,
                'title' => $data['title'],
                'kind' => SessionKind::Live,
                'starts_at' => $start,
                'duration_min' => $data['durationMin'],
                'status' => SessionStatus::Upcoming,
                'notify_students' => $data['notifyStudents'] ?? true,
                'record_auto' => $data['recordAuto'] ?? true,
                'created_by' => $request->user()->id,
            ]);
        });
        Audit::log('session.scheduled', $session);
        if ($session->notify_students) {
            $this->notifyClass($session);
        }

        return $this->created($session, $this->tutorClashWarnings($request, $session));
    }

    public function update(Request $request, ClassSession $session): SessionResource
    {
        Gate::authorize('teach', $session->courseClass);
        abort_unless($session->status === SessionStatus::Upcoming, 409, __('portal.sessions.not_editable'));

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'startsAt' => ['sometimes', 'date', 'after:now'],
            'durationMin' => ['sometimes', 'integer', 'between:'.config('portal.sessions.min_duration').','.config('portal.sessions.max_duration')],
        ]);
        $start = isset($data['startsAt']) ? Carbon::parse($data['startsAt'])->utc() : $session->starts_at;
        $minutes = $data['durationMin'] ?? $session->duration_min;

        DB::transaction(function () use ($session, $start, $minutes, $data) {
            $this->assertNoClash($session->courseClass, $start, $minutes, $session->id);
            $session->update(array_filter([
                'title' => $data['title'] ?? null,
                'starts_at' => $start,
                'duration_min' => $minutes,
            ]));
        });
        Audit::log('session.updated', $session, $data);

        return new SessionResource($session->loadCount('files'));
    }

    public function destroy(Request $request, ClassSession $session): JsonResponse
    {
        Gate::authorize('teach', $session->courseClass);
        abort_unless($session->status === SessionStatus::Upcoming, 409, __('portal.sessions.not_editable'));
        DB::transaction(function () use ($session) {
            // A cancelled recorded session takes its video (and any other files of that session) with it.
            $session->files()->get()->each->delete();
            $session->delete();
        });
        Audit::log('session.cancelled', $session);

        return response()->json(null, 204);
    }

    /** "Add to finance report": notReported (or rejected, after a fix) → pendingApproval. */
    public function report(Request $request, ClassSession $session): SessionResource
    {
        Gate::authorize('teach', $session->courseClass);
        abort_unless($session->status->canMoveTo(SessionStatus::PendingApproval), 409, __('portal.sessions.bad_transition'));

        $session->update(['status' => SessionStatus::PendingApproval, 'reported_at' => now(), 'review_note' => null]);
        Audit::log('session.reported', $session);

        return new SessionResource($session->loadCount('files'));
    }

    public function join(Request $request, ClassSession $session): JsonResponse
    {
        Gate::authorize('teach', $session->courseClass);
        abort_unless($session->kind === SessionKind::Live && $session->status === SessionStatus::Upcoming, 409, __('portal.sessions.not_joinable'));

        // Placeholder until the MeetingProvider adapter for SVU's session platform exists.
        $url = $session->meeting_url ?? config('app.url')."/rooms/{$session->id}";

        return response()->json(['url' => $url]);
    }

    /** Feature 3a: upload a new asynchronous (recorded) session together with its video. */
    public function storeRecorded(Request $request): JsonResponse
    {
        $data = $request->validate([
            'classId' => ['required', 'integer', 'exists:course_classes,id'],
            'title' => ['required', 'string', 'max:255'],
            'publishAt' => ['required', 'date'],
            'durationMin' => ['required', 'integer', 'between:5,'.config('portal.sessions.max_duration')],
            'uploadId' => ['required', 'uuid'],
        ]);
        $class = CourseClass::findOrFail($data['classId']);
        Gate::authorize('teach', $class);
        $publishAt = Carbon::parse($data['publishAt'])->utc();
        $upload = $this->checkRecording($data['uploadId'], $request);

        $session = DB::transaction(function () use ($data, $class, $publishAt, $request, $upload) {
            $stored = Uploads::claim($upload, "files/{$class->offering_id}");
            $session = ClassSession::create([
                'class_id' => $class->id,
                'title' => $data['title'],
                'kind' => SessionKind::Recorded,
                'starts_at' => $publishAt,
                'duration_min' => $data['durationMin'],
                // Already published → it counts as held and waits to be added to the finance report.
                'status' => $publishAt->isPast() ? SessionStatus::NotReported : SessionStatus::Upcoming,
                'notify_students' => false,
                'record_auto' => false,
                'created_by' => $request->user()->id,
            ]);
            $this->createRecordingFile($session, $class, $stored, $request);

            return $session;
        });
        Audit::log('session.uploaded', $session);

        return $this->created($session);
    }

    /** Feature 3b: attach the recording of a live session that has already been held. */
    public function attachRecording(Request $request, ClassSession $session): JsonResponse
    {
        Gate::authorize('teach', $session->courseClass);
        $data = $request->validate(['uploadId' => ['required', 'uuid']]);
        $this->assertCanAttachRecording($session);
        $upload = $this->checkRecording($data['uploadId'], $request);

        DB::transaction(function () use ($session, $request, $upload) {
            // Re-check under a lock so two uploads at once can't both attach a recording.
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->assertCanAttachRecording($session);
            $stored = Uploads::claim($upload, "files/{$session->courseClass->offering_id}");
            $this->createRecordingFile($session, $session->courseClass, $stored, $request);
        });
        Audit::log('session.recording_attached', $session);

        return $this->created($session);
    }

    /** Only a held live session without a recording, and only until the manager has approved it for payment. */
    private function assertCanAttachRecording(ClassSession $session): void
    {
        abort_unless($session->kind === SessionKind::Live && $session->status !== SessionStatus::Upcoming, 409, __('portal.sessions.not_held'));
        abort_if(
            in_array($session->status, [SessionStatus::ApprovedUnpaid, SessionStatus::Paid], true),
            409, __('portal.sessions.recording_closed'),
        );
        abort_if($session->files()->where('kind', FileKind::Video)->exists(), 409, __('portal.sessions.has_recording'));
    }

    private function checkRecording(string $uploadId, Request $request): Upload
    {
        return Uploads::check(
            $uploadId, $request->user(),
            config('portal.uploads.recording_extensions'), config('portal.uploads.max_recording_bytes'),
        );
    }

    private function createRecordingFile(ClassSession $session, CourseClass $class, array $stored, Request $request): void
    {
        CourseFile::create([
            'offering_id' => $class->offering_id,
            'class_id' => $class->id,
            'session_id' => $session->id,
            'title' => $session->title,
            'original_name' => $stored['name'],
            'storage_path' => $stored['path'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'kind' => FileKind::Video,
            'visible' => true,
            'uploaded_by' => $request->user()->id,
        ]);
    }

    /**
     * Two sessions of the same class may not overlap. Call inside a transaction: locking the class row makes
     * two simultaneous requests for the same class check one after the other, so both can't slip through.
     */
    private function assertNoClash(CourseClass $class, Carbon $start, int $minutes, ?int $exceptId = null): void
    {
        CourseClass::whereKey($class->id)->lockForUpdate()->first();
        $clash = ClassSession::overlapping(ClassSession::where('class_id', $class->id), $start, $minutes, $exceptId);
        if ($clash->isNotEmpty()) {
            throw ValidationException::withMessages(['startsAt' => __('portal.sessions.clash', ['title' => $clash->first()->title])]);
        }
    }

    /** Overlaps with the tutor's own sessions in other classes are allowed, but reported back as warnings. */
    private function tutorClashWarnings(Request $request, ClassSession $session): array
    {
        $scope = ClassSession::whereHas('courseClass', fn ($q) => $q->where('tutor_id', $request->user()->id))
            ->where('class_id', '!=', $session->class_id);

        return ClassSession::overlapping($scope, $session->starts_at, $session->duration_min)
            ->map(fn ($s) => __('portal.sessions.tutor_clash', ['title' => $s->title]))
            ->all();
    }

    private function notifyClass(ClassSession $session): void
    {
        $class = $session->courseClass()->with('offering.course')->first();
        $when = $session->starts_at->copy()->tz(config('portal.timezone'))->format('Y-m-d H:i');
        $mail = new PortalNoticeMail(
            "{$class->offering->course->code} {$class->code}: {$session->title}",
            ["جلسة متزامنة جديدة: {$session->title} — {$when} (توقيت دمشق)", "New live session: {$session->title} — {$when} (Damascus time)"],
        );
        foreach ($class->students()->pluck('email')->chunk(config('portal.email.batch_size')) as $batch) {
            Mail::bcc($batch->all())->queue(clone $mail);
        }
    }

    private function created(ClassSession $session, array $warnings = []): JsonResponse
    {
        $session->load('courseClass')->loadCount('files');

        return response()->json(['session' => new SessionResource($session), 'warnings' => $warnings], 201);
    }
}
