<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOffering;
use App\Models\Exam;
use App\Models\Program;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Instant self sign-up for the doctors' trial: a Tutor account with its own demo classes. */
class SignUpTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['portal.signup.instant' => true, 'portal.signup.demo_courses' => ['ADB', 'WAP']]);

        Term::create(['code' => 'F26', 'name_ar' => 'خريف', 'name_en' => 'Fall', 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-31', 'is_current' => true]);
        Program::create(['code' => 'BIT', 'name_ar' => 'تقانة', 'name_en' => 'IT']);
        foreach (['ADB', 'WAP'] as $code) {
            $course = Course::create(['program_code' => 'BIT', 'code' => $code, 'name_ar' => $code, 'name_en' => $code]);
            $offering = CourseOffering::create(['course_id' => $course->id, 'term_code' => 'F26']);
            CourseClass::create(['offering_id' => $offering->id, 'code' => 'C1', 'tutor_id' => User::factory()->create()->id]);
        }
        Student::factory()->count(10)->create(); // a small pool, so sign-up also has to create students
        $this->examCenter();
        // Requests from the SPA's origin get a session, as in the browser.
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
    }

    private function form(array $over = []): array
    {
        return $over + [
            'nameAr' => 'د. ريم الأحمد', 'nameEn' => 'Dr. Reem Al-Ahmad', 'employeeId' => 'T-9001', 'email' => 'T_Reem@svuonline.org',
            'phone' => null, 'program' => 'BIT', 'rank' => 'Lecturer', 'specialty' => 'Databases', 'courses' => 'ADB',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'agree' => true,
        ];
    }

    public function test_sign_up_creates_a_signed_in_tutor_with_private_demo_classes(): void
    {
        $this->getJson('/api/v1/signup-mode')->assertJsonPath('instant', true);

        $this->postJson('/api/v1/account-requests', $this->form())
            ->assertCreated()->assertJsonPath('user.username', 't_reem')->assertJsonPath('user.role', 'tutor');

        $user = User::where('username', 't_reem')->sole();
        $this->assertSame(Role::Tutor, $user->role);
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/auth/me')->assertSuccessful()->assertJsonPath('username', 't_reem');

        // One new class per demo course, numbered after the existing C1, with students, sessions, exams and a file.
        $classes = CourseClass::where('tutor_id', $user->id)->get();
        $this->assertSame(['C2', 'C2'], $classes->pluck('code')->all());
        $classes->each(fn (CourseClass $c) => $this->assertGreaterThanOrEqual(24, $c->students()->count()));
        $this->assertSame(7, ClassSession::whereIn('class_id', $classes->modelKeys())->count());
        $this->assertSame(2, Exam::whereIn('class_id', $classes->modelKeys())->count());
        $this->getJson('/api/v1/sessions')->assertJsonCount(7, 'data');
        $this->getJson('/api/v1/files')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/my/offerings')->assertJsonCount(2);

        // The same university address can't sign up twice, and other domains are refused.
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/account-requests', $this->form(['employeeId' => 'T-9002']))->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/account-requests', $this->form(['email' => 'reem@gmail.com', 'employeeId' => 'T-9003']))->assertJsonValidationErrors('email');
    }

    public function test_each_doctor_only_sees_their_own_demo_work(): void
    {
        $this->postJson('/api/v1/account-requests', $this->form())->assertCreated();
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/account-requests', $this->form(['email' => 't_omar@svuonline.org', 'employeeId' => 'T-9004']))->assertCreated();

        $omar = User::where('username', 't_omar')->sole();
        $this->assertSame(['C3', 'C3'], CourseClass::where('tutor_id', $omar->id)->pluck('code')->all());
        $this->actingAs($omar)->getJson('/api/v1/sessions')->assertJsonCount(7, 'data');
        $this->actingAs($omar)->getJson('/api/v1/files')->assertJsonCount(1, 'data');
    }
}
