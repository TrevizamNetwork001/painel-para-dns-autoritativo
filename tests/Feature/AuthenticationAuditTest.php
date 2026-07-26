<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_records_time_ip_and_user_agent(): void
    {
        $user = $this->makeUser();

        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'DNS Center Test Browser',
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'SenhaForte@2026',
        ])->assertRedirect();

        $user->refresh();

        $this->assertNotNull($user->last_login_at);
        $this->assertSame('192.0.2.10', $user->last_login_ip);
        $this->assertSame(
            'DNS Center Test Browser',
            $user->last_login_user_agent
        );
    }

    public function test_logout_records_logout_time(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/');

        $this->assertNotNull($user->fresh()->last_logout_at);
    }

    public function test_fortify_password_update_records_password_change(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user);

        app(\App\Actions\Fortify\UpdateUserPassword::class)->update(
            $user,
            [
                'current_password' => 'SenhaForte@2026',
                'password' => 'SenhaNovaForte@2026',
                'password_confirmation' => 'SenhaNovaForte@2026',
            ]
        );

        $user->refresh();

        $this->assertNotNull($user->password_changed_at);
        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertTrue(
            Hash::check('SenhaNovaForte@2026', $user->password)
        );
    }

    private function makeUser(): User
    {
        $organization = Organization::query()->create([
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('SenhaForte@2026'),
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return $user;
    }
}
