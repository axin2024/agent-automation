<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Retention
{
    public function __construct(private Db $db)
    {
    }

    public function policy(Scope $scope, bool $lock = false): array
    {
        $scope->require('admin');
        return $this->db->one('SELECT body_days,metering_days,audit_days FROM retention_policies
            WHERE tenant=? AND environment=?' . ($lock ? ' FOR UPDATE' : ''), $scope->keys())
            ?? ['body_days' => 0, 'metering_days' => 0, 'audit_days' => 0];
    }

    public function configure(Scope $scope, int $bodyDays, int $meteringDays, int $auditDays): array
    {
        $scope->require('admin');
        $policy = [
            'body_days' => Values::number($bodyDays, 'body retention', 0, 3650),
            'metering_days' => Values::number($meteringDays, 'metering retention', 0, 3650),
            'audit_days' => Values::number($auditDays, 'audit retention', 0, 3650),
        ];
        return $this->db->transaction(function () use ($scope, $policy): array {
            $changed = $this->db->exec('INSERT INTO retention_policies(tenant,environment,body_days,metering_days,audit_days)
                VALUES(?,?,?,?,?) AS incoming ON DUPLICATE KEY UPDATE body_days=incoming.body_days,
                metering_days=incoming.metering_days,audit_days=incoming.audit_days', [...$scope->keys(), ...array_values($policy)]);
            if ($changed > 0) {
                $this->db->audit($scope, 'retention-configured', $scope->tenant, $policy);
            }
            return $policy;
        });
    }

    public function apply(Scope $scope, int $limit): array
    {
        $scope->require('admin');
        Values::number($limit, 'retention batch size', 1, 100);
        return $this->db->transaction(function () use ($scope, $limit): array {
            $policy = $this->policy($scope, true);
            $counts = [
                'runs_redacted' => $this->redact($scope, $policy['body_days'], $limit),
                'metering_deleted' => $this->expireMetering($scope, $policy['metering_days'], $limit),
                'audit_deleted' => $policy['audit_days'] === 0 ? 0 : $this->db->exec('DELETE FROM audit WHERE tenant=? AND environment=?
                    AND happened_at<UTC_TIMESTAMP(6)-INTERVAL ? DAY ORDER BY id LIMIT ' . $limit, [...$scope->keys(), $policy['audit_days']]),
            ];
            if (array_sum($counts) > 0) {
                $this->db->audit($scope, 'retention-applied', $scope->tenant, $counts);
            }
            return $counts;
        });
    }

    private function redact(Scope $scope, int $days, int $limit): int
    {
        if ($days === 0) {
            return 0;
        }
        $runs = $this->db->all("SELECT id,event,outputs FROM runs WHERE tenant=? AND environment=?
            AND state IN ('completed','failed','cancelled') AND updated_at<UTC_TIMESTAMP(6)-INTERVAL ? DAY
            AND JSON_CONTAINS_PATH(event,'one','$.body')=1 ORDER BY updated_at,id LIMIT " . $limit . ' FOR UPDATE',
            [...$scope->keys(), $days]);
        foreach ($runs as $run) {
            $event = Values::decode($run['event']);
            unset($event['body']);
            $outputs = Values::decode($run['outputs']);
            foreach ($outputs as &$output) {
                unset($output['body'], $output['draft']['body']);
            }
            unset($output);
            $args = [...$scope->keys(), $run['id']];
            $this->db->exec('UPDATE runs SET event=?,outputs=?,updated_at=updated_at WHERE tenant=? AND environment=? AND id=?',
                [Values::json($event), Values::json($outputs), ...$args]);
            $this->db->exec("UPDATE approvals SET draft=JSON_REMOVE(draft,'$.body') WHERE tenant=? AND environment=? AND run=?", $args);
            $this->db->exec("UPDATE inbox i JOIN deliveries d ON d.tenant=i.tenant AND d.environment=i.environment AND d.operation=i.operation
                SET i.body='' WHERE d.tenant=? AND d.environment=? AND d.run=?", $args);
        }
        return count($runs);
    }

    private function expireMetering(Scope $scope, int $days, int $limit): int
    {
        if ($days === 0) {
            return 0;
        }
        $items = $this->db->all("SELECT m.run,m.node,m.attempt FROM model_usage m JOIN runs r
            ON r.tenant=m.tenant AND r.environment=m.environment AND r.id=m.run WHERE m.tenant=? AND m.environment=?
            AND m.started_at<UTC_TIMESTAMP(6)-INTERVAL ? DAY AND r.state IN ('completed','failed','cancelled')
            ORDER BY m.started_at,m.run,m.node,m.attempt LIMIT " . $limit, [...$scope->keys(), $days]);
        foreach ($items as $item) {
            $this->db->exec('DELETE FROM model_usage WHERE tenant=? AND environment=? AND run=? AND node=? AND attempt=?',
                [...$scope->keys(), $item['run'], $item['node'], $item['attempt']]);
        }
        $durations = $this->db->all("SELECT d.id FROM operation_durations d JOIN runs r
            ON r.tenant=d.tenant AND r.environment=d.environment AND r.id=d.run WHERE d.tenant=? AND d.environment=?
            AND d.recorded_at<UTC_TIMESTAMP(6)-INTERVAL ? DAY AND r.state IN ('completed','failed','cancelled')
            ORDER BY d.recorded_at,d.id LIMIT " . $limit, [...$scope->keys(), $days]);
        foreach ($durations as $duration) {
            $this->db->exec('DELETE FROM operation_durations WHERE tenant=? AND environment=? AND id=?', [...$scope->keys(), $duration['id']]);
        }
        return count($items) + count($durations);
    }
}
