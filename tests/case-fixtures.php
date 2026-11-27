<?php

declare(strict_types=1);

use AgentAutomation\Application;
use AgentAutomation\Problem;
use AgentAutomation\Scope;

function caseCheck(bool $passed, string $reason): void
{
    if (!$passed) {
        throw new RuntimeException($reason);
    }
}

function caseReject(int $expected, callable $request): void
{
    try {
        $request();
    } catch (Problem $problem) {
        caseCheck($problem->status === $expected, 'Expected ' . $expected . ', received ' . $problem->status . ' ' . $problem->getMessage());
        return;
    }
    throw new RuntimeException("Expected a $expected response.");
}

function caseCredentials(Application $app, string $tenant): array
{
    $credentials = [];
    foreach (['supervisor' => 'admin', 'investigator' => 'operator', 'deputy' => 'operator', 'operations' => 'operator',
        'finance' => 'approver', 'decision-owner' => 'approver', 'unassigned' => 'author'] as $actor => $role) {
        $value = $app->access->provision($tenant, 'test', $actor, $role, 12);
        $credentials[$actor] = ['scope' => $app->access->identify('Bearer ' . $value['token']), 'token' => $value['token']];
    }
    return $credentials;
}

function caseTemplate(): array
{
    return [
        'sources' => ['after-sales', 'email'],
        'required_items' => ['customer-summary', 'order', 'payment-proof'],
        'people' => ['investigate' => ['investigator', 'deputy'], 'review-operations' => ['operations'],
            'review-finance' => ['finance'], 'decision' => ['decision-owner']],
        'sla_minutes' => ['investigate' => 60, 'review-operations' => 120, 'review-finance' => 180, 'decision' => 240],
        'calendar' => ['zone' => 'Asia/Shanghai', 'opens' => 9, 'closes' => 17, 'weekdays' => [1, 2, 3, 4, 5], 'holidays' => []],
        'refund_limit_minor' => 25000,
        'escalation_recipient' => 'supervisor',
    ];
}

function caseIntake(Application $app, Scope $scope, string $ref): array
{
    return $app->cases->intake($scope, "open-$ref", ['case_type' => 'returns', 'source' => 'after-sales',
        'external_ref' => $ref, 'customer_ref' => 'customer-local-100', 'subject' => "After-sales issue $ref", 'order_no' => 'ORD-4001']);
}

function caseTask(Application $app, Scope $scope, string $case, string $task, string $operation, array $fields = []): array
{
    $row = $app->cases->detail($scope, $case);
    foreach ($row['assignments'] as $assignment) {
        if ($assignment['task_role'] === $task && $assignment['case_round'] === $row['case_round']) {
            return ['assignment_id' => $assignment['assignment_id'], 'lease_rev' => $assignment['lease_rev'], 'operation' => $operation,
                'evidence_rev' => $row['evidence_rev']] + $fields;
        }
    }
    throw new RuntimeException("Task $task unavailable.");
}

function caseWriteTask(Application $app, Scope $scope, string $case, string $task, string $operation, array $fields = []): array
{
    return $app->caseAssignments->update($scope, $case, $task, bin2hex(random_bytes(10)),
        caseTask($app, $scope, $case, $task, $operation, $fields));
}

function caseMaterial(Application $app, Scope $scope, string $case, string $kind, string $value): array
{
    $payload = match ($kind) {
        'customer-summary' => ['issue_code' => $value],
        'order' => ['status' => $value],
        'payment-proof' => ['status' => $value, 'ceiling_minor' => 21000, 'payees' => ['demo-original', 'demo-alternate']],
    };
    $allow = $kind === 'payment-proof' ? ['status', 'ceiling_minor'] : array_keys($payload);
    $revision = $app->cases->detail($scope, $case)['revision'];
    return $app->caseEvidence->put($scope, $case, bin2hex(random_bytes(10)), [
        'revision' => $revision, 'kind' => $kind, 'source' => "$kind-local",
        'visibility' => $kind === 'payment-proof' ? 'finance' : 'workspace',
        'verified' => true,
        'allowed_fields' => $allow,
        'payload' => $payload + ['fragment' => 'PRIVATE raw contact demo-contact@example.test', 'source_ref' => 'private-source-ref-999'],
    ]);
}
