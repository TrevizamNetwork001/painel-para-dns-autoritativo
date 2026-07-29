<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Support\TestingDatabaseGuard;

TestingDatabaseGuard::assertSafe(
    (string) ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV')),
    (string) ($_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE')),
);
