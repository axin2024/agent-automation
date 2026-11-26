<?php

declare(strict_types=1);

namespace AgentAutomation;

final class Approvals
{
    public function __construct(
        private Db $db,
        private Runs $runs,
        private Workflows $flows,
        private Orders $orders,
        private Connections $connections,
    ) {
    }

    public function latest(Scope $scope, string $run, string $node, bool $lock = false): ?array
    {
        $record = $this->db->one('SELECT *,expires>UTC_TIMESTAMP(6) AS unexpired FROM approvals
            WHERE tenant=? AND environment=? AND run=? AND node=? ORDER BY revision DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [...$scope->keys(), $run, $node]);
        if ($record !== null) {
            $record['draft'] = Values::decode($record['draft']);
        }
        return $record;
    }

    public function view(Scope $scope, string $run): array
    {
        $scope->require('operator', 'approver', 'author');
        $this->runs->row($scope, $run);
        $entries = $this->db->all('SELECT a.node,a.revision,a.draft,a.digest,a.state,a.expires,a.actor,a.decided_at,s.assignee
            FROM approvals a LEFT JOIN review_assignments s USING(tenant,environment,run,node)
            WHERE a.tenant=? AND a.environment=? AND a.run=? ORDER BY a.node,a.revision DESC LIMIT 100', [...$scope->keys(), $run]);
        foreach ($entries as &$record) {
            $record['draft'] = Values::decode($record['draft']);
        }
        return $entries;
    }

    public function pending(Scope $scope, string $reviewer = '', string $dueBefore = '', string $after = ''): array
    {
        $scope->require('operator', 'approver');
        $args = $scope->keys();
        $filter = '';
        if ($reviewer !== '') {
            $filter .= ' AND s.assignee=?';
            $args[] = Values::identifier($reviewer, 'reviewer');
        }
        if ($dueBefore !== '') {
            $filter .= ' AND a.expires<?';
            $args[] = Values::utcSecond($dueBefore, 'expiry cutoff');
        }
        if ($after !== '') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.(\d{6})\|([a-f0-9]{32})$/D', $after, $parts) !== 1) {
                throw Problem::invalid('Invalid approval cursor.');
            }
            $time = Values::utcSecond(str_replace(' ', 'T', $parts[1]) . 'Z', 'cursor time') . '.' . $parts[2];
            $filter .= ' AND (a.expires>? OR (a.expires=? AND a.run>?))';
            array_push($args, $time, $time, $parts[3]);
        }
        $rows = $this->db->all("SELECT a.run,r.flow,a.node,a.revision,a.expires,a.expires<=UTC_TIMESTAMP(6) AS expired,s.assignee
            FROM approvals a JOIN runs r ON r.tenant=a.tenant AND r.environment=a.environment AND r.id=a.run AND r.`cursor`=a.node
            LEFT JOIN review_assignments s ON s.tenant=a.tenant AND s.environment=a.environment AND s.run=a.run AND s.node=a.node
            WHERE a.tenant=? AND a.environment=? AND a.state IN ('pending','invalid') AND r.state='waiting-review'
            AND NOT EXISTS (SELECT 1 FROM approvals newer WHERE newer.tenant=a.tenant AND newer.environment=a.environment
                AND newer.run=a.run AND newer.node=a.node AND newer.revision>a.revision)"
            . $filter . ' ORDER BY a.expires,a.run LIMIT 51', $args);
        $last = $rows[49] ?? null;
        return ['items' => array_slice($rows, 0, 50), 'next' => count($rows) === 51 ? $last['expires'] . '|' . $last['run'] : null];
    }

    public function assign(Scope $scope, string $run, string $node, int $revision, ?string $expected, ?string $assignee): array
    {
        $scope->require('operator', 'approver');
        if ($assignee !== null) {
            Values::identifier($assignee, 'reviewer');
        }
        return $this->db->transaction(function () use ($scope, $run, $node, $revision, $expected, $assignee): array {
            $row = $this->runs->row($scope, $run, true);
            $approval = $this->latest($scope, $run, $node, true);
            if ($row['state'] !== 'waiting-review' || $row['cursor'] !== $node || $approval === null ||
                $approval['revision'] !== $revision || !in_array($approval['state'], ['pending', 'invalid'], true)) {
                throw Problem::conflict('The current review task changed.');
            }
            $current = $this->assignee($scope, $run, $node);
            if ($scope->role === 'approver' && ($current !== $scope->actor && !($current === null && $assignee === $scope->actor))) {
                throw Problem::denied();
            }
            if ($current !== $expected) {
                throw Problem::conflict('Another operator changed this assignment.');
            }
            if ($assignee !== $current) {
                if ($assignee === null) {
                    $this->db->exec('DELETE FROM review_assignments WHERE tenant=? AND environment=? AND run=? AND node=?',
                        [...$scope->keys(), $run, $node]);
                } else {
                    $eligible = $this->db->one("SELECT 1 FROM credentials WHERE tenant=? AND environment=? AND actor=?
                        AND role IN ('approver','admin') AND revoked IS NULL AND expires>UTC_TIMESTAMP(6) LIMIT 1",
                        [...$scope->keys(), $assignee]);
                    if ($eligible === null) {
                        throw Problem::invalid('Choose an active approver in this environment.');
                    }
                    $this->db->exec('INSERT INTO review_assignments(tenant,environment,run,node,assignee) VALUES(?,?,?,?,?)
                        AS incoming ON DUPLICATE KEY UPDATE assignee=incoming.assignee', [...$scope->keys(), $run, $node, $assignee]);
                }
                $this->db->audit($scope, 'review-assigned', $run, ['node' => $node, 'previous' => $current, 'assignee' => $assignee]);
            }
            return ['node' => $node, 'revision' => $revision, 'assignee' => $assignee];
        });
    }

    private function assignee(Scope $scope, string $run, string $node): ?string
    {
        return $this->db->one('SELECT assignee FROM review_assignments WHERE tenant=? AND environment=? AND run=? AND node=?',
            [...$scope->keys(), $run, $node])['assignee'] ?? null;
    }

    public function create(Scope $scope, array $run, string $node, array $draft, array $connection, int $ttl): array
    {
        $recipient = Values::email($draft['recipient'] ?? null);
        $this->connections->ensureRecipient($connection, $recipient);
        Values::text($draft['subject'] ?? null, 'draft subject', 350);
        Values::text($draft['body'] ?? null, 'draft body', 4000);
        Orders::orderNumber($draft['order_no'] ?? null);
        Values::number($draft['order_version'] ?? null, 'order revision', 1, 2147483647);
        $prior = $this->latest($scope, $run['id'], $node, true);
        $revision = ($prior['revision'] ?? 0) + 1;
        if ($prior !== null && in_array($prior['state'], ['pending', 'approved'], true)) {
            $this->invalidate($scope, $run['id'], $node, $prior['revision']);
        }
        $digest = Values::digest([$run['id'], $node, $revision, $connection['connection'], $connection['revision'], $draft]);
        $this->db->exec("INSERT INTO approvals
            (tenant,environment,run,node,revision,connection,connection_revision,draft,digest,state,expires)
            VALUES(?,?,?,?,?,?,?,?,?,'pending',DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? MINUTE))",
            [...$scope->keys(), $run['id'], $node, $revision, $connection['connection'], $connection['revision'],
                Values::json($draft), $digest, $ttl]);
        return ['node' => $node, 'revision' => $revision, 'digest' => $digest];
    }

    public function decide(Scope $scope, string $id, string $node, int $revision, string $digest, string $action): array
    {
        return $this->db->transaction(fn (): array => $this->recordDecision($scope, $id, $node, $revision, $digest, $action));
    }

    /** Applies one decision inside the caller's transaction. */
    public function recordDecision(Scope $scope, string $id, string $node, int $revision, string $digest, string $action): array
    {
        $scope->require('approver');
        if (!in_array($action, ['approve', 'reject'], true)) {
            throw Problem::invalid('Unknown review action.');
        }
        $run = $this->runs->row($scope, $id, true);
        $approval = $this->latest($scope, $id, $node, true);
        $state = $action === 'approve' ? 'approved' : 'rejected';
        if ($approval === null || $approval['revision'] !== $revision || !hash_equals($approval['digest'], $digest)) {
            throw Problem::conflict('The draft was replaced. Reload its latest revision.');
        }
        if ($approval['state'] === $state && $approval['actor'] === $scope->actor) {
            return ['state' => $state, 'revision' => $revision, 'digest' => $digest, 'replayed' => true];
        }
        $assignee = $this->assignee($scope, $id, $node);
        if ($assignee !== null && $assignee !== $scope->actor) {
            throw Problem::denied();
        }
        if ($run['state'] !== 'waiting-review' || $run['cursor'] !== $node || $approval['state'] !== 'pending' ||
            ($action === 'approve' && !$this->rechecked($scope, $approval))) {
            throw Problem::conflict('The draft, order, or connection changed. Refresh it before approval.');
        }
        $version = $this->flows->version($scope, $run['flow'], $run['graph_revision']);
        $definition = $version['graph']['nodes'][$node];
        $next = $definition['edges'][$state];
        $elapsed = $this->db->one('SELECT TIMESTAMPDIFF(MICROSECOND,DATE_SUB(CAST(? AS DATETIME(6)), INTERVAL ? MINUTE),
            UTC_TIMESTAMP(6)) AS elapsed', [$approval['expires'], $definition['options']['expires_minutes']]);
        $this->db->recordDuration($scope, $id, 'human-review', (int) $elapsed['elapsed']);
        $this->db->exec('UPDATE approvals SET state=?,actor=?,decided_at=UTC_TIMESTAMP(6)
            WHERE tenant=? AND environment=? AND run=? AND node=? AND revision=?',
            [$state, $scope->actor, ...$scope->keys(), $id, $node, $revision]);
        $outputs = Values::decode($run['outputs']);
        if ($state === 'approved') {
            $outputs[$node] = ['approval' => ['node' => $node, 'revision' => $revision, 'digest' => $digest]];
        }
        $this->db->exec("UPDATE runs SET state='queued',`cursor`=?,outputs=?,revision=revision+1,ready_at=UTC_TIMESTAMP(6)
            WHERE tenant=? AND environment=? AND id=?", [$next, Values::json($outputs), ...$scope->keys(), $id]);
        $this->db->audit($scope, 'review-' . $state, $id, ['node' => $node, 'revision' => $revision, 'digest' => $digest]);
        return ['state' => $state, 'revision' => $revision, 'digest' => $digest, 'replayed' => false];
    }

    public function revise(Scope $scope, string $id, string $node, int $revision, array $changes): array
    {
        $scope->require('approver', 'operator');
        if (array_diff(array_keys($changes), ['recipient', 'body', 'regenerate'])) {
            throw Problem::invalid('Unknown draft changes.');
        }
        return $this->db->transaction(function () use ($scope, $id, $node, $revision, $changes): array {
            $run = $this->runs->row($scope, $id, true);
            $approval = $this->latest($scope, $id, $node, true);
            if ($run['state'] !== 'waiting-review' || $run['cursor'] !== $node || $approval === null ||
                $approval['revision'] !== $revision || !in_array($approval['state'], ['pending', 'invalid'], true)) {
                throw Problem::conflict('The draft is no longer editable at this revision.');
            }
            $assignee = $this->assignee($scope, $id, $node);
            if ($scope->role === 'approver' && $assignee !== null && $assignee !== $scope->actor) {
                throw Problem::denied();
            }
            $version = $this->flows->version($scope, $run['flow'], $run['graph_revision']);
            $definition = $version['graph']['nodes'][$node];
            $draft = $approval['draft'];
            $order = $this->orders->get($scope, $draft['order_no'], true);
            if ($order === null) {
                throw Problem::conflict('The order no longer exists.');
            }
            if (($changes['regenerate'] ?? false) === true || $draft['order_version'] !== $order['version']) {
                if (isset($changes['recipient']) || isset($changes['body'])) {
                    throw Problem::conflict('Regenerate the stale order information before applying edits.');
                }
                $this->invalidate($scope, $id, $node, $revision);
                [$draftNode] = explode('.', $definition['inputs']['draft'], 2);
                $draftInputs = $version['graph']['nodes'][$draftNode]['inputs'];
                $outputs = Values::decode($run['outputs']);
                foreach (['order_no' => 'order_no', 'state' => 'state', 'version' => 'version'] as $port => $field) {
                    [$from, $to] = explode('.', $draftInputs[$port], 2);
                    $outputs[$from][$to] = $order[$field];
                }
                unset($outputs[$draftNode], $outputs[$node]);
                $this->db->exec("UPDATE runs SET state='queued',`cursor`=?,outputs=?,revision=revision+1,ready_at=UTC_TIMESTAMP(6)
                    WHERE tenant=? AND environment=? AND id=?", [$draftNode, Values::json($outputs), ...$scope->keys(), $id]);
                $this->db->audit($scope, 'draft-regenerating', $id, ['order_version' => $order['version']]);
                return ['state' => 'queued', 'order_version' => $order['version']];
            }
            $name = $version['bindings'][$definition['binding']];
            $connection = $this->connections->active($scope, $name, 'inbox', true);
            if ($connection === null) {
                throw Problem::conflict('The inbox connection is unavailable.');
            }
            $draft['recipient'] = $changes['recipient'] ?? $draft['recipient'];
            $draft['body'] = $changes['body'] ?? $draft['body'];
            $replacement = $this->create($scope, $run, $node, $draft, $connection, $definition['options']['expires_minutes']);
            $this->db->exec('UPDATE runs SET revision=revision+1 WHERE tenant=? AND environment=? AND id=?', [...$scope->keys(), $id]);
            $this->db->audit($scope, 'draft-revised', $id, $replacement);
            return ['state' => 'waiting-review'] + $replacement;
        });
    }

    public function rechecked(Scope $scope, array $approval): bool
    {
        if (!$approval['unexpired']) {
            return false;
        }
        $order = $this->orders->get($scope, $approval['draft']['order_no'], true);
        if ($order === null || $order['version'] !== $approval['draft']['order_version']) {
            return false;
        }
        $connection = $this->connections->active($scope, $approval['connection'], 'inbox', true);
        if ($connection === null || $connection['revision'] !== $approval['connection_revision']) {
            return false;
        }
        try {
            $this->connections->ensureRecipient($connection, $approval['draft']['recipient']);
        } catch (Problem) {
            return false;
        }
        return true;
    }

    public function invalidate(Scope $scope, string $id, string $node, int $revision): void
    {
        $this->db->exec("UPDATE approvals SET state='invalid' WHERE tenant=? AND environment=? AND run=? AND node=? AND revision=?
            AND state IN ('pending','approved')", [...$scope->keys(), $id, $node, $revision]);
    }
}
