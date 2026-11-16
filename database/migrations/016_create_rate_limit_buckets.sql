CREATE TABLE rate_limit_buckets (
    bucket_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME(6) NOT NULL,
    reset_at DATETIME(6) NOT NULL,
    blocked_until DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (bucket_key_hash),
    KEY ix_rate_limit_reset (reset_at),
    KEY ix_rate_limit_blocked (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
