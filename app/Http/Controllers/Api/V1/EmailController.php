<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SentEmailResource;
use App\Jobs\SendClassEmail;
use App\Models\CourseClass;
use App\Models\EmailAttachment;
use App\Models\SentEmail;
use App\Support\Audit;
use App\Support\Uploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Feature 6: send e-mail to a whole class or one student. Sending happens on the queue. */
class EmailController extends Controller
{
    public function index(Request $request)
    {
        $emails = SentEmail::where('sender_id', $request->user()->id)
            ->with(['courseClass', 'attachments'])
            ->latest();

        return SentEmailResource::collection($this->paginate($emails, $request));
    }

    public function recipients(Request $request, CourseClass $class): JsonResponse
    {
        Gate::authorize('teach', $class);

        return response()->json(['count' => $class->students()->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $domain = config('portal.email.domain');
        $data = $request->validate([
            'recipientType' => ['required', Rule::in(['class', 'student'])],
            'classId' => ['required_if:recipientType,class', 'nullable', 'integer', 'exists:course_classes,id'],
            'studentAddress' => ['required_if:recipientType,student', 'nullable', 'email', 'ends_with:@'.$domain],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'ccSender' => ['boolean'],
            'attachmentUploadIds' => ['array', 'max:10'],
            'attachmentUploadIds.*' => ['uuid', 'distinct'],
        ]);

        $class = null;
        if ($data['recipientType'] === 'class') {
            $class = CourseClass::findOrFail($data['classId']);
            Gate::authorize('teach', $class);
        } elseif (! $this->teachesStudent($request->user()->id, $data['studentAddress'])) {
            // One-student mode is for the tutor's own students, not any university mailbox.
            throw ValidationException::withMessages(['studentAddress' => __('portal.email.not_your_student')]);
        }
        $recipients = $class ? $class->students()->count() : 1;
        if ($recipients === 0) {
            throw ValidationException::withMessages(['classId' => __('portal.email.no_students')]);
        }

        // Check every attachment and the total size before storing anything.
        $maxBytes = config('portal.email.max_attachments_bytes');
        $uploads = collect($data['attachmentUploadIds'] ?? [])->map(fn (string $id, int $i) => Uploads::check(
            $id, $request->user(), config('portal.uploads.file_extensions'), $maxBytes, "attachmentUploadIds.{$i}",
        ));
        if ($uploads->sum('size_bytes') > $maxBytes) {
            throw ValidationException::withMessages(['attachmentUploadIds' => __('portal.email.attachments_too_big')]);
        }

        $email = DB::transaction(function () use ($data, $class, $recipients, $request, $uploads) {
            $email = SentEmail::create([
                'sender_id' => $request->user()->id,
                'recipient_type' => $data['recipientType'],
                'class_id' => $class?->id,
                'student_address' => $class ? null : strtolower($data['studentAddress']),
                'subject' => $data['subject'],
                'body' => $data['body'],
                'cc_sender' => $data['ccSender'] ?? true,
                'recipients_count' => $recipients,
                'status' => 'queued',
            ]);

            foreach ($uploads as $i => $upload) {
                $stored = Uploads::claim($upload, "email-attachments/{$email->id}", "attachmentUploadIds.{$i}");
                EmailAttachment::create([
                    'email_id' => $email->id,
                    'original_name' => $stored['name'],
                    'storage_path' => $stored['path'],
                    'size_bytes' => $stored['size'],
                ]);
            }

            return $email;
        });

        Audit::log('email.queued', $email, ['recipients' => $recipients]);
        SendClassEmail::dispatch($email->id)->afterCommit();

        return response()->json(new SentEmailResource($email->fresh(['courseClass', 'attachments'])), 202);
    }

    /** Whether the address belongs to a student enrolled in one of this tutor's classes. */
    private function teachesStudent(int $tutorId, string $address): bool
    {
        return DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('course_classes', 'course_classes.id', '=', 'enrollments.class_id')
            ->where('course_classes.tutor_id', $tutorId)
            ->whereRaw('LOWER(students.email) = ?', [strtolower($address)])
            ->exists();
    }
}
