<?php

declare(strict_types=1);

namespace AgentAutomation;

use JsonException;

final class CaseAssistant
{
    private const ALLOWED = [
        'classify' => ['delivery', 'billing', 'quality', 'other'],
        'investigate' => ['placed', 'shipped', 'delivered', 'cancelled'],
        'recommend' => ['refund', 'close', 'manual'],
    ];
    private const GROUPS = [
        'classify' => ['investigate'],
        'investigate' => ['investigate', 'review-operations'],
        'recommend' => ['review-finance', 'decision'],
    ];

    public function __construct(private CaseStore $store, private CaseEvidence $evidence)
    {
    }

    public function generate(Scope $scope, string $id, string $role, mixed $key, array $input): array
    {
        $model = getenv('AUTOMATION_CASE_MODEL_NAME') ?: 'local';
        $url = getenv('AUTOMATION_CASE_MODEL_URL') ?: '';
        $prepared = null;
        $reservation = $this->store->change($scope, $key, 'assistant:' . $role, $id, $input,
            function () use ($scope, $id, $role, $input, $model, &$prepared): array {
                $case = $this->store->open($scope, $id, $input, 'investigating', 'needs-evidence', 'review', 'decision');
                $this->authorize($scope, $case, $role);
                $materials = $this->evidence->materials($scope, $case, $role);
                $payload = self::prompt($model, $role, $materials);
                $db = $this->store->db;
                $parameters = [...$scope->keys(), $id];
                $db->exec("UPDATE case_proposals SET state='interrupted'
                    WHERE tenant=? AND environment=? AND case_id=? AND state='pending'
                    AND created_at<DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE)", $parameters);
                if ($db->one("SELECT 1 FROM case_proposals WHERE tenant=? AND environment=? AND case_id=?
                    AND case_round=? AND assistant_role=? AND state='pending' LIMIT 1",
                    [...$parameters, $case['case_round'], $role]) !== null) {
                    throw Problem::conflict('This assistant already has a request in progress.');
                }
                $proposal = CaseStore::id();
                $usage = ['provider' => 'openai-compatible-loopback', 'model' => $model, 'mode' => 'live',
                    'input_tokens' => null, 'output_tokens' => null, 'metered' => false];
                $db->exec("INSERT INTO case_proposals(tenant,environment,case_id,case_round,proposal_id,evidence_rev,
                        assistant_role,state,input_hash,usage_data,actor)
                    VALUES(?,?,?,?,?,?,?,'pending',?,?,?)", [...$parameters, $case['case_round'], $proposal,
                    $case['evidence_rev'], $role, Values::digest($payload), Values::json($usage), $scope->actor]);
                $prepared = ['materials' => $materials, 'payload' => $payload, 'usage' => $usage,
                    'missing' => $this->evidence->missing($scope, $case)];
                $this->store->event($scope, 'case-assistant-requested', $id, [
                    'proposal_id' => $proposal, 'assistant_role' => $role, 'evidence_rev' => $case['evidence_rev'],
                    'case_round' => $case['case_round'], 'visibility' => self::visibility($role),
                ]);
                return ['proposal_id' => $proposal, 'evidence_rev' => $case['evidence_rev']];
            });
        if ($prepared !== null) {
            $start = hrtime(true);
            $http = ModelHttp::request($url, $prepared['payload'], '', true);
            [$suggestion, $usage] = self::evaluate($role, $http, $prepared);
            $usage['milliseconds'] = (int) ((hrtime(true) - $start) / 1000000);
            $this->finish($scope, $id, $reservation['proposal_id'], $role, $reservation['evidence_rev'], $suggestion, $usage);
        }
        return $this->proposal($scope, $id, $reservation['proposal_id']);
    }

    public function revise(Scope $scope, string $id, mixed $key, array $input): array
    {
        return $this->store->change($scope, $key, 'assistant-revision', $id, $input, function () use ($scope, $id, $input): array {
            $case = $this->store->open($scope, $id, $input, 'investigating', 'needs-evidence', 'review', 'decision');
            $previous = $this->proposal($scope, $id, CaseDecisions::idField($input['based_on'] ?? null, 'based_on'));
            $role = $previous['assistant_role'];
            $this->authorize($scope, $case, $role);
            if ($previous['case_round'] !== $case['case_round'] || $previous['evidence_rev'] !== $case['evidence_rev'] ||
                !in_array($previous['state'], ['ready', 'needs-human'], true)) {
                throw Problem::conflict('The suggestion no longer matches current evidence.');
            }
            $label = $input['label'] ?? null;
            if (!in_array($label, [...self::ALLOWED[$role], 'insufficient'], true)) {
                throw Problem::invalid('Invalid label.');
            }
            $citations = $input['citations'] ?? null;
            $available = array_column($this->evidence->materials($scope, $case, $role), null, 'evidence_id');
            if (!is_array($citations) || !array_is_list($citations) || count($citations) > count($available)) {
                throw Problem::invalid('Expected current citations.');
            }
            $provenance = [];
            foreach ($citations as $reference) {
                $value = CaseDecisions::idField($reference, 'citations');
                if (!isset($available[$value]) || isset($provenance[$value])) {
                    throw Problem::invalid('Unknown or repeated evidence citation.');
                }
                $provenance[$value] = $available[$value];
            }
            if ($label !== 'insufficient' && ($provenance === [] || !self::supported($role, $label, $provenance))) {
                throw Problem::invalid('The revision must describe its cited evidence.');
            }
            $state = $label !== 'insufficient' && $this->evidence->missing($scope, $case) === [] ? 'ready' : 'needs-human';
            $proposal = CaseStore::id();
            $suggestion = ['label' => $label, 'citations' => self::references($provenance),
                'note' => Values::text($input['note'] ?? null, 'note', 1024)];
            $db = $this->store->db;
            $parameters = [...$scope->keys(), $id];
            $db->exec('INSERT INTO case_proposals(tenant,environment,case_id,case_round,proposal_id,evidence_rev,
                    assistant_role,state,suggestion,based_on,actor) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [...$parameters,
                $case['case_round'], $proposal, $case['evidence_rev'], $role, $state, Values::json($suggestion),
                $previous['proposal_id'], $scope->actor]);
            $db->exec("UPDATE case_proposals SET state='superseded' WHERE tenant=? AND environment=? AND case_id=? AND proposal_id=?",
                [...$parameters, $previous['proposal_id']]);
            $this->store->event($scope, 'case-assistant-revised', $id, ['proposal_id' => $proposal, 'based_on' => $previous['proposal_id'],
                'case_round' => $case['case_round'], 'evidence_rev' => $case['evidence_rev'], 'visibility' => self::visibility($role)]);
            return ['proposal_id' => $proposal, 'state' => $state];
        });
    }

    public function list(Scope $scope, string $id): array
    {
        $scope->require('operator', 'approver', 'author');
        $case = $this->store->record($scope, $id);
        $people = $this->store->template($scope, $case)['people'];
        $result = [];
        foreach ($this->store->db->all(self::readSQL() . ' ORDER BY created_at DESC LIMIT 150', [...$scope->keys(), $id]) as $row) {
            if (CaseEvidence::visible($scope, $people, self::visibility($row['assistant_role']))) {
                $result[] = self::decode($row);
            }
        }
        return $result;
    }

    private function proposal(Scope $scope, string $id, string $proposal): array
    {
        $value = $this->store->db->one(self::readSQL() . ' AND proposal_id=?', [...$scope->keys(), $id, $proposal]) ?? throw Problem::missing();
        $this->authorize($scope, $this->store->record($scope, $id), $value['assistant_role']);
        return self::decode($value);
    }

    private static function readSQL(): string
    {
        return "SELECT case_round,proposal_id,evidence_rev,assistant_role,state,suggestion,input_hash,usage_data,based_on,actor,created_at
            FROM case_proposals WHERE tenant=? AND environment=? AND case_id=? AND assistant_role<>'human-decision'";
    }

    private static function decode(array $row): array
    {
        foreach (['suggestion', 'usage_data'] as $key) {
            $row[$key] = $row[$key] === null ? null : Values::decode($row[$key]);
        }
        $row['usage'] = $row['usage_data'];
        unset($row['usage_data']);
        return $row;
    }

    private function authorize(Scope $scope, array $row, string $role): void
    {
        $groups = self::GROUPS[$role] ?? throw Problem::invalid('Unknown assistant role.');
        $people = $this->store->template($scope, $row)['people'];
        if ($scope->role === 'admin') {
            return;
        }
        foreach ($groups as $task) {
            if ($scope->role === CaseStore::role($task) && in_array($scope->actor, $people[$task], true)) {
                $this->store->requireActor($scope, $scope->actor, $scope->role);
                return;
            }
        }
        throw Problem::denied();
    }

    private static function visibility(string $role): string
    {
        return match ($role) {
            'classify' => 'workspace',
            'investigate' => 'investigation',
            'recommend' => 'finance',
        };
    }

    private function finish(Scope $scope, string $id, string $proposal, string $role, int $revision, array $suggestion, array $usage): void
    {
        $this->store->db->transaction(function () use ($scope, $id, $proposal, $role, $revision, $suggestion, $usage): void {
            $case = $this->store->record($scope, $id, true);
            $state = isset($suggestion['reason']) ? 'needs-human' : 'ready';
            if ($case['evidence_rev'] !== $revision || in_array($case['state'], ['closed', 'reconcile'], true)) {
                $state = 'stale';
            }
            $this->store->db->exec("UPDATE case_proposals SET state=IF(state='pending',?,state),suggestion=?,usage_data=?
                WHERE tenant=? AND environment=? AND case_id=? AND proposal_id=?",
                [$state, Values::json($suggestion), Values::json($usage), ...$scope->keys(), $id, $proposal]);
            $this->store->event($scope, 'case-assistant-finished', $id, ['proposal_id' => $proposal, 'state' => $state,
                'evidence_rev' => $revision, 'visibility' => self::visibility($role)]);
        });
    }

    private static function prompt(string $model, string $role, array $materials): array
    {
        $instruction = match ($role) {
            'classify' => 'Copy the customer-summary issue_code exactly. The value "other" is a valid issue code. Cite the summary.',
            'investigate' => 'Return the order status exactly. Ignore the customer-summary issue_code. '
                . 'Example: order status shipped and key e2 gives {"label":"shipped","citations":["e2"]}.',
            'recommend' => 'Read the payment-proof status. If captured, label refund. If refunded, label close. '
                . 'If uncertain, label manual. Use both citations when an order and payment-proof are present.',
        };
        $citationKinds = match ($role) {
            'classify' => ['customer-summary'],
            'investigate' => ['order'],
            'recommend' => ['order', 'payment-proof'],
        };
        $visible = [];
        $aliases = [];
        foreach ($materials as $alias => $material) {
            $visible[$alias] = ['kind' => $material['kind'], 'fields' => $material['fields']];
            if (in_array($material['kind'], $citationKinds, true)) {
                $aliases[] = $alias;
            }
        }
        $minimum = count($aliases) === count($citationKinds) ? count($aliases) : 0;
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'citations'],
            'properties' => ['label' => ['type' => 'string', 'enum' => [...self::ALLOWED[$role], 'insufficient']],
                'citations' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $aliases ?: ['none']],
                    'minItems' => $minimum, 'maxItems' => count($aliases)]]];
        return ['model' => $model, 'temperature' => 0, 'max_tokens' => 128, 'stream' => false,
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'case_' . $role, 'strict' => true, 'schema' => $schema]],
            'messages' => [['role' => 'system', 'content' => $instruction . ' Cite the specific evidence keys. '
                . 'If the needed facts are absent, return insufficient with no citations.'],
                ['role' => 'user', 'content' => Values::json($visible)]]];
    }

    private static function evaluate(string $role, array $http, array $prepared): array
    {
        $usage = $prepared['usage'];
        $manual = ['label' => 'insufficient', 'citations' => [], 'missing' => $prepared['missing']];
        if ($http['state'] !== 'complete') {
            return [$manual + ['reason' => $http['failure']], $usage];
        }
        $usage['response_hash'] = hash('sha256', $http['body']);
        try {
            $body = Values::decode($http['body']);
            $measured = $body['usage'] ?? null;
            if (is_array($measured)) {
                foreach (['input_tokens' => 'prompt_tokens', 'output_tokens' => 'completion_tokens'] as $key => $from) {
                    if (is_int($measured[$from] ?? null) && $measured[$from] >= 0 && $measured[$from] <= 1000000) {
                        $usage[$key] = $measured[$from];
                    }
                }
                $usage['metered'] = $usage['input_tokens'] !== null && $usage['output_tokens'] !== null;
            }
            $choice = $body['choices'][0] ?? [];
            if (count($body['choices'] ?? []) !== 1 || ($choice['finish_reason'] ?? null) !== 'stop' ||
                !is_string($choice['message']['content'] ?? null)) {
                return [$manual + ['reason' => 'incomplete-response'], $usage];
            }
            $output = Values::decode($choice['message']['content']);
            $label = $output['label'] ?? null;
            $citations = $output['citations'] ?? null;
            $materials = $prepared['materials'];
            if (count($output) !== 2 || !in_array($label, [...self::ALLOWED[$role], 'insufficient'], true) ||
                !is_array($citations) || !array_is_list($citations) || count($citations) > count($materials)) {
                return [$manual + ['reason' => 'invalid-structure'], $usage];
            }
            $resolved = [];
            foreach ($citations as $alias) {
                if (!is_string($alias) || !isset($materials[$alias]) || isset($resolved[$alias])) {
                    return [$manual + ['reason' => 'invalid-citation'], $usage];
                }
                $resolved[$alias] = $materials[$alias];
            }
            if ($label === 'insufficient' || $prepared['missing'] !== []) {
                return [$manual + ['reason' => 'insufficient-evidence', 'model_label' => $label], $usage];
            }
            if (!self::supported($role, $label, $resolved)) {
                return [$manual + ['reason' => 'unsupported-finding', 'model_label' => $label,
                    'model_citations' => array_keys($resolved)], $usage];
            }
            return [['label' => $label, 'citations' => self::references($resolved)], $usage];
        } catch (JsonException | Problem | \TypeError) {
            return [$manual + ['reason' => 'invalid-response'], $usage];
        }
    }

    private static function supported(string $role, string $label, array $citations): bool
    {
        $facts = array_column($citations, 'fields', 'kind');
        return match ($role) {
            'classify' => count($citations) === 1 && ($facts['customer-summary']['issue_code'] ?? null) === $label,
            'investigate' => count($citations) === 1 && ($facts['order']['status'] ?? null) === $label,
            'recommend' => isset($facts['order']['status'], $facts['payment-proof']['status']) &&
                match ($facts['payment-proof']['status']) {
                    'captured' => $label === 'refund',
                    'refunded' => $label === 'close',
                    default => $label === 'manual',
                },
        };
    }

    private static function references(array $materials): array
    {
        return array_values(array_map(static fn (array $record): array => array_intersect_key($record,
            array_fill_keys(['evidence_id', 'source', 'kind'], true)), $materials));
    }
}
