<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** Feature 1: picking a course and class. */
class CatalogTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    public function test_my_offerings_lists_only_my_classes_with_student_counts(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $offering = $this->catalog();
        $this->classFor($me, $offering, 'C1', 4);
        $this->classFor($other, $offering, 'C2', 2);
        $this->classFor($other); // an offering I don't teach at all

        $res = $this->actingAs($me)->getJson('/api/v1/my/offerings?term=F26&program=BIT')->assertOk();

        $res->assertJsonCount(1)
            ->assertJsonPath('0.id', (string) $offering->id)
            ->assertJsonCount(1, '0.classes')
            ->assertJsonPath('0.classes.0.code', 'C1')
            ->assertJsonPath('0.classes.0.students', 4);
    }

    public function test_offering_page_is_forbidden_without_a_class_in_it(): void
    {
        $me = User::factory()->create();
        $class = $this->classFor(User::factory()->create());

        $this->actingAs($me)->getJson("/api/v1/offerings/{$class->offering_id}")->assertForbidden();
    }

    public function test_lookups(): void
    {
        $this->catalog();
        $this->examCenter();
        $me = User::factory()->create();

        $this->actingAs($me)->getJson('/api/v1/terms')->assertOk()->assertJsonPath('0.code', 'F26')->assertJsonPath('0.current', true);
        $this->actingAs($me)->getJson('/api/v1/programs')->assertOk()->assertJsonPath('0.code', 'BIT');
        $this->actingAs($me)->getJson('/api/v1/exam-centers')->assertOk()->assertJsonPath('0.online', true);
    }
}
