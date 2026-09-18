<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\BackupR2Settings;
use App\Support\R2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'bec407d758365446312d1c62e87d8acf';

    private const KEY_ID = 'AKIATESTTESTTEST0000';

    private const SECRET = 's3cr3tTESTTESTTESTTESTTESTTESTTESTTEST00';

    public function test_platform_admin_sees_empty_form_and_non_admin_is_forbidden(): void
    {
        $this->actingAs($this->platformAdmin())
            ->get(route('settings.backup.edit'))
            ->assertOk()
            ->assertSee('Não configurado')
            ->assertSee('Salvar configuração');

        $this->actingAs($this->orgAdmin())
            ->get(route('settings.backup.edit'))
            ->assertForbidden();
    }

    public function test_non_admin_cannot_save_test_or_remove(): void
    {
        $user = $this->orgAdmin();

        $this->actingAs($user)->put(route('settings.backup.update'), $this->payload())->assertForbidden();
        $this->actingAs($user)->post(route('settings.backup.test'))->assertForbidden();
        $this->actingAs($user)->delete(route('settings.backup.destroy'))->assertForbidden();

        $this->assertFalse(BackupR2Settings::configured());
    }

    public function test_saving_stores_credentials_encrypted_and_never_renders_the_secret(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->put(route('settings.backup.update'), $this->payload())
            ->assertRedirect(route('settings.backup.edit'));

        $this->assertTrue(BackupR2Settings::configured());

        // No banco (e, portanto, nos dumps) nada aparece em texto puro.
        $raw = DB::table('platform_settings')->where('key', 'backup_r2')->value('value');
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::KEY_ID, $raw);
        $this->assertStringNotContainsString('dns-center-backups', $raw);

        $page = $this->actingAs($admin)->get(route('settings.backup.edit'))->assertOk();
        $page->assertSee('Configurado')
            ->assertSee(self::ACCOUNT)
            ->assertSee('dns-center-backups')
            ->assertSee('AKIA…0000')
            ->assertDontSee(self::SECRET)
            ->assertDontSee(self::KEY_ID);

        $this->assertDatabaseHas('dns_audit_logs', ['action' => 'backup.settings_updated']);
    }

    public function test_blank_keys_on_update_keep_the_current_ones(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->put(route('settings.backup.update'), $this->payload());

        $this->actingAs($admin)
            ->put(route('settings.backup.update'), $this->payload([
                'bucket' => 'outro-bucket',
                'access_key_id' => '',
                'secret_access_key' => '',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $settings = BackupR2Settings::get();
        $this->assertSame('outro-bucket', $settings['bucket']);
        $this->assertSame(self::KEY_ID, $settings['access_key_id']);
        $this->assertSame(self::SECRET, $settings['secret_access_key']);
    }

    public function test_first_save_requires_both_keys(): void
    {
        $this->actingAs($this->platformAdmin())
            ->put(route('settings.backup.update'), $this->payload(['access_key_id' => '', 'secret_access_key' => '']))
            ->assertSessionHasErrors(['access_key_id', 'secret_access_key']);

        $this->assertFalse(BackupR2Settings::configured());
    }

    public function test_values_are_restricted_to_safe_characters(): void
    {
        $admin = $this->platformAdmin();

        foreach ([
            ['account_id' => 'nao-e-hexadecimal'],
            ['bucket' => 'Bucket Com Espaco'],
            ['prefix' => 'pasta; rm -rf /'],
            ['access_key_id' => "abc\ndef"],
            ['secret_access_key' => 'com espaco e $(cmd)'],
        ] as $override) {
            $this->actingAs($admin)
                ->put(route('settings.backup.update'), $this->payload($override))
                ->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertFalse(BackupR2Settings::configured());
    }

    public function test_prefix_is_normalized_with_trailing_slash(): void
    {
        $this->actingAs($this->platformAdmin())
            ->put(route('settings.backup.update'), $this->payload(['prefix' => '/backups/dns']));

        $this->assertSame('backups/dns/', BackupR2Settings::get()['prefix']);
    }

    public function test_connection_test_puts_reads_and_deletes_a_tiny_object(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 200)                                              // PUT
                ->push('', 200, ['Content-Length' => '58'])                 // HEAD
                ->push('', 204),                                            // DELETE
        ]);

        $admin = $this->platformAdmin();
        $this->actingAs($admin)->put(route('settings.backup.update'), $this->payload());

        $this->actingAs($admin)
            ->post(route('settings.backup.test'))
            ->assertRedirect(route('settings.backup.edit'));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_starts_with($request->url(), 'https://'.self::ACCOUNT.'.r2.cloudflarestorage.com/dns-center-backups/dns-center/.conexao-teste-')
            && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential='.self::KEY_ID.'/'));
    }

    public function test_connection_test_reports_rejected_credentials_without_leaking_them(): void
    {
        Http::fake(['*' => Http::response('<Error>AccessDenied</Error>', 403)]);

        $admin = $this->platformAdmin();
        $this->actingAs($admin)->put(route('settings.backup.update'), $this->payload());

        $response = $this->actingAs($admin)
            ->followingRedirects()
            ->post(route('settings.backup.test'))
            ->assertOk()
            ->assertSee('Teste de conexão falhou')
            ->assertSee('recusou as credenciais');

        $response->assertDontSee(self::SECRET);
    }

    public function test_connection_test_requires_configuration(): void
    {
        $this->actingAs($this->platformAdmin())
            ->post(route('settings.backup.test'))
            ->assertStatus(409);
    }

    public function test_remove_credentials(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->put(route('settings.backup.update'), $this->payload());

        $this->actingAs($admin)->delete(route('settings.backup.destroy'))->assertRedirect();

        $this->assertFalse(BackupR2Settings::configured());
        $this->assertDatabaseHas('dns_audit_logs', ['action' => 'backup.settings_removed']);
    }

    public function test_artisan_command_prints_settings_for_the_backup_script_only_when_configured(): void
    {
        $this->assertSame(1, Artisan::call('dns-center:backup-r2-config'));
        $this->assertSame('', trim(Artisan::output()));

        $this->actingAs($this->platformAdmin())->put(route('settings.backup.update'), $this->payload());

        $this->assertSame(0, Artisan::call('dns-center:backup-r2-config'));
        $lines = explode("\n", trim(Artisan::output()));

        $this->assertSame([
            'R2_ACCOUNT_ID='.self::ACCOUNT,
            'R2_BUCKET=dns-center-backups',
            'R2_PREFIX=dns-center/',
            'R2_ACCESS_KEY_ID='.self::KEY_ID,
            'R2_SECRET_ACCESS_KEY='.self::SECRET,
        ], $lines);

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression('/^R2_[A-Z_]+=[A-Za-z0-9._\/-]*$/', $line);
        }
    }

    public function test_sigv4_matches_the_documented_aws_example(): void
    {
        // Exemplo "GET Object" (com Range) da documentação do AWS SigV4 para S3.
        $headers = R2Client::sign(
            'GET', 'examplebucket.s3.amazonaws.com', '/test.txt', '',
            [
                'range' => 'bytes=0-9',
                'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            ],
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'us-east-1', '20130524T000000Z',
        );

        $this->assertStringContainsString(
            'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            .'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            .'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization'],
        );
    }

    /**
     * @param  array<string, string>  $override
     * @return array<string, string>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'account_id' => self::ACCOUNT,
            'bucket' => 'dns-center-backups',
            'prefix' => 'dns-center/',
            'access_key_id' => self::KEY_ID,
            'secret_access_key' => self::SECRET,
        ], $override);
    }

    private function platformAdmin(): User
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'trevizam-network'],
            ['name' => 'Trevizam Network', 'status' => 'active', 'is_default' => true],
        );

        $admin = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => true,
            'status' => 'active',
        ]);
        $admin->organizations()->attach($organization->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);

        return $admin;
    }

    private function orgAdmin(): User
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
            'is_platform_admin' => false,
            'status' => 'active',
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => 'organization_admin', 'status' => 'active', 'is_default' => true,
        ]);

        return $user;
    }
}
