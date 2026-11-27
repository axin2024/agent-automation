<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Cases
{
    public function __construct(private CaseStore $store)
    {
    }

    public function intake(Scope $scope, mixed $key, array $input): array
    {
        $scope->require('operator', 'trigger');
        $reference = [
            'case_type' => Values::identifier($input['case_type'] ?? null, 'case_type'),
            'source' => Values::identifier($input['source'] ?? null, 'source'),
            'external_ref' => Values::text($input['external_ref'] ?? null, 'external_ref', 128),
            'customer_ref' => Values::text($input['customer_ref'] ?? null, 'customer_ref', 96),
            'subject' => Values::text($input['subject'] ?? null, 'subject', 350),
            'order_no' => isset($input['order_no']) ? Orders::orderNumber(Values::text($input['order_no'], 'order_no', 32)) : null,
        ];
        return $this->store->change($scope, $key, 'intake', '', $reference, function () use ($scope, $reference): array {
            $db = $this->store->db;
            $id = CaseStore::id();
            $signature = Values::digest($reference);
            $prior = $this->external($scope, $reference, $signature);
            if ($prior !== null) {
                return $prior;
            }
            $policy = $db->one('SELECT enabled FROM case_intake_policy WHERE tenant=? AND environment=? FOR SHARE', $scope->keys());
            if (!($policy['enabled'] ?? false)) {
                throw new Problem(503, 'case-intake-paused', 'This workspace is not accepting new cases.');
            }
            $template = $this->store->templates->get($scope, $reference['case_type']);
            $definition = $template['definition'];
            if (!in_array($reference['source'], $definition['sources'], true)) {
                throw Problem::invalid('This source is unavailable for the case type.');
            }
            $db->exec('INSERT INTO service_cases(tenant,environment,case_id,case_type,case_type_rev,source,external_ref,
                    intake_hash,subject,customer_ref,order_no,state,deadline,opened_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,\'investigating\',?,?) ON DUPLICATE KEY UPDATE case_id=case_id', [
                ...$scope->keys(), $id, $reference['case_type'], $template['case_type_rev'], $reference['source'],
                $reference['external_ref'], $signature, $reference['subject'], $reference['customer_ref'], $reference['order_no'],
                $this->store->deadline($definition, 'investigate'), $this->store->now()->format('Y-m-d H:i:s.u'),
            ]);
            $result = $this->external($scope, $reference, $signature);
            if ($result['case_id'] === $id) {
                $this->store->createTask($scope, $id, 1, 'investigate', $definition);
                $this->store->event($scope, 'case-opened', $id, ['case_type_rev' => $template['case_type_rev'], 'case_round' => 1]);
            }
            return $result;
        });
    }

    private function external(Scope $scope, array $reference, string $signature): ?array
    {
        $row = $this->store->db->one('SELECT case_id,case_type_rev,intake_hash FROM service_cases
            WHERE tenant=? AND environment=? AND source=? AND external_ref=?',
            [...$scope->keys(), $reference['source'], $reference['external_ref']]);
        if ($row === null) {
            return null;
        }
        if (!hash_equals($row['intake_hash'], $signature)) {
            throw Problem::conflict('This external reference already has different intake content.');
        }
        return ['case_id' => $row['case_id'], 'case_type_rev' => $row['case_type_rev']];
    }

    public function queue(Scope $scope, string $state, string $mine, string $before, int $limit, string $search = ''): array
    {
        $scope->require('operator', 'approver', 'author');
        $parameters = $scope->keys();
        $sql = 'SELECT c.case_id,c.case_type,c.subject,c.external_ref,c.case_round,c.state,c.revision,c.evidence_rev,c.deadline,c.escalated
            FROM service_cases c WHERE c.tenant=? AND c.environment=?';
        if ($state !== '') {
            $sql .= ' AND c.state=?';
            $parameters[] = Values::identifier($state, 'state');
        }
        if ($mine === 'yes') {
            $sql .= " AND EXISTS (SELECT 1 FROM case_assignments a
                WHERE a.tenant=c.tenant AND a.environment=c.environment AND a.case_id=c.case_id AND a.case_round=c.case_round
                AND a.state<>'complete' AND (a.assignee=? OR (a.delegate=? AND a.delegated_until>UTC_TIMESTAMP(6))))";
            array_push($parameters, $scope->actor, $scope->actor);
        }
        if ($before !== '') {
            $sql .= ' AND c.deadline<=?';
            $parameters[] = Values::utcSecond($before, 'due_before');
        }
        if ($search !== '') {
            $search = Values::text($search, 'search', 128);
            $sql .= " AND (c.case_id=? OR c.external_ref=? OR c.subject LIKE ? ESCAPE '!')";
            array_push($parameters, $search, $search, '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        $parameters[] = Values::number($limit, 'limit', 1, 100);
        return $this->store->db->all($sql . ' ORDER BY c.deadline,c.case_id LIMIT ?', $parameters);
    }

    public function detail(Scope $scope, string $id): array
    {
        $scope->require('operator', 'approver', 'author');
        $row = $this->store->record($scope, $id);
        unset($row['intake_hash']);
        $parameters = [...$scope->keys(), $id];
        $db = $this->store->db;
        $row['template'] = $this->store->template($scope, $row);
        $row['assignments'] = $db->all('SELECT case_round,task_role,assignment_id,assignee,delegate,delegated_until,
            lease_rev,state,due_at,handoff,completed_by,evidence_rev FROM case_assignments
            WHERE tenant=? AND environment=? AND case_id=? ORDER BY case_round, task_role', $parameters);
        $row['escalations'] = $db->all('SELECT case_round,escalation_id,reason,recipient,created_at FROM case_escalations
            WHERE tenant=? AND environment=? AND case_id=? ORDER BY created_at', $parameters);
        $row['runs'] = $db->all('SELECT l.run,l.actor,l.linked_at,r.flow,r.graph_revision,r.state
            FROM case_run_links l JOIN runs r ON r.tenant=l.tenant AND r.environment=l.environment AND r.id=l.run
            WHERE l.tenant=? AND l.environment=? AND l.case_id=? ORDER BY l.linked_at', $parameters);
        return $row;
    }

    public function timeline(Scope $scope, string $id, ?int $after = 0): array
    {
        $scope->require('operator', 'approver', 'author');
        $case = $this->store->record($scope, $id);
        $people = $this->store->template($scope, $case)['people'];
        $query = 'SELECT event_id AS id,actor,action,detail,happened_at FROM case_events
            WHERE tenant=? AND environment=? AND case_id=?';
        $parameters = [...$scope->keys(), $id];
        $rows = $after === null
            ? $this->store->db->all($query . ' ORDER BY event_id', $parameters)
            : $this->store->db->all($query . ' AND event_id>? ORDER BY event_id LIMIT 200', [...$parameters, $after]);
        foreach ($rows as &$row) {
            $row['detail'] = Values::decode($row['detail']);
            $visibility = $row['detail']['visibility'] ?? 'workspace';
            if (!CaseEvidence::visible($scope, $people, $visibility)) {
                $row['detail'] = ['restricted' => true];
            }
        }
        return $rows;
    }

    public function linkRun(Scope $scope, string $id, mixed $key, array $input): array
    {
        $scope->require('operator');
        return $this->store->change($scope, $key, 'link-run', $id, $input, function () use ($scope, $id, $input): array {
            $row = $this->store->record($scope, $id, true);
            $run = Values::text($input['run'] ?? null, 'run', 32, 32);
            $definition = $this->store->template($scope, $row)['workflow'];
            $entry = $this->store->db->one('SELECT flow,graph_revision FROM runs WHERE tenant=? AND environment=? AND id=?',
                [...$scope->keys(), $run]) ?? throw Problem::missing();
            if ($definition === null || $definition['name'] !== $entry['flow'] || $definition['revision'] !== $entry['graph_revision']) {
                throw Problem::conflict('The run does not use the workflow version pinned to this case.');
            }
            $changed = $this->store->db->exec('INSERT IGNORE INTO case_run_links(tenant,environment,case_id,run,actor)
                VALUES(?,?,?,?,?)', [...$scope->keys(), $id, $run, $scope->actor]);
            if ($changed === 1) {
                $this->store->event($scope, 'case-run-linked', $id, ['run' => $run, 'workflow' => $definition]);
            }
            return ['case_id' => $id, 'run' => $run];
        });
    }

    public function reopen(Scope $scope, string $id, mixed $key, array $input): array
    {
        $scope->require('admin');
        return $this->store->change($scope, $key, 'reopen', $id, $input, function () use ($scope, $id, $input): array {
            $case = $this->store->open($scope, $id, $input, 'closed');
            $reason = Values::text($input['reason'] ?? null, 'reason', 350);
            $next = $case['case_round'] + 1;
            $this->store->db->exec('UPDATE service_cases SET case_round=?,closed_at=NULL
                WHERE tenant=? AND environment=? AND case_id=?', [$next, ...$scope->keys(), $id]);
            $this->store->createTask($scope, $id, $next, 'investigate', $this->store->template($scope, $case));
            $this->store->transition($scope, array_replace($case, ['case_round' => $next]), 'investigating', 'investigate',
                ['reason' => $reason, 'previous_round' => $case['case_round']]);
            return ['case_id' => $id, 'case_round' => $next, 'revision' => $case['revision'] + 1, 'state' => 'investigating'];
        });
    }

    public function escalate(): bool
    {
        $db = $this->store->db;
        $now = $this->store->now()->format('Y-m-d H:i:s.u');
        $due = $db->one("SELECT tenant,environment,case_id FROM service_cases
            WHERE escalated=0 AND deadline<=? AND state<>'closed' ORDER BY deadline LIMIT 1", [$now]);
        if ($due === null) {
            return false;
        }
        return $db->transaction(function () use ($db, $due, $now): bool {
            $scope = new Scope($due['tenant'], $due['environment'], 'case-worker', 'admin');
            $row = $this->store->record($scope, $due['case_id'], true);
            if ($row['state'] === 'closed' || $row['deadline'] > $now || $row['escalated']) {
                return true;
            }
            $id = CaseStore::id();
            $recipient = $this->store->template($scope, $row)['escalation_recipient'];
            $changed = $db->exec("INSERT INTO case_escalations(tenant,environment,case_id,case_round,escalation_id,reason,recipient)
                VALUES(?,?,?,?,?,'sla-expired',?) ON DUPLICATE KEY UPDATE reason=reason",
                [...$scope->keys(), $row['case_id'], $row['case_round'], $id, $recipient]);
            $db->exec('UPDATE service_cases SET escalated=1 WHERE tenant=? AND environment=? AND case_id=?',
                [...$scope->keys(), $row['case_id']]);
            if ($changed === 1) {
                $this->store->event($scope, 'case-escalated', $row['case_id'], ['case_round' => $row['case_round'],
                    'escalation_id' => $id, 'recipient' => $recipient, 'deadline' => $row['deadline']]);
            }
            return true;
        });
    }

    public function metrics(Scope $scope): array
    {
        $scope->require('admin');
        $db = $this->store->db;
        return [
            'intake_enabled' => (bool) ($db->one('SELECT enabled FROM case_intake_policy WHERE tenant=? AND environment=?',
                $scope->keys())['enabled'] ?? false),
            'by_stage' => $db->all('SELECT state,COUNT(*) AS total,SUM(escalated) AS overdue FROM service_cases
                WHERE tenant=? AND environment=? GROUP BY state ORDER BY state', $scope->keys()),
            'by_type' => $db->all("SELECT case_type,COUNT(*) AS total,SUM(case_round>1) AS reopened,
                AVG(IF(closed_at IS NULL,NULL,TIMESTAMPDIFF(MINUTE,opened_at,closed_at))) AS mean_minutes
                FROM service_cases WHERE tenant=? AND environment=? GROUP BY case_type ORDER BY case_type", $scope->keys()),
            'decisions' => $db->all('SELECT state,COUNT(*) AS total FROM case_decisions
                WHERE tenant=? AND environment=? GROUP BY state ORDER BY state', $scope->keys()),
        ];
    }

    public function setIntake(Scope $scope, bool $enabled): array
    {
        $scope->require('admin');
        return $this->store->db->transaction(function () use ($scope, $enabled): array {
            $this->store->db->exec('INSERT INTO case_intake_policy(tenant,environment,enabled) VALUES(?,?,?)
                ON DUPLICATE KEY UPDATE enabled=?', [...$scope->keys(), (int) $enabled, (int) $enabled]);
            $this->store->db->audit($scope, 'case-intake-policy', $scope->tenant, ['enabled' => $enabled]);
            return ['intake_enabled' => $enabled];
        });
    }

    public function preflight(Scope $scope): array
    {
        $scope->require('admin');
        $db = $this->store->db;
        $migrations = $db->all('SELECT version FROM schema_migrations ORDER BY version');
        return [
            'tenant' => $scope->tenant, 'environment' => $scope->environment, 'schema' => array_column($migrations, 'version'),
            'legacy_runs' => $db->all('SELECT state,COUNT(*) AS total FROM runs WHERE tenant=? AND environment=? GROUP BY state ORDER BY state', $scope->keys()),
            'legacy_pending' => $db->all("SELECT id,state FROM runs WHERE tenant=? AND environment=?
                AND state IN ('queued','running','waiting-input','waiting-review','waiting-receipt') ORDER BY id LIMIT 100", $scope->keys()),
            'ownership_conflicts' => $db->one('SELECT COUNT(*) AS total FROM runs r LEFT JOIN graph_versions g
                ON g.tenant=r.tenant AND g.environment=r.environment AND g.flow=r.flow AND g.revision=r.graph_revision
                WHERE r.tenant=? AND r.environment=? AND g.flow IS NULL', $scope->keys())['total'],
        ];
    }
}
