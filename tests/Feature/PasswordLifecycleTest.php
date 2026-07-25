<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_temporary_password_is_redirected(): void
    {
        [$organization, $user] = $this->makeUser();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/alterar-senha');
    }

    public function test_user_can_replace_temporary_password(): void
    {
        [$organization, $user] = $this->makeUser();

        $this->actingAs($user)
            ->put('/alterar-senha', [
                'current_password' => 'Mudar@123',
                'password' => 'NovaSenhaForte@2026',
                'password_confirmation' => 'NovaSenhaForte@2026',
            ])
            ->assertRedirect('/dashboard');

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertNull($user->temporary_password_expires_at);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(
            Hash::check('NovaSenhaForte@2026', $user->password)
        );
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        [, $user] = $this->makeUser();

        $this->actingAs($user)
            ->put('/alterar-senha', [
                'current_password' => 'SenhaErrada@123',
                'password' => 'NovaSenhaForte@2026',
                'password_confirmation' => 'NovaSenhaForte@2026',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    private function makeUser(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'password' => Hash::make('Mudar@123'),
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => true,
            'temporary_password_expires_at' => now()->addHours(48),
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'operator',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$organization, $user];
    }
}
