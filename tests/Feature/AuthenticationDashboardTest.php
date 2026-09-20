<?php

namespace Tests\Feature;

use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available_to_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Entrar em DNS Center')
            ->assertSee('DNS Center');
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_authenticated_member_can_access_dashboard(): void
    {
        config(['app.release' => '1.3.51']);

        $organization = Organization::query()->create([
            'name' => 'Trevizam Network',
            'slug' => 'trevizam-network',
            'status' => 'active',
            'is_default' => true,
        ]);

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trevizam Network')
            ->assertSee('DNS Center v1.3.51')
            ->assertSee($user->name)
            ->assertSee(route('servers.index'), false)
            ->assertSee(route('nameservers.index'), false)
            ->assertSee(route('zones.index'), false)
            ->assertSee(route('users.index'), false);
    }

    public function test_dashboard_counts_only_zones_from_current_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'status' => 'active',
        ]);

        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin',
            'status' => 'active',
            'is_default' => true,
        ]);

        foreach ([$organization, $otherOrganization] as $owner) {
            DnsZone::query()->create([
                'organization_id' => $owner->id,
                'name' => $owner->slug.'.example',
                'kind' => 'primary',
                'serial' => 2026072901,
                'soa_mname' => 'ns1.example.net',
                'soa_rname' => 'hostmaster.example.net',
            ]);
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee(
                'data-dashboard-zone-count',
                false,
            )
            ->assertSeeInOrder([
                'data-dashboard-zone-count',
                '1',
                'Zonas autoritativas',
            ], false);
    }

    public function test_user_without_organization_context_is_forbidden(): void
    {
        $user = User::factory()->create([
            'current_organization_id' => null,
            'is_platform_admin' => false,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_shows_real_server_health_and_compact_empty_activity(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['role' => 'organization_admin', 'status' => 'active']);
        DnsServer::factory()->create(['organization_id' => $organization->id, 'name' => 'ns-operacional', 'hostname' => 'ns.example.test', 'role' => 'standalone', 'status' => 'offline']);
        DnsServer::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'outro-tenant', 'status' => 'online']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ambiente crítico')
            ->assertSee('Servidor offline')
            ->assertSee('ns-operacional')
            ->assertSee('ns.example.test')
            ->assertSee('Sem operações recentes registradas.')
            ->assertSee('DNS autoritativo')
            ->assertDontSee('outro-tenant')
            ->assertDontSee('Nenhum servidor cadastrado')
            ->assertDontSee('Nenhuma zona configurada');
    }

    public function test_dashboard_success_indicators_ignore_disabled_servers(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['role' => 'organization_admin', 'status' => 'active']);

        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ns-ativo',
            'role' => 'standalone',
            'status' => 'online',
            'enabled' => true,
        ]);
        DnsServer::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'ns-desativado',
            'status' => 'maintenance',
            'enabled' => false,
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Ambiente operacional')
            ->assertSee('1 de 1 servidores online')
            ->assertSee('data-dashboard-kpi="pendencias"', false)
            ->assertSee('data-dashboard-kpi="zonas-autoritativas"', false)
            ->assertSee('dashboard-kpi-card dashboard-kpi-online', false)
            ->assertSee('Ambiente sem alertas')
            ->assertDontSee('ns-desativado');
    }

    public function test_dashboard_neutral_empty_state_and_read_only_actions(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['role' => 'viewer', 'status' => 'active']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Aguardando infraestrutura')
            ->assertSee('Nenhuma pendência operacional')
            ->assertSee('Ver servidores')
            ->assertSee('Ver zonas')
            ->assertDontSee('Novo servidor')
            ->assertDontSee('Nova zona')
            ->assertDontSee('Novo usuário');
    }

    public function test_dashboard_topology_uses_registered_roles_and_statuses_without_claiming_location(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['role' => 'organization_admin', 'status' => 'active']);
        DnsServer::factory()->create(['organization_id' => $organization->id, 'name' => 'primario-real', 'role' => 'primary', 'status' => 'online']);
        DnsServer::factory()->create(['organization_id' => $organization->id, 'name' => 'secundario-real', 'role' => 'secondary', 'status' => 'warning']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Topologia da infraestrutura')
            ->assertSee('Primários')
            ->assertSee('Secundários')
            ->assertSee('primario-real')
            ->assertSee('secundario-real')
            ->assertSee('Online')
            ->assertSee('Atenção')
            ->assertDontSee('Mapa da infraestrutura')
            ->assertDontSee('brazil-map.svg')
            ->assertDontSee('Última atualização');
    }
}
