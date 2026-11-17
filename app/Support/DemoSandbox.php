<?php

namespace App\Support;

use App\Enums\ExamStatus;
use App\Enums\FileKind;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\CourseFile;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamCenter;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives a newly signed-up tutor their own classes to try every feature on: one new class (C<next number>) in each
 * demo course of the current term, with enrolled students, sessions in every status, two exam requests and a file.
 * Everything is attached to classes only this tutor teaches, so no other tutor sees it.
 */
class DemoSandbox
{
    private const FIRST = [['ليلى', 'layla'], ['محمد', 'mohammad'], ['رهف', 'rahaf'], ['عمر', 'omar'], ['نور', 'nour'], ['سارة', 'sara'], ['أحمد', 'ahmad'], ['هبة', 'hiba'], ['يزن', 'yazan'], ['مايا', 'maya']];

    private const LAST = [['حداد', 'Haddad'], ['العلي', 'Al-Ali'], ['السيد', 'Al-Sayed'], ['قباني', 'Kabbani'], ['شامي', 'Shami'], ['يوسف', 'Youssef'], ['درويش', 'Darwish'], ['خوري', 'Khoury']];

    /** Call inside a transaction (the class numbers are taken under a lock). Returns the new classes. */
    public static function provision(User $tutor): Collection
    {
        $term = Term::where('is_current', true)->value('code');
        $codes = config('portal.signup.demo_courses');
        if (! $term || ! $codes) {
            return collect();
        }

        $classes = CourseOffering::where('term_code', $term)
            ->whereHas('course', fn ($q) => $q->whereIn('code', $codes))
            ->with('course')->get()
            ->sortBy(fn (CourseOffering $o) => array_search($o->course->code, $codes, true))
            ->values()
            ->map(function (CourseOffering $offering) use ($tutor) {
                $class = CourseClass::create(['offering_id' => $offering->id, 'code' => self::nextCode($offering), 'tutor_id' => $tutor->id]);
                $class->students()->attach(self::studentIds(random_int(24, 36)));

                return $class;
            });
        if ($classes->isEmpty()) {
            return $classes;
        }

        self::sampleWork($tutor, $classes[0], $classes[1] ?? $classes[0], $classes[2] ?? $classes[0]);

        return $classes;
    }

    /** "C" + one more than the highest class number in the offering, taken under a row lock. */
    private static function nextCode(CourseOffering $offering): string
    {
        CourseOffering::whereKey($offering->id)->lockForUpdate()->first();
        $highest = CourseClass::where('offering_id', $offering->id)->pluck('code')
            ->map(fn (string $code) => (int) preg_replace('/\D/', '', $code))
            ->max() ?? 0;

        return 'C'.($highest + 1);
    }

    /** Existing students where possible; new ones only when the pool is too small. */
    private static function studentIds(int $count): array
    {
        $ids = Student::inRandomOrder()->limit($count)->pluck('id')->all();
        while (count($ids) < $count) {
            [$firstAr, $firstEn] = self::FIRST[array_rand(self::FIRST)];
            [$lastAr, $lastEn] = self::LAST[array_rand(self::LAST)];
            $uid = $firstEn.'_'.random_int(100000, 999999);
            if (Student::where('university_id', $uid)->exists()) {
                continue;
            }
            $ids[] = Student::create([
                'university_id' => $uid, 'name_ar' => "{$firstAr} {$lastAr}", 'name_en' => Str::ucfirst($firstEn)." {$lastEn}",
                'email' => "{$uid}@".config('portal.email.domain'),
            ])->id;
        }

        return $ids;
    }

    private static function sampleWork(User $tutor, CourseClass $a, CourseClass $b, CourseClass $c): void
    {
        $tz = config('portal.timezone');
        $at = fn (int $days, int $hour) => Carbon::now($tz)->startOfDay()->addDays($days)->setTime($hour, 0)->utc();
        $session = fn (CourseClass $class, string $title, int $days, int $hour, int $minutes, SessionStatus $status, SessionKind $kind = SessionKind::Live, ?int $present = null, ?string $note = null) => ClassSession::create([
            'class_id' => $class->id, 'title' => $title, 'kind' => $kind, 'starts_at' => $at($days, $hour), 'duration_min' => $minutes,
            'status' => $status, 'attendance_count' => $present, 'review_note' => $note, 'created_by' => $tutor->id,
            'notify_students' => false,
            'reported_at' => in_array($status, [SessionStatus::Upcoming, SessionStatus::NotReported], true) ? null : $at($days, $hour + 3),
        ]);

        $held = $session($a, 'الفهارس وأنواعها', -2, 18, 90, SessionStatus::NotReported, present: 28);
        $session($a, 'مقدمة المقرر وخطة الفصل', -15, 18, 90, SessionStatus::Paid, present: 31);
        $session($a, 'تحسين الاستعلامات وخطط التنفيذ', 1, 18, 90, SessionStatus::Upcoming);
        $session($b, 'إدارة الحالة في الواجهة', -4, 20, 60, SessionStatus::PendingApproval, present: 22);
        $session($b, 'أساسيات HTTP', -9, 19, 60, SessionStatus::Rejected, present: 5, note: 'عدد الحضور أقل من الحد الأدنى.');
        $session($b, 'واجهات REST والمصادقة', 3, 20, 60, SessionStatus::Upcoming);
        $session($c, 'تمثيل المعرفة', -6, 10, 50, SessionStatus::ApprovedUnpaid, SessionKind::Recorded);

        $online = ExamCenter::where('is_online', true)->value('id') ?? ExamCenter::value('id');
        if ($online) {
            foreach ([[$a, 'midterm', 10, '10:00', 90, ExamStatus::Approved], [$b, 'practical', 20, '12:00', 120, ExamStatus::Pending]] as [$class, $type, $days, $start, $minutes, $status]) {
                Exam::create([
                    'class_id' => $class->id, 'type' => $type, 'date' => Carbon::now($tz)->addDays($days)->toDateString(),
                    'start_time' => $start, 'duration_min' => $minutes, 'center_id' => $online, 'status' => $status, 'requested_by' => $tutor->id,
                ]);
            }
        }

        $name = 'session-notes.pdf';
        $path = "files/{$a->offering_id}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n% Demo file for {$tutor->username} — SVU Faculty Portal trial.\n");
        CourseFile::create([
            'offering_id' => $a->offering_id, 'class_id' => $a->id, 'session_id' => $held->id, 'title' => 'ملاحظات الجلسة: الفهارس',
            'original_name' => $name, 'storage_path' => $path, 'mime' => 'application/pdf', 'size_bytes' => Storage::disk('local')->size($path),
            'kind' => FileKind::fromFileName($name), 'visible' => true, 'uploaded_by' => $tutor->id,
        ]);
        // The file is written outside the database; remove it again if the sign-up transaction rolls back.
        DB::afterRollBack(fn () => Storage::disk('local')->delete($path));
    }
}
