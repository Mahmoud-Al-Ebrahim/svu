<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 30)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('employee_id', 30);
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('program_code', 10);
            $table->string('rank');
            $table->string('specialty');
            $table->string('courses_text');
            $table->string('password_hash');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->string('code', 10)->primary(); // F26 = Fall 2026, S26 = Spring 2026
            $table->string('name_ar');
            $table->string('name_en');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
        });

        Schema::create('programs', function (Blueprint $table) {
            $table->string('code', 10)->primary(); // BIT, ITE, MWS
            $table->string('name_ar');
            $table->string('name_en');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('program_code', 10);
            $table->foreign('program_code')->references('code')->on('programs')->cascadeOnUpdate();
            $table->string('code', 20);
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unique(['program_code', 'code']);
        });

        // A course given in a specific term (the frontend's `Course`).
        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('term_code', 10);
            $table->foreign('term_code')->references('code')->on('terms')->cascadeOnUpdate();
            $table->unsignedSmallInteger('hue')->default(205);
            $table->unique(['course_id', 'term_code']);
        });

        Schema::create('course_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained('course_offerings')->cascadeOnDelete();
            $table->string('code', 10); // C1, C2…
            $table->foreignId('tutor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['offering_id', 'code']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('university_id', 60)->unique(); // e.g. omar_148312
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('email')->unique();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->primary(['class_id', 'student_id']);
        });

        // Named class_sessions because `sessions` is Laravel's HTTP session table.
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind', 10); // live | recorded
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_min');
            $table->string('status', 20)->default('upcoming');
            $table->unsignedSmallInteger('attendance_count')->nullable();
            $table->boolean('notify_students')->default(true);
            $table->boolean('record_auto')->default(true);
            $table->string('meeting_url')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['class_id', 'starts_at']);
            $table->index('status');
        });

        // Chunked, resumable uploads. A finished upload is consumed by a file, recording or e-mail attachment.
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedInteger('chunk_size');
            $table->string('status', 20)->default('open'); // open | complete | consumed | aborted
            $table->string('storage_path')->nullable();
            $table->string('mime')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('course_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offering_id')->constrained('course_offerings')->cascadeOnDelete();
            $table->foreignId('class_id')->nullable()->constrained('course_classes')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('class_sessions')->nullOnDelete();
            $table->string('title');
            $table->string('original_name');
            $table->string('storage_disk', 20)->default('local');
            $table->string('storage_path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('kind', 10);
            $table->boolean('visible')->default(true);
            $table->unsignedInteger('download_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sha256', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['offering_id', 'class_id']);
        });

        Schema::create('exam_centers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_online')->default(false);
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('course_classes')->cascadeOnDelete();
            $table->string('type', 20); // midterm | final | practical | resit
            $table->date('date');
            $table->string('start_time', 5); // HH:mm, Damascus time
            $table->unsignedSmallInteger('duration_min');
            $table->foreignId('center_id')->constrained('exam_centers');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
            $table->index(['class_id', 'date']);
        });

        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_type', 10); // class | student
            $table->foreignId('class_id')->nullable()->constrained('course_classes')->nullOnDelete();
            $table->string('student_address')->nullable();
            $table->string('subject', 200);
            $table->text('body');
            $table->boolean('cc_sender')->default(true);
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained('sent_emails')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->unsignedBigInteger('size_bytes');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_logs', 'email_attachments', 'sent_emails', 'exams', 'exam_centers', 'course_files', 'uploads',
            'class_sessions', 'enrollments', 'students', 'course_classes', 'course_offerings', 'courses', 'programs',
            'terms', 'account_requests',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
