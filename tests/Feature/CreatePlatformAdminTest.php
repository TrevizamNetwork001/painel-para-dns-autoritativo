<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_creates_organization_transactionally(): void
    {
        $password = 'Strong-Install-Password-2026!';

        $this->artisan('dns-center:create-admin', [
            '--organization' => 'example-network',
            '--organization-name' => 'Example Network',
            '--name' => 'Platform Admin',
            '--email' => 'admin@example.test',
        ])
            ->expectsQuestion(
                'Senha: mínimo 12 caracteres, maiúscula, minúscula, número e símbolo',
                $password,
            )
            ->expectsQuestion('Confirme a senha', $password)
            ->assertSuccessful();

        $organization = Organization::query()->sole();
        $user = User::query()->sole();

        $this->assertSame('example-network', $organization->slug);
        $this->assertTrue($organization->is_default);
        $this->assertSame($organization->id, $user->current_organization_id);
        $this->assertTrue($user->is_platform_admin);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'organization_admin',
            'status' => 'active',
        ]);
    }

    public function test_invalid_admin_input_does_not_create_organization(): void
    {
        $this->artisan('dns-center:create-admin', [
            '--organization' => 'invalid slug',
            '--organization-name' => 'Example Network',
            '--name' => 'Platform Admin',
            '--email' => 'admin@example.test',
        ])
            ->expectsQuestion('Senha: mínimo 12 caracteres, maiúscula, minúscula, número e símbolo', 'weak')
            ->expectsQuestion('Confirme a senha', 'weak')
            ->assertFailed();

        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('users', 0);
    }
}
