<?php

declare(strict_types=1);

namespace AgentAutomation;

final class CaseDispatch
{
    public function __construct(private CaseStore $store)
    {
    }

    public function pump(): bool
    {
        $row = $this->store->db->one("SELECT tenant,environment,case_id,decision_id,state
            FROM case_dispatch WHERE state IN ('queued','needs-check') ORDER BY created_at,decision_id LIMIT 1");
        if ($row === null) {
            return false;
        }
        $scope = new Scope($row['tenant'], $row['environment'], 'case-worker', 'admin');
        if ($row['state'] === 'queued') {
            $this->accept($scope, $row['case_id'], $row['decision_id']);
        }
        $this->readback($scope, $row['case_id'], $row['decision_id']);
        return true;
    }

    public function reconcile(Scope $scope, string $id, string $decision): array
    {
        $scope->require('approver');
        $case = $this->store->record($scope, $id);
        if ($scope->role !== 'admin' && !in_array($scope->actor, $this->store->template($scope, $case)['people']['review-finance'], true)) {
            throw Problem::denied();
        }
        $this->readback($scope, $id, $decision);
        return $this->store->db->one('SELECT decision_id,state,operation,receipt_id,failure FROM case_dispatch
            WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?',
            [...$scope->keys(), $id, $decision]) ?? throw Problem::missing();
    }

    public function accept(Scope $scope, string $id, string $decision): void
    {
        $scope->require('admin');
        // Acceptance commits before readback. A new worker can resume from the same operation after interruption.
        $db = $this->store->db;
        $db->transaction(function () use ($scope, $id, $decision, $db): void {
            $row = $this->store->record($scope, $id, true);
            $args = [...$scope->keys(), $id, $decision];
            $work = $db->one('SELECT x.operation,x.state,d.case_round,d.evidence_rev,d.args_hash,d.required_votes,d.expires_at,
                p.operational_args,p.state AS proposal_state
                FROM case_dispatch x JOIN case_decisions d USING(tenant,environment,case_id,decision_id)
                JOIN case_proposals p ON p.tenant=d.tenant AND p.environment=d.environment AND p.case_id=d.case_id AND p.proposal_id=d.proposal_id
                WHERE x.tenant=? AND x.environment=? AND x.case_id=? AND x.decision_id=?', $args);
            if ($work === null || $work['state'] !== 'queued') {
                return;
            }
            $approval = $db->all('SELECT actor,approval_role,args_hash,evidence_rev FROM case_votes
                WHERE tenant=? AND environment=? AND case_id=? AND decision_id=? ORDER BY approval_role', $args);
            $operation = Values::decode($work['operational_args']);
            $valid = $row['state'] === 'ready-to-dispatch' && $row['case_round'] === $work['case_round'] &&
                $row['evidence_rev'] === $work['evidence_rev'] && $work['proposal_state'] === 'ready' &&
                $work['expires_at'] > $this->store->now()->format('Y-m-d H:i:s.u') &&
                hash_equals($work['args_hash'], Values::digest($operation)) &&
                $work['required_votes'] === 2 && count($approval) === 2 &&
                count(array_unique(array_column($approval, 'actor'))) === 2 &&
                array_column($approval, 'approval_role') === ['finance', 'operations'];
            if ($valid) {
                $people = $this->store->template($scope, $row)['people'];
                foreach ($approval as $vote) {
                    $task = 'review-' . $vote['approval_role'];
                    if (!in_array($vote['actor'], $people[$task], true) || $vote['evidence_rev'] !== $row['evidence_rev'] ||
                        !hash_equals($vote['args_hash'], $work['args_hash']) ||
                        !$this->store->actorActive($scope, $vote['actor'], CaseStore::role($task))) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                $db->exec("UPDATE case_dispatch SET state='invalidated',failure='authorization-no-longer-current'
                    WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", $args);
                $db->exec("UPDATE case_decisions SET state='invalidated' WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", $args);
                if ($row['state'] === 'ready-to-dispatch') {
                    $this->store->transition($scope, $row, 'decision', 'decision', ['decision_id' => $decision,
                        'reason' => 'authorization-no-longer-current']);
                }
                return;
            }
            $now = $this->store->now()->format('Y-m-d H:i:s.u');
            $db->exec('INSERT INTO case_connector_receipts(tenant,environment,operation,receipt_id,kind,outcome,accepted_at)
                VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE operation=operation', [...$scope->keys(), $work['operation'],
                CaseStore::id(), $operation['kind'], Values::json($operation + ['connector' => 'local-case-ledger']), $now]);
            $db->exec("UPDATE case_dispatch SET state='needs-check',accepted_at=?,failure='receipt-readback-pending'
                WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", [$now, ...$args]);
            $db->exec("UPDATE case_decisions SET state='needs-check' WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", $args);
            $this->store->transition($scope, $row, 'reconcile', 'decision',
                ['decision_id' => $decision, 'operation' => $work['operation'], 'reason' => 'receipt-readback-pending']);
        });
    }

    private function readback(Scope $scope, string $id, string $decision): void
    {
        $db = $this->store->db;
        $db->transaction(function () use ($scope, $id, $decision, $db): void {
            $row = $this->store->record($scope, $id, true);
            $args = [...$scope->keys(), $id, $decision];
            $result = $db->one('SELECT x.state,x.operation,r.receipt_id,r.accepted_at,d.case_round,p.actor AS author
                FROM case_dispatch x JOIN case_decisions d USING(tenant,environment,case_id,decision_id)
                JOIN case_proposals p ON p.tenant=d.tenant AND p.environment=d.environment AND p.case_id=d.case_id AND p.proposal_id=d.proposal_id
                JOIN case_connector_receipts r ON r.tenant=x.tenant AND r.environment=x.environment AND r.operation=x.operation
                WHERE x.tenant=? AND x.environment=? AND x.case_id=? AND x.decision_id=?', $args);
            if ($result === null || $result['state'] !== 'needs-check' || $row['state'] !== 'reconcile' ||
                $result['case_round'] !== $row['case_round']) {
                return;
            }
            $db->exec("UPDATE case_dispatch SET state='executed',receipt_id=?,failure=NULL
                WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", [$result['receipt_id'], ...$args]);
            $db->exec("UPDATE case_decisions SET state='executed' WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", $args);
            $db->exec("UPDATE case_assignments SET state='complete',lease_rev=lease_rev+1,completed_by=?,evidence_rev=?
                WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND task_role='decision'",
                [$result['author'], $row['evidence_rev'], ...$scope->keys(), $id, $row['case_round']]);
            $db->exec("UPDATE service_cases SET state='closed',closed_at=?,revision=revision+1 WHERE tenant=? AND environment=? AND case_id=?",
                [$result['accepted_at'], ...$scope->keys(), $id]);
            $this->store->event($scope, 'case-executed', $id, ['case_round' => $row['case_round'], 'decision_id' => $decision,
                'operation' => $result['operation'], 'receipt_id' => $result['receipt_id'], 'from' => 'reconcile', 'to' => 'closed']);
        });
    }
}
