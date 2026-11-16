<?php
declare(strict_types=1);

/** Minimal project autoloader; no Composer/runtime dependency is introduced. */
if (!defined('MRNEON_PROJECT_ROOT')) {
    define('MRNEON_PROJECT_ROOT', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class)) {
        return;
    }
    foreach (['app/core', 'app/http', 'app/http/middleware', 'app/http/controllers', 'app/dto', 'app/security', 'app/services', 'app/support', 'app/repositories', 'app/validators', 'app/integrations/Verification', 'app/integrations/Http', 'app/integrations/Telegram', 'app/integrations/Email'] as $directory) {
        $path = MRNEON_PROJECT_ROOT . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $class . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
