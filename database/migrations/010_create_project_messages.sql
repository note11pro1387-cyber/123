CREATE TABLE project_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('user','admin','system') NOT NULL,
    sender_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
    body TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL,
    read_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY ix_project_messages_timeline (project_id, created_at, id),
    KEY ix_project_messages_unread (project_id, read_at, created_at),
    CONSTRAINT fk_project_messages_project FOREIGN KEY (project_id) REFERENCES projects(id) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
