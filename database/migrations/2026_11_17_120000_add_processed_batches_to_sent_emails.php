<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lets the send job remember which BCC batches it has already handled, so a retry (after a crash or timeout)
// continues where it stopped instead of e-mailing the first students again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->unsignedInteger('processed_batches')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('sent_emails', function (Blueprint $table) {
            $table->dropColumn('processed_batches');
        });
    }
};
