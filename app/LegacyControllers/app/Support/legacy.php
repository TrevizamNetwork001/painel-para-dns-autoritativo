<?php

declare(strict_types=1);

require_once __DIR__ . '/paths.php';

function panel_include_path(string $relativePath): string
{
    return PANEL_INCLUDE_DIR . '/' . ltrim($relativePath, '/');
}

function panel_root_path(string $relativePath = ''): string
{
    $relativePath = ltrim($relativePath, '/');

    return $relativePath === ''
        ? PANEL_ROOT
        : PANEL_ROOT . '/' . $relativePath;
}
