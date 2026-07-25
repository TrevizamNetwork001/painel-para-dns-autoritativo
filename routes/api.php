<?php

use App\Http\Controllers\Api\DnsAgentRegistrationController;
use Illuminate\Support\Facades\Route;

Route::post(
    '/agent/enroll',
    [DnsAgentRegistrationController::class, 'store'],
)->middleware('throttle:10,1')
    ->name('api.agent.enroll');
