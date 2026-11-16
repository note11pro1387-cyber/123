CREATE TABLE internal_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    admin_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_internal_notes_project_timeline (project_id, created_at, id),
    KEY ix_internal_notes_admin (admin_id, created_at),
    CONSTRAINT fk_internal_notes_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
