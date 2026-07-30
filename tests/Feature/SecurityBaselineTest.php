<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SecurityAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class SecurityBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.admin_2fa.required', false);
        RateLimiter::clear('unused');
    }

    public function test_web_responses_receive_strict_security_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        $csp = (string) $response->headers->get(
            'Content-Security-Policy',
        );

        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader(
                'Referrer-Policy',
                'strict-origin-when-cross-origin',
            )
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $csp,
        );
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp);
        $this->assertMatchesRegularExpression(
            "/script-src 'self' 'nonce-[^']+'/u",
            $csp,
        );
    }

    public function test_hsts_is_only_added_to_https_in_production(): void
    {
        $this->get('http://localhost/login')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/login')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->app['env'] = 'production';

        $this->get('https://localhost/login')
            ->assertHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
    }

    public function test_agent_json_does_not_receive_csp_or_web_session(): void
    {
        $this->postJson('/api/agent/heartbeat')
            ->assertUnauthorized()
            ->assertJsonPath('ok', false)
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_email_ip_login_limit_still_blocks_repetition(): void
    {
        config()->set('security.login.email_ip_limit', 2);
        config()->set('security.login.global_ip_limit', 20);

        $payload = [
            'email' => 'same@example.test',
            'password' => 'invalid-secret',
        ];

        $this->post('/login', $payload);
        $this->post('/login', $payload);
        $this->post('/login', $payload)
            ->assertSessionHasErrors('email');
    }

    public function test_global_ip_limit_blocks_email_variation(): void
    {
        config()->set('security.login.email_ip_limit', 20);
        config()->set('security.login.global_ip_limit', 2);

        foreach (['one', 'two'] as $email) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
                ->post('/login', [
                    'email' => $email.'@example.test',
                    'password' => 'invalid-secret',
                ]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.40'])
            ->post('/login', [
                'email' => 'three@example.test',
                'password' => 'invalid-secret',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('security_audits', [
            'event' => 'auth.login_failed',
            'ip_address' => '192.0.2.40',
            'rate_limit_hit' => true,
        ]);
    }

    public function test_untrusted_forwarded_ip_cannot_bypass_limit(): void
    {
        config()->set('security.login.email_ip_limit', 20);
        config()->set('security.login.global_ip_limit', 2);

        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $index => $forged) {
            $response = $this
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
                ->withHeader('X-Forwarded-For', $forged)
                ->post('/login', [
                    'email' => 'forged-'.$index.'@example.test',
                    'password' => 'invalid-secret',
                ]);
        }

        $response->assertSessionHasErrors('email');
        $this->assertDatabaseHas('security_audits', [
            'event' => 'auth.login_failed',
            'ip_address' => '192.0.2.50',
            'rate_limit_hit' => true,
        ]);
    }

    public function test_login_failure_audit_is_sanitized_and_deduplicated(): void
    {
        config()->set('security.login.global_ip_limit', 50);
        config()->set('security.login.email_ip_limit', 50);

        $password = 'NeverStoreThisPassword!';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('User-Agent', str_repeat('Browser', 100))
                ->post('/login', [
                    'email' => 'unknown@example.test',
                    'password' => $password,
                ]);
        }

        $this->assertDatabaseCount('security_audits', 1);

        $audit = SecurityAudit::query()->sole();
        $encoded = json_encode($audit->getAttributes());

        $this->assertSame('auth.login_failed', $audit->event);
        $this->assertNull($audit->user_id);
        $this->assertNull($audit->email_hash);
        $this->assertLessThanOrEqual(500, strlen($audit->user_agent));
        $this->assertStringNotContainsString($password, (string) $encoded);
        $this->assertStringNotContainsString(
            'unknown@example.test',
            (string) $encoded,
        );
    }

    public function test_login_response_does_not_disclose_user_existence(): void
    {
        $user = User::factory()->create([
            'email' => 'known@example.test',
            'password' => Hash::make('correct-password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);

        $this->post('/login', [
            'email' => 'unknown@example.test',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);
    }

    public function test_admin_without_factor_is_redirected_but_viewer_is_not(): void
    {
        config()->set('security.admin_2fa.required', true);

        [$admin, $organization] = $this->member('organization_admin');
        [$viewer] = $this->member('viewer', $organization);

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('security.two-factor.setup'));

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_admin_with_confirmed_totp_can_access_normally(): void
    {
        config()->set('security.admin_2fa.required', true);

        [$admin] = $this->member('organization_admin');
        $admin->forceFill([
            'two_factor_secret' => 'encrypted-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_pending_totp_shows_inline_qr_and_manual_google_key(): void
    {
        [$admin] = $this->member('organization_admin');
        $secret = 'JBSWY3DPEHPK3PXP';
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->actingAs($admin)
            ->get(route('security.two-factor.setup'))
            ->assertOk()
            ->assertSee('class="totp-qr-code"', false)
            ->assertSee('<svg', false)
            ->assertSee('Inserir chave de configuração')
            ->assertSee($secret)
            ->assertSee('data-copy-totp-secret', false);
    }

    public function test_grace_allows_navigation_but_not_critical_operations(): void
    {
        config()->set('security.admin_2fa.required', true);
        config()->set('security.admin_2fa.grace_days', 7);

        [$admin] = $this->member('organization_admin');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect(route('security.two-factor.setup'));

        $this->post(route('security.two-factor.grace'))
            ->assertRedirect(route('dashboard'));
        $this->get('/dashboard')->assertOk();

        $this->post(route('users.store'), [])
            ->assertRedirect(route('security.two-factor.setup'));
    }

    public function test_admin_cannot_remove_last_second_factor(): void
    {
        [$admin] = $this->member('organization_admin');
        $admin->forceFill([
            'two_factor_secret' => 'encrypted-placeholder',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->delete(route('two-factor.disable'))
            ->assertSessionHasErrors('two_factor');

        $this->assertNotNull($admin->fresh()->two_factor_secret);
    }

    public function test_security_check_blocks_unsafe_production_without_printing_key(): void
    {
        $secret = 'base64:DO-NOT-PRINT-THIS-APPLICATION-KEY';
        $this->app['env'] = 'production';
        config()->set('app.env', 'production');
        config()->set('app.debug', true);
        config()->set('app.url', 'http://dns.example.test');
        config()->set('app.key', $secret);
        config()->set('session.secure', false);

        $this->artisan('dns-center:security-check')
            ->expectsOutputToContain('BLOCKED')
            ->doesntExpectOutputToContain($secret)
            ->assertExitCode(1);
    }

    /**
     * @return array{User, Organization}
     */
    private function member(
        string $role,
        ?Organization $organization = null,
    ): array {
        $organization ??= Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);
        $user->organizations()->attach($organization, [
            'role' => $role,
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $organization];
    }
}
