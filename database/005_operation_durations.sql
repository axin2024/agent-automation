CREATE TABLE IF NOT EXISTS operation_durations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind ENUM('human-review','human-correction','local-orders','local-inbox') NOT NULL,
    microseconds BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY duration_time (tenant,environment,recorded_at,id),
    FOREIGN KEY (tenant,environment,run) REFERENCES runs(tenant,environment,id)
) ENGINE=InnoDB;
