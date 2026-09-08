<?php

use App\Http\Controllers\AdminTwoFactorController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DnsAgentEnrollmentController;
use App\Http\Controllers\DnsAgentInstallAssignmentController;
use App\Http\Controllers\DnsBindApplyController;
use App\Http\Controllers\DnsBindDiscoveryController;
use App\Http\Controllers\DnsNameserverController;
use App\Http\Controllers\DnsServerController;
use App\Http\Controllers\DnsServerTransferController;
use App\Http\Controllers\DnsTsigKeyController;
use App\Http\Controllers\DnsZoneController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\PlatformOrganizationContextController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/install/dns-center-agent.py', function () {
    return response()->file(
        base_path('agent/dns-center-agent.py'),
        ['Content-Type' => 'text/x-python'],
    );
})->name('install.agent.binary');

Route::get('/install/dns-center-agent.py.sha256', function () {
    $path = base_path('agent/dns-center-agent.py');

    return response(hash_file('sha256', $path)."  dns-center-agent.py\n")
        ->header('Content-Type', 'text/plain');
})->name('install.agent.checksum');

Route::get('/install/agent_install.sh.sha256', function () {
    $path = public_path('install/agent_install.sh');

    return response(hash_file('sha256', $path)."  agent_install.sh\n")
        ->header('Content-Type', 'text/plain');
})->name('install.agent.installer-checksum');

$agentSystemdArtifacts = [
    'dns-center-agent.service',
    'dns-center-agent.timer',
    'dns-center-agent-operation.service',
    'dns-center-agent-operation.timer',
    'dns-center-agent-approval.service',
    'dns-center-agent-approval.timer',
];

foreach ($agentSystemdArtifacts as $artifact) {
    Route::get("/install/{$artifact}", function () use ($artifact) {
        return response()->file(
            base_path("agent/systemd/{$artifact}"),
            ['Content-Type' => 'text/plain'],
        );
    });

    Route::get("/install/{$artifact}.sha256", function () use ($artifact) {
        $path = base_path("agent/systemd/{$artifact}");

        return response(hash_file('sha256', $path)."  {$artifact}\n")
            ->header('Content-Type', 'text/plain');
    });
}

Route::middleware('guest')->group(function (): void {
    Route::get(
        '/esqueci-minha-senha',
        [ForgotPasswordController::class, 'create'],
    )->name('password.request');

    Route::post(
        '/esqueci-minha-senha',
        [ForgotPasswordController::class, 'store'],
    )->middleware('throttle:password-reset-request')
        ->name('password.email');

    Route::get(
        '/redefinir-senha/{token}',
        [ResetPasswordController::class, 'create'],
    )->name('password.reset');

    Route::post(
        '/redefinir-senha',
        [ResetPasswordController::class, 'store'],
    )->middleware('throttle:password-reset-submit')
        ->name('password.update');
});

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function (): void {
    Route::get(
        '/seguranca/segundo-fator',
        [AdminTwoFactorController::class, 'show'],
    )->name('security.two-factor.setup');

    Route::post(
        '/seguranca/segundo-fator/prazo',
        [AdminTwoFactorController::class, 'continueDuringGrace'],
    )->name('security.two-factor.grace');

    Route::get(
        '/alterar-senha',
        [PasswordChangeController::class, 'edit']
    )->name('password.change');

    Route::put(
        '/alterar-senha',
        [PasswordChangeController::class, 'update']
    )->name('password.change.update');
});

Route::middleware(['auth', 'password.changed', 'organization'])
    ->get('/dashboard', function () {
        return view('dashboard.index');
    })
    ->name('dashboard');

Route::middleware([
    'auth',
    'password.changed',
])->post(
    '/plataforma/empresa-atual',
    [PlatformOrganizationContextController::class, 'update'],
)->name('platform.organization-context.update');

