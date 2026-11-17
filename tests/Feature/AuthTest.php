<?php

namespace Tests\Feature;

use App\Mail\PasswordResetCodeMail;
use App\Models\AccountRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Accept' => 'application/json']);
    }

    public function test_login_with_username_returns_the_previous_login_and_starts_a_session(): void
    {
        $user = User::factory()->create(['username' => 't_samer', 'last_login_ip' => '10.0.0.1']);

        $this->spa()->postJson('/api/v1/auth/login', ['login' => 't_samer', 'password' => 'Password1'])
            ->assertOk()
            ->assertJsonPath('username', 't_samer')
            ->assertJsonPath('group', 'TU')
            ->assertJsonPath('lastIp', '10.0.0.1');

        $this->spa()->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', (string) $user->id);
        $this->assertNotSame('10.0.0.1', $user->fresh()->last_login_ip);
    }

    public function test_login_accepts_email_and_rejects_wrong_password_in_arabic(): void
    {
        User::factory()->create(['email' => 't_x@svuonline.org']);

        $this->spa()->postJson('/api/v1/auth/login', ['login' => 't_x@svuonline.org', 'password' => 'Password1'])->assertOk();
        $this->spa()->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/auth/login', ['login' => 't_x@svuonline.org', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('errors.login.0', 'اسم المستخدم أو كلمة المرور غير صحيحة.');
    }

    public function test_suspended_accounts_cannot_log_in(): void
    {
        User::factory()->create(['username' => 't_off', 'status' => 'suspended']);

        $this->spa()->postJson('/api/v1/auth/login', ['login' => 't_off', 'password' => 'Password1'])->assertStatus(422);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/sessions')->assertUnauthorized();
    }

    public function test_forgot_password_flow_with_code_and_reset_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 't_r@svuonline.org']);

        $this->postJson('/api/v1/auth/forgot', ['email' => 't_r@svuonline.org'])->assertStatus(202);
        $code = null;
        Mail::assertSent(PasswordResetCodeMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->postJson('/api/v1/auth/forgot/verify', ['email' => 't_r@svuonline.org', 'code' => '000000'])->assertStatus(422);
        $token = $this->postJson('/api/v1/auth/forgot/verify', ['email' => 't_r@svuonline.org', 'code' => $code])
            ->assertOk()->json('resetToken');

        $this->postJson('/api/v1/auth/reset', ['resetToken' => $token, 'password' => 'weak'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset', ['resetToken' => $token, 'password' => 'NewPass123'])->assertNoContent();
        $this->spa()->postJson('/api/v1/auth/login', ['login' => $user->username, 'password' => 'NewPass123'])->assertOk();
        // The token is single-use.
        $this->postJson('/api/v1/auth/reset', ['resetToken' => $token, 'password' => 'Another123'])->assertStatus(422);
    }

    public function test_forgot_password_does_not_reveal_unknown_addresses(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/forgot', ['email' => 'nobody@svuonline.org'])->assertStatus(202);
        Mail::assertNothingSent();
    }

    public function test_account_request_requires_university_email_and_strong_password(): void
    {
        config(['portal.signup.instant' => false]); // the approval flow
        $this->catalog();
        $valid = [
            'nameAr' => 'د. علي', 'nameEn' => 'Dr. Ali', 'employeeId' => 'T-99999', 'email' => 't_ali@svuonline.org',
            'program' => 'BIT', 'rank' => 'Lecturer', 'specialty' => 'Databases', 'courses' => 'ADB',
            'password' => 'Password1', 'password_confirmation' => 'Password1', 'agree' => true,
        ];

        $this->postJson('/api/v1/account-requests', ['email' => 'ali@gmail.com'] + $valid)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/account-requests', ['password' => 'short', 'password_confirmation' => 'short'] + $valid)->assertJsonValidationErrors('password');
        $ref = $this->postJson('/api/v1/account-requests', $valid)->assertCreated()->json('ref');

        $this->assertStringStartsWith('FAC-', $ref);
        $this->assertSame('pending', AccountRequest::firstWhere('ref', $ref)->status);
    }

    public function test_preferences_are_merged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/me/preferences', ['theme' => 'dark', 'notifyExams' => false])
            ->assertOk()
            ->assertJsonPath('preferences.theme', 'dark')
            ->assertJsonPath('preferences.notifyExams', false)
            ->assertJsonPath('preferences.notifySessions', true);
    }
}
