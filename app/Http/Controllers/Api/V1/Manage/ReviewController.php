<?php

namespace App\Http\Controllers\Api\V1\Manage;

use App\Enums\ExamStatus;
use App\Enums\Role;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;
use App\Http\Resources\SessionResource;
use App\Mail\PortalNoticeMail;
use App\Models\ClassSession;
use App\Models\Exam;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use App\Support\Lifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Program-manager side of the session finance report and exam approvals. Kept as a small API so the
 * status flow can be completed end to end; a full admin panel can be added on top later.
 */
class ReviewController extends Controller
{
    private const CONTEXT = ['courseClass.offering.course', 'courseClass.tutor'];

    public function sessions(Request $request)
    {
        Lifecycle::closeEndedSoon(); // statuses are current even if the scheduler wasn't running
        $request->validate(['status' => ['nullable', Rule::enum(SessionStatus::class)]]);
        $sessions = ClassSession::query()
            ->whereHas('courseClass', fn ($q) => $this->managedClasses($q, $request->user()))
            ->where('status', $request->query('status', SessionStatus::PendingApproval->value))
            ->with(self::CONTEXT)->withCount('files')
            ->orderBy('starts_at');

        return SessionResource::collection($this->paginate($sessions, $request));
    }

    public function decideSession(Request $request, ClassSession $session, string $decision): SessionResource
    {
        Gate::authorize('review', $session->courseClass);
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']]);
        $next = match ($decision) {
            'approve' => SessionStatus::ApprovedUnpaid,
            'reject' => SessionStatus::Rejected,
            'pay' => SessionStatus::Paid,
        };
        // The row lock makes a double click (or two managers at once) wait, then fail the status check.
        $session = DB::transaction(function () use ($session, $next, $request, $data) {
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status->canMoveTo($next), 409, __('portal.sessions.bad_transition'));
            $session->update([
                'status' => $next,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
                'paid_at' => $next === SessionStatus::Paid ? now() : $session->paid_at,
            ]);

            return $session;
        });
        Audit::log("session.{$decision}", $session, ['note' => $data['note'] ?? null]);
        // The decision goes to whoever made the request, even if the class has been given to another tutor since.
        $this->tellTutor($session->creator ?? $session->courseClass->tutor, 'notifyFinance', $session->title, 'portal.status.session.'.$next->value, $data['note'] ?? null);

        return new SessionResource($session->load(self::CONTEXT)->loadCount('files'));
    }

    public function exams(Request $request)
    {
        Lifecycle::closeEndedSoon(); // statuses are current even if the scheduler wasn't running
        $request->validate(['status' => ['nullable', Rule::enum(ExamStatus::class)]]);
        $exams = Exam::query()
            ->whereHas('courseClass', fn ($q) => $this->managedClasses($q, $request->user()))
            ->where('status', $request->query('status', ExamStatus::Pending->value))
            ->with(self::CONTEXT)
            ->orderBy('date')->orderBy('start_time');

        return ExamResource::collection($this->paginate($exams, $request));
    }

    public function decideExam(Request $request, Exam $exam, string $decision): ExamResource
    {
        Gate::authorize('review', $exam->courseClass);
        $data = $request->validate(['note' => [$decision === 'reject' ? 'required' : 'nullable', 'string', 'max:255']]);
        $next = $decision === 'approve' ? ExamStatus::Approved : ExamStatus::Rejected;
        $exam = DB::transaction(function () use ($exam, $next, $request, $data) {
            $exam = Exam::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            abort_unless($exam->status === ExamStatus::Pending, 409, __('portal.exams.already_decided'));
            $exam->update([
                'status' => $next,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
            ]);

            return $exam;
        });
        Audit::log("exam.{$decision}", $exam, ['note' => $data['note'] ?? null]);
        $this->tellTutor($exam->requester ?? $exam->courseClass->tutor, 'notifyExams', $exam->type->value.' '.$exam->date->format('Y-m-d'), 'portal.status.exam.'.$next->value, $data['note'] ?? null);

        return new ExamResource($exam->load(self::CONTEXT));
    }

    private function managedClasses(Builder $q, User $user): Builder
    {
        return $user->role === Role::Admin
            ? $q
            : $q->whereHas('offering.course.program', fn ($p) => $p->where('manager_id', $user->id));
    }

    /** E-mails the tutor in their own interface language, not the reviewer's. */
    private function tellTutor(?User $tutor, string $preference, string $what, string $statusKey, ?string $note): void
    {
        if (! $tutor || ! $tutor->preference($preference)) {
            return;
        }
        $status = __($statusKey, [], $tutor->preference('locale'));
        Mail::to($tutor->email)->queue(new PortalNoticeMail("{$what}: {$status}", array_filter([$what, $status, $note])));
    }
}
