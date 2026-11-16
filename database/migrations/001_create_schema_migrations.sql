CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    migration_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    applied_at DATETIME(6) NOT NULL,
    execution_ms INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (version),
    UNIQUE KEY uq_schema_migrations_name (migration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
