<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_profile(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->get('/perfil')
            ->assertOk()
            ->assertSee('Escolha seu avatar')
            ->assertSee($user->email)
            ->assertSee('sidebar app-sidebar', false)
            ->assertSee(route('dashboard'), false)
            ->assertSee(route('servers.index'), false)
            ->assertSee(route('nameservers.index'), false)
            ->assertSee(route('zones.index'), false)
            ->assertSee(route('users.index'), false)
            ->assertSee(
                'action="'.route('profile.avatar.update').'"',
                false,
            )
            ->assertSee('name="_method" value="PUT"', false)
            ->assertDontSee('href="#"', false);
    }

    public function test_guest_is_redirected_from_profile_to_login(): void
    {
        $this->get('/perfil')
            ->assertRedirect('/login');
    }

    public function test_user_can_update_avatar(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->put('/perfil/avatar', [
                'avatar_key' => 'owl',
            ])
            ->assertRedirect('/perfil');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'avatar_key' => 'owl',
        ]);
    }

    public function test_custom_avatars_can_be_selected(): void
    {
        $user = $this->makeUser();

        $customAvatars = [
            'ogre',
            'donkey',
            'ceo',
            'anta',
            'peixe',
            'carrasco',
            'engenheiro_obra',
        ];

        foreach ($customAvatars as $avatar) {
            $this->actingAs($user)
                ->put('/perfil/avatar', [
                    'avatar_key' => $avatar,
                ])
                ->assertRedirect('/perfil');

            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'avatar_key' => $avatar,
            ]);
        }
    }

    public function test_invalid_avatar_is_rejected(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->put('/perfil/avatar', [
                'avatar_key' => 'avatar-inexistente',
            ])
            ->assertSessionHasErrors('avatar_key');

        $this->assertNull($user->fresh()->avatar_key);
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
