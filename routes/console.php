<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retenção dos eventos de idempotência dos agentes (ver PruneAgentEvents). O
// container `scheduler` roda `schedule:work`.
Schedule::command('dns-center:prune-agent-events --days=30')
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onOneServer();
