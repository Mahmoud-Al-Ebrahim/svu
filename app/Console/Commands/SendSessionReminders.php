<?php

namespace App\Console\Commands;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Mail\PortalNoticeMail;
use App\Models\ClassSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** E-mails tutors about live sessions starting within the reminder window (once per session). */
class SendSessionReminders extends Command
{
    protected $signature = 'portal:session-reminders';

    protected $description = 'Remind tutors of live sessions starting soon';

    public function handle(): int
    {
        $sessions = ClassSession::where('status', SessionStatus::Upcoming)
            ->where('kind', SessionKind::Live)
            ->whereNull('reminded_at')
            ->whereBetween('starts_at', [now(), now()->addMinutes(config('portal.sessions.reminder_minutes'))])
            ->with('courseClass.tutor', 'courseClass.offering.course')
            ->get();

        foreach ($sessions as $session) {
            $tutor = $session->courseClass->tutor;
            if ($tutor && $tutor->preference('notifySessions')) {
                $when = $session->starts_at->copy()->tz(config('portal.timezone'))->format('H:i');
                $label = "{$session->courseClass->offering->course->code} {$session->courseClass->code}";
                Mail::to($tutor->email)->queue(new PortalNoticeMail(
                    "{$label}: {$session->title} — {$when}",
                    ["تبدأ جلستك {$session->title} الساعة {$when}.", "Your session {$session->title} starts at {$when}."],
                ));
            }
            $session->update(['reminded_at' => now()]);
        }
        $this->info("Sent {$sessions->count()} reminders.");

        return self::SUCCESS;
    }
}
