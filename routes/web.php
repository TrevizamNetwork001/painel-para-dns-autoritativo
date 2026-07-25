<?php

use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\PasswordChangeController;

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
