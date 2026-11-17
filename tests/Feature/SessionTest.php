<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionStatus;
use App\Models\ClassSession;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Feature 2: schedule a session, and the finance-report status flow. */
class SessionTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $tutor;

    private CourseClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tutor = User::factory()->create();
        $this->class = $this->classFor($this->tutor);
    }

    private function schedule(array $overrides = [])
    {
        return $this->actingAs($this->tutor)->postJson('/api/v1/sessions', $overrides + [
            'classId' => $this->class->id,
            'title' => 'Query optimisation',
            'startsAt' => now()->addDay()->setTime(18, 0)->toIso8601String(),
            'durationMin' => 90,
        ]);
    }

    public function test_schedules_a_live_session(): void
    {
        $this->schedule()
            ->assertCreated()
            ->assertJsonPath('session.status', 'upcoming')
            ->assertJsonPath('session.kind', 'live')
            ->assertJsonPath('session.classCode', 'C1')
            ->assertJsonPath('warnings', []);

        $this->actingAs($this->tutor)->getJson('/api/v1/sessions?class='.$this->class->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_rejects_past_times_bad_durations_and_clashes_in_the_same_class(): void
    {
        $this->schedule(['startsAt' => now()->subHour()->toIso8601String()])->assertJsonValidationErrors('startsAt');
        $this->schedule(['durationMin' => 5])->assertJsonValidationErrors('durationMin');

        $this->schedule()->assertCreated();
        $this->schedule(['startsAt' => now()->addDay()->setTime(19, 0)->toIso8601String()])->assertJsonValidationErrors('startsAt');
        // Right after the first one ends is fine.
        $this->schedule(['startsAt' => now()->addDay()->setTime(19, 30)->toIso8601String()])->assertCreated();
    }

    public function test_warns_about_my_sessions_in_other_classes_at_the_same_time(): void
    {
        $other = $this->classFor($this->tutor, code: 'C2');
        $this->schedule(['classId' => $other->id])->assertCreated();

        $this->schedule()->assertCreated()->assertJsonCount(1, 'warnings');
    }

    public function test_cannot_schedule_for_someone_elses_class(): void
    {
        $foreign = $this->classFor(User::factory()->create());

        $this->schedule(['classId' => $foreign->id])->assertForbidden();
    }

    public function test_upcoming_sessions_can_be_edited_and_cancelled_but_held_ones_cannot(): void
    {
        $id = $this->schedule()->json('session.id');
        $this->actingAs($this->tutor)->patchJson("/api/v1/sessions/{$id}", ['title' => 'Renamed'])->assertOk()->assertJsonPath('title', 'Renamed');

        $held = $this->heldSession();
        $this->actingAs($this->tutor)->patchJson("/api/v1/sessions/{$held->id}", ['title' => 'x'])->assertStatus(409);
        $this->actingAs($this->tutor)->deleteJson("/api/v1/sessions/{$held->id}")->assertStatus(409);
        $this->actingAs($this->tutor)->deleteJson("/api/v1/sessions/{$id}")->assertNoContent();
    }

    public function test_scheduler_closes_ended_sessions(): void
    {
        $s = ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Done', 'kind' => SessionKind::Live,
            'starts_at' => now()->subHours(3), 'duration_min' => 90, 'status' => SessionStatus::Upcoming,
        ]);
        $running = ClassSession::create([
            'class_id' => $this->class->id, 'title' => 'Running', 'kind' => SessionKind::Live,
            'starts_at' => now()->subMinutes(30), 'duration_min' => 90, 'status' => SessionStatus::Upcoming,
        ]);

        $this->artisan('portal:close-finished')->assertSuccessful();

        $this->assertSame(SessionStatus::NotReported, $s->fresh()->status);
        $this->assertSame(SessionStatus::Upcoming, $running->fresh()->status);
    }

    public function test_finance_report_flow_through_the_program_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $offering = $this->catalog($manager);
        $class = $this->classFor($this->tutor, $offering);
        $session = $this->heldSession($class);

        // Reporting an upcoming session is a conflict.
        $upcoming = $this->schedule(['classId' => $class->id])->json('session.id');
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$upcoming}/report")->assertStatus(409);

        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/report")->assertOk()->assertJsonPath('status', 'pendingApproval');
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/report")->assertStatus(409);

        // Tutors can't reach the review endpoints; another program's manager can't decide.
        $this->actingAs($this->tutor)->getJson('/api/v1/manage/sessions')->assertForbidden();
        $this->actingAs(User::factory()->manager()->create())->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertForbidden();

        $this->actingAs($manager)->getJson('/api/v1/manage/sessions')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/reject")->assertJsonValidationErrors('note');
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/reject", ['note' => 'Fix attendance'])
            ->assertOk()->assertJsonPath('status', 'rejected')->assertJsonPath('reviewNote', 'Fix attendance');

        // A rejected session can be corrected and reported again.
        $this->actingAs($this->tutor)->postJson("/api/v1/sessions/{$session->id}/report")->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/pay")->assertStatus(409);
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/approve")->assertJsonPath('status', 'approvedUnpaid');
        $this->actingAs($manager)->postJson("/api/v1/manage/sessions/{$session->id}/pay")->assertJsonPath('status', 'paid');
    }

    private function heldSession(?CourseClass $class = null): ClassSession
    {
        return ClassSession::create([
            'class_id' => ($class ?? $this->class)->id, 'title' => 'Indexes', 'kind' => SessionKind::Live,
            'starts_at' => now()->subDays(2), 'duration_min' => 90, 'status' => SessionStatus::NotReported, 'attendance_count' => 30,
        ]);
    }
}
