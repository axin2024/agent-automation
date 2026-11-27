<?php

declare(strict_types=1);

namespace AgentAutomation;

final class CaseEvidence
{
    private const MODEL_FIELDS = [
        'customer-summary' => ['issue_code'],
        'order' => ['status'],
        'payment-proof' => ['status', 'ceiling_minor'],
    ];
    private const VISIBLE_KINDS = [
        'classify' => ['customer-summary'],
        'investigate' => ['customer-summary', 'order'],
        'recommend' => ['order', 'payment-proof'],
    ];

    public function __construct(private CaseStore $store)
    {
    }

    public function put(Scope $scope, string $id, mixed $key, array $input): array
    {
        $scope->require('operator', 'approver');
        return $this->store->change($scope, $key, 'evidence', $id, $input, function () use ($scope, $id, $input): array {
            $row = $this->store->open($scope, $id, $input, 'investigating', 'needs-evidence', 'review', 'decision',
                'awaiting-votes', 'ready-to-dispatch');
            $template = $this->store->template($scope, $row);
            $kind = Values::identifier($input['kind'] ?? null, 'kind');
            if (!isset(self::MODEL_FIELDS[$kind])) {
                throw Problem::invalid('Unknown evidence kind.');
            }
            $visibility = $input['visibility'] ?? 'workspace';
            if (!in_array($visibility, ['workspace', 'investigation', 'finance'], true) ||
                ($kind === 'payment-proof' && $visibility !== 'finance')) {
                throw Problem::invalid('Invalid visibility for the evidence kind.');
            }
            if ($scope->role !== 'admin') {
                $group = $visibility === 'finance' ? 'review-finance' : 'investigate';
                if ($scope->role !== CaseStore::role($group) || !in_array($scope->actor, $template['people'][$group], true)) {
                    throw Problem::denied();
                }
                $this->store->requireActor($scope, $scope->actor, $scope->role);
            }
            $verified = $input['verified'] ?? false;
            $source = Values::identifier($input['source'] ?? null, 'source');
            $selected = $input['allowed_fields'] ?? [];
            if (!is_bool($verified) || !is_array($selected) || !array_is_list($selected) ||
                count($selected) > count(self::MODEL_FIELDS[$kind]) || array_filter($selected, static fn ($key) => !is_string($key)) ||
                array_diff($selected, self::MODEL_FIELDS[$kind])) {
                throw Problem::invalid('Invalid verification flag or allowed_fields.');
            }
            $payload = self::payload($kind, $input['payload'] ?? null);
            if (array_diff($selected, array_keys($payload))) {
                throw Problem::invalid('Allowed fields must exist in the evidence.');
            }
            $next = $row['evidence_rev'] + 1;
            $evidence = CaseStore::id();
            $allowed = array_values(array_unique($selected));
            sort($allowed);
            $digest = Values::digest([$kind, $visibility, $source, $verified, $allowed, $payload]);
            $db = $this->store->db;
            CaseDecisions::invalidate($db, $scope, $row);
            $db->exec('INSERT INTO case_evidence(tenant,environment,case_id,evidence_rev,evidence_id,case_round,
                    kind,source,visibility,verified,allowed_fields,payload,digest,actor)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$scope->keys(), $id, $next, $evidence, $row['case_round'],
                $kind, $source, $visibility, (int) $verified, Values::json($allowed), Values::json($payload), $digest, $scope->actor]);
            $db->exec('UPDATE service_cases SET evidence_rev=?,revision=revision+1 WHERE tenant=? AND environment=? AND case_id=?',
                [$next, ...$scope->keys(), $id]);
            $db->exec("UPDATE case_proposals SET state='stale' WHERE tenant=? AND environment=? AND case_id=?
                AND case_round=? AND state IN ('pending','ready','needs-human')", [...$scope->keys(), $id, $row['case_round']]);
            if (in_array($row['state'], ['review', 'decision', 'awaiting-votes', 'ready-to-dispatch'], true)) {
                $db->exec("UPDATE case_assignments SET state=IF(assignee IS NULL,'pending','claimed'), lease_rev=lease_rev+1,
                    completed_by=NULL,evidence_rev=NULL,delegate=NULL,delegated_until=NULL
                    WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND task_role<>'investigate'",
                    [...$scope->keys(), $id, $row['case_round']]);
                foreach (['review-operations', 'review-finance'] as $review) {
                    $this->store->createTask($scope, $id, $row['case_round'], $review, $template);
                }
                $earliest = $template['sla_minutes']['review-operations'] <= $template['sla_minutes']['review-finance']
                    ? 'review-operations' : 'review-finance';
                $this->store->transition($scope, array_replace($row, ['revision' => $row['revision'] + 1]), 'review',
                    $earliest, ['reason' => 'evidence-replaced']);
                $row['revision']++;
            }
            $this->store->event($scope, 'case-evidence-stored', $id, ['case_round' => $row['case_round'], 'evidence_rev' => $next,
                'evidence_id' => $evidence, 'source' => $source, 'kind' => $kind, 'visibility' => $visibility, 'digest' => $digest]);
            return ['evidence_id' => $evidence, 'evidence_rev' => $next, 'revision' => $row['revision'] + 1, 'allowed_fields' => $allowed];
        });
    }

    public function list(Scope $scope, string $id): array
    {
        $scope->require('operator', 'approver', 'author');
        $row = $this->store->record($scope, $id);
        $people = $this->store->template($scope, $row)['people'];
        $records = $this->store->db->all('SELECT evidence_rev,evidence_id,case_round,kind,source,visibility,verified,
            allowed_fields,payload,digest,actor,captured_at FROM case_evidence
            WHERE tenant=? AND environment=? AND case_id=? ORDER BY evidence_rev DESC LIMIT 250', [...$scope->keys(), $id]);
        $visible = [];
        foreach ($records as $record) {
            if (!self::visible($scope, $people, $record['visibility'])) {
                continue;
            }
            $record['allowed_fields'] = Values::decode($record['allowed_fields']);
            $record['payload'] = Values::decode($record['payload']);
            $visible[] = $record;
        }
        return $visible;
    }

    public function materials(Scope $scope, array $row, string $role): array
    {
        $kinds = self::VISIBLE_KINDS[$role] ?? throw Problem::invalid('Unknown assistant role.');
        $allowedVisibility = match ($role) {
            'classify' => ['workspace'],
            'investigate' => ['workspace', 'investigation'],
            'recommend' => ['workspace', 'finance'],
        };
        $materials = [];
        foreach ($this->current($scope, $row) as $evidence) {
            if (!$evidence['verified'] || !in_array($evidence['kind'], $kinds, true) ||
                !in_array($evidence['visibility'], $allowedVisibility, true)) {
                continue;
            }
            $fields = array_intersect_key($evidence['payload'], array_flip($evidence['allowed_fields']));
            if ($fields !== []) {
                // The model sees a local alias, a type and enumerated fields. It receives no record identifiers or notes.
                $materials['e' . (count($materials) + 1)] = ['evidence_id' => $evidence['evidence_id'],
                    'source' => $evidence['source'], 'kind' => $evidence['kind'], 'fields' => $fields];
            }
        }
        return $materials;
    }

    public function missing(Scope $scope, array $row): array
    {
        $present = [];
        foreach ($this->current($scope, $row) as $evidence) {
            if ($evidence['verified']) {
                $present[] = $evidence['kind'];
            }
        }
        return array_values(array_diff($this->store->template($scope, $row)['required_items'], $present));
    }

    public function current(Scope $scope, array $row): array
    {
        $parameters = [...$scope->keys(), $row['case_id'], $row['case_round']];
        $items = $this->store->db->all('SELECT e.evidence_id,e.evidence_rev,e.kind,e.source,e.visibility,e.verified,e.allowed_fields,e.payload
            FROM case_evidence e JOIN (
                SELECT kind,MAX(evidence_rev) AS last_rev FROM case_evidence
                WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND evidence_rev<=? GROUP BY kind
            ) versions ON versions.kind=e.kind AND versions.last_rev=e.evidence_rev
            WHERE e.tenant=? AND e.environment=? AND e.case_id=? AND e.case_round=? ORDER BY e.kind',
            [...$parameters, $row['evidence_rev'], ...$parameters]);
        foreach ($items as &$item) {
            $item['payload'] = Values::decode($item['payload']);
            $item['allowed_fields'] = Values::decode($item['allowed_fields']);
        }
        return $items;
    }

    public static function visible(Scope $scope, array $people, string $visibility): bool
    {
        return $scope->role === 'admin' || $visibility === 'workspace' ||
            ($visibility === 'investigation' && $scope->role === 'operator' &&
                in_array($scope->actor, [...$people['investigate'], ...$people['review-operations']], true)) ||
            ($visibility === 'finance' && $scope->role === 'approver' &&
                in_array($scope->actor, [...$people['review-finance'], ...$people['decision']], true));
    }

    private static function payload(string $kind, mixed $input): array
    {
        if (!is_array($input)) {
            throw Problem::invalid('Expected an evidence payload object.');
        }
        $core = match ($kind) {
            'customer-summary' => ['issue_code' => self::choice($input['issue_code'] ?? null, CaseTemplates::CATEGORIES, 'payload.issue_code')],
            'order' => ['status' => self::choice($input['status'] ?? null, ['placed', 'shipped', 'delivered', 'cancelled'], 'payload.status')],
            'payment-proof' => [
                'status' => self::choice($input['status'] ?? null, ['captured', 'refunded', 'uncertain'], 'payload.status'),
                'ceiling_minor' => Values::number($input['ceiling_minor'] ?? null, 'payload.ceiling_minor', 1, 100000000),
                'payees' => self::payees($input['payees'] ?? null),
            ],
        };
        if (array_diff(array_keys($input), [...array_keys($core), 'fragment', 'source_ref'])) {
            throw Problem::invalid('Unknown evidence payload fields.');
        }
        return $core + ['fragment' => Values::text($input['fragment'] ?? '', 'payload.fragment', 3500, 0),
            'source_ref' => Values::text($input['source_ref'] ?? '', 'payload.source_ref', 160, 0)];
    }

    private static function payees(mixed $input): array
    {
        if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > 8) {
            throw Problem::invalid('Expected locally verified payee references.');
        }
        return array_values(array_unique(array_map(static fn ($payee) => Values::identifier($payee, 'payee reference'), $input)));
    }

    private static function choice(mixed $value, array $options, string $label): string
    {
        if (!in_array($value, $options, true)) {
            throw Problem::invalid("Invalid $label.");
        }
        return $value;
    }
}
