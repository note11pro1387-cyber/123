<?php
declare(strict_types=1);

/** A safe, lazy PDO connection factory for MariaDB/MySQL. */
final class Database
{
    private static ?PDO $connection = null;

    /** @var array<string,mixed> */
    public static function configuration(array $overrides = []): array
    {
        $env = static function (string $name, string $default = ''): string {
            $value = getenv($name);
            return $value === false ? $default : trim((string) $value);
        };
        $envRaw = static function (string $name, string $default = ''): string {
            $value = getenv($name);
            return $value === false ? $default : (string) $value;
        };

        $config = [
            'host' => $env('MRNEON_DB_HOST'),
            'port' => $env('MRNEON_DB_PORT', '3306'),
            'name' => $env('MRNEON_DB_NAME'),
            'user' => $env('MRNEON_DB_USER'),
            'password' => $envRaw('MRNEON_DB_PASSWORD'),
            'charset' => $env('MRNEON_DB_CHARSET', 'utf8mb4'),
            'connect_timeout' => $env('MRNEON_DB_CONNECT_TIMEOUT', '5'),
            'ssl_ca' => $env('MRNEON_DB_SSL_CA'),
            'require_tls' => $env('MRNEON_DB_REQUIRE_TLS') === '1',
            'allow_empty_password' => $env('MRNEON_DB_ALLOW_EMPTY_PASSWORD') === '1',
        ];

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $config)) {
                $config[$key] = $value;
            }
        }
        if (!array_key_exists('require_tls', $overrides) && getenv('MRNEON_DB_REQUIRE_TLS') === false) {
            $host = strtolower(trim((string) $config['host']));
            $config['require_tls'] = $host !== '' && !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        }
        return $config;
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }
        self::$connection = self::connect();
        return self::$connection;
    }

    /** Creates a new connection; overrides support isolated integration tests. */
    public static function connect(array $overrides = []): PDO
    {
        $config = self::configuration($overrides);
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('Database unavailable: the PHP pdo_mysql driver is not installed.');
        }
        self::validateConfiguration($config);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            (int) $config['port'],
            $config['name'],
            $config['charset']
        );
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => (int) $config['connect_timeout'],
        ];

        $configuredHost = strtolower(trim((string) $config['host']));
        $loopbackHost = in_array($configuredHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if ((!$loopbackHost || !empty($config['require_tls'])) && (string) $config['ssl_ca'] === '') {
            throw new RuntimeException('Remote or TLS-required database connections require MRNEON_DB_SSL_CA and verified TLS.');
        }
        if ((string) $config['ssl_ca'] !== '') {
            if (!is_file($config['ssl_ca']) || !is_readable($config['ssl_ca'])) {
                throw new RuntimeException('Database TLS CA file is missing or unreadable.');
            }
            if (!defined('PDO::MYSQL_ATTR_SSL_CA') || !defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('This PDO MySQL build cannot enforce CA and server-certificate verification.');
            }
            $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = (string) $config['ssl_ca'];
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;
        }
        if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $options[constant('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')] = (int) $config['connect_timeout'];
        }

        try {
            $pdo = new PDO($dsn, (string) $config['user'], (string) $config['password'], $options);
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        } catch (Throwable $exception) {
            // Do not relay PDO's DSN/driver details or credentials to callers.
            throw new RuntimeException('Database connection failed. Check private DB configuration, service availability, and grants.');
        }
    }

    public static function resetForTests(): void
    {
        self::$connection = null;
    }

    /** A report-safe identity. Never includes host credentials or DSN. */
    public static function safeIdentity(array $overrides = []): array
    {
        $config = self::configuration($overrides);
        return [
            'engine' => 'MariaDB/MySQL via PDO',
            'database' => preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $config['name']) ? $config['name'] : '(not configured)',
            'host_configured' => (string) $config['host'] !== '',
            'user_configured' => (string) $config['user'] !== '',
            'tls_ca_configured' => (string) $config['ssl_ca'] !== '',
            'pdo_mysql_driver_available' => in_array('mysql', PDO::getAvailableDrivers(), true),
        ];
    }

    private static function validateConfiguration(array $config): void
    {
        foreach (['host', 'name', 'user'] as $required) {
            if (!is_string($config[$required]) || trim($config[$required]) === '') {
                throw new RuntimeException('Database configuration incomplete: set MRNEON_DB_' . strtoupper($required) . '.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $config['name'])) {
            throw new RuntimeException('Database name must use only letters, digits, and underscores (max 64 characters).');
        }
        if (!ctype_digit((string) $config['port']) || (int) $config['port'] < 1 || (int) $config['port'] > 65535) {
            throw new RuntimeException('Database port configuration is invalid.');
        }
        if (!in_array((string) $config['charset'], ['utf8mb4'], true)) {
            throw new RuntimeException('Only utf8mb4 is supported for database connections.');
        }
        if (!ctype_digit((string) $config['connect_timeout']) || (int) $config['connect_timeout'] < 1 || (int) $config['connect_timeout'] > 60) {
            throw new RuntimeException('Database connect timeout must be between 1 and 60 seconds.');
        }
        if ((string) $config['password'] === '' && empty($config['allow_empty_password'])) {
            throw new RuntimeException('An empty database password is refused unless MRNEON_DB_ALLOW_EMPTY_PASSWORD=1 is explicitly set for local development.');
        }
        if ((string) $config['password'] === '' && !empty($config['allow_empty_password'])) {
            $host = strtolower(trim((string) $config['host']));
            if (!in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
                throw new RuntimeException('An empty database password is permitted only for a loopback development database.');
            }
        }
    }
}
