<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Mail\PortalNoticeMail;
use App\Models\AccountRequest;
use App\Models\ClassSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Admin: account requests and class assignment; manager review lists carry course and tutor details. */
class AdminTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function request(array $overrides = []): AccountRequest
    {
        return AccountRequest::create($overrides + [
            'ref' => 'FAC-2026-000001', 'name_ar' => 'د. علي', 'name_en' => 'Dr. Ali', 'employee_id' => 'T-99999',
            'email' => 't_ali@svuonline.org', 'program_code' => 'BIT', 'rank' => 'Lecturer', 'specialty' => 'Databases',
            'courses_text' => 'ADB', 'password_hash' => Hash::make('Password1'),
        ]);
    }

    public function test_admin_approves_a_request_and_the_tutor_can_sign_in(): void
    {
        Mail::fake();
        $this->catalog();
        $admin = User::factory()->admin()->create();
        $req = $this->request();

        $this->actingAs($admin)->getJson('/api/v1/manage/account-requests')->assertOk()->assertJsonPath('data.0.ref', 'FAC-2026-000001');
        $this->actingAs($admin)->postJson("/api/v1/manage/account-requests/{$req->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('username', 't_ali');
        $this->actingAs($admin)->postJson("/api/v1/manage/account-requests/{$req->id}/approve")->assertStatus(409);
        Mail::assertQueued(PortalNoticeMail::class, fn ($m) => $m->hasTo('t_ali@svuonline.org'));

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/login', ['login' => 't_ali', 'password' => 'Password1'])
            ->assertOk()->assertJsonPath('role', 'tutor');
    }

    public function test_reject_needs_a_note_and_only_admins_decide(): void
    {
        Mail::fake();
        $this->catalog();
        $req = $this->request();

        $this->actingAs(User::factory()->manager()->create())->getJson('/api/v1/manage/account-requests')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/v1/manage/account-requests/{$req->id}/approve")->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/v1/manage/account-requests/{$req->id}/reject")->assertJsonValidationErrors('note');
        $this->actingAs($admin)->postJson("/api/v1/manage/account-requests/{$req->id}/reject", ['note' => 'Unknown employee ID'])
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertDatabaseMissing('users', ['email' => 't_ali@svuonline.org']);
    }

    public function test_approval_refuses_an_email_that_is_already_in_use(): void
    {
        $this->catalog();
        User::factory()->create(['email' => 't_ali@svuonline.org']);
        $req = $this->request();

        $this->actingAs(User::factory()->admin()->create())->postJson("/api/v1/manage/account-requests/{$req->id}/approve")->assertStatus(409);
    }

    public function test_admin_assigns_a_tutor_to_a_class(): void
    {
        $admin = User::factory()->admin()->create();
        $old = User::factory()->create();
        $new = User::factory()->create();
        $class = $this->classFor($old);

        $this->actingAs($admin)->getJson('/api/v1/manage/classes?term=F26')->assertOk()->assertJsonPath('0.tutor.id', (string) $old->id);
        $this->actingAs($admin)->getJson('/api/v1/manage/tutors')->assertOk()->assertJsonCount(2);
        $this->actingAs($admin)->patchJson("/api/v1/manage/classes/{$class->id}", ['tutorId' => $new->id])
            ->assertOk()->assertJsonPath('tutor.id', (string) $new->id);
        // Only tutors can be assigned.
        $this->actingAs($admin)->patchJson("/api/v1/manage/classes/{$class->id}", ['tutorId' => $admin->id])->assertJsonValidationErrors('tutorId');

        $this->actingAs($new)->getJson('/api/v1/my/offerings')->assertJsonCount(1);
        $this->actingAs($old)->getJson('/api/v1/my/offerings')->assertJsonCount(0);
    }

    public function test_review_lists_include_course_and_tutor(): void
    {
        $manager = User::factory()->manager()->create();
        $tutor = User::factory()->create(['name_en' => 'Dr. Test']);
        $class = $this->classFor($tutor, $this->catalog($manager));
        ClassSession::create([
            'class_id' => $class->id, 'title' => 'Indexes', 'kind' => SessionKind::Live, 'starts_at' => now()->subDay(),
            'duration_min' => 90, 'status' => SessionStatus::PendingApproval,
        ]);

        $this->actingAs($manager)->getJson('/api/v1/manage/sessions')
            ->assertOk()
            ->assertJsonPath('data.0.context.tutor.en', 'Dr. Test')
            ->assertJsonPath('data.0.context.term', 'F26');
        // A tutor's own lists don't carry it.
        $this->actingAs($tutor)->getJson('/api/v1/sessions')->assertJsonMissingPath('data.0.context');
    }
}
