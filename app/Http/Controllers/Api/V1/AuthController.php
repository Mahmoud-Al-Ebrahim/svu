<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $field = str_contains($data['login'], '@') ? 'email' : 'username';
        $guard = Auth::guard('web');
        $credentials = [$field => $field === 'email' ? strtolower($data['login']) : $data['login'], 'password' => $data['password'], 'status' => 'active'];
        if (! $guard->validate($credentials)) {
            throw ValidationException::withMessages(['login' => __('portal.auth.failed')]);
        }
        /** @var User $user */
        $user = $guard->getLastAttempted();
        $remember = $data['remember'] ?? false;

        // Same address as the API (Laravel serves the SPA, or the Vite proxy): a session cookie.
        // Another address (frontend hosted separately): no session, the response carries a token instead.
        if ($request->hasSession()) {
            $guard->login($user, $remember);
            $request->session()->regenerate();
        }
        $user->forceFill([
            'previous_login_at' => $user->last_login_at,
            'previous_login_ip' => $user->last_login_ip,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();
        Audit::log('auth.login', $user);

        // The user as /auth/me returns it; a frontend on another address also gets its token.
        $token = $request->hasSession() ? null : $user->issueToken($remember);

        return response()->json((new UserResource($user))->resolve($request) + ['token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Audit::log('auth.logout', $user);
        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Step 1: e-mail a six-digit code. Always answers 202 so the endpoint can't be used to probe accounts. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'ends_with:@'.config('portal.email.domain')]]);
        $user = User::where('email', $data['email'])->where('status', 'active')->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);
            PasswordResetCode::where('email', $user->email)->delete();
            PasswordResetCode::create([
                'email' => $user->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(PasswordResetCode::TTL_MINUTES),
            ]);
            Mail::to($user->email)->send(new PasswordResetCodeMail($user, $code));
        }

        return response()->json(['message' => __('portal.auth.code_sent')], 202);
    }

    /** Step 2: exchange the code for a one-time reset token. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);
        $row = PasswordResetCode::where('email', $data['email'])->latest('id')->first();

        if (! $row || $row->expires_at->isPast() || $row->attempts >= PasswordResetCode::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => __('portal.auth.code_expired')]);
        }
        if (! Hash::check($data['code'], $row->code_hash)) {
            $row->increment('attempts');
            throw ValidationException::withMessages(['code' => __('portal.auth.code_invalid')]);
        }

        $token = Str::random(64);
        $row->update(['reset_token_hash' => hash('sha256', $token), 'attempts' => PasswordResetCode::MAX_ATTEMPTS]);

        return response()->json(['resetToken' => $token]);
    }

    /** Step 3: set the new password. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resetToken' => ['required', 'string'],
            'password' => ['required', 'string', Password::min(8)->numbers()->mixedCase()],
        ]);
        $row = PasswordResetCode::where('reset_token_hash', hash('sha256', $data['resetToken']))
            ->where('expires_at', '>', now())
            ->first();
        if (! $row) {
            throw ValidationException::withMessages(['resetToken' => __('portal.auth.code_expired')]);
        }

        $user = User::where('email', $row->email)->firstOrFail();
        $user->update(['password' => $data['password']]);
        Sessions::signOutEverywhere($user);
        PasswordResetCode::where('email', $row->email)->delete();
        Audit::log('auth.password_reset', $user);

        return response()->json(null, 204);
    }
}
