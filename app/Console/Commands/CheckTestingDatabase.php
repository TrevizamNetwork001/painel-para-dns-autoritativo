<?php

namespace App\Console\Commands;

use App\Support\TestingDatabaseGuard;
use Illuminate\Console\Command;
use Throwable;

class CheckTestingDatabase extends Command
{
    protected $signature = 'dns-center:check-testing-database';

    protected $description = 'Confirma que o ambiente e o banco de testes são seguros';

    public function handle(): int
    {
        $environment = (string) app()->environment();
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->line('APP_ENV: '.$environment);
        $this->line('DB_CONNECTION: '.$connection);
        $this->line('DB_DATABASE: '.$database);

        try {
            if ($connection !== 'pgsql') {
                throw new \RuntimeException(
                    'A suíte deve usar explicitamente DB_CONNECTION=pgsql.'
                );
            }

            TestingDatabaseGuard::assertSafe($environment, $database);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Banco de testes permitido.');

        return self::SUCCESS;
    }
}
