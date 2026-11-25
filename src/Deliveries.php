<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Deliveries
{
    public function __construct(
        private Db $db,
        private Runs $runs,
        private Workflows $flows,
        private Approvals $approvals,
    ) {
    }

    public function stage(Scope $scope, array $run, string $connection, array $ref): array
    {
        $approval = $this->approvals->latest($scope, $run['id'], $ref['node'], true);
        if ($approval === null || $approval['state'] !== 'approved' || $approval['revision'] !== $ref['revision'] ||
            $approval['connection'] !== $connection || !hash_equals($approval['digest'], $ref['digest'])) {
            throw Problem::conflict('Delivery authority is missing or stale.');
        }
        $operation = Values::digest([...$scope->keys(), $run['id'], $run['cursor'], $ref['digest']]);
        $this->db->exec("INSERT INTO deliveries(tenant,environment,operation,run,node,approval_node,approval_revision,digest,state)
            VALUES(?,?,?,?,?,?,?,?,'pending') ON DUPLICATE KEY UPDATE operation=operation",
            [...$scope->keys(), $operation, $run['id'], $run['cursor'], $ref['node'], $ref['revision'], $ref['digest']]);
        return ['operation' => $operation];
    }

    public function pump(bool $loseResponse = false): bool
    {
        $this->db->exec("UPDATE deliveries SET state='unknown' WHERE state='dispatching'
            AND updated_at<UTC_TIMESTAMP(6)-INTERVAL 35 SECOND LIMIT 20");
        $pending = $this->db->one("SELECT tenant,environment,operation,run FROM deliveries
            WHERE state='pending' ORDER BY updated_at,operation LIMIT 1");
        if ($pending === null) {
            return false;
        }
        $scope = new Scope($pending['tenant'], $pending['environment'], 'inbox-worker', 'worker');
        $operation = $pending['operation'];
        $committed = $this->db->transaction(function () use ($scope, $pending, $operation): bool {
            $run = $this->runs->row($scope, $pending['run'], true);
            $item = $this->operation($scope, $operation, true);
            if ($item['state'] !== 'pending' || $run['state'] !== 'waiting-receipt' || $run['cursor'] !== $item['node']) {
                return false;
            }
            $approval = $this->approvals->latest($scope, $run['id'], $item['approval_node'], true);
            if ($approval === null || $approval['revision'] !== $item['approval_revision'] || $approval['state'] !== 'approved' ||
                !hash_equals($approval['digest'], $item['digest']) || !$this->approvals->rechecked($scope, $approval)) {
                $this->db->exec("UPDATE deliveries SET state='rejected' WHERE tenant=? AND environment=? AND operation=?",
                    [...$scope->keys(), $operation]);
                $this->approvals->invalidate($scope, $run['id'], $item['approval_node'], $item['approval_revision']);
                $this->db->exec("UPDATE runs SET state='waiting-review',`cursor`=?,revision=revision+1
                    WHERE tenant=? AND environment=? AND id=?", [$item['approval_node'], ...$scope->keys(), $run['id']]);
                $this->db->audit($scope, 'delivery-needs-review', $run['id'], ['operation' => $operation]);
                return false;
            }
            $this->db->exec("UPDATE deliveries SET state='dispatching',attempts=attempts+1
                WHERE tenant=? AND environment=? AND operation=?", [...$scope->keys(), $operation]);
            $this->db->exec('INSERT INTO inbox(tenant,environment,operation,receipt,recipient,body) VALUES(?,?,?,?,?,?)',
                [...$scope->keys(), $operation, bin2hex(random_bytes(16)), $approval['draft']['recipient'], $approval['draft']['body']]);
            return true;
        });
        if (!$committed) {
            return true;
        }
        if ($loseResponse && $scope->environment === 'test') {
            $this->db->exec("UPDATE deliveries SET state='unknown' WHERE tenant=? AND environment=? AND operation=? AND state='dispatching'",
                [...$scope->keys(), $operation]);
        } else {
            $this->reconcile($scope, $operation, true);
        }
        return true;
    }

    public function reconcile(Scope $scope, string $operation, bool $worker = false): array
    {
        if (!$worker) {
            $scope->require('operator');
        }
        $initial = $this->operation($scope, $operation);
        return $this->db->transaction(function () use ($scope, $initial, $operation): array {
            $run = $this->runs->row($scope, $initial['run'], true);
            $item = $this->operation($scope, $operation, true);
            if ($item['state'] === 'sent') {
                return ['state' => 'sent', 'operation' => $operation, 'receipt' => $item['response_id'], 'replayed' => true];
            }
            if (!in_array($item['state'], ['dispatching', 'unknown'], true) ||
                $run['state'] !== 'waiting-receipt' || $run['cursor'] !== $item['node']) {
                throw Problem::conflict('The delivery has no unknown or pending acknowledgement to check.');
            }
            $receipt = $this->db->one('SELECT receipt FROM inbox WHERE tenant=? AND environment=? AND operation=?',
                [...$scope->keys(), $operation]);
            if ($receipt === null) {
                $this->db->exec("UPDATE deliveries SET state='unknown' WHERE tenant=? AND environment=? AND operation=?",
                    [...$scope->keys(), $operation]);
                $this->db->audit($scope, 'delivery-unresolved', $run['id'], ['operation' => $operation]);
                return ['state' => 'unknown', 'operation' => $operation];
            }
            $receipt = $receipt['receipt'];
            $graph = $this->flows->version($scope, $run['flow'], $run['graph_revision']);
            $next = $graph['graph']['nodes'][$run['cursor']]['edges']['sent'];
            $outputs = Values::decode($run['outputs']);
            $outputs[$run['cursor']] = ['receipt' => $receipt];
            $this->db->exec("UPDATE deliveries SET state='sent',response_id=? WHERE tenant=? AND environment=? AND operation=?",
                [$receipt, ...$scope->keys(), $operation]);
            $this->db->exec("UPDATE runs SET state='queued',`cursor`=?,outputs=?,revision=revision+1,ready_at=UTC_TIMESTAMP(6)
                WHERE tenant=? AND environment=? AND id=?", [$next, Values::json($outputs), ...$scope->keys(), $run['id']]);
            $this->db->audit($scope, 'delivery-confirmed', $run['id'], ['operation' => $operation, 'receipt' => $receipt]);
            return ['state' => 'sent', 'operation' => $operation, 'receipt' => $receipt, 'replayed' => false];
        });
    }

    public function operation(Scope $scope, string $operation, bool $lock = false): array
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $operation) !== 1) {
            throw Problem::missing();
        }
        $value = $this->db->one('SELECT operation,run,node,approval_node,approval_revision,digest,state,attempts,response_id,updated_at
            FROM deliveries WHERE tenant=? AND environment=? AND operation=?' . ($lock ? ' FOR UPDATE' : ''),
            [...$scope->keys(), $operation]);
        if ($value === null) {
            throw Problem::missing();
        }
        return $value;
    }

    public function forRun(Scope $scope, string $run): array
    {
        $scope->require('operator', 'author', 'approver');
        $this->runs->row($scope, $run);
        return $this->db->all('SELECT operation,node,approval_revision,state,attempts,response_id FROM deliveries
            WHERE tenant=? AND environment=? AND run=? ORDER BY updated_at DESC LIMIT 100', [...$scope->keys(), $run]);
    }

    public function inbox(Scope $scope): array
    {
        $scope->require('operator', 'approver');
        return $this->db->all('SELECT operation,receipt,recipient,body,received_at FROM inbox WHERE tenant=? AND environment=?
            ORDER BY received_at DESC,receipt DESC LIMIT 100', $scope->keys());
    }
}
