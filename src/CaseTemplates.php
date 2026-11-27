<?php

declare(strict_types=1);

namespace AgentAutomation;

final class CaseTemplates
{
    public const KINDS = ['customer-summary', 'order', 'payment-proof'];
    public const TASKS = ['investigate', 'review-operations', 'review-finance', 'decision'];
    public const CATEGORIES = ['delivery', 'billing', 'quality', 'other'];

    public function __construct(private Db $db, private Workflows $flows)
    {
    }

    public function preview(Scope $scope, array $input, mixed $source = null): array
    {
        $scope->require('admin');
        $definition = $this->normalize($scope, $input);
        return ['definition' => $definition, 'required_items' => $definition['required_items'],
            'matches' => $source === null || in_array(Values::identifier($source, 'source'), $definition['sources'], true)];
    }

    public function publish(Scope $scope, string $name, int $expected, array $input): array
    {
        $scope->require('admin');
        $definition = $this->normalize($scope, $input);
        return $this->db->transaction(function () use ($scope, $name, $expected, $definition): array {
            $this->db->one('SELECT tenant FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $prior = $this->db->one('SELECT revision, definition FROM case_type_versions
                WHERE tenant=? AND environment=? AND case_type=? ORDER BY revision DESC LIMIT 1', [...$scope->keys(), $name]);
            $current = $prior['revision'] ?? 0;
            if ($expected !== $current) {
                throw Problem::conflict('The case template revision changed.');
            }
            if ($prior !== null && Values::digest(Values::decode($prior['definition'])) === Values::digest($definition)) {
                return ['case_type' => $name, 'case_type_rev' => $current, 'definition' => $definition];
            }
            $revision = $current + 1;
            $this->db->exec('INSERT INTO case_type_versions(tenant,environment,case_type,revision,definition,actor)
                VALUES(?,?,?,?,?,?)', [...$scope->keys(), $name, $revision, Values::json($definition), $scope->actor]);
            $this->db->exec('INSERT INTO case_intake_policy(tenant,environment,enabled) VALUES(?,?,1)
                ON DUPLICATE KEY UPDATE enabled=enabled', $scope->keys());
            $this->db->audit($scope, 'case-template-published', $name, ['case_type_rev' => $revision]);
            return ['case_type' => $name, 'case_type_rev' => $revision, 'definition' => $definition];
        });
    }

    public function get(Scope $scope, string $name, ?int $revision = null): array
    {
        $parameters = [...$scope->keys(), $name];
        $sql = 'SELECT case_type, revision AS case_type_rev, definition FROM case_type_versions
            WHERE tenant=? AND environment=? AND case_type=?';
        if ($revision !== null) {
            $sql .= ' AND revision=?';
            $parameters[] = $revision;
        }
        $row = $this->db->one($sql . ' ORDER BY revision DESC LIMIT 1', $parameters) ?? throw Problem::missing();
        $row['definition'] = Values::decode($row['definition']);
        return $row;
    }

    public function list(Scope $scope): array
    {
        $scope->require('operator', 'approver', 'author');
        return $this->db->all('SELECT case_type, MAX(revision) AS case_type_rev FROM case_type_versions
            WHERE tenant=? AND environment=? GROUP BY case_type ORDER BY case_type LIMIT 200', $scope->keys());
    }

    private function normalize(Scope $scope, array $input): array
    {
        $sources = self::uniqueList($input['sources'] ?? null, 'sources', 20);
        $required = self::uniqueList($input['required_items'] ?? null, 'required_items', count(self::KINDS));
        if (array_diff($required, self::KINDS)) {
            throw Problem::invalid('Unknown evidence kind in required_items.');
        }
        $people = $input['people'] ?? [];
        $sla = $input['sla_minutes'] ?? [];
        $calendar = $input['calendar'] ?? [];
        if (!is_array($people) || !is_array($sla) || !is_array($calendar)) {
            throw Problem::invalid('Expected people, sla_minutes and calendar objects.');
        }
        $assigned = [];
        $deadlines = [];
        foreach (self::TASKS as $task) {
            $assigned[$task] = self::uniqueList($people[$task] ?? null, "people.$task", 30);
            $deadlines[$task] = Values::number($sla[$task] ?? null, "sla_minutes.$task", 1, 10080);
        }
        if (array_intersect($assigned['review-operations'], $assigned['review-finance'])) {
            throw Problem::invalid('Operations and finance require separate reviewers.');
        }
        $workflow = $input['workflow'] ?? null;
        if ($workflow !== null) {
            if (!is_array($workflow)) {
                throw Problem::invalid('Expected a workflow and revision.');
            }
            $version = $this->flows->version($scope, Values::identifier($workflow['name'] ?? null, 'workflow.name'),
                Values::number($workflow['revision'] ?? null, 'workflow.revision', 1, 2147483647));
            $workflow = ['name' => $version['flow'], 'revision' => $version['revision'], 'digest' => $version['digest']];
        }
        $refund = Values::number($input['refund_limit_minor'] ?? 0, 'refund_limit_minor', 0, 100000000);
        if ($refund > 0 && !in_array('payment-proof', $required, true)) {
            throw Problem::invalid('A refund template must require payment proof.');
        }
        return ['sources' => $sources, 'required_items' => $required, 'people' => $assigned, 'sla_minutes' => $deadlines,
            'calendar' => CaseCalendar::validate($calendar), 'refund_limit_minor' => $refund,
            'escalation_recipient' => Values::identifier($input['escalation_recipient'] ?? null, 'escalation_recipient'),
            'workflow' => $workflow];
    }

    private static function uniqueList(mixed $input, string $label, int $limit): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) > $limit) {
            throw Problem::invalid("Expected a nonempty list for $label.");
        }
        $list = array_map(static fn (mixed $value): string => Values::identifier($value, $label), $input);
        $list = array_values(array_unique($list));
        sort($list);
        return $list;
    }
}
