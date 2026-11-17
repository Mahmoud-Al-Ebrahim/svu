<?php

namespace Tests\Feature;

use App\Jobs\SendClassEmail;
use App\Mail\ClassMessageMail;
use App\Models\CourseClass;
use App\Models\SentEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Feature 6: send e-mail to a class or a student. */
class EmailTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $tutor;

    private CourseClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->tutor = User::factory()->create();
        $this->class = $this->classFor($this->tutor, students: 5);
    }

    public function test_sends_to_every_student_of_the_class_in_bcc(): void
    {
        $this->actingAs($this->tutor)->getJson("/api/v1/classes/{$this->class->id}/recipients")->assertJsonPath('count', 5);

        $this->actingAs($this->tutor)->postJson('/api/v1/emails', [
            'recipientType' => 'class', 'classId' => $this->class->id,
            'subject' => 'Session moved', 'body' => 'The session moves to Tuesday.',
        ])->assertStatus(202)->assertJsonPath('recipients', 5)->assertJsonPath('to.classCode', 'C1');

        Mail::assertSent(ClassMessageMail::class, function (ClassMessageMail $mail) {
            return count($mail->bcc) === 5 && $mail->hasCc($this->tutor->email) && $mail->hasReplyTo($this->tutor->email);
        });
        $this->actingAs($this->tutor)->getJson('/api/v1/emails')->assertJsonPath('data.0.status', 'sent');
    }

    public function test_sends_to_one_student_with_an_attachment(): void
    {
        $upload = $this->actingAs($this->tutor)
            ->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create('guide.pdf', 10)], ['Accept' => 'application/json'])
            ->json('uploadId');
        $address = ucfirst($this->class->students()->first()->email); // the name part is matched case-insensitively

        $this->actingAs($this->tutor)->postJson('/api/v1/emails', [
            'recipientType' => 'student', 'studentAddress' => $address,
            'subject' => 'Your file', 'body' => 'See attached.', 'ccSender' => false, 'attachmentUploadIds' => [$upload],
        ])->assertStatus(202)->assertJsonPath('attachments.0', 'guide.pdf');

        Mail::assertSent(ClassMessageMail::class, fn ($mail) => $mail->hasTo(strtolower($address)) && ! $mail->hasCc($this->tutor->email));
    }

    public function test_validates_recipients(): void
    {
        $this->actingAs($this->tutor)->postJson('/api/v1/emails', [
            'recipientType' => 'student', 'studentAddress' => 'someone@gmail.com', 'subject' => 's', 'body' => 'b',
        ])->assertJsonValidationErrors('studentAddress');

        $foreign = $this->classFor(User::factory()->create());

        // Another tutor's student, or a university address that is not a student, is refused.
        foreach ([$foreign->students()->first()->email, 'dean@svuonline.org'] as $address) {
            $this->actingAs($this->tutor)->postJson('/api/v1/emails', [
                'recipientType' => 'student', 'studentAddress' => $address, 'subject' => 's', 'body' => 'b',
            ])->assertJsonValidationErrors('studentAddress');
        }

        $this->actingAs($this->tutor)->postJson('/api/v1/emails', [
            'recipientType' => 'class', 'classId' => $foreign->id, 'subject' => 's', 'body' => 'b',
        ])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_a_retry_skips_the_batches_already_sent(): void
    {
        config(['portal.email.batch_size' => 2]); // 5 students → batches of 2, 2, 1
        $email = SentEmail::create([
            'sender_id' => $this->tutor->id, 'recipient_type' => 'class', 'class_id' => $this->class->id,
            'subject' => 's', 'body' => 'b', 'cc_sender' => true, 'recipients_count' => 5, 'status' => 'queued',
            'processed_batches' => 2, // a first attempt sent two batches, then the worker died
        ]);

        (new SendClassEmail($email->id))->handle();

        Mail::assertSentCount(1);
        Mail::assertSent(ClassMessageMail::class, fn ($m) => count($m->bcc) === 1 && ! $m->hasCc($this->tutor->email));
        $this->assertSame('sent', $email->fresh()->status);
    }

    public function test_a_job_that_gives_up_is_marked_failed_not_left_queued(): void
    {
        config(['portal.email.batch_size' => 2]);
        $email = SentEmail::create([
            'sender_id' => $this->tutor->id, 'recipient_type' => 'class', 'class_id' => $this->class->id,
            'subject' => 's', 'body' => 'b', 'recipients_count' => 5, 'status' => 'queued', 'processed_batches' => 1,
        ]);

        (new SendClassEmail($email->id))->failed(new \RuntimeException('worker timed out'));

        $email->refresh();
        $this->assertSame('partially_failed', $email->status);
        $this->assertSame(3, $email->failed_count);
        $this->actingAs($this->tutor)->getJson('/api/v1/emails')->assertJsonPath('data.0.status', 'partially_failed')->assertJsonPath('data.0.failed', 3);
    }
}
