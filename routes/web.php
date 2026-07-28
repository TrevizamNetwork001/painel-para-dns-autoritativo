<?php

use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProfileController;

use Illuminate\Support\Facades\Route;

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
        [App\Http\Controllers\DnsServerController::class, 'index']
    )->name('servers.index');

    Route::post(
        '/servidores',
        [App\Http\Controllers\DnsServerController::class, 'store']
    )->name('servers.store');

    Route::put(
        '/servidores/{server}',
        [App\Http\Controllers\DnsServerController::class, 'update']
    )->name('servers.update');

    Route::patch(
        '/servidores/{server}/status',
        [App\Http\Controllers\DnsServerController::class, 'toggleStatus']
    )->name('servers.status');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->group(function (): void {
    Route::get(
        '/servidores/{server}/agente',
        [\App\Http\Controllers\DnsAgentEnrollmentController::class, 'show'],
    )->name('servers.agent.show');

    Route::post(
        '/servidores/{server}/agente/ativacao',
        [\App\Http\Controllers\DnsAgentEnrollmentController::class, 'store'],
    )->name('servers.agent.enrollment.store');

    Route::post(
        '/servidores/{server}/agente/revogar',
        [\App\Http\Controllers\DnsAgentEnrollmentController::class, 'revoke'],
    )->name('servers.agent.revoke');
});


Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('nameservers')->name('nameservers.')->group(function (): void {
    Route::get(
        '/',
        [\App\Http\Controllers\DnsNameserverController::class, 'index'],
    )->name('index');

    Route::post(
        '/identidades',
        [\App\Http\Controllers\DnsNameserverController::class, 'storeIdentity'],
    )->name('identity.store');

    Route::put(
        '/identidades/{identity}',
        [\App\Http\Controllers\DnsNameserverController::class, 'updateIdentity'],
    )->name('identity.update');

    Route::patch(
        '/identidades/{identity}/status',
        [\App\Http\Controllers\DnsNameserverController::class, 'toggleIdentity'],
    )->name('identity.status');

    Route::post(
        '/perfis',
        [\App\Http\Controllers\DnsNameserverController::class, 'storeProfile'],
    )->name('profile.store');

    Route::put(
        '/perfis/{profile}',
        [\App\Http\Controllers\DnsNameserverController::class, 'updateProfile'],
    )->name('profile.update');

    Route::patch(
        '/perfis/{profile}/status',
        [\App\Http\Controllers\DnsNameserverController::class, 'toggleProfile'],
    )->name('profile.status');
});

Route::middleware([
    'auth',
    'password.changed',
    'organization',
])->prefix('zonas')->name('zones.')->group(function (): void {
    Route::get(
        '/',
        [\App\Http\Controllers\DnsZoneController::class, 'index'],
    )->name('index');

    Route::post(
        '/',
        [\App\Http\Controllers\DnsZoneController::class, 'store'],
    )->name('store');

    Route::get(
        '/{zone}',
        [\App\Http\Controllers\DnsZoneController::class, 'show'],
    )->name('show');

    Route::put(
        '/{zone}',
        [\App\Http\Controllers\DnsZoneController::class, 'update'],
    )->name('update');

    Route::post(
        '/{zone}/registros',
        [\App\Http\Controllers\DnsZoneController::class, 'storeRecord'],
    )->name('records.store');

    Route::put(
        '/{zone}/registros/{record}',
        [\App\Http\Controllers\DnsZoneController::class, 'updateRecord'],
    )->name('records.update');

    Route::delete(
        '/{zone}/registros/{record}',
        [\App\Http\Controllers\DnsZoneController::class, 'destroyRecord'],
    )->name('records.destroy');

    Route::post(
        '/{zone}/publicar',
        [\App\Http\Controllers\DnsZoneController::class, 'publish'],
    )->name('publish');
});
