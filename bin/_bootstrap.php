<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('MRNEON_PROJECT_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class)) return;
    foreach (['app/core', 'app/support', 'app/repositories', 'app/validators'] as $directory) {
        $path = MRNEON_PROJECT_ROOT . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $class . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
