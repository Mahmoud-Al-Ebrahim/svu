<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The welcome box shows the login *before* the current one (as in SVUIS), so both are kept.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('previous_login_at')->nullable()->after('last_login_ip');
            $table->string('previous_login_ip', 45)->nullable()->after('previous_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['previous_login_at', 'previous_login_ip']);
        });
    }
};
