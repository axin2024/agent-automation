CREATE TABLE IF NOT EXISTS tenants (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    max_open INT UNSIGNED NOT NULL DEFAULT 64,
    daily_tokens INT UNSIGNED NOT NULL DEFAULT 10000
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS credentials (
    digest BINARY(32) PRIMARY KEY,
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    role ENUM('admin','author','approver','operator','trigger') NOT NULL,
    expires DATETIME(6) NOT NULL,
    revoked DATETIME(6),
    KEY actor_lookup (tenant, environment, actor),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS connections (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    connection VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    capability ENUM('orders','model','inbox') NOT NULL,
    active_revision INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (tenant, environment, connection),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS connection_versions (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    connection VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    settings JSON NOT NULL,
    encrypted_secret VARBINARY(4200),
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, connection, revision),
    FOREIGN KEY (tenant, environment, connection) REFERENCES connections(tenant, environment, connection)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflows (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    flow VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_revision INT UNSIGNED,
    PRIMARY KEY (tenant, environment, flow),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS graph_versions (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    flow VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    graph JSON NOT NULL,
    bindings JSON NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, flow, revision),
    UNIQUE KEY graph_content (tenant, environment, flow, digest),
    FOREIGN KEY (tenant, environment, flow) REFERENCES workflows(tenant, environment, flow)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    order_no VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('placed','shipped','delivered','returned','cancelled') NOT NULL,
    version INT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, order_no),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;
