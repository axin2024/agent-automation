CREATE TABLE IF NOT EXISTS case_intake_policy (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    enabled TINYINT(1) NOT NULL,
    PRIMARY KEY (tenant, environment),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_type_versions (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    revision INT UNSIGNED NOT NULL,
    definition JSON NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_type, revision),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_cases (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_type_rev INT UNSIGNED NOT NULL,
    source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    external_ref VARCHAR(128) NOT NULL,
    intake_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject VARCHAR(350) NOT NULL,
    customer_ref VARCHAR(96) NOT NULL,
    order_no VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    case_round INT UNSIGNED NOT NULL DEFAULT 1,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    evidence_rev INT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('new','investigating','needs-evidence','review','decision','awaiting-votes','ready-to-dispatch','reconcile','closed') NOT NULL DEFAULT 'new',
    deadline DATETIME(6) NOT NULL,
    opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    closed_at DATETIME(6),
    escalated TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (tenant, environment, case_id),
    UNIQUE KEY case_external (tenant, environment, source, external_ref),
    KEY case_todo (tenant, environment, state, deadline, case_id),
    KEY case_deadline (tenant, environment, deadline, case_id),
    KEY case_calendar (escalated, deadline, state, tenant, environment, case_id),
    FOREIGN KEY (tenant, environment, case_type, case_type_rev) REFERENCES case_type_versions(tenant, environment, case_type, revision)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_assignments (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    task_role VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assignment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    assignee VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    delegate VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    delegated_until DATETIME(6),
    lease_rev INT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM('pending','claimed','complete') NOT NULL DEFAULT 'pending',
    due_at DATETIME(6) NOT NULL,
    handoff VARCHAR(1024),
    completed_by VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    evidence_rev INT UNSIGNED,
    PRIMARY KEY (tenant, environment, case_id, case_round, task_role),
    UNIQUE KEY assignment_id (tenant, environment, assignment_id),
    KEY assignee_queue (tenant, environment, assignee, state, case_id),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_evidence (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_rev INT UNSIGNED NOT NULL,
    evidence_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    kind VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    visibility VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    verified TINYINT(1) NOT NULL,
    allowed_fields JSON NOT NULL,
    payload JSON NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, evidence_rev),
    UNIQUE KEY case_evidence_identity (tenant, environment, case_id, evidence_id),
    KEY case_evidence_kind (tenant, environment, case_id, case_round, kind, evidence_rev),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_proposals (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    proposal_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_rev INT UNSIGNED NOT NULL,
    assistant_role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('pending','ready','needs-human','stale','interrupted','superseded') NOT NULL,
    suggestion JSON,
    operational_args JSON,
    args_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin,
    usage_data JSON,
    based_on CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, proposal_id),
    KEY case_proposal_round (tenant, environment, case_id, case_round, evidence_rev, state),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_decisions (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    decision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    proposal_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_rev INT UNSIGNED NOT NULL,
    args_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    required_votes TINYINT UNSIGNED NOT NULL,
    state ENUM('awaiting-votes','queued','executed','needs-check','invalidated') NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, decision_id),
    KEY case_open_decision (tenant, environment, case_id, case_round, state),
    FOREIGN KEY (tenant, environment, case_id, proposal_id) REFERENCES case_proposals(tenant, environment, case_id, proposal_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_votes (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    approval_role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    evidence_rev INT UNSIGNED NOT NULL,
    args_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    signed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, decision_id, approval_role),
    UNIQUE KEY distinct_case_signer (tenant, environment, case_id, decision_id, actor),
    FOREIGN KEY (tenant, environment, case_id, decision_id) REFERENCES case_decisions(tenant, environment, case_id, decision_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_dispatch (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    decision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('queued','needs-check','executed','invalidated') NOT NULL,
    receipt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin,
    failure VARCHAR(160),
    accepted_at DATETIME(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, decision_id),
    UNIQUE KEY business_operation (tenant, environment, operation),
    KEY case_dispatch_queue (state, created_at, tenant, environment, case_id),
    FOREIGN KEY (tenant, environment, case_id, decision_id) REFERENCES case_decisions(tenant, environment, case_id, decision_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_connector_receipts (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    operation CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    receipt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    outcome JSON NOT NULL,
    accepted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, operation),
    FOREIGN KEY (tenant, environment, operation) REFERENCES case_dispatch(tenant, environment, operation)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_escalations (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_round INT UNSIGNED NOT NULL,
    escalation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recipient VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, case_round, reason),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_request_receipts (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload JSON,
    PRIMARY KEY (tenant, environment, actor, request_id),
    FOREIGN KEY (tenant) REFERENCES tenants(tenant)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_run_links (
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    run CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (tenant, environment, case_id, run),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id),
    FOREIGN KEY (tenant, environment, run) REFERENCES runs(tenant, environment, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS case_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    environment ENUM('test','production') NOT NULL,
    case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    action VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    detail JSON NOT NULL,
    happened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY case_timeline (tenant, environment, case_id, event_id),
    FOREIGN KEY (tenant, environment, case_id) REFERENCES service_cases(tenant, environment, case_id)
) ENGINE=InnoDB;
