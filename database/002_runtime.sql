CREATE TABLE IF NOT EXISTS runs (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    flow VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    graph_revision INT UNSIGNED NOT NULL,
    state ENUM('queued','running','waiting-input','waiting-review','waiting-receipt','completed','failed','cancelled') NOT NULL,
    `cursor` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'start',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    attempt INT UNSIGNED NOT NULL DEFAULT 0,
    runner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    lease_until DATETIME(6),
    event JSON NOT NULL,
    outputs JSON NOT NULL,
    override_order VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    result VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    ready_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, id),
    KEY worker_queue (state, ready_at, lease_until, id),
    KEY run_status (tenant, environment, state, created_at, id),
    FOREIGN KEY (tenant, environment, flow, graph_revision) REFERENCES graph_versions(tenant, environment, flow, revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_keys (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_id VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (tenant, environment, source, event_id),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS node_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    outcome VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detail JSON NOT NULL,
    happened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY node_time (tenant, environment, run, id),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tickets (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ticket CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    message VARCHAR(180) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_order VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    corrected_order VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    PRIMARY KEY (tenant, environment, run),
    UNIQUE KEY ticket_lookup (tenant, environment, ticket),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS approvals (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    connection VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    connection_revision INT UNSIGNED NOT NULL,
    draft JSON NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('pending','approved','rejected','invalid') NOT NULL,
    expires DATETIME(6) NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    decided_at DATETIME(6),
    PRIMARY KEY (tenant, environment, run, node, revision),
    KEY review_queue (tenant, environment, state, expires, run),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id),
    FOREIGN KEY (tenant, environment, connection, connection_revision)
        REFERENCES connection_versions(tenant, environment, connection, revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS deliveries (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    operation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approval_node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approval_revision INT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('pending','dispatching','unknown','sent','rejected') NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    response_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, operation),
    UNIQUE KEY deliver_approval (tenant, environment, run, node, approval_revision),
    KEY deliver_queue (state, updated_at, operation),
    FOREIGN KEY (tenant, environment, run, approval_node, approval_revision)
        REFERENCES approvals(tenant, environment, run, node, revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inbox (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    operation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient VARCHAR(254) NOT NULL,
    body TEXT NOT NULL,
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, operation),
    KEY inbox_time (tenant, environment, received_at, receipt),
    FOREIGN KEY (tenant, environment, operation) REFERENCES deliveries(tenant, environment, operation)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    object VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detail JSON NOT NULL,
    happened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY audit_cursor (tenant, environment, id),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS model_usage (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    mode ENUM('rule','live','replay') NOT NULL,
    provider VARCHAR(100) NOT NULL,
    state ENUM('reserved','complete','failed','unknown') NOT NULL,
    reserved_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED,
    output_tokens INT UNSIGNED,
    milliseconds INT UNSIGNED,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, run, node, attempt),
    KEY model_budget (tenant, environment, mode, started_at),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;
