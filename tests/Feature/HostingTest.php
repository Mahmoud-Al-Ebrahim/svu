<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_api_urls_stay_json_404s(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->get('/sanctum/nothing')->assertNotFound();
    }

    public function test_page_urls_return_the_web_app_when_built(): void
    {
        if (! is_file(public_path('spa/index.html'))) {
            $this->get('/app/sessions')->assertStatus(503);

            return;
        }
        $this->get('/app/sessions')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->get('/login')->assertOk();
    }

    public function test_create_user_command_makes_a_working_admin(): void
    {
        $this->artisan('portal:create-user')
            ->expectsQuestion('Username', 'root_admin')
            ->expectsQuestion('E-mail', 'admin@example.org')
            ->expectsQuestion('Employee ID', 'A-1')
            ->expectsQuestion('Full name (Arabic)', 'مدير النظام')
            ->expectsQuestion('Full name (English)', 'System Admin')
            ->expectsQuestion('Password (8+ characters, upper and lower case, a number)', 'Secret123')
            ->expectsQuestion('Repeat password', 'Secret123')
            ->assertSuccessful();

        $admin = User::where('username', 'root_admin')->firstOrFail();
        $this->assertSame(Role::Admin, $admin->role);

        // Signing in from the app opened at a LAN address works: requests from the host the API itself runs on get
        // session (cookie) authentication without listing that address anywhere.
        $this->withHeader('Referer', 'http://192.168.1.20:8000/login')
            ->postJson('http://192.168.1.20:8000/api/v1/auth/login', ['login' => 'root_admin', 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('role', 'admin');
    }

    public function test_create_user_command_rejects_weak_passwords_and_links_managers(): void
    {
        $this->artisan('portal:create-user')
            ->expectsQuestion('Username', 'weak')
            ->expectsQuestion('E-mail', 'weak@example.org')
            ->expectsQuestion('Employee ID', 'W-1')
            ->expectsQuestion('Full name (Arabic)', 'ض')
            ->expectsQuestion('Full name (English)', 'W')
            ->expectsQuestion('Password (8+ characters, upper and lower case, a number)', 'short')
            ->expectsQuestion('Repeat password', 'short')
            ->assertFailed();
        $this->assertDatabaseMissing('users', ['username' => 'weak']);

        $program = Program::create(['code' => 'BIT', 'name_ar' => 'ب', 'name_en' => 'B']);
        $this->artisan('portal:create-user', ['--role' => 'program_manager', '--program' => ['BIT']])
            ->expectsQuestion('Username', 'm_new')
            ->expectsQuestion('E-mail', 'm_new@example.org')
            ->expectsQuestion('Employee ID', 'M-9')
            ->expectsQuestion('Full name (Arabic)', 'م')
            ->expectsQuestion('Full name (English)', 'M')
            ->expectsQuestion('Password (8+ characters, upper and lower case, a number)', 'Secret123')
            ->expectsQuestion('Repeat password', 'Secret123')
            ->assertSuccessful();
        $this->assertSame(User::where('username', 'm_new')->value('id'), $program->fresh()->manager_id);
    }
}
