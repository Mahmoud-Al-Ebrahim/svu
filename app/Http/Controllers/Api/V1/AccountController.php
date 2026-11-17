<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AccountRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\DemoSandbox;
use App\Support\Sessions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Public sign-up (or account requests), and the signed-in user's own profile. */
class AccountController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        // Addresses are stored in lower case; compare them that way too, or "T_Reem@" would pass the unique check.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'nameAr' => ['required', 'string', 'max:120'],
            'nameEn' => ['required', 'string', 'max:120'],
            'employeeId' => ['required', 'string', 'max:30', Rule::unique('users', 'employee_id')],
            'email' => ['required', 'email', 'max:255', 'ends_with:@'.config('portal.email.domain'), Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'program' => ['required', Rule::exists('programs', 'code')],
            'rank' => ['required', 'string', 'max:60'],
            'specialty' => ['required', 'string', 'max:120'],
            'courses' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->numbers()->mixedCase()],
            'agree' => ['accepted'],
        ]);

        if (config('portal.signup.instant')) {
            return $this->signUp($request, $data);
        }

        do {
            $ref = 'FAC-'.now()->year.'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (AccountRequest::where('ref', $ref)->exists());
        $row = AccountRequest::create([
            'ref' => $ref,
            'name_ar' => $data['nameAr'],
            'name_en' => $data['nameEn'],
            'employee_id' => $data['employeeId'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
            'program_code' => $data['program'],
            'rank' => $data['rank'],
            'specialty' => $data['specialty'],
            'courses_text' => $data['courses'],
            'password_hash' => Hash::make($data['password']),
        ]);
        Audit::log('account.requested', $row);

        return response()->json(['ref' => $ref], 201);
    }

    /** Instant sign-up: an active Tutor account with its own demo classes, signed in straight away. */
    private function signUp(Request $request, array $data): JsonResponse
    {
        $user = DB::transaction(function () use ($data) {
            $email = strtolower($data['email']);
            $user = User::create([
                'username' => User::usernameFor($email),
                'email' => $email,
                'employee_id' => $data['employeeId'],
                'password' => $data['password'], // hashed by the model's cast
                'name_ar' => $data['nameAr'],
                'name_en' => $data['nameEn'],
                'rank_ar' => $data['rank'],
                'rank_en' => $data['rank'],
                'phone' => $data['phone'] ?? null,
                'role' => Role::Tutor,
            ]);
            DemoSandbox::provision($user);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Audit::log('account.signed_up', $user);

        return response()->json(['user' => new UserResource($user)], 201);
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'nameAr' => ['sometimes', 'string', 'max:120'],
            'nameEn' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        $request->user()->update(array_filter([
            'name_ar' => $data['nameAr'] ?? null,
            'name_en' => $data['nameEn'] ?? null,
        ]) + (array_key_exists('phone', $data) ? ['phone' => $data['phone']] : []));

        return new UserResource($request->user());
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->numbers()->mixedCase()],
        ]);
        if (! Hash::check($data['currentPassword'], $request->user()->password)) {
            throw ValidationException::withMessages(['currentPassword' => __('portal.auth.wrong_password')]);
        }
        $request->user()->update(['password' => $data['password']]);
        // Keep this browser signed in; sign out every other device.
        Sessions::signOutEverywhere($request->user(), except: $request->session()->getId());
        Audit::log('auth.password_changed', $request->user());

        return response()->json(null, 204);
    }

    public function preferences(Request $request): UserResource
    {
        $data = $request->validate([
            'locale' => ['sometimes', Rule::in(['ar', 'en'])],
            'theme' => ['sometimes', Rule::in(['light', 'dark'])],
            'notifySessions' => ['sometimes', 'boolean'],
            'notifyExams' => ['sometimes', 'boolean'],
            'notifyFinance' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $user->update(['preferences' => array_merge(User::DEFAULT_PREFERENCES, $user->preferences ?? [], $data)]);

        return new UserResource($user);
    }
}
