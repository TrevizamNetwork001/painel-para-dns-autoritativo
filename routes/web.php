<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\DnsAgentEnrollmentController;
use App\Http\Controllers\DnsNameserverController;
use App\Http\Controllers\DnsServerController;
use App\Http\Controllers\DnsZoneController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

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

    Route::post(
        '/servidores/{server}/agente/ativacao',
        [DnsAgentEnrollmentController::class, 'store'],
    )->name('servers.agent.enrollment.store');

    Route::post(
        '/servidores/{server}/agente/revogar',
        [DnsAgentEnrollmentController::class, 'revoke'],
    )->name('servers.agent.revoke');

    Route::post(
        '/servidores/{server}/bind/plano',
        [DnsAgentEnrollmentController::class, 'planBind'],
    )->name('servers.bind.plan');

    Route::post(
        '/servidores/{server}/bind/operacoes/{operation}/autorizar',
        [DnsAgentEnrollmentController::class, 'authorizeBind'],
    )->name('servers.bind.authorize');
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
});
