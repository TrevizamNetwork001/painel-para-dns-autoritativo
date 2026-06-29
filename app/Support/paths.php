<?php

declare(strict_types=1);

if (!defined('PANEL_ROOT')) {
    define('PANEL_ROOT', dirname(__DIR__, 2));
}

if (!defined('PANEL_APP_DIR')) {
    define('PANEL_APP_DIR', PANEL_ROOT . '/app');
}

if (!defined('PANEL_INCLUDE_DIR')) {
    define('PANEL_INCLUDE_DIR', PANEL_ROOT . '/includes');
}

if (!defined('PANEL_STORAGE_DIR')) {
    define('PANEL_STORAGE_DIR', PANEL_ROOT . '/storage');
}

if (!defined('PANEL_DB_DIR')) {
    define('PANEL_DB_DIR', PANEL_ROOT . '/db');
}

if (!defined('PANEL_DOCS_DIR')) {
    define('PANEL_DOCS_DIR', PANEL_ROOT . '/docs');
}
