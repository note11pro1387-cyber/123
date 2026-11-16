CREATE TABLE project_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    actor_type ENUM('user','admin','system') NOT NULL,
    actor_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    from_status ENUM('submitted','under_review','needs_information','in_progress','waiting_for_client','completed','rejected','cancelled') NULL,
    to_status ENUM('submitted','under_review','needs_information','in_progress','waiting_for_client','completed','rejected','cancelled') NULL,
    message VARCHAR(1000) NULL,
    metadata_json LONGTEXT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_project_events_timeline (project_id, created_at, id),
    KEY ix_project_events_actor (actor_type, actor_id, created_at),
    CONSTRAINT fk_project_events_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
