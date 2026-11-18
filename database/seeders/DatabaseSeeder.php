<?php

namespace Database\Seeders;

use App\Enums\ExamStatus;
use App\Enums\FileKind;
use App\Enums\Role;
use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseFile;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\ExamCenter;
use App\Models\Program;
use App\Models\SentEmail;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Development data mirroring faculty-portal/src/data/mock.ts. Dates are relative to "now".
 * Every seeded account uses the password in SEED_PASSWORD (`Password1` by default, see backend/README.md).
 * On a live server (APP_ENV=production) it refuses that public default: set SEED_PASSWORD in .env first.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $password = config('portal.seed_password');
        if (app()->isProduction() && ($password === 'Password1' || strlen((string) $password) < 10)) {
            throw new \RuntimeException('Set SEED_PASSWORD in .env to a strong password (10+ characters) before seeding a live server.');
        }
        $tz = config('portal.timezone');
        $at = fn (int $days, int $h = 9, int $m = 0) => Carbon::now($tz)->addDays($days)->setTime($h, $m)->utc();
        $day = fn (int $days) => Carbon::now($tz)->addDays($days)->toDateString();

        // ---- People ----
        $samer = User::create([
            'username' => 't_samer', 'email' => 't_samer@svuonline.org', 'employee_id' => 'T-20418', 'password' => $password,
            'name_ar' => 'د. سامر الحلبي', 'name_en' => 'Dr. Samer Al-Halabi', 'rank_ar' => 'أستاذ مساعد', 'rank_en' => 'Assistant Professor',
            'phone' => '+963 944 000 000', 'role' => Role::Tutor, 'last_login_at' => $at(-1, 13, 54), 'last_login_ip' => '10.1.19.250',
            'previous_login_at' => $at(-3, 9, 12), 'previous_login_ip' => '10.1.19.250',
        ]);
        $mona = User::create([
            'username' => 't_mona', 'email' => 't_mona@svuonline.org', 'employee_id' => 'T-20533', 'password' => $password,
            'name_ar' => 'م. منى نصر', 'name_en' => 'Eng. Mona Nasr', 'rank_ar' => 'مهندس مشرف', 'rank_en' => 'Supervising Engineer', 'role' => Role::Tutor,
        ]);
        $bitManager = User::create([
            'username' => 'm_bit', 'email' => 'bit.program@svuonline.org', 'employee_id' => 'M-1001', 'password' => $password,
            'name_ar' => 'مدير برنامج BIT', 'name_en' => 'BIT Program Manager', 'role' => Role::ProgramManager,
        ]);
        $mwsManager = User::create([
            'username' => 'm_mws', 'email' => 'mws.program@svuonline.org', 'employee_id' => 'M-1002', 'password' => $password,
            'name_ar' => 'مدير برنامج MWS', 'name_en' => 'MWS Program Manager', 'role' => Role::ProgramManager,
        ]);
        User::create([
            'username' => 'admin', 'email' => 'portal.admin@svuonline.org', 'employee_id' => 'A-0001', 'password' => $password,
            'name_ar' => 'مدير النظام', 'name_en' => 'System administrator', 'role' => Role::Admin,
        ]);

        // ---- Catalog ----
        Term::insert([
            ['code' => 'F26', 'name_ar' => 'فصل خريف 2026', 'name_en' => 'Fall 2026', 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-31', 'is_current' => true],
            ['code' => 'S26', 'name_ar' => 'فصل ربيع 2026', 'name_en' => 'Spring 2026', 'starts_on' => '2026-02-15', 'ends_on' => '2026-07-15', 'is_current' => false],
            ['code' => 'F25', 'name_ar' => 'فصل خريف 2025', 'name_en' => 'Fall 2025', 'starts_on' => '2025-09-15', 'ends_on' => '2026-01-31', 'is_current' => false],
        ]);
        Program::insert([
            ['code' => 'BIT', 'name_ar' => 'الإجازة في تقانة المعلومات', 'name_en' => 'Bachelor in Information Technology', 'manager_id' => $bitManager->id],
            ['code' => 'ITE', 'name_ar' => 'الإجازة في هندسة نظم المعلومات', 'name_en' => 'Bachelor in Information System Engineering', 'manager_id' => $bitManager->id],
            ['code' => 'MWS', 'name_ar' => 'ماجستير علوم الويب', 'name_en' => 'Master in Web Sciences', 'manager_id' => $mwsManager->id],
        ]);

        // key => [program, code, ar, en, term, hue, [class => [students, tutor]]]
        $catalog = [
            'adb' => ['BIT', 'ADB', 'قواعد المعطيات المتقدمة', 'Advanced Databases', 'F26', 205, ['C1' => [42, $samer], 'C2' => [39, $samer], 'C3' => [37, $samer]]],
            'wap' => ['BIT', 'WAP', 'برمجة تطبيقات الويب', 'Web Application Programming', 'F26', 38, ['C1' => [44, $samer], 'C2' => [40, $samer], 'C3' => [35, $mona]]],
            'ae' => ['ITE', 'AE', 'الذكاء الصنعي', 'Artificial Intelligence', 'F26', 160, ['C1' => [46, $samer]]],
            'an' => ['ITE', 'AN', 'شبكات متقدمة', 'Advanced Networking', 'F26', 280, ['C1' => [31, $samer], 'C2' => [28, $samer]]],
            'rm' => ['MWS', 'RM', 'مناهج البحث العلمي', 'Research Methods', 'F26', 340, ['C1' => [22, $samer]]],
            'db1' => ['BIT', 'DB1', 'قواعد المعطيات 1', 'Databases 1', 'S26', 205, ['C1' => [48, $samer], 'C2' => [45, $samer]]],
        ];
        $offering = [];
        $class = [];
        foreach ($catalog as $key => [$program, $code, $ar, $en, $term, $hue, $classes]) {
            $course = Course::firstOrCreate(['program_code' => $program, 'code' => $code], ['name_ar' => $ar, 'name_en' => $en]);
            $offering[$key] = CourseOffering::create(['course_id' => $course->id, 'term_code' => $term, 'hue' => $hue]);
            foreach ($classes as $classCode => [$count, $tutor]) {
                $c = CourseClass::create(['offering_id' => $offering[$key]->id, 'code' => $classCode, 'tutor_id' => $tutor->id]);
                $c->students()->attach(Student::factory()->count($count)->create()->modelKeys());
                $class["{$key}.{$classCode}"] = $c;
            }
        }

        foreach ([
            ['online', 'عن بُعد — نظام EXAMS', 'Online — EXAMS system', true],
            ['dam-mezzeh', 'مركز نفاذ دمشق — المزة', 'Damascus telecenter — Mezzeh', false],
            ['aleppo', 'مركز نفاذ حلب', 'Aleppo telecenter', false],
            ['lattakia', 'مركز نفاذ اللاذقية', 'Lattakia telecenter', false],
            ['homs', 'مركز نفاذ حمص', 'Homs telecenter', false],
        ] as [$code, $ar, $en, $online]) {
            $center[$code] = ExamCenter::create(['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'is_online' => $online]);
        }

        // ---- Sessions ----
        $session = fn (string $cls, string $title, int $days, int $h, int $dur, SessionStatus $status, SessionKind $kind = SessionKind::Live, ?int $att = null) => ClassSession::create([
            'class_id' => $class[$cls]->id, 'title' => $title, 'kind' => $kind, 'starts_at' => $at($days, $h), 'duration_min' => $dur,
            'status' => $status, 'attendance_count' => $att, 'created_by' => $class[$cls]->tutor_id,
            'reported_at' => in_array($status, [SessionStatus::Upcoming, SessionStatus::NotReported], true) ? null : $at($days, $h + 3),
        ]);
        $session('adb.C1', 'تحسين الاستعلامات وخطط التنفيذ', 0, 18, 90, SessionStatus::Upcoming);
        $session('wap.C2', 'واجهات REST والمصادقة', 0, 20, 60, SessionStatus::Upcoming);
        $session('adb.C2', 'تحسين الاستعلامات وخطط التنفيذ', 1, 18, 90, SessionStatus::Upcoming);
        $session('ae.C1', 'خوارزميات البحث المُوجَّه', 2, 17, 90, SessionStatus::Upcoming);
        $session('an.C1', 'بروتوكولات التوجيه الديناميكي', 3, 19, 90, SessionStatus::Upcoming);
        $session('rm.C1', 'تصميم أداة الاستبيان', 5, 17, 90, SessionStatus::Upcoming);
        $indexes = $session('adb.C1', 'الفهارس وأنواعها', -2, 18, 90, SessionStatus::NotReported, att: 34);
        $state = $session('wap.C2', 'إدارة الحالة في الواجهة', -3, 20, 60, SessionStatus::PendingApproval, att: 27);
        $knowledge = $session('ae.C1', 'تمثيل المعرفة', -5, 10, 50, SessionStatus::ApprovedUnpaid, SessionKind::Recorded);
        $session('adb.C2', 'إدارة المعاملات والتزامن', -9, 18, 90, SessionStatus::Paid, att: 31);
        $session('an.C2', 'جودة الخدمة QoS', -10, 19, 90, SessionStatus::Rejected, att: 6)
            ->update(['review_note' => 'عدد الحضور أقل من الحد الأدنى.', 'reviewed_by' => $bitManager->id]);
        $session('adb.C1', 'مراجعة النموذج العلائقي', -16, 18, 90, SessionStatus::Paid, att: 38);
        $session('wap.C3', 'مقدمة في React', 1, 19, 90, SessionStatus::Upcoming); // t_mona's class: not visible to t_samer

        // ---- Files (small placeholder contents so downloads work) ----
        $file = function (string $key, ?string $cls, string $title, string $name, int $days, bool $visible = true, ?ClassSession $s = null, int $downloads = 0) use ($offering, $class, $samer, $at) {
            $path = "files/{$offering[$key]->id}/".Str::uuid().'.'.pathinfo($name, PATHINFO_EXTENSION);
            Storage::disk('local')->put($path, "Placeholder for {$name} — SVU Faculty Portal development data.\n");
            $f = CourseFile::create([
                'offering_id' => $offering[$key]->id, 'class_id' => $cls ? $class["{$key}.{$cls}"]->id : null, 'session_id' => $s?->id,
                'title' => $title, 'original_name' => $name, 'storage_path' => $path, 'mime' => 'text/plain',
                'size_bytes' => Storage::disk('local')->size($path), 'kind' => FileKind::fromFileName($name), 'visible' => $visible,
                'download_count' => $downloads, 'uploaded_by' => $samer->id,
            ]);
            $f->forceFill(['created_at' => $at($days), 'updated_at' => $at($days)])->save();
        };
        $file('adb', null, 'خطة المقرر ومعايير التقييم', 'ADB_plan_F26.pdf', -40, downloads: 97);
        $file('adb', null, 'سلايدات: الفهارس وأنواعها', 'ADB_indexes.pptx', -2, s: $indexes, downloads: 41);
        $file('adb', 'C1', 'تسجيل الجلسة: الفهارس وأنواعها', 'ADB_C1_indexes.lrec', -2, s: $indexes, downloads: 22);
        $file('adb', null, 'ملفات قاعدة البيانات التدريبية', 'hospital_db.zip', -19, downloads: 63);
        $file('adb', null, 'مرجع إضافي: توثيق PostgreSQL', 'postgres_reference.pdf', -1, visible: false);
        $file('wap', null, 'دليل إعداد بيئة العمل', 'WAP_setup.pdf', -30, downloads: 71);
        $file('wap', 'C2', 'تسجيل الجلسة: إدارة الحالة', 'WAP_C2_state.lrec', -3, s: $state, downloads: 19);
        $file('ae', 'C1', 'جلسة مسجّلة: تمثيل المعرفة', 'AE_knowledge.mp4', -5, s: $knowledge, downloads: 33);
        $file('an', null, 'تمارين محاكاة على Packet Tracer', 'AN_labs.docx', -12, downloads: 25);
        $file('rm', null, 'دليل كتابة مقترح البحث', 'RM_proposal_guide.pdf', -36, downloads: 22);

        // ---- Exams ----
        foreach ([
            ['adb.C1', 'midterm', 6, '10:00', 90, 'online', ExamStatus::Approved],
            ['adb.C2', 'midterm', 6, '12:00', 90, 'online', ExamStatus::Approved],
            ['wap.C1', 'practical', 11, '09:00', 120, 'dam-mezzeh', ExamStatus::Pending],
            ['ae.C1', 'final', 34, '11:00', 120, 'dam-mezzeh', ExamStatus::Pending],
            ['an.C1', 'midterm', -4, '10:00', 90, 'online', ExamStatus::Done],
        ] as [$cls, $type, $days, $start, $dur, $c, $status]) {
            Exam::create([
                'class_id' => $class[$cls]->id, 'type' => $type, 'date' => $day($days), 'start_time' => $start, 'duration_min' => $dur,
                'center_id' => $center[$c]->id, 'status' => $status, 'requested_by' => $samer->id,
            ]);
        }

        // ---- Sent e-mails ----
        foreach ([
            ['class', 'adb.C1', null, 'تأجيل جلسة الأسبوع الخامس', 'تم تأجيل جلسة الأسبوع الخامس إلى يوم الثلاثاء بنفس التوقيت.', -2],
            ['class', 'wap.C2', null, 'Environment setup before the next session', 'Please install the tools in the attached guide before our next session.', -4],
            ['student', null, 'omar_148312@svuonline.org', 'بخصوص ملف قاعدة البيانات', 'يمكنك إرفاق قاعدة البيانات بصيغة SQL بدلاً من النسخة الاحتياطية.', -6],
        ] as [$type, $cls, $address, $subject, $body, $days]) {
            $email = SentEmail::create([
                'sender_id' => $samer->id, 'recipient_type' => $type, 'class_id' => $cls ? $class[$cls]->id : null,
                'student_address' => $address, 'subject' => $subject, 'body' => $body,
                'recipients_count' => $cls ? $class[$cls]->students()->count() : 1, 'status' => 'sent', 'sent_at' => $at($days, 19),
            ]);
            $email->forceFill(['created_at' => $at($days, 19)])->save();
        }
    }
}
