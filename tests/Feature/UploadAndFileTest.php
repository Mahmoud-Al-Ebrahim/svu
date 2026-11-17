<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Features 3 and 4: chunked uploads, course files, session recordings. */
class UploadAndFileTest extends TestCase
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

    /** Uploads $content in 4-byte chunks and returns the upload id. */
    private function upload(string $name, string $content): string
    {
        $id = $this->actingAs($this->tutor)->postJson('/api/v1/uploads', ['fileName' => $name, 'size' => strlen($content)])
            ->assertCreated()->assertJsonPath('chunkSize', 4)->json('uploadId');
        foreach (str_split($content, 4) as $n => $chunk) {
            $this->actingAs($this->tutor)->call('PUT', "/api/v1/uploads/{$id}/chunks/{$n}", [], [], [], ['HTTP_ACCEPT' => 'application/json'], $chunk)->assertOk();
        }
        $this->actingAs($this->tutor)->postJson("/api/v1/uploads/{$id}/complete")->assertOk()->assertJsonPath('status', 'complete');

        return $id;
    }

    public function test_chunks_must_arrive_in_order_and_complete_requires_all_bytes(): void
    {
        $id = $this->actingAs($this->tutor)->postJson('/api/v1/uploads', ['fileName' => 'a.pdf', 'size' => 10])->json('uploadId');

        $this->actingAs($this->tutor)->call('PUT', "/api/v1/uploads/{$id}/chunks/1", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'abcd')->assertStatus(409);
        $this->actingAs($this->tutor)->call('PUT', "/api/v1/uploads/{$id}/chunks/0", [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'abcd')->assertOk();
        $this->actingAs($this->tutor)->postJson("/api/v1/uploads/{$id}/complete")->assertStatus(422);
        // Resuming: the server says which chunk comes next.
        $this->actingAs($this->tutor)->getJson("/api/v1/uploads/{$id}")->assertJsonPath('nextChunk', 1);
        // Someone else's upload is invisible.
        $this->actingAs(User::factory()->create())->getJson("/api/v1/uploads/{$id}")->assertNotFound();
    }

    public function test_upload_share_list_download_and_delete_a_file(): void
    {
        $uploadId = $this->upload('week6 notes.pdf', 'hello students');

        $file = $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id,
            'classId' => $this->class->id,
            'items' => [['uploadId' => $uploadId, 'title' => 'Week 6 notes']],
        ])->assertCreated()->assertJsonPath('0.kind', 'pdf')->assertJsonPath('0.classCode', 'C1')->json('0');

        // The same upload can't be used twice.
        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $uploadId]],
        ])->assertJsonValidationErrors('items.0.uploadId');

        $this->actingAs($this->tutor)->getJson('/api/v1/files?class='.$this->class->id)->assertOk()->assertJsonCount(1, 'data');

        $signed = $this->actingAs($this->tutor)->get("/api/v1/files/{$file['id']}/download")->assertRedirect()->headers->get('Location');
        $this->get($signed)->assertOk()->assertDownload('week6 notes.pdf');
        $this->get(preg_replace('/signature=\w+/', 'signature=bad', $signed))->assertForbidden();
        $this->actingAs($this->tutor)->getJson('/api/v1/files')->assertJsonPath('data.0.downloads', 1);

        $this->actingAs($this->tutor)->patchJson("/api/v1/files/{$file['id']}", ['visible' => false])->assertJsonPath('visible', false);
        $this->actingAs(User::factory()->create())->deleteJson("/api/v1/files/{$file['id']}")->assertForbidden();
        $this->actingAs($this->tutor)->deleteJson("/api/v1/files/{$file['id']}")->assertNoContent();
    }

    public function test_rejects_disallowed_types_and_other_tutors_classes(): void
    {
        $exe = $this->upload('virus.exe', 'MZ..');
        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $exe]],
        ])->assertJsonValidationErrors('items.0.uploadId');

        $foreign = $this->classFor(User::factory()->create());
        $pdf = $this->upload('a.pdf', 'pdf!');
        $this->actingAs($this->tutor)->postJson('/api/v1/files', [
            'offeringId' => $foreign->offering_id, 'items' => [['uploadId' => $pdf]],
        ])->assertForbidden();
    }

    public function test_direct_upload_for_small_files(): void
    {
        $id = $this->actingAs($this->tutor)->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create('slides.pptx', 20)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'complete')->json('uploadId');

        $this->actingAs($this->tutor)->postJson('/api/v1/files', ['offeringId' => $this->class->offering_id, 'items' => [['uploadId' => $id]]])
            ->assertCreated()->assertJsonPath('0.kind', 'slides')->assertJsonPath('0.classId', null);
    }

    public function test_upload_a_new_recorded_session(): void
    {
        $id = $this->upload('lecture.mp4', 'videobytes');

        $this->actingAs($this->tutor)->postJson('/api/v1/sessions/recorded', [
            'classId' => $this->class->id, 'title' => 'Knowledge representation',
            'publishAt' => now()->subMinute()->toIso8601String(), 'durationMin' => 45, 'uploadId' => $id,
        ])->assertCreated()
            ->assertJsonPath('session.kind', 'recorded')
            ->assertJsonPath('session.status', 'notReported')
            ->assertJsonPath('session.filesCount', 1);
    }

    public function test_attach_a_recording_to_a_held_live_session_once(): void
    {
        $session = ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Indexes', 'kind' => SessionKind::Live,
            'starts_at' => now()->subDay(), 'duration_min' => 90, 'status' => SessionStatus::NotReported,
        ]);
        $notVideo = $this->upload('notes.pdf', 'pdf!');
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/recording", ['uploadId' => $notVideo])
            ->assertJsonValidationErrors('uploadId');

        $video = $this->upload('rec.lrec', 'recording');
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/recording", ['uploadId' => $video])->assertCreated();

        $again = $this->upload('rec2.lrec', 'recording');
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/recording", ['uploadId' => $again])->assertStatus(409);
    }
}
