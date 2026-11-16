CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type ENUM('user','admin','system','anonymous') NOT NULL,
    actor_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    action VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    subject_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    user_agent_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_audit_actor_timeline (actor_type, actor_id, created_at),
    KEY ix_audit_subject_timeline (subject_type, subject_id, created_at),
    KEY ix_audit_action_timeline (action, created_at),
    KEY ix_audit_request_id (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
