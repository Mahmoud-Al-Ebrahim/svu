<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/** A frontend hosted on another address signs in with a token instead of the session cookie. */
class TokenAuthTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private function fromElsewhere(?string $token = null): static
    {
        // An origin that is not one of Sanctum's stateful domains: no session, as for a separately hosted SPA.
        $headers = ['Origin' => 'https://svu-portal.netlify.app', 'Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = "Bearer {$token}";
        }
        $this->app['auth']->forgetGuards(); // each call is a fresh request, as in the browser

        return $this->withHeaders($headers);
    }

    public function test_sign_in_use_the_api_and_sign_out_with_a_token(): void
    {
        $user = User::factory()->create(['username' => 't_reem', 'password' => 'Secret123']);

        $this->fromElsewhere()->postJson('/api/v1/auth/login', ['login' => 't_reem', 'password' => 'wrong'])->assertJsonValidationErrors('login');
        $token = $this->fromElsewhere()->postJson('/api/v1/auth/login', ['login' => 't_reem', 'password' => 'Secret123'])
            ->assertOk()->assertJsonPath('username', 't_reem')->json('token');
        $this->assertNotEmpty($token);

        $this->fromElsewhere()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->fromElsewhere($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('username', 't_reem');

        $this->fromElsewhere($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
        $this->fromElsewhere($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_downloads_work_without_cookies(): void
    {
        Storage::fake('local');
        $tutor = User::factory()->create(['password' => 'Secret123']);
        $class = $this->classFor($tutor);
        $token = $tutor->issueToken();
        $upload = $this->fromElsewhere($token)
            ->post('/api/v1/uploads/direct', ['file' => UploadedFile::fake()->create('notes.pdf', 1)])->json('uploadId');
        $id = $this->fromElsewhere($token)
            ->postJson('/api/v1/files', ['offeringId' => $class->offering_id, 'items' => [['uploadId' => $upload]]])->json('0.id');

        $url = $this->fromElsewhere($token)->getJson("/api/v1/files/{$id}/download")->assertOk()->json('url');

        $this->get($url)->assertOk()->assertDownload('notes.pdf');
    }

    public function test_changing_the_password_keeps_this_token_and_ends_the_others(): void
    {
        $user = User::factory()->create(['password' => 'Secret123']);
        $current = $user->issueToken();
        $user->issueToken(); // another device

        $this->fromElsewhere($current)->putJson('/api/v1/me/password', [
            'currentPassword' => 'Secret123', 'password' => 'Newer1234', 'password_confirmation' => 'Newer1234',
        ])->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());
        $this->fromElsewhere($current)->getJson('/api/v1/auth/me')->assertOk();
    }
}
