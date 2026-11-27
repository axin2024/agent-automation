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
    public readonly ReviewBatches $reviewBatches;
    public readonly Retention $retention;
    public readonly Deliveries $deliveries;
    public readonly Engine $engine;
    public readonly Cases $cases;
    public readonly CaseTemplates $caseTemplates;
    public readonly CaseAssignments $caseAssignments;
    public readonly CaseEvidence $caseEvidence;
    public readonly CaseAssistant $caseAssistant;
    public readonly CaseDecisions $caseDecisions;
    public readonly CaseDispatch $caseDispatch;

    public function __construct(public readonly Db $db, ?\Closure $caseClock = null)
    {
        $this->access = new Access($db);
        $this->connections = new Connections($db);
        $this->orders = new Orders($db);
        $this->flows = new Workflows($db, $this->connections);
        $this->runs = new Runs($db, $this->flows, $this->orders);
        $this->approvals = new Approvals($db, $this->runs, $this->flows, $this->orders, $this->connections);
        $this->reviewBatches = new ReviewBatches($db, $this->approvals);
        $this->retention = new Retention($db);
        $this->deliveries = new Deliveries($db, $this->runs, $this->flows, $this->approvals);
        $this->engine = new Engine($db, $this->runs, $this->flows, $this->connections, $this->orders,
            new DraftModel($this->connections), $this->approvals, $this->deliveries);
        $this->caseTemplates = new CaseTemplates($db, $this->flows);
        $caseStore = new CaseStore($db, $this->caseTemplates, $caseClock);
        $this->cases = new Cases($caseStore);
        $this->caseEvidence = new CaseEvidence($caseStore);
        $this->caseAssignments = new CaseAssignments($caseStore, $this->caseEvidence);
        $this->caseAssistant = new CaseAssistant($caseStore, $this->caseEvidence);
        $this->caseDecisions = new CaseDecisions($caseStore, $this->caseEvidence);
        $this->caseDispatch = new CaseDispatch($caseStore);
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

    public function verifyConnection(Scope $scope, string $name, int $expected, array $fixture): array
    {
        $scope->require('admin');
        return $this->db->transaction(function () use ($scope, $name, $expected, $fixture): array {
            $connection = $this->connections->active($scope, $name, null, true) ?? throw Problem::missing();
            if ($connection['revision'] !== $expected) {
                throw Problem::conflict('The connection changed. Test its current revision.');
            }
            $result = ['connection' => $name, 'revision' => $expected, 'capability' => $connection['capability']];
            switch ($connection['capability']) {
                case 'orders':
                    $order = Orders::orderNumber(Values::text($fixture['order_no'] ?? null, 'order number', 32));
                    $row = $this->orders->get($scope, $order) ?? throw Problem::missing();
                    return $result + ['check' => 'order-read', 'order' => $row];
                case 'inbox':
                    $this->connections->ensureRecipient($connection, Values::email($fixture['recipient'] ?? null));
                    return $result + ['check' => 'recipient-allowed'];
                case 'model':
                    $token = $this->connections->token($scope, $connection);
                    return $result + ['check' => 'model-settings', 'credential_configured' => $token !== '',
                        'fields' => $connection['settings']['fields'], 'rates' => $connection['settings']['rates'] ?? null];
            }
            throw Problem::invalid('Unsupported connection capability.');
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
            'model_costs' => $this->db->all("SELECT c.currency,COUNT(*) AS calls,COUNT(c.estimate_micros) AS estimated_calls,
                SUM(c.estimate_micros) AS estimate_micros FROM model_usage m
                LEFT JOIN model_costs c USING(tenant,environment,run,node,attempt)
                WHERE m.tenant=? AND m.environment=? AND m.mode='live' AND m.started_at>=UTC_DATE()
                GROUP BY c.currency ORDER BY c.currency", $scope->keys()),
            'operation_durations' => $this->db->all('SELECT kind,COUNT(*) AS count,SUM(microseconds) AS total_us,
                AVG(microseconds) AS mean_us FROM operation_durations WHERE tenant=? AND environment=? GROUP BY kind ORDER BY kind',
                $scope->keys()),
            'evaluations' => $this->db->all("SELECT detail->>'$.label' AS label,COUNT(*) AS count FROM audit
                WHERE tenant=? AND environment=? AND action='evaluation' GROUP BY label ORDER BY label", $scope->keys()),
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

    public function evaluations(Scope $scope, string $run): array
    {
        $scope->require('operator', 'author', 'approver');
        return $this->db->all("SELECT actor,detail->>'$.node' AS node,detail->>'$.label' AS label,happened_at FROM audit
            WHERE tenant=? AND environment=? AND action='evaluation' AND object=? ORDER BY id DESC LIMIT 50", [...$scope->keys(), $run]);
    }
}
