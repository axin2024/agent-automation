<?php

declare(strict_types=1);

namespace AgentAutomation;

final class CaseAssignments
{
    public function __construct(private CaseStore $store, private CaseEvidence $evidence)
    {
    }

    public function update(Scope $scope, string $id, string $task, mixed $key, array $input): array
    {
        if (!in_array($task, CaseTemplates::TASKS, true)) {
            throw Problem::missing();
        }
        $scope->require(CaseStore::role($task));
        return $this->store->change($scope, $key, 'assignment:' . $task, $id, $input,
            function () use ($scope, $id, $task, $input): array {
                $case = $this->store->record($scope, $id, true);
                $stage = match ($task) {
                    'investigate' => ['investigating', 'needs-evidence'],
                    'decision' => ['decision'],
                    default => ['review'],
                };
                if (!in_array($case['state'], $stage, true)) {
                    throw Problem::conflict('The stage no longer accepts this task.');
                }
                $template = $this->store->template($scope, $case);
                $args = [...$scope->keys(), $id, $case['case_round'], $task];
                $db = $this->store->db;
                $record = $db->one('SELECT * FROM case_assignments WHERE tenant=? AND environment=? AND case_id=?
                    AND case_round=? AND task_role=?', $args) ?? throw Problem::missing();
                if ($record['state'] === 'complete' ||
                    $record['assignment_id'] !== ($input['assignment_id'] ?? null) ||
                    $record['lease_rev'] !== Values::number($input['lease_rev'] ?? null, 'lease_rev', 1, 2147483647)) {
                    throw Problem::conflict('The assignment or lease changed.');
                }
                $operation = $input['operation'] ?? null;
                if (!in_array($operation, ['claim', 'assign', 'transfer', 'delegate', 'revoke', 'complete'], true)) {
                    throw Problem::invalid('Unknown assignment operation.');
                }
                $actors = $template['people'][$task];
                $role = CaseStore::role($task);
                $isSupervisor = $scope->role === 'admin';
                $isOwner = $scope->actor === $record['assignee'];
                $isDelegate = $scope->actor === $record['delegate'] &&
                    $record['delegated_until'] > $this->store->now()->format('Y-m-d H:i:s.u');
                $change = [];
                switch ($operation) {
                    case 'claim':
                        if ($isSupervisor || !in_array($scope->actor, $actors, true)) {
                            throw Problem::denied();
                        }
                        if ($record['assignee'] !== null && !$isOwner) {
                            throw Problem::conflict('This assignment is already claimed.');
                        }
                        $this->store->requireActor($scope, $scope->actor, $role);
                        $change = ['assignee' => $scope->actor, 'state' => 'claimed'];
                        break;
                    case 'assign':
                    case 'transfer':
                    case 'delegate':
                    case 'revoke':
                        if (!$isOwner && !$isSupervisor) {
                            throw Problem::denied();
                        }
                        if ($operation === 'revoke') {
                            $change = ['delegate' => null, 'delegated_until' => null];
                            break;
                        }
                        $actor = Values::identifier($input['actor'] ?? null, 'actor');
                        $this->store->requireActor($scope, $actor, $role);
                        $handoff = Values::text($input['handoff'] ?? '', 'handoff', 1024, $operation === 'assign' ? 0 : 1);
                        if ($operation === 'delegate') {
                            if ($record['assignee'] === null || $actor === $record['assignee'] ||
                                ($task === 'review-finance' && in_array($actor, $template['people']['review-operations'], true)) ||
                                ($task === 'review-operations' && in_array($actor, $template['people']['review-finance'], true))) {
                                throw Problem::invalid('Invalid delegate for this task.');
                            }
                            $hours = Values::number($input['hours'] ?? null, 'hours', 1, 168);
                            $change = ['delegate' => $actor, 'delegated_until' => $this->store->now()->modify("+$hours hours")->format('Y-m-d H:i:s.u'),
                                'handoff' => $handoff];
                        } else {
                            if (!in_array($actor, $actors, true)) {
                                throw Problem::denied();
                            }
                            $change = ['assignee' => $actor, 'delegate' => null, 'delegated_until' => null, 'state' => 'claimed',
                                'handoff' => $handoff];
                        }
                        break;
                    case 'complete':
                        if ((!$isOwner && !$isDelegate) || $isSupervisor || $task === 'decision') {
                            throw Problem::denied();
                        }
                        $this->store->requireActor($scope, $scope->actor, $role);
                        $current = Values::number($input['evidence_rev'] ?? null, 'evidence_rev', 0, 2147483647);
                        if ($current !== $case['evidence_rev']) {
                            throw Problem::conflict('The evidence changed. Refresh before completing the task.');
                        }
                        if ($task !== 'investigate' && $db->one("SELECT 1 FROM case_assignments WHERE tenant=? AND environment=?
                            AND case_id=? AND case_round=? AND task_role IN ('review-operations','review-finance')
                            AND completed_by=? AND state='complete' LIMIT 1",
                            [...$scope->keys(), $id, $case['case_round'], $scope->actor]) !== null) {
                            throw Problem::denied();
                        }
                        $missing = $this->evidence->missing($scope, $case);
                        if ($missing !== []) {
                            if ($task === 'investigate' && $case['state'] !== 'needs-evidence') {
                                $this->store->transition($scope, $case, 'needs-evidence', 'investigate', ['missing' => $missing]);
                            }
                            return ['assignment_id' => $record['assignment_id'], 'lease_rev' => $record['lease_rev'],
                                'state' => $record['state'], 'missing' => $missing];
                        }
                        $change = ['completed_by' => $scope->actor, 'evidence_rev' => $current, 'state' => 'complete',
                            'handoff' => Values::text($input['handoff'] ?? '', 'review note', 1024, 0)];
                        break;
                }
                $columns = implode(',', array_map(static fn (string $name): string => "$name=?", array_keys($change)));
                $db->exec("UPDATE case_assignments SET $columns,lease_rev=lease_rev+1
                    WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND task_role=?", [...array_values($change), ...$args]);
                $record = array_replace($record, $change);
                $receipt = ['assignment_id' => $record['assignment_id'], 'lease_rev' => $record['lease_rev'] + 1,
                    'state' => $record['state'], 'task_role' => $task, 'case_round' => $case['case_round']];
                $this->store->event($scope, 'case-assignment-' . $operation, $id, $receipt + [
                    'assignee' => $record['assignee'], 'delegate' => $record['delegate'], 'evidence_rev' => $record['evidence_rev'],
                    'handoff' => $record['handoff'],
                ]);
                if ($operation === 'complete') {
                    $this->advance($scope, $case, $task, $template);
                }
                return $receipt;
            });
    }

    private function advance(Scope $scope, array $case, string $task, array $template): void
    {
        $id = $case['case_id'];
        if ($task === 'investigate') {
            $this->store->createTask($scope, $id, $case['case_round'], 'review-operations', $template);
            $this->store->createTask($scope, $id, $case['case_round'], 'review-finance', $template);
            $earliest = $template['sla_minutes']['review-operations'] <= $template['sla_minutes']['review-finance']
                ? 'review-operations' : 'review-finance';
            $this->store->transition($scope, $case, 'review', $earliest);
            return;
        }
        $review = $this->store->db->one("SELECT COUNT(DISTINCT completed_by) AS done FROM case_assignments
            WHERE tenant=? AND environment=? AND case_id=? AND case_round=? AND evidence_rev=? AND state='complete'
            AND task_role IN ('review-operations','review-finance')", [...$scope->keys(), $id, $case['case_round'], $case['evidence_rev']]);
        if ($review['done'] === 2) {
            $this->store->createTask($scope, $id, $case['case_round'], 'decision', $template);
            $this->store->transition($scope, $case, 'decision', 'decision');
        } else {
            $other = $task === 'review-finance' ? 'review-operations' : 'review-finance';
            $this->store->db->exec('UPDATE service_cases c
                JOIN case_assignments a ON a.tenant=c.tenant AND a.environment=c.environment AND a.case_id=c.case_id AND a.case_round=c.case_round
                SET c.deadline=a.due_at WHERE c.tenant=? AND c.environment=? AND c.case_id=? AND a.task_role=?',
                [...$scope->keys(), $id, $other]);
        }
    }
}
