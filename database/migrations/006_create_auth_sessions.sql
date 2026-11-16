CREATE TABLE auth_sessions (
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    principal_type ENUM('user','admin') NOT NULL,
    principal_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    last_activity_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    revoke_reason VARCHAR(64) NULL,
    PRIMARY KEY (session_hash),
    KEY ix_auth_sessions_principal (principal_type, principal_id, revoked_at, expires_at),
    KEY ix_auth_sessions_user (user_id, revoked_at, expires_at),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_auth_sessions_principal CHECK ((principal_type = 'user' AND user_id IS NOT NULL) OR (principal_type = 'admin' AND user_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
