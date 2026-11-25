<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Workflows
{
    public function __construct(private Db $db, private Connections $connections)
    {
    }

    public function save(Scope $scope, string $name, array $definition, array $bindings, int $expected, bool $activate): array
    {
        $scope->require('author');
        Values::identifier($name, 'workflow name');
        Values::number($expected, 'expected workflow revision', 0, 2147483646);
        $graph = Graph::compile($definition);
        $requirements = Graph::bindingKinds($graph);
        if (array_diff_key($bindings, $requirements) || array_diff_key($requirements, $bindings)) {
            throw Problem::invalid('Connect every required alias to a local connection.');
        }
        foreach ($bindings as $alias => $connection) {
            Values::identifier($connection, "connection for $alias");
        }
        $digest = Values::digest([$graph, $bindings]);
        return $this->db->transaction(function () use ($scope, $name, $graph, $requirements, $bindings, $digest, $expected, $activate): array {
            $this->db->one('SELECT tenant FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $this->db->exec('INSERT INTO workflows(tenant,environment,flow) VALUES(?,?,?) ON DUPLICATE KEY UPDATE flow=flow',
                [...$scope->keys(), $name]);
            $current = $this->active($scope, $name);
            if (($current ?? 0) !== $expected) {
                throw Problem::conflict('The active workflow revision changed.');
            }
            foreach ($bindings as $alias => $connection) {
                if ($this->connections->active($scope, $connection, $requirements[$alias]) === null) {
                    throw Problem::invalid("Connection $alias is unavailable in this environment.");
                }
            }
            if ($scope->environment !== 'test' && array_filter($graph['nodes'], static fn (array $node): bool =>
                $node['kind'] === 'draft' && $node['options']['mode'] === 'replay')) {
                throw Problem::denied();
            }
            $prior = $this->db->one('SELECT revision FROM graph_versions WHERE tenant=? AND environment=? AND flow=? AND digest=?',
                [...$scope->keys(), $name, $digest]);
            if ($prior === null) {
                $version = $this->db->one('SELECT COALESCE(MAX(revision),0)+1 AS next FROM graph_versions
                    WHERE tenant=? AND environment=? AND flow=?', [...$scope->keys(), $name]);
                $revision = (int) $version['next'];
                $this->db->exec('INSERT INTO graph_versions(tenant,environment,flow,revision,digest,graph,bindings,actor)
                    VALUES(?,?,?,?,?,?,?,?)', [...$scope->keys(), $name, $revision, $digest, Values::json($graph),
                    Values::json($bindings), $scope->actor]);
                $this->db->audit($scope, 'workflow-saved', $name, ['revision' => $revision, 'digest' => $digest]);
            } else {
                $revision = (int) $prior['revision'];
            }
            if ($activate && $current !== $revision) {
                $this->switch($scope, $name, $revision);
                $current = $revision;
            }
            return ['flow' => $name, 'revision' => $revision, 'digest' => $digest, 'active' => $current];
        });
    }

    public function activate(Scope $scope, string $name, int $revision, int $expected): array
    {
        $scope->require('author');
        return $this->db->transaction(function () use ($scope, $name, $revision, $expected): array {
            $this->db->one('SELECT tenant FROM tenants WHERE tenant=? FOR UPDATE', [$scope->tenant]);
            $current = $this->active($scope, $name);
            $version = $this->version($scope, $name, $revision);
            if ($current === $revision) {
                return ['flow' => $name, 'active' => $revision, 'digest' => $version['digest']];
            }
            if (($current ?? 0) !== $expected) {
                throw Problem::conflict('The active workflow revision changed.');
            }
            $this->switch($scope, $name, $revision);
            return ['flow' => $name, 'active' => $revision, 'digest' => $version['digest']];
        });
    }

    public function active(Scope $scope, string $name): ?int
    {
        $row = $this->db->one('SELECT active_revision FROM workflows WHERE tenant=? AND environment=? AND flow=?',
            [...$scope->keys(), $name]);
        return $row['active_revision'] ?? null;
    }

    public function version(Scope $scope, string $name, int $revision): array
    {
        $row = $this->db->one('SELECT flow,revision,digest,graph,bindings FROM graph_versions
            WHERE tenant=? AND environment=? AND flow=? AND revision=?', [...$scope->keys(), $name, $revision]);
        if ($row === null) {
            throw Problem::missing();
        }
        $row['graph'] = Values::decode($row['graph']);
        $row['bindings'] = Values::decode($row['bindings']);
        return $row;
    }

    public function list(Scope $scope): array
    {
        $scope->require('author', 'operator', 'approver');
        return $this->db->all('SELECT flow,active_revision AS active FROM workflows WHERE tenant=? AND environment=? ORDER BY flow LIMIT 200',
            $scope->keys());
    }

    public function template(Scope $scope, string $name, int $revision): array
    {
        $scope->require('author');
        $source = $this->version($scope, $name, $revision);
        return ['schema' => 1, 'definition' => ['nodes' => $source['graph']['nodes']],
            'connection_contract' => Graph::bindingKinds($source['graph']),
            'fixtures' => [['message' => '<sample-ticket@example.test>', 'sender' => 'shopper@example.test',
                'subject' => 'Return inquiry for ORD-4001', 'body' => 'I need help with the parcel from ORD-4001.']]];
    }

    private function switch(Scope $scope, string $name, int $revision): void
    {
        $this->db->exec('UPDATE workflows SET active_revision=? WHERE tenant=? AND environment=? AND flow=?',
            [$revision, ...$scope->keys(), $name]);
        $this->db->audit($scope, 'workflow-activated', $name, ['revision' => $revision]);
    }
}
