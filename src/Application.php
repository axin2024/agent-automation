<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Application
{
    public readonly Access $access;
    public readonly Orders $orders;
    public readonly Connections $connections;
    public readonly Workflows $flows;
    public readonly Runs $runs;
    public readonly Approvals $approvals;
    public readonly Deliveries $deliveries;
    public readonly Engine $engine;

    public function __construct(public readonly Db $db)
    {
        $this->access = new Access($db);
        $this->connections = new Connections($db);
        $this->orders = new Orders($db);
        $this->flows = new Workflows($db, $this->connections);
        $this->runs = new Runs($db, $this->flows, $this->orders);
        $this->approvals = new Approvals($db, $this->runs, $this->flows, $this->orders, $this->connections);
        $this->deliveries = new Deliveries($db, $this->runs, $this->flows, $this->approvals);
        $this->engine = new Engine($db, $this->runs, $this->flows, $this->connections, $this->orders,
            new DraftModel($this->connections), $this->approvals, $this->deliveries);
    }

    public function limits(Scope $scope, int $maxOpen, int $dailyTokens): array
    {
        $scope->require('admin');
        Values::number($maxOpen, 'open run limit', 1, 20000);
        Values::number($dailyTokens, 'daily token limit', 0, 10000000);
        return $this->db->transaction(function () use ($scope, $maxOpen, $dailyTokens): array {
            $this->db->exec('UPDATE tenants SET max_open=?, daily_tokens=? WHERE tenant=?', [$maxOpen, $dailyTokens, $scope->tenant]);
            $info = ['max_open' => $maxOpen, 'daily_tokens' => $dailyTokens];
            $this->db->audit($scope, 'tenant-limits', $scope->tenant, $info);
            return $info;
        });
    }

    public function statistics(Scope $scope): array
    {
        $scope->require('operator', 'author', 'approver');
        return [
            'runs' => $this->db->all('SELECT state,COUNT(*) AS count FROM runs WHERE tenant=? AND environment=? GROUP BY state',
                $scope->keys()),
            'deliveries' => $this->db->all('SELECT state,COUNT(*) AS count FROM deliveries WHERE tenant=? AND environment=? GROUP BY state',
                $scope->keys()),
            'models' => $this->db->all('SELECT mode,provider,state,COUNT(*) AS count,SUM(reserved_tokens) AS reserved,
                SUM(input_tokens) AS input_tokens,SUM(output_tokens) AS output_tokens,AVG(milliseconds) AS mean_ms
                FROM model_usage WHERE tenant=? AND environment=? AND started_at>=UTC_DATE() GROUP BY mode,provider,state',
                $scope->keys()),
        ];
    }

    public function audit(Scope $scope, int $after): array
    {
        $scope->require('operator', 'author', 'approver');
        $list = $this->db->all('SELECT id,actor,action,object,detail,happened_at FROM audit
            WHERE tenant=? AND environment=? AND id>? ORDER BY id LIMIT 100', [...$scope->keys(), $after]);
        foreach ($list as &$event) {
            $event['detail'] = Values::decode($event['detail']);
        }
        return $list;
    }

    public function feedback(Scope $scope, string $run, string $node, string $label): array
    {
        $scope->require('operator', 'approver');
        Values::identifier($node, 'node');
        if (!in_array($label, ['classification-correct', 'classification-wrong', 'extraction-correct', 'extraction-wrong', 'needs-human'], true)) {
            throw Problem::invalid('Unknown evaluation label.');
        }
        $this->runs->row($scope, $run);
        if ($this->db->one('SELECT id FROM node_history WHERE tenant=? AND environment=? AND run=? AND node=? LIMIT 1',
            [...$scope->keys(), $run, $node]) === null) {
            throw Problem::missing();
        }
        $value = ['node' => $node, 'label' => $label];
        $this->db->audit($scope, 'evaluation', $run, $value);
        return $value;
    }
}
