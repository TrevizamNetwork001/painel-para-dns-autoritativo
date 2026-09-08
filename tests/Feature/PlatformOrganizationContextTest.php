<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformOrganizationContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_switch_to_any_active_organization(): void
    {
        [$admin, $home] = $this->user(true);
        $client = Organization::factory()->create([
            'name' => 'Conecta Network',
            'status' => 'active',
        ]);

        $this->actingAs($admin)->post(
            route('platform.organization-context.update'),
            ['organization_id' => $client->id],
        )->assertRedirect(route('dashboard'));

        $this->assertSame($client->id, $admin->fresh()->current_organization_id);
        $this->assertDatabaseHas('security_audits', [
            'event' => 'platform.organization_context_changed',
            'organization_id' => $client->id,
            'result' => 'success',
        ]);

        $this->actingAs($admin->fresh())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrando como plataforma')
            ->assertSee('Conecta Network')
            ->assertSee($home->name);
    }

    public function test_regular_admin_cannot_switch_organization_context(): void
    {
        [$admin, $home] = $this->user(false);
        $client = Organization::factory()->create();

        $this->actingAs($admin)->post(
            route('platform.organization-context.update'),
            ['organization_id' => $client->id],
        )->assertForbidden();

        $this->assertSame($home->id, $admin->fresh()->current_organization_id);
        $this->assertDatabaseCount('security_audits', 0);
    }

    public function test_platform_admin_cannot_switch_to_inactive_organization(): void
    {
        [$admin, $home] = $this->user(true);
        $inactive = Organization::factory()->create(['status' => 'inactive']);

        $this->actingAs($admin)->post(
            route('platform.organization-context.update'),
            ['organization_id' => $inactive->id],
        )->assertSessionHasErrors('organization_id');

        $this->assertSame($home->id, $admin->fresh()->current_organization_id);
    }

    private function user(bool $platformAdmin): array
    {
        $organization = Organization::factory()->create([
            'name' => 'Trevizam Network',
        ]);
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
            'must_change_password' => false,
            'is_platform_admin' => $platformAdmin,
            'two_factor_secret' => $platformAdmin ? 'encrypted-placeholder' : null,
            'two_factor_confirmed_at' => $platformAdmin ? now() : null,
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        return [$user, $organization];
    }
}
