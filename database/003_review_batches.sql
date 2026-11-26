CREATE TABLE IF NOT EXISTS review_batches (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    items JSON NOT NULL,
    completed TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, actor, request_id),
    KEY recent_batches (tenant, environment, actor, created_at, request_id),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS review_assignments (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assignee VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (tenant, environment, run, node),
    KEY reviewer_lookup (tenant, environment, assignee, run, node),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;
