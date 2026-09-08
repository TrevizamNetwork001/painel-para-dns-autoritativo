<?php

namespace Tests;

use App\Support\TestingDatabaseGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestingDatabaseGuard::assertSafe(
            (string) app()->environment(),
            (string) config('database.connections.pgsql.database'),
        );

        // CACHE_STORE=array em phpunit.xml é por processo, não por
        // teste: o rate limiter (baseado no cache) acumula contadores
        // de um teste pro outro, e até de um arquivo pro outro, dentro
        // da mesma execução de "php artisan test". Isso derrubava
        // testes de endpoints com throttle (ex.: /api/agent/install-
        // requests) com 429 em vez do status esperado, dependendo só
        // da ordem/quantidade de testes já executados antes. Limpar o
        // cache a cada teste evita que a ordem de execução afete o
        // resultado.
        Cache::flush();
    }
}
