<?php

namespace MasterCore\Auth\Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use MasterCore\Auth\Http\Controllers\AuthenticatedUserController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_and_authenticated_user_api_are_registered(): void
    {
        $this->get('/login')->assertOk()->assertSee('master-core-auth');
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
        $this->get('/sanctum/csrf-cookie')->assertNoContent();

        $loginMiddleware = app('router')->getRoutes()->getByName('login.store')->gatherMiddleware();
        $this->assertContains('web', $loginMiddleware);
    }

    public function test_login_creates_a_session_and_returns_a_local_redirect(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $this->get('/login')->assertOk();
        $sessionIdBeforeLogin = $this->app['session']->driver()->getId();

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'StrongPass123',
        ])->assertOk()->assertExactJson(['redirect' => '/app']);

        $this->assertNotSame($sessionIdBeforeLogin, $this->app['session']->driver()->getId());
        $this->assertAuthenticatedAs($user);
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
        $this->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password');
    }

    public function test_login_accepts_only_a_local_intended_destination(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);
        $this->withSession(['url.intended' => 'https://evil.example/collect']);

        $this->postJson('/login', ['email' => $user->email, 'password' => 'StrongPass123'])
            ->assertOk()->assertExactJson(['redirect' => '/app']);
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/logout')->assertNoContent();

        $this->assertGuest();
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_password_confirmation_uses_the_configured_authenticatable_model(): void
    {
        $user = User::factory()->create(['password' => Hash::make('StrongPass123')]);

        $this->actingAs($user)->postJson('/user/confirm-password', ['password' => 'StrongPass123'])
            ->assertCreated();
    }

    public function test_invalid_login_has_a_generic_json_failure(): void
    {
        $this->postJson('/login', ['email' => 'nobody@example.test', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->postJson('/login', ['email' => 'attempt@example.test', 'password' => 'incorrect'])
                ->assertUnprocessable();
        }

        $this->postJson('/login', ['email' => 'attempt@example.test', 'password' => 'incorrect'])
            ->assertTooManyRequests();
    }

    public function test_password_reset_requests_do_not_disclose_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->postJson('/forgot-password', ['email' => $user->email]);
        $missing = $this->postJson('/forgot-password', ['email' => 'missing@example.test']);

        $existing->assertOk()->assertExactJson($missing->json());
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_validates_password_and_invalidates_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        \DB::table('sessions')->insert([
            'id' => 'other-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $this->postJson('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson('/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('StrongPass123', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
    }

    public function test_email_verification_uses_a_signed_link_and_marks_the_host_user(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($url)->assertRedirect();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_user_can_request_another_verification_message(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson('/email/verification-notification')->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_session_inventory_is_owned_and_does_not_allow_revoking_current_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        config(['session.driver' => 'database']);
        $this->withSession(['_token' => 'test-token']);
        $currentSessionId = str_repeat('c', 40);
        $otherId = str_repeat('o', 40);
        foreach ([$currentSessionId, $otherId] as $id) {
            \DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test browser',
                'payload' => 'payload',
                'last_activity' => time(),
            ]);
        }

        // Sanctum starts the web session stack only for requests from an allowed SPA origin.
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
        $list = $this->getJson('/api/v1/auth/sessions')->assertOk();
        $listedId = hash('sha256', $otherId);
        $this->deleteJson('/api/v1/auth/sessions/'.$listedId)->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => $otherId]);

        $session = new Store('test-session', new ArraySessionHandler(120), $currentSessionId);
        $session->start();
        $request = Request::create('/api/v1/auth/sessions/'.hash('sha256', $currentSessionId), 'DELETE');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);

        try {
            (new AuthenticatedUserController)->revokeSession($request, hash('sha256', $currentSessionId));
            $this->fail('The current session must not be revoked through the session inventory endpoint.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_session_inventory_reports_unsupported_session_drivers(): void
    {
        config(['session.driver' => 'array']);
        $this->actingAs(User::factory()->create())
            ->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'])
            ->getJson('/api/v1/auth/sessions')
            ->assertStatus(501);
    }
}
