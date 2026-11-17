<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Program;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an account from the command line. On a fresh server (no demo seed) this is how the first admin is made;
 * from then on tutors register on the site and the admin approves them.
 */
class CreateUser extends Command
{
    protected $signature = 'portal:create-user
        {--role=admin : admin, program_manager or tutor}
        {--program=* : program code(s) a program manager reviews, e.g. --program=BIT}';

    protected $description = 'Create a portal account (use it to make the first admin)';

    public function handle(): int
    {
        $data = [
            'role' => $this->option('role'),
            'username' => $this->ask('Username'),
            'email' => $this->ask('E-mail'),
            'employee_id' => $this->ask('Employee ID'),
            'name_ar' => $this->ask('Full name (Arabic)'),
            'name_en' => $this->ask('Full name (English)'),
            'password' => $this->secret('Password (8+ characters, upper and lower case, a number)'),
        ];
        $data['password_confirmation'] = $this->secret('Repeat password');

        $v = Validator::make($data, [
            'role' => ['required', Rule::enum(Role::class)],
            'username' => ['required', 'string', 'max:60', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'unique:users,email'],
            'employee_id' => ['required', 'string', 'max:30', 'unique:users,employee_id'],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->numbers()->mixedCase()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create(collect($data)->except('password_confirmation')->all());

        $codes = (array) $this->option('program');
        if ($codes && $user->role === Role::ProgramManager) {
            $updated = Program::whereIn('code', $codes)->update(['manager_id' => $user->id]);
            $this->line("Assigned as manager of {$updated} program(s).");
        }

        $this->info("Created {$user->role->value} account '{$user->username}'. Sign in at ".config('app.url').'/login');

        return self::SUCCESS;
    }
}
