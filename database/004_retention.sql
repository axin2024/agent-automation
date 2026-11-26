CREATE TABLE IF NOT EXISTS retention_policies (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    body_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metering_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    audit_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant, environment),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;
