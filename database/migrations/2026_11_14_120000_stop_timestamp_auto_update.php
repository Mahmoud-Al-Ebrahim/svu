<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MariaDB 10.4 (XAMPP) and MySQL 5.7 run with explicit_defaults_for_timestamp=OFF, which turns the first NOT NULL
// TIMESTAMP column of a table into "DEFAULT current_timestamp() ON UPDATE current_timestamp()". Every save then
// overwrote these columns with the current time (a session's start time moved to the moment it was reported or
// approved). DATETIME columns never get that behaviour.
return new class extends Migration
{
    private const COLUMNS = [
        'class_sessions' => 'starts_at',
        'uploads' => 'expires_at',
        'password_reset_codes' => 'expires_at',
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->dateTime($column)->change());
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->timestamp($column)->change());
        }
    }
};
