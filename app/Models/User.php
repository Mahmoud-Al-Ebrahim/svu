<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const DEFAULT_PREFERENCES = [
        'locale' => 'ar',
        'theme' => 'light',
        'notifySessions' => true,
        'notifyExams' => true,
        'notifyFinance' => true,
    ];

    protected $fillable = [
        'username', 'email', 'employee_id', 'password', 'name_ar', 'name_en', 'rank_ar', 'rank_en', 'phone',
        'role', 'status', 'preferences', 'last_login_at', 'last_login_ip', 'previous_login_at', 'previous_login_ip',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'preferences' => 'array',
            'last_login_at' => 'datetime',
            'previous_login_at' => 'datetime',
        ];
    }

    public function classes(): HasMany
    {
        return $this->hasMany(CourseClass::class, 'tutor_id');
    }

    public function preference(string $key): mixed
    {
        return ($this->preferences ?? [])[$key] ?? self::DEFAULT_PREFERENCES[$key] ?? null;
    }

    /** SVU usernames are the part of the university e-mail before the @ (t_name); a number is added if taken. */
    public static function usernameFor(string $email): string
    {
        $base = strtolower(strstr($email, '@', true) ?: $email);
        $name = $base;
        for ($n = 2; self::where('username', $name)->exists(); $n++) {
            $name = "{$base}{$n}";
        }

        return $name;
    }

    public function teaches(CourseClass|int|null $class): bool
    {
        $id = $class instanceof CourseClass ? $class->tutor_id : CourseClass::whereKey($class)->value('tutor_id');

        return $id !== null && (int) $id === $this->id;
    }

    public function isManager(): bool
    {
        return in_array($this->role, [Role::ProgramManager, Role::Admin], true);
    }
}
