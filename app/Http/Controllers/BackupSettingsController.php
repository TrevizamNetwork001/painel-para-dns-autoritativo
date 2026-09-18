<?php

namespace App\Http\Controllers;

use App\Support\BackupR2Settings;
use App\Support\DnsAuditLogger;
use App\Support\R2Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BackupSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $this->ensurePlatformAdmin($request);

        $record = BackupR2Settings::record();
        $settings = BackupR2Settings::get() ?? [];

        return view('settings.backup', [
            'configured' => BackupR2Settings::configured(),
            'settings' => [
                'account_id' => $settings['account_id'] ?? '',
                'bucket' => $settings['bucket'] ?? '',
                'prefix' => $settings['prefix'] ?? 'dns-center/',
                'access_key_id_masked' => BackupR2Settings::mask($settings['access_key_id'] ?? null),
                'has_secret' => filled($settings['secret_access_key'] ?? null),
            ],
            'updatedAt' => $record?->updated_at,
            'updatedBy' => $record?->updatedBy?->name,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensurePlatformAdmin($request);

        $current = BackupR2Settings::get() ?? [];
        $firstTime = ! filled($current['access_key_id'] ?? null) || ! filled($current['secret_access_key'] ?? null);

        // A validação restringe os caracteres: os valores são lidos por um script
        // do servidor, então nada além de letras, números e . _ - / é aceito.
        $validated = $request->validate([
            'account_id' => ['required', 'regex:/^[A-Fa-f0-9]{32}$/'],
            'bucket' => ['required', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'prefix' => ['nullable', 'regex:/^[A-Za-z0-9._\/-]{0,100}$/'],
            'access_key_id' => [Rule::requiredIf($firstTime), 'nullable', 'regex:/^[A-Za-z0-9]{16,64}$/'],
            'secret_access_key' => [Rule::requiredIf($firstTime), 'nullable', 'regex:/^[A-Za-z0-9]{16,128}$/'],
        ], [
            'account_id.regex' => 'O Account ID tem 32 caracteres hexadecimais (aparece na URL do painel da Cloudflare).',
            'bucket.regex' => 'Nome de bucket inválido (use letras minúsculas, números, ponto ou hífen).',
            'prefix.regex' => 'O prefixo aceita apenas letras, números e . _ - /',
            'access_key_id.regex' => 'ID da chave de acesso inválido (só letras e números).',
            'secret_access_key.regex' => 'Chave de acesso secreta inválida (só letras e números).',
        ]);

        $prefix = trim((string) ($validated['prefix'] ?? ''), '/');

        BackupR2Settings::save([
            'account_id' => strtolower($validated['account_id']),
            'bucket' => $validated['bucket'],
            'prefix' => $prefix === '' ? '' : $prefix.'/',
            // Campo em branco mantém o valor atual (a chave secreta nunca é exibida).
            'access_key_id' => $validated['access_key_id'] ?? $current['access_key_id'],
            'secret_access_key' => $validated['secret_access_key'] ?? $current['secret_access_key'],
        ], $request->user()->id);

        $this->audit($request, 'backup.settings_updated');

        return redirect()
            ->route('settings.backup.edit')
            ->with('status', 'Configuração de backup salva. Use "Testar conexão" para validar as credenciais.');
    }

    public function test(Request $request): RedirectResponse
    {
        $this->ensurePlatformAdmin($request);

        abort_unless(BackupR2Settings::configured(), 409, 'Configure as credenciais antes de testar.');

        $settings = BackupR2Settings::get();
        $result = R2Client::fromSettings($settings)->testConnection($settings['prefix'] ?? '');

        $this->audit($request, $result['ok'] ? 'backup.connection_tested' : 'backup.connection_test_failed');

        return redirect()
            ->route('settings.backup.edit')
            ->with($result['ok'] ? 'status' : 'test_error', $result['message']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->ensurePlatformAdmin($request);

        BackupR2Settings::forget();
        $this->audit($request, 'backup.settings_removed');

        return redirect()
            ->route('settings.backup.edit')
            ->with('status', 'Credenciais do R2 removidas. O backup diário continua, só que somente local.');
    }

    private function audit(Request $request, string $action): void
    {
        DnsAuditLogger::record(
            organizationId: (int) $request->user()->current_organization_id,
            user: $request->user(),
            action: $action,
            recordName: 'Backup R2',
        );
    }

    private function ensurePlatformAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->is_platform_admin,
            403,
            'Apenas administradores da plataforma podem alterar configurações.',
        );
    }
}
