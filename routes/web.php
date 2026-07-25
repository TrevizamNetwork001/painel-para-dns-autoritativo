<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/health', function () {
    $database = 'ok';
    $redis = 'ok';
    $status = 200;

    try {
        DB::select('select 1');
    } catch (Throwable) {
        $database = 'error';
        $status = 503;
    }

    try {
        Redis::ping();
    } catch (Throwable) {
        $redis = 'error';
        $status = 503;
    }

    return response()->json([
        'status' => $status === 200 ? 'ok' : 'degraded',
        'application' => config('app.name'),
        'database' => $database,
        'redis' => $redis,
        'timestamp' => now()->toIso8601String(),
    ], $status);
})->name('health');
