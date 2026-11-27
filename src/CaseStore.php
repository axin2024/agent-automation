<?php

declare(strict_types=1);

namespace AgentAutomation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class CaseStore
{
    public function __construct(public readonly Db $db, public readonly CaseTemplates $templates, private ?Closure $clock = null)
    {
    }

    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function now(): DateTimeImmutable
    {
        $time = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public function change(Scope $scope, mixed $key, string $action, string $id, array $input, callable $run): array
    {
        if (!is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $key) !== 1) {
            throw Problem::invalid('A valid Idempotency-Key is required.');
        }
        $hash = Values::digest([$action, $id, $input]);
        return $this->db->transaction(function () use ($scope, $key, $hash, $run): array {
            $parameters = [...$scope->keys(), $scope->actor, $key];
            $this->db->exec('INSERT INTO case_request_receipts(tenant,environment,actor,request_id,request_hash)
                VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE request_id=request_id', [...$parameters, $hash]);
            $previous = $this->db->one('SELECT request_hash,payload FROM case_request_receipts
                WHERE tenant=? AND environment=? AND actor=? AND request_id=? FOR UPDATE', $parameters);
            if (!hash_equals($previous['request_hash'], $hash)) {
                throw Problem::conflict('The idempotency key belongs to a different request.');
            }
            if ($previous['payload'] !== null) {
                return Values::decode($previous['payload']);
            }
            $receipt = $run();
            $this->db->exec('UPDATE case_request_receipts SET payload=?
                WHERE tenant=? AND environment=? AND actor=? AND request_id=?', [Values::json($receipt), ...$parameters]);
            return $receipt;
        });
    }

    public function record(Scope $scope, string $id, bool $lock = false): array
    {
        return $this->db->one('SELECT * FROM service_cases WHERE tenant=? AND environment=? AND case_id=?' .
            ($lock ? ' FOR UPDATE' : ''), [...$scope->keys(), $id]) ?? throw Problem::missing();
    }

    public function template(Scope $scope, array $case): array
    {
        return $this->templates->get($scope, $case['case_type'], $case['case_type_rev'])['definition'];
    }

    public function open(Scope $scope, string $id, array $input, string ...$states): array
    {
        $row = $this->record($scope, $id, true);
        $expected = Values::number($input['revision'] ?? null, 'revision', 1, 2147483647);
        if ($row['revision'] !== $expected || !in_array($row['state'], $states, true)) {
            throw Problem::conflict('The case revision or stage changed.');
        }
        return $row;
    }

    public function transition(Scope $scope, array $case, string $next, string $stage, array $extra = []): void
    {
        $this->db->exec('UPDATE service_cases SET state=?, revision=revision+1, deadline=?, escalated=0
            WHERE tenant=? AND environment=? AND case_id=?', [
            $next, $this->deadline($this->template($scope, $case), $stage), ...$scope->keys(), $case['case_id'],
        ]);
        $this->event($scope, 'case-stage', $case['case_id'], $extra + [
            'from' => $case['state'], 'to' => $next, 'case_round' => $case['case_round'], 'revision' => $case['revision'] + 1,
        ]);
    }

    public function deadline(array $template, string $stage): string
    {
        return CaseCalendar::deadline($this->now(), $template['calendar'], $template['sla_minutes'][$stage]);
    }

    public function createTask(Scope $scope, string $id, int $round, string $role, array $template): void
    {
        $this->db->exec('INSERT INTO case_assignments(tenant,environment,case_id,case_round,task_role,assignment_id,due_at)
            VALUES(?,?,?,?,?,?,?) AS incoming ON DUPLICATE KEY UPDATE due_at=incoming.due_at',
            [...$scope->keys(), $id, $round, $role, self::id(), $this->deadline($template, $role)]);
    }

    public function event(Scope $scope, string $action, string $id, array $detail): void
    {
        $this->db->exec('INSERT INTO case_events(tenant,environment,case_id,actor,action,detail,happened_at) VALUES(?,?,?,?,?,?,?)',
            [...$scope->keys(), $id, $scope->actor, $action, Values::json($detail), $this->now()->format('Y-m-d H:i:s.u')]);
    }

    public function requireActor(Scope $scope, string $actor, string $role): void
    {
        if (!$this->actorActive($scope, $actor, $role)) {
            throw Problem::denied();
        }
    }

    public function actorActive(Scope $scope, string $actor, string $role): bool
    {
        return $this->db->one('SELECT 1 FROM credentials WHERE tenant=? AND environment=? AND actor=?
            AND role=? AND revoked IS NULL AND expires>UTC_TIMESTAMP(6) LIMIT 1 FOR SHARE',
            [...$scope->keys(), $actor, $role]) !== null;
    }

    public static function role(string $task): string
    {
        return in_array($task, ['investigate', 'review-operations'], true) ? 'operator' : 'approver';
    }
}
