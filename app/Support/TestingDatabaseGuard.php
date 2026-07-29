<?php

namespace App\Support;

use RuntimeException;

final class TestingDatabaseGuard
{
    public static function assertSafe(
        string $environment,
        ?string $database,
    ): void {
        if ($environment !== 'testing') {
            throw new RuntimeException(
                'A suíte só pode rodar com APP_ENV=testing.'
            );
        }

        $database = trim((string) $database);

        if (
            $database === ''
            || $database === 'dns_center'
            || ! str_ends_with($database, '_testing')
        ) {
            throw new RuntimeException(
                "Banco inseguro para testes: {$database}"
            );
        }
    }
}
