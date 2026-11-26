<?php

declare(strict_types=1);

namespace AgentAutomation;

final class ReviewBatches
{
    public function __construct(private Db $db, private Approvals $approvals)
    {
    }

    public function prepare(Scope $scope, string $request, array $selection): array
    {
        $scope->require('approver');
        Values::identifier($request, 'batch request');
        if (!array_is_list($selection) || count($selection) < 1 || count($selection) > 20) {
            throw Problem::invalid('Select one to twenty approval decisions.');
        }
        $seen = [];
        $items = [];
        foreach ($selection as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['run', 'node', 'revision', 'digest', 'action'])) {
                throw Problem::invalid('Invalid batch decision.');
            }
            $run = Values::text($item['run'] ?? null, 'run ID', 32);
            $node = Values::identifier($item['node'] ?? null, 'approval node');
            $revision = Values::number($item['revision'] ?? null, 'approval revision', 1, 2147483647);
            $digest = Values::text($item['digest'] ?? null, 'draft digest', 64);
            $action = $item['action'] ?? null;
            $key = $run . '/' . $node;
            if (isset($seen[$key]) || preg_match('/^[a-f0-9]{32}$/D', $run) !== 1 ||
                preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || !in_array($action, ['approve', 'reject'], true)) {
                throw Problem::invalid('Invalid or repeated approval decision.');
            }
            $seen[$key] = true;
            $items[] = compact('run', 'node', 'revision', 'digest', 'action') + ['state' => 'pending'];
        }
        $signature = Values::digest($items);
        return $this->db->transaction(function () use ($scope, $request, $items, $signature): array {
            $this->db->exec('INSERT INTO review_batches(tenant,environment,actor,request_id,input_digest,items)
                VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE request_id=request_id',
                [...$scope->keys(), $scope->actor, $request, $signature, Values::json($items)]);
            $batch = $this->row($scope, $request, true);
            if (!hash_equals($batch['input_digest'], $signature)) {
                throw Problem::conflict('This batch identifier was used with a different selection.');
            }
            return $this->receipt($batch);
        });
    }

    public function recent(Scope $scope): array
    {
        $scope->require('approver');
        return $this->db->all('SELECT request_id,completed,JSON_LENGTH(items) AS total,created_at FROM review_batches
            WHERE tenant=? AND environment=? AND actor=? ORDER BY created_at DESC,request_id DESC LIMIT 25',
            [...$scope->keys(), $scope->actor]);
    }

    public function get(Scope $scope, string $request): array
    {
        $scope->require('approver');
        return $this->receipt($this->row($scope, $request));
    }

    public function advance(Scope $scope, string $request, int $limit = 20): array
    {
        $scope->require('approver');
        Values::number($limit, 'batch item limit', 1, 20);
        for ($n = 0; $n < $limit; $n++) {
            $receipt = $this->db->transaction(fn (): array => $this->next($scope, $request));
            if ($receipt['completed'] === count($receipt['items'])) {
                break;
            }
        }
        return $receipt;
    }

    private function next(Scope $scope, string $request): array
    {
        $row = $this->row($scope, $request, true);
        $receipt = $this->receipt($row);
        $index = $receipt['completed'];
        if ($index === count($receipt['items'])) {
            return $receipt;
        }
        $item = $receipt['items'][$index];
        $this->db->exec('SAVEPOINT review_decision');
        try {
            $item['receipt'] = $this->approvals->recordDecision($scope, $item['run'], $item['node'],
                $item['revision'], $item['digest'], $item['action']);
            $item['state'] = 'applied';
        } catch (Problem $error) {
            if (!in_array($error->status, [403, 404, 409], true)) {
                throw $error;
            }
            $this->db->exec('ROLLBACK TO SAVEPOINT review_decision');
            $item['state'] = 'rejected';
            $item['error'] = $error->kind;
            $item['message'] = $error->getMessage();
        }
        $receipt['items'][$index] = $item;
        $receipt['completed']++;
        $this->db->exec('UPDATE review_batches SET items=?,completed=? WHERE tenant=? AND environment=? AND actor=? AND request_id=?',
            [Values::json($receipt['items']), $receipt['completed'], ...$scope->keys(), $scope->actor, $request]);
        return $receipt;
    }

    private function row(Scope $scope, string $request, bool $lock = false): array
    {
        Values::identifier($request, 'batch request');
        $row = $this->db->one('SELECT request_id,input_digest,items,completed FROM review_batches
            WHERE tenant=? AND environment=? AND actor=? AND request_id=?' . ($lock ? ' FOR UPDATE' : ''),
            [...$scope->keys(), $scope->actor, $request]);
        return $row ?? throw Problem::missing();
    }

    private function receipt(array $row): array
    {
        return ['request_id' => $row['request_id'], 'completed' => $row['completed'], 'items' => Values::decode($row['items'])];
    }
}
