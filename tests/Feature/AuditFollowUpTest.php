<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\CourseFile;
use App\Models\Upload;
use App\Models\User;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Regression tests for the lower-priority issues found in the 2026-11-14 audit. */
class AuditFollowUpTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $tutor;

    private CourseClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['portal.uploads.chunk_size' => 4]);
        $this->tutor = User::factory()->create();
        $this->class = $this->classFor($this->tutor);
    }

    private function direct(string $name, int $kb = 1): string
    {
        return $this->actingAs($this->tutor)
            ->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create($name, $kb)], ['Accept' => 'application/json'])
            ->assertCreated()->json('uploadId');
    }

    private function putChunk(string $id, int $n, string $bytes)
    {
        return $this->actingAs($this->tutor)->call('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", [], [], [], ['HTTP_ACCEPT' => 'application/json'], $bytes);
    }

    private function heldSession(SessionStatus $status): ClassSession
    {
        return ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Indexes', 'kind' => SessionKind::Live,
            'starts_at' => now()->subDay(), 'duration_min' => 90, 'status' => $status, 'created_by' => $this->tutor->id,
        ]);
    }

    public function test_each_piece_keeps_an_upload_alive_and_an_expired_one_is_refused(): void
    {
        $id = $this->actingAs($this->tutor)->postJson('/api/v1/uploads', ['fileName' => 'a.pdf', 'size' => 8])->json('uploadId');

        $this->travel(20)->hours();
        $this->putChunk($id, 0, 'abcd')->assertOk();
        $this->travel(20)->hours(); // 40 hours after starting, but only 20 since the last piece
        $this->putChunk($id, 1, 'efgh')->assertOk();

        $this->travel(25)->hours();
        $this->actingAs($this->tutor)->postJson("/api/v1/uploads/{$id}/complete")->assertStatus(410);
    }

    public function test_an_expired_finished_upload_cannot_be_used(): void
    {
        $id = $this->direct('notes.pdf');
        $this->travel(25)->hours();

        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $id]],
        ])->assertJsonValidationErrors('items.0.uploadId');
    }

    public function test_the_expiry_purge_leaves_used_uploads_alone(): void
    {
        $used = $this->direct('used.pdf');
        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $used]],
        ])->assertCreated();
        $unused = $this->direct('unused.pdf');
        $unusedPath = Upload::find($unused)->storage_path;

        $this->travel(25)->hours();
        $this->artisan('portal:purge-uploads')->assertSuccessful();

        $this->assertNull(Upload::find($unused));
        Storage::disk('local')->assertMissing($unusedPath);
        $file = CourseFile::sole();
        Storage::disk('local')->assertExists($file->storage_path);
    }

    public function test_a_claimed_file_moves_back_when_the_transaction_fails(): void
    {
        $upload = Upload::find($this->direct('notes.pdf'));
        $original = $upload->storage_path;

        try {
            DB::transaction(function () use ($upload) {
                $stored = Uploads::claim($upload, 'files/1');
                Storage::disk('local')->assertExists($stored['path']);
                throw new RuntimeException('something later in the request failed');
            });
        } catch (RuntimeException) {
        }

        Storage::disk('local')->assertExists($original);
        $this->assertSame('complete', $upload->fresh()->status);
    }

    public function test_a_recording_cannot_be_added_once_the_session_is_approved(): void
    {
        foreach ([SessionStatus::ApprovedUnpaid, SessionStatus::Paid] as $status) {
            $session = $this->heldSession($status);
            $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/recording", ['uploadId' => $this->direct('rec.mp4')])
                ->assertStatus(409);
        }
        // Rejected sessions can still be fixed, for example by adding the missing recording.
        $rejected = $this->heldSession(SessionStatus::Rejected);
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$rejected->id}/recording", ['uploadId' => $this->direct('rec.mp4')])
            ->assertCreated();
    }

    public function test_a_session_file_belongs_to_the_sessions_class(): void
    {
        $other = $this->classFor($this->tutor, $this->class->offering, 'C2');
        $session = $this->heldSession(SessionStatus::NotReported);

        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'sessionId' => $session->id, 'items' => [['uploadId' => $this->direct('slides.pdf')]],
        ])->assertCreated()->assertJsonPath('0.classCode', 'C1');

        $this->actingAs($this->tutor)->getJson("/api/v1/files?class={$other->id}")->assertJsonCount(0, 'data');
    }

    public function test_cancelling_an_upcoming_recorded_session_removes_its_video(): void
    {
        $id = $this->actingAs($this->tutor)->postJson('/api/v1/sessions/recorded', [
            'classId' => $this->class->id, 'title' => 'Week 7', 'publishAt' => now()->addDays(2)->toIso8601String(),
            'durationMin' => 45, 'uploadId' => $this->direct('week7.mp4'),
        ])->assertCreated()->json('session.id');
        $this->actingAs($this->tutor)->getJson('/api/v1/files')->assertJsonCount(1, 'data');

        $this->actingAs($this->tutor)->deleteJson("/api/v1/sessions/{$id}")->assertNoContent();

        $this->actingAs($this->tutor)->getJson('/api/v1/files')->assertJsonCount(0, 'data');
    }

    public function test_deleted_files_are_removed_from_disk_after_the_keep_period(): void
    {
        $upload = fn (string $name) => $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $this->direct($name)]],
        ])->json('0.id');
        $old = CourseFile::find($upload('old.pdf'));
        $recent = CourseFile::find($upload('recent.pdf'));

        $old->delete();
        $this->travel(31)->days();
        $recent->delete();
        $this->artisan('portal:purge-deleted-files')->assertSuccessful();

        $this->assertNull(CourseFile::withTrashed()->find($old->id));
        Storage::disk('local')->assertMissing($old->storage_path);
        $this->assertNotNull(CourseFile::withTrashed()->find($recent->id));
        Storage::disk('local')->assertExists($recent->storage_path);
    }

    public function test_ended_sessions_are_closed_when_the_list_loads_even_without_the_scheduler(): void
    {
        $ended = ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Last week', 'kind' => SessionKind::Live,
            'starts_at' => now()->subDays(2), 'duration_min' => 90, 'status' => SessionStatus::Upcoming,
        ]);
        $running = ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Now', 'kind' => SessionKind::Live,
            'starts_at' => now()->subMinutes(30), 'duration_min' => 90, 'status' => SessionStatus::Upcoming,
        ]);

        $this->actingAs($this->tutor)->getJson('/api/v1/sessions')->assertOk();

        $this->assertSame(SessionStatus::NotReported, $ended->fresh()->status);
        $this->assertSame(SessionStatus::Upcoming, $running->fresh()->status);
    }

    public function test_deciding_twice_is_refused(): void
    {
        $manager = User::factory()->manager()->create();
        $this->class->offering->course->program->update(['manager_id' => $manager->id]);
        $session = $this->heldSession(SessionStatus::PendingApproval);

        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertStatus(409);
    }
}
