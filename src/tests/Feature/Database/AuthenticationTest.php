<?php

namespace Tests\Feature\Database;

use App\Models\SheetSource;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsClient;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\DatabaseTestCase;

class AuthenticationTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registration_creates_pending_user_and_ignores_privilege_payload(): void
    {
        $this->get('/register')->assertOk();
        $this->post('/register', ['name' => 'New User', 'email' => 'new@example.com', 'password' => 'StrongTestPassword123!', 'password_confirmation' => 'StrongTestPassword123!', 'is_admin' => true, 'approved_at' => now()->toDateTimeString(), 'approved_by' => 1])
            ->assertRedirect('/approval-pending');
        $user = User::where('email', 'new@example.com')->sole();
        $this->assertFalse($user->is_admin);
        $this->assertNull($user->approved_at);
        $this->assertNull($user->approved_by);
        $this->assertAuthenticatedAs($user);
    }

    public function test_pending_user_cannot_read_panel_or_invoke_google_or_dispatch(): void
    {
        $user = User::factory()->create();
        $source = SheetSource::factory()->create();
        $client = Mockery::mock(GoogleSheetsClient::class);
        $client->shouldNotReceive('getSheetTitles');
        $this->app->instance(GoogleSheetsClient::class, $client);
        $this->actingAs($user)->get('/approval-pending')->assertOk();
        foreach (['/sources', '/tabs', '/runs', '/admin/users'] as $url) {
            $this->get($url)->assertRedirect('/approval-pending');
        }
        $this->post('/sources/'.$source->id.'/check')->assertRedirect('/approval-pending');
        $this->postJson('/sync', [])->assertForbidden();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('sync_runs', 0);
    }

    public function test_admin_can_approve_idempotently_and_non_admin_cannot_manage_users(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee($user->email);
        $this->post('/admin/users/'.$user->id.'/approve')->assertRedirect();
        $this->assertNotNull($user->fresh()->approved_at);
        $this->assertSame($admin->id, $user->fresh()->approved_by);
        $approved = $user->fresh()->approved_at->toDateTimeString();
        $this->travel(1)->minutes();
        $this->post('/admin/users/'.$user->id.'/approve')->assertRedirect();
        $this->assertSame($approved, $user->fresh()->approved_at->toDateTimeString());
        $this->actingAs($user)->get('/sources')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->post('/admin/users/'.$admin->id.'/approve')->assertForbidden();
    }

    public function test_revocation_blocks_existing_session_and_admin_cannot_revoke_admin(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['approved_at' => now(), 'approved_by' => $admin->id]);
        $this->actingAs($admin)->post('/admin/users/'.$user->id.'/revoke')->assertRedirect();
        $this->assertNull($user->fresh()->approved_at);
        $this->actingAs($user)->get('/sources')->assertRedirect('/approval-pending');
        $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/revoke')->assertForbidden();
    }

    public function test_login_allows_pending_and_approved_users_and_logout_invalidates_auth(): void
    {
        $user = User::factory()->create(['password' => 'StrongTestPassword123!']);
        $this->post('/login', ['email' => $user->email, 'password' => 'StrongTestPassword123!'])->assertRedirect('/approval-pending');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/sources')->assertRedirect('/login');
    }

    public function test_registration_requires_confirmation_and_unique_email(): void
    {
        $user = User::factory()->create();
        $this->post('/register', ['name' => 'Another', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_seeder_is_repeatable_preserves_password_and_bootstraps_approval(): void
    {
        config(['admin.name' => 'Test Admin', 'admin.email' => 'admin@example.com', 'admin.password' => 'StrongTestPassword123!']);
        $this->seed(AdminSeeder::class);
        $admin = User::sole();
        $this->assertTrue($admin->is_admin);
        $this->assertNotNull($admin->approved_at);
        $this->assertTrue(Hash::check('StrongTestPassword123!', $admin->password));
        config(['admin.password' => 'DifferentStrongPassword123!']);
        $this->seed(AdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($admin->password, $admin->fresh()->password);
    }

    public function test_admin_seeder_requires_explicit_credentials(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);
        $this->expectException(\RuntimeException::class);
        $this->seed(AdminSeeder::class);
    }

    public function test_login_throttling_blocks_correct_credentials_after_repeated_failures(): void
    {
        $user = User::factory()->create(['password' => 'StrongTestPassword123!']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'WrongPassword'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'StrongTestPassword123!'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_seeder_cannot_promote_a_pre_registered_account_claiming_admin_email(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);
        config(['admin.email' => 'admin@example.com', 'admin.password' => 'StrongTestPassword123!']);
        try {
            $this->seed(AdminSeeder::class);
            $this->fail('Seeder must reject an existing non-admin account.');
        } catch (\RuntimeException) {
            $this->assertFalse(User::sole()->is_admin);
            $this->assertNull(User::sole()->approved_at);
        }
    }

    public function test_revoked_user_can_wait_and_logout_without_redirect_loop(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $this->actingAs($user)->get('/sources')->assertOk();
        $user->forceFill(['approved_at' => null])->save();
        $this->get('/sources')->assertRedirect('/approval-pending');
        $this->get('/approval-pending')->assertOk()->assertSee('Очікуємо схвалення');
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->get('/approval-pending')->assertRedirect('/login');
    }

    public function test_public_auth_pages_have_security_headers_and_secure_cookie_configuration(): void
    {
        config(['session.secure' => true]);
        $response = $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'approved_at' => now()]);
    }
}