Route::middleware([
    'auth',
    'password.changed',
    'organization',
    'organization.role:organization_admin',
])->prefix('usuarios')->name('users.')->group(function (): void {
    Route::get('/', [UserManagementController::class, 'index'])
        ->name('index');

    Route::post('/', [UserManagementController::class, 'store'])
        ->name('store');

    Route::patch(
        '/{user}/papel',
        [UserManagementController::class, 'updateRole']
    )->name('role');

    Route::post(
        '/{user}/status',
        [UserManagementController::class, 'updateStatus']
    )->name('status');
});

Route::middleware([
    'auth',
    'password.changed',
])->prefix('empresas')->name('organizations.')->group(function (): void {
    Route::get('/', [OrganizationController::class, 'index'])
        ->name('index');

    Route::post('/', [OrganizationController::class, 'store'])
        ->name('store');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('perfil')->name('profile.')->group(function (): void {
    Route::get(
        '/',
        [ProfileController::class, 'edit']
    )->name('edit');

    Route::put(
        '/avatar',
        [ProfileController::class, 'updateAvatar']
    )->name('avatar.update');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->group(function (): void {
    Route::get(
        '/servidores/agentes/solicitacoes/{installRequest}/associacao',
        [DnsAgentInstallAssignmentController::class, 'show'],
    )->name('servers.agent.install-requests.assignment.show');

    Route::post(
        '/servidores/agentes/solicitacoes/{installRequest}/associacao',
        [DnsAgentInstallAssignmentController::class, 'store'],
    )->name('servers.agent.install-requests.assignment.store');

    Route::get(
        '/servidores',
        [DnsServerController::class, 'index']
    )->name('servers.index');

    Route::post(
        '/servidores',
        [DnsServerController::class, 'store']
    )->name('servers.store');

    Route::put(
        '/servidores/{server}',
        [DnsServerController::class, 'update']
    )->name('servers.update');

    Route::patch(
        '/servidores/{server}/status',
        [DnsServerController::class, 'toggleStatus']
    )->name('servers.status');

    Route::get(
        '/servidores/{server}/transferencia',
        [DnsServerTransferController::class, 'show']
    )->name('servers.transfer.show');

    Route::post(
        '/servidores/{server}/transferencia',
        [DnsServerTransferController::class, 'store']
    )->name('servers.transfer.store');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->group(function (): void {
    Route::get(
        '/servidores/{server}/agente',
        [DnsAgentEnrollmentController::class, 'show'],
    )->name('servers.agent.show');

    Route::get(
        '/servidores/{server}/agente/solicitacoes/status',
        [DnsAgentEnrollmentController::class, 'installRequestStatus'],
    )->name('servers.agent.install-requests.status');

    Route::post(
        '/servidores/{server}/agente/vinculos',
        [DnsAgentEnrollmentController::class, 'issueCode'],
    )->middleware('throttle:5,1')->name('servers.agent.enrollment-codes.store');

    Route::post(
        '/servidores/{server}/agente/solicitacoes/{installRequest}/aprovar',
        [DnsAgentEnrollmentController::class, 'approve'],
    )->name('servers.agent.install-requests.approve');

    Route::post(
        '/servidores/{server}/agente/solicitacoes/{installRequest}/rejeitar',
        [DnsAgentEnrollmentController::class, 'reject'],
    )->name('servers.agent.install-requests.reject');

    Route::post(
        '/servidores/{server}/agente/revogar',
        [DnsAgentEnrollmentController::class, 'revoke'],
    )->name('servers.agent.revoke');

    Route::post(
        '/servidores/{server}/agente/atualizar',
        [DnsAgentEnrollmentController::class, 'upgradeAgent'],
    )->middleware('throttle:5,1')->name('servers.agent.upgrade');

    Route::get(
        '/servidores/{server}/agente/atualizar/status',
        [DnsAgentEnrollmentController::class, 'upgradeAgentStatus'],
    )->name('servers.agent.upgrade.status');

    Route::post(
        '/servidores/{server}/bind/plano',
        [DnsAgentEnrollmentController::class, 'planBind'],
    )->name('servers.bind.plan');

    Route::post(
        '/servidores/{server}/bind/operacoes/{operation}/autorizar',
        [DnsAgentEnrollmentController::class, 'authorizeBind'],
    )->name('servers.bind.authorize');

    Route::post(
        '/servidores/{server}/bind/descoberta',
        [DnsBindDiscoveryController::class, 'store'],
    )->middleware('throttle:5,1')->name('servers.bind.discover');

    Route::get(
        '/servidores/{server}/bind/descoberta',
        [DnsBindDiscoveryController::class, 'show'],
    )->name('servers.bind.discovery.show');

    Route::get(
        '/servidores/{server}/bind/descoberta/status',
        [DnsBindDiscoveryController::class, 'status'],
    )->name('servers.bind.discovery.status');

    Route::get(
        '/servidores/{server}/bind/descoberta/zonas/{zone}',
        [DnsBindDiscoveryController::class, 'showZone'],
    )->name('servers.bind.discovery.zone');

    Route::post(
        '/servidores/{server}/bind/descoberta/importar',
        [DnsBindDiscoveryController::class, 'import'],
    )->name('servers.bind.discovery.import');

    Route::post(
        '/servidores/{server}/bind/aplicar-zonas',
        [DnsBindApplyController::class, 'store'],
    )->middleware('throttle:5,1')->name('servers.bind.apply');

    Route::get(
        '/servidores/{server}/bind/aplicar-zonas/status',
        [DnsBindApplyController::class, 'status'],
    )->name('servers.bind.apply.status');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('nameservers')->name('nameservers.')->group(function (): void {
    Route::get(
        '/',
        [DnsNameserverController::class, 'index'],
    )->name('index');

    Route::post(
        '/identidades',
        [DnsNameserverController::class, 'storeIdentity'],
    )->name('identity.store');

    Route::put(
        '/identidades/{identity}',
        [DnsNameserverController::class, 'updateIdentity'],
    )->name('identity.update');

    Route::patch(
        '/identidades/{identity}/status',
        [DnsNameserverController::class, 'toggleIdentity'],
    )->name('identity.status');

    Route::post(
        '/perfis',
        [DnsNameserverController::class, 'storeProfile'],
    )->name('profile.store');

    Route::put(
        '/perfis/{profile}',
        [DnsNameserverController::class, 'updateProfile'],
    )->name('profile.update');

    Route::patch(
        '/perfis/{profile}/status',
        [DnsNameserverController::class, 'toggleProfile'],
    )->name('profile.status');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('zonas')->name('zones.')->group(function (): void {
    Route::get(
        '/',
        [DnsZoneController::class, 'index'],
    )->name('index');

    Route::post(
        '/',
        [DnsZoneController::class, 'store'],
    )->name('store');

    Route::get(
        '/{zone}',
        [DnsZoneController::class, 'show'],
    )->name('show');

    Route::put(
        '/{zone}',
        [DnsZoneController::class, 'update'],
    )->name('update');

    Route::post(
        '/{zone}/registros',
        [DnsZoneController::class, 'storeRecord'],
    )->name('records.store');

    Route::put(
        '/{zone}/registros/{record}',
        [DnsZoneController::class, 'updateRecord'],
    )->name('records.update');

    Route::delete(
        '/{zone}/registros/{record}',
        [DnsZoneController::class, 'destroyRecord'],
    )->name('records.destroy');

    Route::post(
        '/{zone}/publicar',
        [DnsZoneController::class, 'publish'],
    )->name('publish');

    Route::post(
        '/{zone}/adotar',
        [DnsZoneController::class, 'adopt'],
    )->name('adopt');

    Route::post(
        '/{zone}/vincular-ptr',
        [DnsZoneController::class, 'ptrSync'],
    )->name('ptr-sync');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('tsig')->name('tsig.')->group(function (): void {
    Route::post('/', [DnsTsigKeyController::class, 'store'])->name('store');
    Route::post('/{tsigKey}/rotacionar', [DnsTsigKeyController::class, 'rotate'])
        ->name('rotate');
    Route::post('/{tsigKey}/desativar', [DnsTsigKeyController::class, 'disable'])
        ->name('disable');
    Route::post('/zonas/{zone}', [DnsTsigKeyController::class, 'associate'])
        ->name('associate');
});
