<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Mail\ClassMessageMail;
use App\Mail\PasswordResetCodeMail;
use App\Mail\PortalNoticeMail;
use App\Models\AccountRequest;
use App\Models\ClassSession;
use App\Models\CourseFile;
use App\Models\EmailAttachment;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Regression tests for the issues found in the API review (2026-11-09). */
class ReviewRegressionTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function direct(User $u, string $name, int $kb = 1): string
    {
        return $this->actingAs($u)
            ->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create($name, $kb)], ['Accept' => 'application/json'])
            ->assertCreated()->json('uploadId');
    }

    public function test_cors_lets_the_spa_send_its_session_cookie(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/v1/auth/login')
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }

    public function test_a_refused_file_leaves_the_other_uploads_reusable(): void
    {
        Storage::fake('local');
        $t = User::factory()->create();
        $c = $this->classFor($t);
        $good = $this->direct($t, 'good.pdf');
        $bad = $this->direct($t, 'bad.exe');

        $this->actingAs($t)->postJson('/api/v1/files', ['offeringId' => $c->offering_id, 'items' => [['uploadId' => $good], ['uploadId' => $bad]]])
            ->assertJsonValidationErrors('items.1.uploadId');
        $this->assertSame('complete', Upload::find($good)->status);

        $this->actingAs($t)->postJson('/api/v1/files', ['offeringId' => $c->offering_id, 'items' => [['uploadId' => $good]]])->assertCreated();
        $this->assertTrue(Storage::disk('local')->exists(CourseFile::sole()->storage_path));
    }

    public function test_oversized_email_attachments_leave_the_uploads_reusable(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['portal.email.max_attachments_bytes' => 3 * 1024]);
        $t = User::factory()->create();
        $c = $this->classFor($t);
        $a = $this->direct($t, 'a.pdf', 2);
        $b = $this->direct($t, 'b.pdf', 2);

        $this->actingAs($t)->postJson('/api/v1/emails', [
            'recipientType' => 'class', 'classId' => $c->id, 'subject' => 's', 'body' => 'b', 'attachmentUploadIds' => [$a, $b],
        ])->assertJsonValidationErrors('attachmentUploadIds');

        $this->actingAs($t)->postJson('/api/v1/emails', [
            'recipientType' => 'class', 'classId' => $c->id, 'subject' => 's', 'body' => 'b', 'attachmentUploadIds' => [$a],
        ])->assertStatus(202);
        $this->assertTrue(Storage::disk('local')->exists(EmailAttachment::sole()->storage_path));
    }

    public function test_class_files_view_only_includes_that_course(): void
    {
        Storage::fake('local');
        $t = User::factory()->create();
        $a = $this->classFor($t);
        $b = $this->classFor($t);
        $this->actingAs($t)->postJson('/api/v1/files', ['offeringId' => $b->offering_id, 'items' => [['uploadId' => $this->direct($t, 'b.pdf')]]])->assertCreated();

        $this->actingAs($t)->getJson('/api/v1/files?class='.$a->id)->assertJsonCount(0, 'data');
        $this->actingAs($t)->getJson('/api/v1/files?class='.$b->id)->assertJsonCount(1, 'data');
    }

    public function test_executable_content_is_refused_whatever_the_extension(): void
    {
        Storage::fake('local');
        $t = User::factory()->create();
        $c = $this->classFor($t);
        $exe = "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 64).'This program cannot be run in DOS mode';
        $id = $this->actingAs($t)
            ->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->createWithContent('notes.pdf', $exe)], ['Accept' => 'application/json'])
            ->json('uploadId');

        $this->actingAs($t)->postJson('/api/v1/files', ['offeringId' => $c->offering_id, 'items' => [['uploadId' => $id]]])
            ->assertJsonValidationErrors('items.0.uploadId');
    }

    public function test_only_the_uploader_can_change_a_file(): void
    {
        Storage::fake('local');
        config(['portal.files.share_course_wide' => true]); // SVU's normal rule: course-wide files are shared
        $owner = User::factory()->create();
        $coTutor = User::factory()->create();
        $offering = $this->catalog();
        $this->classFor($owner, $offering, 'C1');
        $this->classFor($coTutor, $offering, 'C2');
        $id = $this->actingAs($owner)->postJson('/api/v1/files', ['offeringId' => $offering->id, 'items' => [['uploadId' => $this->direct($owner, 'plan.pdf')]]])
            ->json('0.id');

        // The co-tutor still sees and downloads the shared file, but can't hide, rename or delete it.
        $this->actingAs($coTutor)->getJson('/api/v1/files')->assertJsonCount(1, 'data');
        $this->actingAs($coTutor)->patchJson("/api/v1/files/{$id}", ['visible' => false])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/v1/files/{$id}", ['visible' => false])->assertOk();
    }

    public function test_during_the_trial_course_wide_files_stay_with_their_uploader(): void
    {
        Storage::fake('local');
        config(['portal.files.share_course_wide' => false]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $offering = $this->catalog();
        $this->classFor($owner, $offering, 'C1');
        $this->classFor($other, $offering, 'C2');
        $id = $this->actingAs($owner)->postJson('/api/v1/files', ['offeringId' => $offering->id, 'items' => [['uploadId' => $this->direct($owner, 'plan.pdf')]]])
            ->json('0.id');

        $this->actingAs($owner)->getJson('/api/v1/files')->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/v1/files')->assertJsonCount(0, 'data');
        $this->actingAs($other)->get("/api/v1/files/{$id}/download")->assertForbidden();
    }

    public function test_tutor_is_copied_once_however_many_batches(): void
    {
        Mail::fake();
        $t = User::factory()->create();
        $c = $this->classFor($t, students: 120);
        $this->actingAs($t)->postJson('/api/v1/emails', ['recipientType' => 'class', 'classId' => $c->id, 'subject' => 's', 'body' => 'b'])->assertStatus(202);

        Mail::assertSentCount(3);
        $this->assertSame(1, Mail::sent(ClassMessageMail::class)->filter(fn ($m) => $m->hasCc($t->email))->count());
    }

    public function test_welcome_box_keeps_showing_the_previous_login_after_a_reload(): void
    {
        $spa = ['Origin' => 'http://localhost:5173', 'Accept' => 'application/json'];
        User::factory()->create(['username' => 't_p', 'last_login_ip' => '10.0.0.1', 'last_login_at' => now()->subDay()]);

        $this->withHeaders($spa)->postJson('/api/v1/auth/login', ['login' => 't_p', 'password' => 'Password1'])->assertJsonPath('lastIp', '10.0.0.1');
        $this->app['auth']->forgetGuards(); // like a page reload: the user is loaded again from the database
        $this->withHeaders($spa)->getJson('/api/v1/auth/me')->assertJsonPath('lastIp', '10.0.0.1');
    }

    public function test_account_request_numbers_have_six_digits(): void
    {
        config(['portal.signup.instant' => false]); // the approval flow
        $this->catalog();
        $ref = $this->postJson('/api/v1/account-requests', [
            'nameAr' => 'د. علي', 'nameEn' => 'Dr. Ali', 'employeeId' => 'T-99999', 'email' => 't_ali@svuonline.org',
            'program' => 'BIT', 'rank' => 'Lecturer', 'specialty' => 'Databases', 'courses' => 'ADB',
            'password' => 'Password1', 'password_confirmation' => 'Password1', 'agree' => true,
        ])->assertCreated()->json('ref');

        $this->assertMatchesRegularExpression('/^FAC-\d{4}-\d{6}$/', $ref);
        $this->assertSame(1, AccountRequest::count());
    }

    public function test_a_finished_upload_piece_cannot_be_sent_again(): void
    {
        Storage::fake('local');
        config(['portal.uploads.chunk_size' => 4]);
        $t = User::factory()->create();
        $id = $this->actingAs($t)->postJson('/api/v1/uploads', ['fileName' => 'a.pdf', 'size' => 8])->json('uploadId');
        $put = fn (int $n, string $bytes) => $this->actingAs($t)->call('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", [], [], [], ['HTTP_ACCEPT' => 'application/json'], $bytes);

        $put(0, 'abcd')->assertOk();
        $put(0, 'abcd')->assertStatus(409); // a retried piece is refused, not appended twice
        $this->assertSame(4, Upload::find($id)->received_bytes);
    }

    public function test_direct_uploads_respect_the_configured_limit(): void
    {
        Storage::fake('local');
        config(['portal.uploads.direct_max_kb' => 10]);
        $t = User::factory()->create();

        $this->actingAs($t)->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create('big.pdf', 11)], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
    }

    public function test_password_reset_invalidates_remember_me(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 't_r@svuonline.org']);
        $before = $user->remember_token;
        $this->postJson('/api/v1/auth/forgot', ['email' => $user->email]);
        $code = Mail::sent(PasswordResetCodeMail::class)->first()->code;
        $token = $this->postJson('/api/v1/auth/forgot/verify', ['email' => $user->email, 'code' => $code])->json('resetToken');

        $this->postJson('/api/v1/auth/reset', ['resetToken' => $token, 'password' => 'NewPass123'])->assertNoContent();
        $this->assertNotSame($before, $user->fresh()->remember_token);
    }

    public function test_decision_emails_use_the_tutors_language(): void
    {
        Mail::fake();
        $tutor = User::factory()->create(['preferences' => ['locale' => 'en']]);
        $manager = User::factory()->manager()->create();
        $class = $this->classFor($tutor, $this->catalog($manager));
        $session = ClassSession::create([
            'class_id' => $class->id, 'title' => 'Indexes', 'kind' => SessionKind::Live, 'starts_at' => now()->subDay(),
            'duration_min' => 90, 'status' => SessionStatus::PendingApproval,
        ]);

        $this->withHeader('Accept-Language', 'ar')->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertOk();

        Mail::assertQueued(PortalNoticeMail::class, fn ($m) => str_contains($m->heading, 'Approved, dues not paid yet'));
    }

    public function test_decision_emails_reach_the_requesting_tutor_after_the_class_is_reassigned(): void
    {
        Mail::fake();
        $original = User::factory()->create();
        $successor = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $class = $this->classFor($original, $this->catalog($manager));
        $session = ClassSession::create([
            'class_id' => $class->id, 'title' => 'Indexes', 'kind' => SessionKind::Live, 'starts_at' => now()->subDay(),
            'duration_min' => 90, 'status' => SessionStatus::PendingApproval, 'created_by' => $original->id,
        ]);
        $class->update(['tutor_id' => $successor->id]);

        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertOk();

        Mail::assertQueued(PortalNoticeMail::class, fn ($m) => $m->hasTo($original->email));
        Mail::assertNotQueued(PortalNoticeMail::class, fn ($m) => $m->hasTo($successor->email));
    }

    public function test_session_start_time_survives_updates(): void
    {
        $tutor = User::factory()->create();
        $start = now()->subDay()->startOfMinute();
        $session = ClassSession::create([
            'class_id' => $this->classFor($tutor)->id, 'title' => 'Indexes', 'kind' => SessionKind::Live, 'starts_at' => $start,
            'duration_min' => 90, 'status' => SessionStatus::NotReported,
        ]);

        $this->travel(2)->hours();
        $session->update(['status' => SessionStatus::PendingApproval, 'reported_at' => now()]);

        $this->assertTrue($session->fresh()->starts_at->equalTo($start));
    }
}
