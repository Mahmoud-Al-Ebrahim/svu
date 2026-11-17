<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $username = 't_'.Str::lower(fake()->unique()->lexify('??????'));

        return [
            'username' => $username,
            'email' => "{$username}@svuonline.org",
            'employee_id' => 'T-'.fake()->unique()->numerify('#####'),
            'password' => static::$password ??= Hash::make('Password1'),
            'name_ar' => 'د. '.fake()->firstName(),
            'name_en' => 'Dr. '.fake()->name(),
            'rank_ar' => 'مدرّس',
            'rank_en' => 'Lecturer',
            'role' => Role::Tutor,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => Role::ProgramManager]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }
}
