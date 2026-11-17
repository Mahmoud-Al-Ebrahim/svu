<?php

namespace Tests\Feature;

use App\Models\CourseClass;
use App\Models\CourseOffering;
use App\Models\ExamCenter;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Feature 5: schedule an exam. */
class ExamTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private User $tutor;

    private CourseOffering $offering;

    private CourseClass $c1;

    private CourseClass $c2;

    private ExamCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tutor = User::factory()->create();
        $this->offering = $this->catalog();
        $this->c1 = $this->classFor($this->tutor, $this->offering, 'C1');
        $this->c2 = $this->classFor($this->tutor, $this->offering, 'C2');
        $this->center = $this->examCenter();
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'offeringId' => $this->offering->id,
            'classIds' => [$this->c1->id, $this->c2->id],
            'type' => 'midterm',
            'date' => now(config('portal.timezone'))->addDays(10)->toDateString(),
            'start' => '10:00',
            'durationMin' => 90,
            'centerId' => $this->center->id,
        ];
    }

    public function test_schedules_one_pending_exam_per_class(): void
    {
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body())
            ->assertCreated()
            ->assertJsonCount(2, 'exams')
            ->assertJsonPath('exams.0.status', 'pending')
            ->assertJsonPath('exams.0.end', '11:30');

        $this->actingAs($this->tutor)->getJson('/api/v1/exams?term=F26&program=BIT')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_validates_date_time_window_and_classes(): void
    {
        $today = now(config('portal.timezone'))->toDateString();
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['date' => $today]))->assertJsonValidationErrors('date');
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['start' => '22:00']))->assertJsonValidationErrors('start');
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['durationMin' => 10]))->assertJsonValidationErrors('durationMin');

        $foreign = $this->classFor(User::factory()->create(), $this->offering, 'C3');
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['classIds' => [$foreign->id]]))->assertJsonValidationErrors('classIds');
    }

    public function test_overlap_is_refused_and_same_day_is_a_warning(): void
    {
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['classIds' => [$this->c1->id]]))->assertCreated();

        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['classIds' => [$this->c1->id], 'start' => '11:00']))
            ->assertJsonValidationErrors('start');

        $this->actingAs($this->tutor)->postJson('/api/v1/exams/check', $this->body(['classIds' => [$this->c1->id], 'start' => '14:00']))
            ->assertOk()->assertJsonCount(1, 'warnings');
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body(['classIds' => [$this->c1->id], 'start' => '14:00']))
            ->assertCreated()->assertJsonCount(1, 'warnings');
    }

    public function test_only_pending_exams_can_be_cancelled_and_approval_by_manager(): void
    {
        $manager = User::factory()->manager()->create();
        Program::whereKey('BIT')->update(['manager_id' => $manager->id]);
        [$a, $b] = $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body())->json('exams');

        $this->actingAs($manager)->postJson("/api/v1/manage/exams/{$a['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
        $this->actingAs($manager)->postJson("/api/v1/manage/exams/{$a['id']}/reject", ['note' => 'x'])->assertStatus(409);

        $this->actingAs($this->tutor)->deleteJson("/api/v1/exams/{$a['id']}")->assertStatus(409);
        $this->actingAs(User::factory()->create())->deleteJson("/api/v1/exams/{$b['id']}")->assertForbidden();
        $this->actingAs($this->tutor)->deleteJson("/api/v1/exams/{$b['id']}")->assertNoContent();
    }

    public function test_exports_csv(): void
    {
        $this->actingAs($this->tutor)->postJson('/api/v1/exams', $this->body())->assertCreated();

        $res = $this->actingAs($this->tutor)->get('/api/v1/exams/export?term=F26')->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1); // header + 2 rows
    }
}
