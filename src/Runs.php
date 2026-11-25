<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Runs
{
    public function __construct(private Db $db, private Workflows $flows, private Orders $orders)
    {
    }

    public function trigger(Scope $scope, string $flow, string $source, array $input): array
    {
        if ($scope->environment === 'test') {
            $scope->require('trigger', 'operator', 'author');
        } else {
            $scope->require('trigger', 'operator');
        }
        Values::identifier($flow, 'workflow');
        Values::identifier($source, 'event source');
        if (array_diff(array_keys($input), ['message', 'sender', 'subject', 'body'])) {
            throw Problem::invalid('Unknown message fields.');
        }
        $message = Values::text($input['message'] ?? null, 'message identity', 180);
        if (preg_match('/^[\x21-\x7e]+$/D', $message) !== 1) {
            throw Problem::invalid('Invalid message identity.');
        }
        $event = [
            'message' => $message,
            'sender' => Values::email($input['sender'] ?? null),
            'subject' => Values::text($input['subject'] ?? null, 'subject', 350),
            'body' => Values::text($input['body'] ?? null, 'body', 12000, 0),
        ];
        $digest = Values::digest([$flow, $event]);
        return $this->db->transaction(function () use ($scope, $flow, $source, $event, $message, $digest): array {
            $limits = $this->db->one('SELECT max_open FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $prior = $this->db->one('SELECT digest,run FROM event_keys WHERE tenant=? AND environment=? AND source=? AND event_id=?',
                [...$scope->keys(), $source, $message]);
            if ($prior !== null) {
                if (!hash_equals($prior['digest'], $digest)) {
                    throw Problem::conflict('The source identity was already used with different content.');
                }
                $run = $this->row($scope, $prior['run']);
                return $this->summary($run) + ['replayed' => true];
            }
            $revision = $this->flows->active($scope, $flow);
            if ($revision === null) {
                throw Problem::missing();
            }
            $open = $this->db->one("SELECT COUNT(*) AS total FROM runs WHERE tenant=? AND environment=?
                AND state IN ('queued','running','waiting-input','waiting-review','waiting-receipt')", $scope->keys());
            if ($open['total'] >= $limits['max_open']) {
                throw new Problem(429, 'workspace-capacity', 'The workspace has reached its open-run limit.');
            }
            $id = bin2hex(random_bytes(16));
            $this->db->exec("INSERT INTO runs(tenant,environment,id,flow,graph_revision,state,event,outputs)
                VALUES(?,?,?,?,?,'queued',?,?)", [...$scope->keys(), $id, $flow, $revision, Values::json($event), '[]']);
            $this->db->exec('INSERT INTO event_keys(tenant,environment,source,event_id,digest,run) VALUES(?,?,?,?,?,?)',
                [...$scope->keys(), $source, $message, $digest, $id]);
            $this->db->audit($scope, 'run-triggered', $id, ['flow' => $flow, 'revision' => $revision, 'event_digest' => $digest]);
            return $this->summary($this->row($scope, $id)) + ['replayed' => false];
        });
    }

    public function row(Scope $scope, string $run, bool $lock = false): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $run) !== 1) {
            throw Problem::missing();
        }
        $row = $this->db->one('SELECT *,lease_until>UTC_TIMESTAMP(6) AS lease_fresh
            FROM runs WHERE tenant=? AND environment=? AND id=?' . ($lock ? ' FOR UPDATE' : ''),
            [...$scope->keys(), $run]);
        if ($row === null) {
            throw Problem::missing();
        }
        return $row;
    }

    public function detail(Scope $scope, string $run): array
    {
        $scope->require('operator', 'author', 'approver');
        $row = $this->row($scope, $run);
        $history = $this->db->all('SELECT id,node,attempt,outcome,detail,happened_at FROM node_history
            WHERE tenant=? AND environment=? AND run=? ORDER BY id LIMIT 250', [...$scope->keys(), $run]);
        $ticket = $this->db->one('SELECT ticket,category,original_order,corrected_order FROM tickets
            WHERE tenant=? AND environment=? AND run=?', [...$scope->keys(), $run]);
        return $this->summary($row) + ['event' => Values::decode($row['event']), 'ticket' => $ticket,
            'outputs' => Values::decode($row['outputs']), 'history' => array_map(static function (array $event): array {
                $event['detail'] = Values::decode($event['detail']);
                return $event;
            }, $history)];
    }

    public function list(Scope $scope, string $state = '', string $before = ''): array
    {
        $scope->require('operator', 'author', 'approver');
        $criteria = [];
        $args = $scope->keys();
        if ($state !== '') {
            if (!in_array($state, ['queued', 'running', 'waiting-input', 'waiting-review', 'waiting-receipt', 'completed', 'failed', 'cancelled'], true)) {
                throw Problem::invalid('Unknown run state.');
            }
            $criteria[] = 'state=?';
            $args[] = $state;
        }
        if ($before !== '') {
            if (preg_match('/^[a-f0-9]{32}$/D', $before) !== 1) {
                throw Problem::invalid('Invalid page cursor.');
            }
            $cursor = $this->row($scope, $before);
            $criteria[] = '(created_at<? OR (created_at=? AND id<?))';
            array_push($args, $cursor['created_at'], $cursor['created_at'], $before);
        }
        $filter = $criteria === [] ? '' : ' AND ' . implode(' AND ', $criteria);
        $items = $this->db->all('SELECT id,flow,graph_revision,state,`cursor`,revision,attempt,result,created_at,updated_at FROM runs
            WHERE tenant=? AND environment=?' . $filter . ' ORDER BY created_at DESC,id DESC LIMIT 101', $args);
        $next = count($items) > 100 ? $items[99]['id'] : null;
        return ['runs' => array_slice($items, 0, 100), 'next' => $next];
    }

    public function correct(Scope $scope, string $run, int $expected, string $number): array
    {
        $scope->require('operator');
        Orders::orderNumber($number);
        return $this->db->transaction(function () use ($scope, $run, $expected, $number): array {
            $row = $this->row($scope, $run, true);
            if ($row['override_order'] === $number && $row['revision'] === $expected + 1 && $row['state'] === 'queued') {
                return $this->summary($row);
            }
            if ($row['state'] !== 'waiting-input' || $row['revision'] !== $expected || $this->orders->get($scope, $number) === null) {
                throw Problem::conflict('The pending lookup or replacement order changed.');
            }
            $this->db->exec("UPDATE runs SET state='queued',override_order=?,revision=revision+1,ready_at=UTC_TIMESTAMP(6)
                WHERE tenant=? AND environment=? AND id=?", [$number, ...$scope->keys(), $run]);
            $this->db->exec('UPDATE tickets SET corrected_order=? WHERE tenant=? AND environment=? AND run=?',
                [$number, ...$scope->keys(), $run]);
            $this->db->audit($scope, 'order-corrected', $run, ['node' => $row['cursor'], 'order_no' => $number]);
            return $this->summary($this->row($scope, $run));
        });
    }

    public function cancel(Scope $scope, string $run, int $expected): array
    {
        $scope->require('operator');
        return $this->db->transaction(function () use ($scope, $run, $expected): array {
            $row = $this->row($scope, $run, true);
            if ($row['state'] === 'cancelled') {
                return $this->summary($row);
            }
            if ($row['revision'] !== $expected || !in_array($row['state'], ['queued', 'running', 'waiting-input', 'waiting-review'], true)) {
                throw Problem::conflict('The run is no longer cancellable at this revision.');
            }
            $this->db->exec("UPDATE runs SET state='cancelled',revision=revision+1,runner=NULL,lease_until=NULL
                WHERE tenant=? AND environment=? AND id=?", [...$scope->keys(), $run]);
            $this->db->exec("UPDATE approvals SET state='invalid' WHERE tenant=? AND environment=? AND run=? AND state='pending'",
                [...$scope->keys(), $run]);
            $this->db->audit($scope, 'run-cancelled', $run, ['from' => $row['state']]);
            return $this->summary($this->row($scope, $run));
        });
    }

    public function summary(array $row): array
    {
        return array_intersect_key($row, array_flip(['id', 'flow', 'graph_revision', 'state', 'cursor', 'revision',
            'attempt', 'result', 'created_at', 'updated_at']));
    }

    public function nodeEvent(Scope $scope, array $row, string $outcome, array $detail): void
    {
        $this->db->exec('INSERT INTO node_history(tenant,environment,run,node,attempt,outcome,detail) VALUES(?,?,?,?,?,?,?)',
            [...$scope->keys(), $row['id'], $row['cursor'], $row['attempt'], $outcome, Values::json($detail)]);
    }
}
