<?php

declare(strict_types=1);

namespace AgentAutomation;

final class CaseDecisions
{
    private const SIGNERS = ['operations' => 'review-operations', 'finance' => 'review-finance'];

    public function __construct(private CaseStore $store, private CaseEvidence $evidence)
    {
    }

    public function propose(Scope $scope, string $id, mixed $key, array $input): array
    {
        $scope->require('approver');
        return $this->store->change($scope, $key, 'propose-action', $id, $input, function () use ($scope, $id, $input): array {
            $row = $this->store->open($scope, $id, $input, 'decision', 'awaiting-votes', 'ready-to-dispatch');
            $template = $this->store->template($scope, $row);
            $parameters = [...$scope->keys(), $id];
            $db = $this->store->db;
            $task = $db->one("SELECT assignee,delegate,delegated_until FROM case_assignments WHERE tenant=? AND environment=?
                AND case_id=? AND case_round=? AND task_role='decision'", [...$parameters, $row['case_round']]) ?? throw Problem::missing();
            $delegated = $scope->actor === $task['delegate'] &&
                $task['delegated_until'] > $this->store->now()->format('Y-m-d H:i:s.u');
            if ($scope->role !== 'approver' || ($scope->actor !== $task['assignee'] && !$delegated)) {
                throw Problem::denied();
            }
            $this->store->requireActor($scope, $scope->actor, 'approver');
            if ($this->evidence->missing($scope, $row) !== []) {
                throw Problem::conflict('The case has missing verified evidence.');
            }
            $args = $this->arguments($scope, $row, $template, $input);
            $reason = Values::text($input['reason'] ?? null, 'reason', 1024);
            $based = isset($input['based_on']) ? self::idField($input['based_on'], 'based_on') : null;
            if ($based !== null && $db->one("SELECT 1 FROM case_proposals WHERE tenant=? AND environment=? AND case_id=?
                AND proposal_id=? AND case_round=? AND evidence_rev=? AND assistant_role='recommend' AND state='ready'",
                [...$parameters, $based, $row['case_round'], $row['evidence_rev']]) === null) {
                throw Problem::conflict('The recommendation does not match current evidence.');
            }
            self::invalidate($db, $scope, $row);
            $db->exec("UPDATE case_proposals SET state='superseded' WHERE tenant=? AND environment=? AND case_id=?
                AND case_round=? AND assistant_role='human-decision' AND state='ready'", [...$parameters, $row['case_round']]);
            $hash = Values::digest($args);
            $proposal = CaseStore::id();
            $decision = CaseStore::id();
            $db->exec("INSERT INTO case_proposals(tenant,environment,case_id,case_round,proposal_id,evidence_rev,
                    assistant_role,state,suggestion,operational_args,args_hash,based_on,actor)
                VALUES(?,?,?,?,?,?,'human-decision','ready',?,?,?,?,?)", [...$parameters, $row['case_round'], $proposal,
                $row['evidence_rev'], Values::json(['reason' => $reason]), Values::json($args), $hash, $based, $scope->actor]);
            $expires = $this->store->deadline($template, 'decision');
            $db->exec("INSERT INTO case_decisions(tenant,environment,case_id,case_round,decision_id,proposal_id,evidence_rev,
                    args_hash,required_votes,state,expires_at)
                VALUES(?,?,?,?,?,?,?,?,2,'awaiting-votes',?)", [...$parameters, $row['case_round'], $decision, $proposal,
                $row['evidence_rev'], $hash, $expires]);
            $this->store->transition($scope, $row, 'awaiting-votes', 'decision', [
                'proposal_id' => $proposal, 'decision_id' => $decision, 'evidence_rev' => $row['evidence_rev'],
                'args_hash' => $hash, 'based_on' => $based,
            ]);
            return ['decision_id' => $decision, 'proposal_id' => $proposal, 'evidence_rev' => $row['evidence_rev'],
                'args_hash' => $hash, 'required_votes' => 2, 'dispatch_state' => 'awaiting_first_vote'];
        });
    }

    public function vote(Scope $scope, string $id, mixed $key, array $input): array
    {
        $approval = $input['approval_role'] ?? null;
        $task = is_string($approval) ? (self::SIGNERS[$approval] ?? null) : null;
        if ($task === null) {
            throw Problem::invalid('Invalid approval_role.');
        }
        $role = CaseStore::role($task);
        if ($scope->role !== $role) {
            throw Problem::denied();
        }
        return $this->store->change($scope, $key, 'vote', $id, $input, function () use ($scope, $id, $input, $approval, $task, $role): array {
            $row = $this->store->record($scope, $id, true);
            $template = $this->store->template($scope, $row);
            $this->store->requireActor($scope, $scope->actor, $role);
            if (!in_array($scope->actor, $template['people'][$task], true)) {
                throw Problem::denied();
            }
            $proposal = self::idField($input['proposal_id'] ?? null, 'proposal_id');
            $evidence = Values::number($input['evidence_rev'] ?? null, 'evidence_rev', 0, 2147483647);
            $hash = self::digestField($input['args_hash'] ?? null);
            $db = $this->store->db;
            $parameters = [...$scope->keys(), $id];
            $decision = $db->one('SELECT d.*, p.operational_args, p.state AS proposal_state FROM case_decisions d
                JOIN case_proposals p USING(tenant,environment,case_id,proposal_id)
                WHERE d.tenant=? AND d.environment=? AND d.case_id=? AND d.proposal_id=?', [...$parameters, $proposal]) ?? throw Problem::missing();
            $votes = $this->votes($scope, $id, $decision['decision_id']);
            if ($row['state'] !== 'awaiting-votes' || $decision['state'] !== 'awaiting-votes' ||
                $decision['case_round'] !== $row['case_round'] || $decision['proposal_state'] !== 'ready' ||
                $decision['expires_at'] <= $this->store->now()->format('Y-m-d H:i:s.u') ||
                $decision['evidence_rev'] !== $row['evidence_rev'] || $evidence !== $row['evidence_rev'] ||
                !hash_equals($decision['args_hash'], $hash) || !hash_equals(Values::digest(Values::decode($decision['operational_args'])), $hash)) {
                throw Problem::conflict('The decision or its parameters changed or expired.');
            }
            foreach ($votes as $vote) {
                if ($vote['actor'] === $scope->actor || $vote['approval_role'] === $approval) {
                    throw Problem::conflict('The role or signer already approved this decision.');
                }
            }
            $identity = [...$parameters, $decision['decision_id']];
            $db->exec('INSERT INTO case_votes(tenant,environment,case_id,decision_id,approval_role,actor,evidence_rev,args_hash)
                VALUES(?,?,?,?,?,?,?,?)', [...$identity, $approval, $scope->actor, $evidence, $hash]);
            $this->store->event($scope, 'case-vote', $id, ['decision_id' => $decision['decision_id'], 'proposal_id' => $proposal,
                'approval_role' => $approval, 'case_round' => $row['case_round'], 'evidence_rev' => $evidence, 'args_hash' => $hash]);
            $queued = count($votes) + 1 === $decision['required_votes'];
            if ($queued) {
                $operation = Values::digest([$scope->keys(), $id, $row['case_round'], $decision['decision_id'], $hash]);
                $db->exec("INSERT INTO case_dispatch(tenant,environment,case_id,case_round,decision_id,operation,state)
                    VALUES(?,?,?,?,?,?,'queued')", [...$parameters, $row['case_round'], $decision['decision_id'], $operation]);
                $db->exec("UPDATE case_decisions SET state='queued'
                    WHERE tenant=? AND environment=? AND case_id=? AND decision_id=?", $identity);
                $this->store->transition($scope, $row, 'ready-to-dispatch', 'decision',
                    ['decision_id' => $decision['decision_id'], 'operation' => $operation]);
            }
            return ['decision_id' => $decision['decision_id'], 'required_votes' => $decision['required_votes'],
                'dispatch_state' => $queued ? 'queued' : 'awaiting_second_vote'];
        });
    }

    public function list(Scope $scope, string $id): array
    {
        $scope->require('operator', 'approver', 'author');
        $this->store->record($scope, $id);
        $rows = $this->store->db->all('SELECT d.case_round,d.decision_id,d.proposal_id,d.evidence_rev,d.args_hash,d.required_votes,
            d.state,d.expires_at,p.operational_args,p.based_on,p.suggestion,x.operation,x.receipt_id,x.failure,x.accepted_at,
            r.outcome FROM case_decisions d
            JOIN case_proposals p USING(tenant,environment,case_id,proposal_id)
            LEFT JOIN case_dispatch x USING(tenant,environment,case_id,decision_id)
            LEFT JOIN case_connector_receipts r ON r.tenant=x.tenant AND r.environment=x.environment AND r.operation=x.operation
            WHERE d.tenant=? AND d.environment=? AND d.case_id=? ORDER BY d.created_at DESC LIMIT 100', [...$scope->keys(), $id]);
        foreach ($rows as &$row) {
            $row['operational_args'] = Values::decode($row['operational_args']);
            $row['suggestion'] = Values::decode($row['suggestion']);
            $row['outcome'] = $row['outcome'] === null ? null : Values::decode($row['outcome']);
            $row['votes'] = $this->votes($scope, $id, $row['decision_id']);
            $row['retryable'] = $row['state'] === 'needs-check';
        }
        return $rows;
    }

    private function votes(Scope $scope, string $id, string $decision): array
    {
        return $this->store->db->all('SELECT approval_role,actor,evidence_rev,args_hash,signed_at FROM case_votes
            WHERE tenant=? AND environment=? AND case_id=? AND decision_id=? ORDER BY signed_at', [...$scope->keys(), $id, $decision]);
    }

    private function arguments(Scope $scope, array $row, array $template, array $input): array
    {
        $kind = $input['kind'] ?? null;
        if ($kind === 'close') {
            if (isset($input['amount_minor']) || isset($input['payee'])) {
                throw Problem::invalid('A closure cannot include refund parameters.');
            }
            return ['kind' => 'close'];
        }
        if ($kind !== 'refund' || $template['refund_limit_minor'] < 1) {
            throw Problem::invalid('The action is unavailable for this case type.');
        }
        $amount = Values::number($input['amount_minor'] ?? null, 'amount_minor', 1, $template['refund_limit_minor']);
        $payee = Values::identifier($input['payee'] ?? null, 'payee');
        foreach ($this->evidence->current($scope, $row) as $record) {
            $payment = $record['payload'];
            if ($record['kind'] === 'payment-proof' && $record['verified'] && $payment['status'] === 'captured' &&
                $amount <= $payment['ceiling_minor'] && in_array($payee, $payment['payees'], true)) {
                return ['kind' => 'refund', 'amount_minor' => $amount, 'payee' => $payee];
            }
        }
        throw Problem::invalid('The payee or amount is unsupported by current payment evidence.');
    }

    public static function invalidate(Db $db, Scope $scope, array $row): void
    {
        $parameters = [...$scope->keys(), $row['case_id'], $row['case_round']];
        if ($db->one("SELECT 1 FROM case_dispatch WHERE tenant=? AND environment=? AND case_id=?
            AND case_round=? AND state IN ('needs-check','executed') LIMIT 1", $parameters) !== null) {
            throw Problem::conflict('The connector already accepted the case action.');
        }
        $db->exec("UPDATE case_dispatch SET state='invalidated',failure='decision-revised'
            WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND state='queued'", $parameters);
        $db->exec("UPDATE case_decisions SET state='invalidated'
            WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND state IN ('awaiting-votes','queued')", $parameters);
    }

    public static function idField(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw Problem::invalid("Invalid $field.");
        }
        return $value;
    }

    private static function digestField(mixed $value): string
    {
        $value = is_string($value) && str_starts_with($value, 'sha256:') ? substr($value, 7) : $value;
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw Problem::invalid('Invalid args_hash.');
        }
        return $value;
    }
}
