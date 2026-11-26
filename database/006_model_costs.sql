CREATE TABLE IF NOT EXISTS model_costs (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    node VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt INT UNSIGNED NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    input_per_million_micros BIGINT UNSIGNED NOT NULL,
    output_per_million_micros BIGINT UNSIGNED NOT NULL,
    estimate_micros BIGINT UNSIGNED,
    PRIMARY KEY (tenant,environment,run,node,attempt),
    FOREIGN KEY (tenant,environment,run,node,attempt) REFERENCES model_usage(tenant,environment,run,node,attempt) ON DELETE CASCADE
) ENGINE=InnoDB;
