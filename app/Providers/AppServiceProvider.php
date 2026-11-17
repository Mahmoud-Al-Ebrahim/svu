<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\CourseClass;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // A tutor may only act on classes assigned to them.
        Gate::define('teach', fn (User $user, CourseClass $class) => $user->teaches($class));

        Gate::define('manage', fn (User $user) => $user->isManager());
        Gate::define('admin', fn (User $user) => $user->role === Role::Admin);

        // Program managers review sessions and exams of the programs they manage; admins review everything.
        Gate::define('review', function (User $user, CourseClass $class) {
            if ($user->role === Role::Admin) {
                return true;
            }
            $class->loadMissing('offering.course.program');

            return $user->role === Role::ProgramManager && $class->offering->course->program->manager_id === $user->id;
        });

        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) $r->input('login')).'|'.$r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(3)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('emails', fn (Request $r) => Limit::perHour(config('portal.email.per_hour'))->by((string) $r->user()?->id));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(600)->by((string) $r->user()?->id));
    }
}
