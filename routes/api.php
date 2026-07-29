<?php

use App\Http\Controllers\Api\DnsAgentPublicationController;
use App\Http\Controllers\Api\DnsAgentRegistrationController;
use App\Http\Controllers\Api\DnsAgentRuntimeController;
use App\Http\Controllers\Api\DnsBindRuntimeController;
use App\Http\Controllers\Api\DnsZoneArtifactController;
use App\Http\Middleware\AuthenticateDnsAgent;
use Illuminate\Support\Facades\Route;

Route::post(
    '/agent/enroll',
    [DnsAgentRegistrationController::class, 'store'],
)->middleware('throttle:10,1')
    ->name('api.agent.enroll');

Route::middleware([
    AuthenticateDnsAgent::class,
    'throttle:120,1',
])->prefix('agent')->name('api.agent.')->group(
    function (): void {
        Route::post(
            '/heartbeat',
            [DnsAgentRuntimeController::class, 'heartbeat'],
        )->name('heartbeat');

        Route::post(
            '/inventory',
            [DnsAgentRuntimeController::class, 'inventory'],
        )->name('inventory');

        Route::get(
            '/zones',
            [DnsZoneArtifactController::class, 'manifest'],
        )->name('zones.manifest');

        Route::get(
            '/zones/{zone}/artifact',
            [DnsZoneArtifactController::class, 'artifact'],
        )->name('zones.artifact');

        Route::post(
            '/publications/{publication}/apply',
            [DnsAgentPublicationController::class, 'apply'],
        )->whereNumber('publication')
            ->name('publications.apply');

        Route::post('/bind/readiness', [DnsBindRuntimeController::class, 'readiness'])
            ->name('bind.readiness');
        Route::get('/bind/operations/next', [DnsBindRuntimeController::class, 'nextOperation'])
            ->name('bind.operations.next');
        Route::post(
            '/bind/operations/{operation}/report',
            [DnsBindRuntimeController::class, 'report'],
        )->whereNumber('operation')->name('bind.operations.report');
    }
);
