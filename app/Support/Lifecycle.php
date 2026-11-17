<?php

namespace App\Support;

use App\Enums\ExamStatus;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\Exam;
use Illuminate\Support\Facades\Cache;

/**
 * Moves sessions and exams whose end time has passed to their "held" status.
 *
 * The scheduler runs this every five minutes, but the portal may have been off (or the scheduler not started),
 * so the lists also call closeEndedSoon() before loading: statuses are then right whenever someone looks.
 */
class Lifecycle
{
    /** @return array{sessions: int, exams: int} */
    public static function closeEnded(): array
    {
        $sessions = ClassSession::where('status', SessionStatus::Upcoming)
            ->where('starts_at', '<', now())
            ->get()
            ->filter(fn (ClassSession $s) => $s->endsAt()->isPast());
        $sessions->each->update(['status' => SessionStatus::NotReported]);

        $exams = Exam::where('status', ExamStatus::Approved)
            ->where('date', '<=', now(config('portal.timezone'))->toDateString())
            ->get()
            ->filter(fn (Exam $e) => $e->endsAt()->isPast());
        $exams->each->update(['status' => ExamStatus::Done]);

        return ['sessions' => $sessions->count(), 'exams' => $exams->count()];
    }

    /** Same as closeEnded(), at most once a minute, so busy list pages don't repeat the work. */
    public static function closeEndedSoon(): void
    {
        if (Cache::add('portal:close-ended', true, 60)) {
            self::closeEnded();
        }
    }
}
