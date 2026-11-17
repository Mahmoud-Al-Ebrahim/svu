<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ends a user's browser sessions, e.g. after the password changes. */
class Sessions
{
    public static function signOutEverywhere(User $user, ?string $except = null): void
    {
        // "Remember me" cookies stop working once the token changes.
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($except, fn ($q) => $q->where('id', '!=', $except))
                ->delete();
        }
    }
}
