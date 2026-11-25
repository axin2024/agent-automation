<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Engine
{
    public function __construct(
        private Db $db,
        private Runs $runs,
        private Workflows $flows,
        private Connections $connections,
        private Orders $orders,
        private DraftModel $model,
        private Approvals $approvals,
        private Deliveries $deliveries,
    ) {
    }

    public function step(): bool
    {
        $claim = $this->claim();
        if ($claim === null) {
            return false;
        }
        $scope = new Scope($claim['tenant'], $claim['environment'], 'workflow-worker', 'worker');
        try {
            $invocation = $this->db->transaction(function () use ($scope, $claim): ?array {
                $row = $this->fenced($scope, $claim);
                if ($row === null) {
                    return null;
                }
                $version = $this->flows->version($scope, $row['flow'], $row['graph_revision']);
                $node = $version['graph']['nodes'][$row['cursor']];
                $outputs = Values::decode($row['outputs']);
                $input = [];
                foreach ($node['inputs'] as $field => $reference) {
                    [$source, $port] = explode('.', $reference, 2);
                    if (!isset($outputs[$source]) || !array_key_exists($port, $outputs[$source])) {
                        throw Problem::conflict('A required predecessor output is missing.');
                    }
                    $input[$field] = $outputs[$source][$port];
                }
                if ($node['kind'] === 'draft') {
                    return $this->prepareDraft($scope, $row, $node, $version, $input);
                }
                $outcome = $this->execute($scope, $row, $node, $version, $input);
                $this->record($scope, $row, $node, $outcome);
                return null;
            });
            if ($invocation !== null) {
                $result = $this->model->run($invocation['call']);
                $this->finishDraft($scope, $claim, $invocation, $result);
            }
        } catch (Problem $error) {
            $this->db->transaction(function () use ($scope, $claim, $error): void {
                $row = $this->fenced($scope, $claim);
                if ($row !== null) {
                    $this->record($scope, $row, [], ['state' => 'failed', 'detail' => ['reason' => $error->kind]]);
                }
            });
        }
        return true;
    }

    private function claim(): ?array
    {
        return $this->db->transaction(function (): ?array {
            $row = $this->db->one("SELECT tenant,environment,id FROM runs
                WHERE (state='queued' AND ready_at<=UTC_TIMESTAMP(6))
                   OR (state='running' AND lease_until<=UTC_TIMESTAMP(6))
                ORDER BY ready_at,id LIMIT 1 FOR UPDATE SKIP LOCKED");
            if ($row === null) {
                return null;
            }
            $row['runner'] = bin2hex(random_bytes(16));
            $this->db->exec("UPDATE runs SET state='running',attempt=attempt+1,
                runner=?,lease_until=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 45 SECOND)
                WHERE tenant=? AND environment=? AND id=?", [$row['runner'], $row['tenant'], $row['environment'], $row['id']]);
            return $row;
        });
    }

    private function fenced(Scope $scope, array $claim): ?array
    {
        $now = $this->runs->row($scope, $claim['id'], true);
        return $now['state'] === 'running' && $now['runner'] === $claim['runner'] && $now['lease_fresh'] ? $now : null;
    }

    private function execute(Scope $scope, array $row, array $node, array $version, array $input): array
    {
        return match ($node['kind']) {
            'start' => ['branch' => 'next', 'output' => Values::decode($row['event'])],
            'classify' => $this->classify($input),
            'ticket' => $this->writeTicket($scope, $row, $input),
            'order' => $this->lookup($scope, $row, $node, $version, $input),
            'approval' => $this->requestApproval($scope, $row, $node, $version, $input),
            'deliver' => ['state' => 'waiting-receipt', 'detail' => $this->deliveries->stage(
                $scope, $row, $version['bindings'][$node['binding']], $input['approval'])],
            'stop' => ['state' => 'completed', 'result' => $node['options']['result']],
            default => throw Problem::invalid('Unknown workflow node.'),
        };
    }

    public function classify(array $input): array
    {
        $message = $input['subject'] . ' ' . $input['body'];
        preg_match_all('/\bORD-[0-9]{4,12}\b/i', $message, $matches);
        $orderNumbers = array_values(array_unique(array_map('strtoupper', $matches[0])));
        $order = count($orderNumbers) === 1 ? $orderNumbers[0] : '';
        $category = match (true) {
            preg_match('/\b(return|refund|damaged|delivery|parcel|shipment|cancel)\b|退货|退款|物流|破损|取消/iu', $message) === 1 => 'after_sale',
            preg_match('/\b(question|inquiry|information|hours|pricing|available)\b|咨询|营业|报价/iu', $message) === 1 => 'inquiry',
            default => 'unknown',
        };
        return ['branch' => $category, 'output' => ['category' => $category, 'order_no' => $order],
            'detail' => ['classification' => $category, 'order_candidates' => count($orderNumbers)]];
    }

    private function writeTicket(Scope $scope, array $row, array $input): array
    {
        $this->db->exec('INSERT INTO tickets(tenant,environment,run,ticket,message,category,original_order)
            VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ticket=ticket',
            [...$scope->keys(), $row['id'], bin2hex(random_bytes(16)), $input['message'], $input['category'], $input['order_no']]);
        $ticket = $this->db->one('SELECT ticket FROM tickets WHERE tenant=? AND environment=? AND run=?',
            [...$scope->keys(), $row['id']]);
        return ['branch' => 'next', 'output' => $ticket, 'detail' => ['ticket' => $ticket['ticket']]];
    }

    private function lookup(Scope $scope, array $row, array $node, array $version, array $input): array
    {
        $this->activeConnection($scope, $node, $version, 'orders');
        $number = $row['override_order'] ?? $input['order_no'];
        $order = $number === '' ? null : $this->orders->get($scope, Orders::orderNumber($number));
        if ($order === null) {
            $detail = ['attempted' => $number, 'reason' => 'order-not-found'];
            return $node['options']['human_correction']
                ? ['state' => 'waiting-input', 'detail' => $detail]
                : ['branch' => 'missing', 'detail' => $detail];
        }
        return ['branch' => 'found', 'output' => $order, 'detail' => ['order_no' => $number, 'order_version' => $order['version']]];
    }

    private function requestApproval(Scope $scope, array $row, array $node, array $version, array $input): array
    {
        $connection = $this->activeConnection($scope, $node, $version, 'inbox');
        $ref = $this->approvals->create($scope, $row, $row['cursor'], $input['draft'], $connection, $node['options']['expires_minutes']);
        return ['state' => 'waiting-review', 'detail' => $ref];
    }

    private function activeConnection(Scope $scope, array $node, array $version, string $capability): array
    {
        $name = $version['bindings'][$node['binding']];
        return $this->connections->active($scope, $name, $capability)
            ?? throw Problem::conflict('The required connection is unavailable in this environment.');
    }

    private function prepareDraft(Scope $scope, array $row, array $node, array $version, array $input): ?array
    {
        $connection = $this->activeConnection($scope, $node, $version, 'model');
        $call = $this->model->prepare($scope, $connection, $input, $node['options']);
        $prior = $this->db->exec("UPDATE model_usage SET state='unknown' WHERE tenant=? AND environment=?
            AND run=? AND node=? AND state='reserved'", [...$scope->keys(), $row['id'], $row['cursor']]);
        if ($prior > 0) {
            $this->record($scope, $row, $node, ['branch' => 'error', 'detail' => ['reason' => 'interrupted-inference']]);
            return null;
        }
        if ($call['reserved'] > 0) {
            $limits = $this->db->one('SELECT daily_tokens FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $spent = $this->db->one("SELECT COALESCE(SUM(CASE
                WHEN input_tokens IS NULL OR output_tokens IS NULL THEN reserved_tokens ELSE input_tokens+output_tokens END),0) AS used
                FROM model_usage WHERE tenant=? AND environment=? AND mode='live' AND started_at>=UTC_DATE()", $scope->keys());
            if ($call['reserved'] + $spent['used'] > $limits['daily_tokens']) {
                $this->record($scope, $row, $node, ['branch' => 'error', 'detail' => ['reason' => 'model-budget-exhausted']]);
                return null;
            }
        }
        $this->db->exec("INSERT INTO model_usage(tenant,environment,run,node,attempt,mode,provider,state,reserved_tokens)
            VALUES(?,?,?,?,?,?,?,'reserved',?)", [...$scope->keys(), $row['id'], $row['cursor'], $row['attempt'],
            $call['mode'], $call['model'], $call['reserved']]);
        return ['node' => $node, 'call' => $call, 'attempt' => $row['attempt'], 'cursor' => $row['cursor']];
    }

    private function finishDraft(Scope $scope, array $claim, array $invocation, array $result): void
    {
        $this->db->transaction(function () use ($scope, $claim, $invocation, $result): void {
            $row = $this->fenced($scope, $claim);
            $this->db->exec("UPDATE model_usage SET state=?,input_tokens=?,output_tokens=?,milliseconds=?
                WHERE tenant=? AND environment=? AND run=? AND node=? AND attempt=? AND state='reserved'",
                [$result['state'], $result['input_tokens'] ?? null, $result['output_tokens'] ?? null, $result['milliseconds'],
                    ...$scope->keys(), $claim['id'], $invocation['cursor'], $invocation['attempt']]);
            if ($row === null) {
                return;
            }
            $accepted = $result['state'] === 'complete';
            $this->record($scope, $row, $invocation['node'], [
                'branch' => $accepted ? 'ready' : 'error', 'output' => $accepted ? ['draft' => $result['draft']] : [],
                'detail' => ['mode' => $invocation['call']['mode'], 'prompt_revision' => $invocation['node']['options']['prompt_revision'],
                    'state' => $result['state'], 'reason' => $result['failure'] ?? null,
                    'milliseconds' => $result['milliseconds']],
            ]);
        });
    }

    private function record(Scope $scope, array $row, array $node, array $outcome): void
    {
        $branch = $outcome['branch'] ?? null;
        $state = $outcome['state'] ?? 'queued';
        $next = $branch === null ? $row['cursor'] : $node['edges'][$branch];
        $outputs = Values::decode($row['outputs']);
        if (!empty($outcome['output'])) {
            $outputs[$row['cursor']] = $outcome['output'];
        }
        $this->db->exec('UPDATE runs SET state=?,`cursor`=?,outputs=?,result=?,revision=revision+1,
            runner=NULL,lease_until=NULL,ready_at=UTC_TIMESTAMP(6) WHERE tenant=? AND environment=? AND id=?',
            [$state, $next, Values::json($outputs), $outcome['result'] ?? null, ...$scope->keys(), $row['id']]);
        $this->runs->nodeEvent($scope, $row, $branch ?? $state, $outcome['detail'] ?? []);
    }
}
