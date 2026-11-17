<?php

namespace Tests\Concerns;

use App\Models\Course;
use App\Models\CourseClass;
use App\Models\CourseOffering;
use App\Models\ExamCenter;
use App\Models\Program;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;

/** Minimal catalog for feature tests: one term, one program, one course offering with classes. */
trait BuildsCatalog
{
    protected function catalog(?User $manager = null): CourseOffering
    {
        Term::firstOrCreate(['code' => 'F26'], ['name_ar' => 'خريف 2026', 'name_en' => 'Fall 2026', 'starts_on' => '2026-09-14', 'ends_on' => '2027-01-31', 'is_current' => true]);
        $program = Program::firstOrCreate(['code' => 'BIT'], ['name_ar' => 'تقانة المعلومات', 'name_en' => 'Information Technology']);
        if ($manager) {
            $program->update(['manager_id' => $manager->id]);
        }
        $course = Course::create(['program_code' => 'BIT', 'code' => 'C'.random_int(100, 999), 'name_ar' => 'مقرر', 'name_en' => 'Course']);

        return CourseOffering::create(['course_id' => $course->id, 'term_code' => 'F26', 'hue' => 205]);
    }

    protected function classFor(User $tutor, ?CourseOffering $offering = null, string $code = 'C1', int $students = 3): CourseClass
    {
        $class = CourseClass::create(['offering_id' => ($offering ?? $this->catalog())->id, 'code' => $code, 'tutor_id' => $tutor->id]);
        $class->students()->attach(Student::factory()->count($students)->create()->modelKeys());

        return $class;
    }

    protected function examCenter(): ExamCenter
    {
        return ExamCenter::firstOrCreate(['code' => 'online'], ['name_ar' => 'عن بعد', 'name_en' => 'Online', 'is_online' => true]);
    }
}
