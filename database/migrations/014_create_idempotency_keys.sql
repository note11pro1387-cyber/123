CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    scope_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    route VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    response_status SMALLINT UNSIGNED NULL,
    response_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_scope_route_key (scope_type, scope_id, route, key_hash),
    KEY ix_idempotency_expiry (expires_at),
    CONSTRAINT chk_idempotency_status CHECK (response_status IS NULL OR response_status BETWEEN 100 AND 599)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
